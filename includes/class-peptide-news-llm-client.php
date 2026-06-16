<?php
declare( strict_types=1 );
/**
 * HTTP transport for the OpenRouter chat-completions API.
 *
 * Encapsulates authentication, request construction, retry logic
 * (429 back-off), and response parsing. Returns structured arrays
 * with content, token usage, request ID, and reported cost.
 *
 * Called by Peptide_News_LLM::process_article() for each LLM task.
 * Depends on WordPress HTTP API (wp_remote_post) and the Encryption helper.
 *
 * @since      2.5.0
 * @see        class-peptide-news-llm.php                Orchestrator that drives the calls.
 * @see        class-peptide-news-llm-prompt-builder.php Builds the prompts sent here.
 * @see        class-peptide-news-encryption.php         Decrypts the stored API key.
 */
class Peptide_News_LLM_Client {

	/** @var int Maximum retries for rate-limited (429) requests. */
	const MAX_RETRIES = 1;

	/**
	 * Get the OpenRouter API URL from options with a hardcoded default.
	 *
	 * @return string
	 */
	public static function get_api_url(): string {
		return get_option( 'peptide_news_llm_api_url', 'https://openrouter.ai/api/v1/chat/completions' );
	}

	/**
	 * Retrieve and decrypt the OpenRouter API key.
	 *
	 * Handles both encrypted (AES-256-CBC) and legacy plaintext values
	 * transparently via Peptide_News_Encryption::decrypt().
	 *
	 * @return string Decrypted API key or empty string.
	 */
	public static function get_api_key(): string {
		$raw = get_option( 'peptide_news_openrouter_api_key', '' );
		if ( empty( $raw ) ) {
			return '';
		}
		if ( class_exists( 'Peptide_News_Encryption' ) ) {
			return Peptide_News_Encryption::decrypt( $raw );
		}
		return $raw;
	}

	/**
	 * Validate an OpenRouter model ID format.
	 *
	 * Accepts patterns like: provider/model-name, provider/model-name:version
	 *
	 * @param string $model
	 * @return bool
	 */
	public static function is_valid_model( string $model ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9._-]+(:[a-zA-Z0-9._-]+)?$/', $model );
	}

	/**
	 * Call the OpenRouter API with retry logic for 429 rate limits.
	 *
	 * Backward-compatible wrapper that returns only the content string.
	 * Internally delegates to call_with_usage().
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param string $prompt
	 * @return string|\WP_Error The response text or an error.
	 */
	public static function call( string $api_key, string $model, string $prompt ) {
		$result = self::call_with_usage( $api_key, $model, $prompt );
		return $result['content'];
	}

	/**
	 * Call the OpenRouter API and return content + token usage data.
	 *
	 * Returns an array with 'content' (string|WP_Error), 'usage' (token counts),
	 * 'request_id' (for debugging), and 'cost' (if API reports it).
	 *
	 * Side effects: one outbound HTTP POST (15 s timeout), up to MAX_RETRIES
	 * additional retries on 429. Sleeps during back-off.
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param string $prompt
	 * @return array{content: string|\WP_Error, usage: array, request_id: string, cost: float}
	 */
	public static function call_with_usage( string $api_key, string $model, string $prompt ): array {
		$result = array(
			'content'    => '',
			'usage'      => array(),
			'request_id' => '',
			'cost'       => 0.0,
		);

		$body = array(
			'model'    => $model,
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'max_tokens'  => 300,
			'temperature' => 0.3,
		);

		$retries = 0;
		$backoff = 2; // Initial backoff in seconds.

		while ( $retries <= self::MAX_RETRIES ) {
			$response = wp_remote_post( self::get_api_url(), array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url(),
					'X-Title'       => 'Peptide News Aggregator',
				),
				'body' => wp_json_encode( $body ),
			) );

			if ( is_wp_error( $response ) ) {
				$result['content'] = $response;
				return $result;
			}

			$status = wp_remote_retrieve_response_code( $response );

			// Handle rate limiting with exponential backoff.
			if ( 429 === $status && $retries < self::MAX_RETRIES ) {
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$wait = $retry_after ? min( (int) $retry_after, 30 ) : $backoff;
				sleep( $wait );
				++$retries;
				$backoff *= 2;
				continue;
			}

			break;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Extract usage data from response regardless of success/failure.
		if ( is_array( $data ) && isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$result['usage'] = array(
				'prompt_tokens'     => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $data['usage']['total_tokens'] ?? 0 ),
			);
		}

		// Extract the request ID for debugging/reconciliation.
		if ( is_array( $data ) && isset( $data['id'] ) ) {
			$result['request_id'] = (string) $data['id'];
		}

		// OpenRouter may include generation cost in data.usage.total_cost.
		if ( is_array( $data ) && isset( $data['usage']['total_cost'] ) ) {
			$result['cost'] = (float) $data['usage']['total_cost'];
		}

		if ( $status < 200 || $status >= 300 ) {
			$error_msg = 'HTTP ' . $status;
			if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['message'] ) ) {
				$error_msg = $data['error']['message'];
			}
			$result['content'] = new \WP_Error( 'openrouter_error', $error_msg );
			return $result;
		}

		// Validate the response structure thoroughly.
		if ( ! is_array( $data ) ) {
			$result['content'] = new \WP_Error( 'openrouter_invalid', 'Invalid JSON response from OpenRouter' );
			return $result;
		}
		if ( ! isset( $data['choices'] ) || ! is_array( $data['choices'] ) || empty( $data['choices'] ) ) {
			$result['content'] = new \WP_Error( 'openrouter_empty', 'No choices in OpenRouter response' );
			return $result;
		}
		if ( ! isset( $data['choices'][0]['message'] ) || ! is_array( $data['choices'][0]['message'] ) ) {
			$result['content'] = new \WP_Error( 'openrouter_empty', 'Malformed choice in OpenRouter response' );
			return $result;
		}
		if ( ! isset( $data['choices'][0]['message']['content'] ) || '' === trim( $data['choices'][0]['message']['content'] ) ) {
			$result['content'] = new \WP_Error( 'openrouter_empty', 'Empty content in OpenRouter response' );
			return $result;
		}

		$result['content'] = trim( $data['choices'][0]['message']['content'] );
		return $result;
	}
}
