<?php
declare( strict_types=1 );

/**
 * Resolves Google News RSS redirect URLs to their true publisher destination URL.
 *
 * Implements a 3-tier resolution strategy:
 * 1. HTTP HEAD Location header follower (allowing cross-domain redirects for known aggregators).
 * 2. HTML landing page interstitial parser (meta refresh, window.location, data-n-au attributes).
 * 3. Google batchexecute RPC (Fbv4je) for obfuscated CBMi URLs.
 *
 * Includes an adversarially-hardened Circuit-Breaker pattern to protect server IPs from rate limits.
 *
 * @since 2.6.0
 */
class Peptide_News_Google_URL_Resolver {

	const AGGREGATOR_DOMAINS  = array( 'news.google.com', 'news.yahoo.com', 'msn.com' );
	const BACKOFF_TRANSIENT   = 'peptide_news_google_backoff';
	const FAIL_COUNT_OPTION   = 'peptide_news_google_fail_count';
	const CIRCUIT_BREAKER_MAX = 3;
	const BACKOFF_DURATION    = 1800; // 30 minutes

	/**
	 * Resolves a Google News RSS redirect URL to its true destination URL.
	 *
	 * @param string $url The raw RSS link URL.
	 * @return string The resolved destination URL, or original URL on fallback.
	 */
	public static function resolve( string $url ): string {
		if ( empty( $url ) || ! self::is_aggregator_url( $url ) ) {
			return $url;
		}

		// Check circuit breaker transient.
		if ( false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
			Peptide_News_Logger::warning( 'Google URL Resolver circuit breaker active. Skipping remote resolution.', 'fetcher' );
			return $url;
		}

		// Tier 1: HTTP HEAD Location Resolution
		$resolved = self::resolve_via_http_header( $url );
		if ( $resolved && ! self::is_google_domain( $resolved ) ) {
			self::reset_circuit_breaker();
			return $resolved;
		}

		// Tier 2: Fetch HTML landing page and parse JS/meta redirect patterns
		$html_response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
				'cookies'     => array(),
			)
		);

		$code = is_wp_error( $html_response ) ? 0 : (int) wp_remote_retrieve_response_code( $html_response );

		if ( 429 === $code || 403 === $code ) {
			self::record_failure();
			return $url;
		}

		if ( ! is_wp_error( $html_response ) && 200 === $code ) {
			$html       = wp_remote_retrieve_body( $html_response );
			$parsed_url = self::parse_redirect_from_html( $html );
			if ( $parsed_url && ! self::is_google_domain( $parsed_url ) ) {
				self::reset_circuit_breaker();
				return $parsed_url;
			}

			// Tier 3: Google batchexecute RPC (Fbv4je) resolution for CBMi URLs
			$rpc_url = self::resolve_via_batchexecute( $url, $html );
			if ( $rpc_url && ! self::is_google_domain( $rpc_url ) ) {
				self::reset_circuit_breaker();
				return $rpc_url;
			}
		}

		return $url;
	}

	/**
	 * Attempt resolution via HTTP HEAD Location header.
	 *
	 * @param string $url Target URL.
	 * @return string|null Resolved URL or null.
	 */
	private static function resolve_via_http_header( string $url ): ?string {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || 403 === $code ) {
			self::record_failure();
			return null;
		}

		$final_url = wp_remote_retrieve_header( $response, 'location' );
		if ( empty( $final_url ) && isset( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$raw = $response['http_response']->get_response_object();
			if ( isset( $raw->url ) ) {
				$final_url = (string) $raw->url;
			}
		}

		if ( empty( $final_url ) || ! is_string( $final_url ) ) {
			return null;
		}

		// Allow cross-domain redirect if original host is an aggregator.
		$original_host = wp_parse_url( $url, PHP_URL_HOST );
		$final_host    = wp_parse_url( $final_url, PHP_URL_HOST );

		if (
			$original_host !== $final_host &&
			in_array( preg_replace( '/^www\./', '', (string) $original_host ), self::AGGREGATOR_DOMAINS, true )
		) {
			return $final_url;
		}

		return $final_url;
	}

	/**
	 * Parse redirect destination from Google consent/interstitial HTML.
	 *
	 * @param string $html Page body.
	 * @return string|null Parsed destination URL.
	 */
	private static function parse_redirect_from_html( string $html ): ?string {
		if ( preg_match( '/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]+content=["\'][0-9]+;\s*url=([^"\']+)["\']/i', $html, $matches ) ) {
			return esc_url_raw( trim( $matches[1] ) );
		}
		if ( preg_match( '/window\.location\.(?:replace|href)\s*=\s*[\'"](https?:\/\/[^\'"]+)[\'"]/i', $html, $matches ) ) {
			return esc_url_raw( trim( $matches[1] ) );
		}
		if ( preg_match( '/data-n-au=["\'](https?:\/\/(?!news\.google\.com|www\.google\.com)[^"\']+)["\']/i', $html, $matches ) ) {
			return esc_url_raw( trim( $matches[1] ) );
		}
		if ( preg_match( '/<a[^>]+href=["\'](https?:\/\/(?!news\.google\.com|www\.google\.com|support\.google\.com|accounts\.google\.com)[^"\']+)["\']/i', $html, $matches ) ) {
			return esc_url_raw( trim( $matches[1] ) );
		}
		return null;
	}

	/**
	 * Invokes Google batchexecute RPC (Fbv4je) to decode CBMi URLs.
	 *
	 * @param string $url  Raw Google URL.
	 * @param string $html Page body for signature extraction.
	 * @return string|null
	 */
	private static function resolve_via_batchexecute( string $url, string $html ): ?string {
		if ( ! preg_match( '/\/articles\/([a-zA-Z0-9_-]+)/', $url, $id_match ) ) {
			return null;
		}
		$gn_art_id = $id_match[1];

		$sig = '';
		$ts  = '';
		if ( preg_match( '/data-n-a-sg=["\']([^"\']+)["\']/', $html, $m_sig ) ) {
			$sig = $m_sig[1];
		}
		if ( preg_match( '/data-n-a-ts=["\']([^"\']+)["\']/', $html, $m_ts ) ) {
			$ts = $m_ts[1];
		}

		$rpc_payload = wp_json_encode(
			array(
				array(
					array( 'Fbv4je', wp_json_encode( array( $gn_art_id, $sig, $ts ) ), null, 'generic' ),
				),
			)
		);

		$response = wp_remote_post(
			'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je',
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
					'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
					'Referer'      => 'https://news.google.com/',
				),
				'body'    => 'f.req=' . urlencode( (string) $rpc_payload ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$body = preg_replace( '/^\)]\}\'\s*/', '', $body );
		$data = json_decode( (string) $body, true );

		$resolved_url = null;
		if ( is_array( $data ) ) {
			array_walk_recursive(
				$data,
				function ( $item ) use ( &$resolved_url ) {
					if ( is_string( $item ) && preg_match( '/^https?:\/\/(?!news\.google\.com|www\.google\.com)/i', $item ) ) {
						$resolved_url = $item;
					}
				}
			);
			if ( ! empty( $resolved_url ) ) {
				return esc_url_raw( (string) $resolved_url );
			}
		}

		return null;
	}

	/**
	 * Record an HTTP 429/403 failure and trigger circuit breaker if threshold exceeded.
	 */
	private static function record_failure(): void {
		$fails = (int) get_option( self::FAIL_COUNT_OPTION, 0 ) + 1;
		update_option( self::FAIL_COUNT_OPTION, $fails );

		if ( $fails >= self::CIRCUIT_BREAKER_MAX ) {
			set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_DURATION );
			Peptide_News_Logger::warning( 'Google URL Resolver hit ' . $fails . ' failures. Circuit breaker tripped for 30m.', 'fetcher' );
		}
	}

	/**
	 * Reset failure counter upon successful resolution.
	 */
	private static function reset_circuit_breaker(): void {
		if ( (int) get_option( self::FAIL_COUNT_OPTION, 0 ) > 0 ) {
			update_option( self::FAIL_COUNT_OPTION, 0 );
		}
		delete_transient( self::BACKOFF_TRANSIENT );
	}

	/**
	 * Check if URL host is a known aggregator.
	 *
	 * @param string $url URL string.
	 * @return bool
	 */
	public static function is_aggregator_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && in_array( preg_replace( '/^www\./', '', (string) $host ), self::AGGREGATOR_DOMAINS, true );
	}

	/**
	 * Check if URL points to a Google domain.
	 *
	 * @param string $url URL string.
	 * @return bool
	 */
	public static function is_google_domain( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && ( strpos( (string) $host, 'google.com' ) !== false || strpos( (string) $host, 'googleusercontent.com' ) !== false );
	}
}
