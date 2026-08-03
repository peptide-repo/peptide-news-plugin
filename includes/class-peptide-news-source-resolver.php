<?php
declare( strict_types=1 );
/**
 * Resolves the real publisher source name for aggregated news articles.
 *
 * Aggregators like Google News wrap articles behind their own URLs, so
 * the feed-level domain is useless. This class tries several strategies
 * (SimplePie <source>, title suffix parsing, redirect resolution) to
 * find the actual publisher. Also provides a backfill routine for
 * existing articles stored with aggregator domains.
 *
 * Called by Peptide_News_RSS_Source during item processing and by the
 * admin "Backfill Sources" AJAX action.
 *
 * @since 2.5.0
 * @see   class-peptide-news-rss-source.php   Consumes resolve_article_source().
 * @see   class-peptide-news-fetcher.php      Orchestrator that triggers fetch cycles.
 */
class Peptide_News_Source_Resolver {

	/** @var string[] Domains that aggregate content from other publishers. */
	const AGGREGATOR_DOMAINS = array( 'news.google.com', 'news.yahoo.com', 'msn.com', 'feedly.com' );

	/**
	 * Resolve the real source name for an RSS feed item.
	 *
	 * Tries strategies in priority order: SimplePie <source> element,
	 * title suffix parsing, redirect URL resolution, article domain,
	 * then feed domain as last resort.
	 *
	 * @param \SimplePie_Item $item        The RSS item.
	 * @param string          $feed_url    The feed URL.
	 * @param string          $article_url The article permalink.
	 * @return string Source name or domain.
	 */
	public static function resolve( $item, string $feed_url, string $article_url ): string {
		$feed_domain   = self::extract_domain( $feed_url );
		$is_aggregator = in_array( $feed_domain, self::AGGREGATOR_DOMAINS, true );

		// Strategy 1: SimplePie <source> element.
		$source_obj = $item->get_source();
		if ( $source_obj ) {
			$source_title = $source_obj->get_title();
			if ( ! empty( $source_title ) ) {
				return sanitize_text_field( $source_title );
			}
			$source_link = $source_obj->get_link();
			if ( ! empty( $source_link ) ) {
				$domain = self::extract_domain( $source_link );
				if ( 'unknown' !== $domain ) {
					return $domain;
				}
			}
		}

		// Strategy 2: Parse "Headline - Source" from title.
		if ( $is_aggregator ) {
			$parsed = self::parse_source_from_title( $item->get_title() );
			if ( $parsed ) {
				return $parsed;
			}
		}

		// Strategy 3: Resolve aggregator redirect URL to actual destination.
		if ( $is_aggregator ) {
			$resolved = Peptide_News_Google_URL_Resolver::resolve( $article_url );
			if ( ! $resolved || $resolved === $article_url ) {
				$resolved = self::resolve_redirect_url( $article_url );
			}
			if ( $resolved && $resolved !== $article_url ) {
				$domain = self::extract_domain( (string) $resolved );
				if ( 'unknown' !== $domain && $domain !== $feed_domain ) {
					return $domain;
				}
			}
		}

		// Strategy 4: Article permalink domain.
		$article_domain = self::extract_domain( $article_url );
		if ( 'unknown' !== $article_domain && $article_domain !== $feed_domain ) {
			return $article_domain;
		}

		// Strategy 5: Feed domain.
		return $feed_domain;
	}

	/**
	 * Backfill source names for existing articles stored with aggregator domains.
	 *
	 * Side effects: DB reads + updates, HTTP HEAD requests (throttled 0.3 s each),
	 * clears article caches if any rows updated.
	 *
	 * @return int Number of articles updated.
	 */
	public static function backfill(): int {
		global $wpdb;

		$table        = $wpdb->prefix . 'peptide_news_articles';
		$aggregators  = array( 'news.google.com', 'news.yahoo.com', 'msn.com' );
		$placeholders = implode( ', ', array_fill( 0, count( $aggregators ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$articles = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, source, source_url FROM {$table} WHERE source IN ({$placeholders}) AND is_active = 1",
			...$aggregators
		) );

		if ( empty( $articles ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( $articles as $article ) {
			$new_source = self::parse_source_from_title( $article->title );

			// Fall back to resolving the redirect URL.
			if ( empty( $new_source ) ) {
				$resolved = Peptide_News_Google_URL_Resolver::resolve( $article->source_url );
				if ( ! $resolved || $resolved === $article->source_url ) {
					$resolved = self::resolve_redirect_url( $article->source_url );
				}
				if ( $resolved && $resolved !== $article->source_url ) {
					$domain = self::extract_domain( (string) $resolved );
					if ( 'unknown' !== $domain && ! in_array( $domain, $aggregators, true ) ) {
						$new_source = $domain;
					}
				}
				usleep( 300000 ); // 0.3s throttle for HTTP requests.
			}

			if ( ! empty( $new_source ) && $new_source !== $article->source ) {
				$wpdb->update( $table, array( 'source' => $new_source ), array( 'id' => $article->id ), array( '%s' ), array( '%d' ) );
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * AJAX handler for the backfill action.
	 *
	 * Side effects: nonce + capability check, delegates to backfill(),
	 * clears article cache on success, sends JSON response.
	 *
	 * @return void
	 */
	public static function ajax_backfill(): void {
		check_ajax_referer( 'peptide_news_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'peptide-news' ) ), 403 );
		}

		$updated = self::backfill();

		if ( $updated > 0 ) {
			wp_cache_delete( 'peptide_news_recent_articles' );
		}

		wp_send_json_success( array(
			'updated' => $updated,
			/* translators: %d: number of articles updated */
			'message' => sprintf( _n( 'Updated %d article source.', 'Updated %d article sources.', $updated, 'peptide-news' ), $updated ),
		) );
	}

	/**
	 * Parse a source name from the end of a title ("Headline - Source Name").
	 *
	 * @param string $title
	 * @return string|null Source name or null if not matched.
	 */
	public static function parse_source_from_title( string $title ): ?string {
		if ( preg_match( '/\s+-\s+([^-]+)$/', $title, $matches ) ) {
			$candidate = trim( $matches[1] );
			if ( strlen( $candidate ) >= 2 && strlen( $candidate ) <= 60 ) {
				return sanitize_text_field( $candidate );
			}
		}
		return null;
	}

	/**
	 * Resolve a redirect URL to its final destination via HEAD request.
	 *
	 * Side effects: one outbound HTTP HEAD (8 s timeout, up to 5 redirects).
	 *
	 * @param string $url The URL to resolve.
	 * @return string|false The final URL or false on failure.
	 */
	public static function resolve_redirect_url( string $url ) {
		if ( empty( $url ) ) {
			return false;
		}

		$response = wp_remote_head( $url, array(
			'timeout'     => 8,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; PeptideNewsBot/1.0)',
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$final_url = wp_remote_retrieve_header( $response, 'location' );

		if ( empty( $final_url ) && isset( $response['http_response'] ) ) {
			$http_response = $response['http_response'];
			if ( method_exists( $http_response, 'get_response_object' ) ) {
				$raw = $http_response->get_response_object();
				if ( isset( $raw->url ) ) {
					$final_url = $raw->url;
				}
			}
		}

		if ( ! empty( $final_url ) && is_string( $final_url ) ) {
			$original_host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$final_host    = (string) wp_parse_url( $final_url, PHP_URL_HOST );
			$clean_original = preg_replace( '/^www\./', '', $original_host );
			// Allow cross-domain redirects if original host is an aggregator.
			if ( $original_host !== $final_host && ! in_array( $clean_original, self::AGGREGATOR_DOMAINS, true ) ) {
				return false;
			}
			return $final_url;
		}

		return false;
	}

	/**
	 * Extract domain from a URL, stripping "www." prefix.
	 *
	 * @param string $url
	 * @return string Domain or 'unknown'.
	 */
	public static function extract_domain( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', $host ) : 'unknown';
	}
}
