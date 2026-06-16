<?php
declare( strict_types=1 );
/**
 * LLM integration orchestrator — keyword extraction and summarization.
 *
 * Delegates prompts to LLM_Prompt_Builder and HTTP to LLM_Client.
 * Triggered by cron (via Fetcher) and admin bulk-generate AJAX.
 *
 * @since 1.1.0
 * @see   class-peptide-news-llm-client.php
 * @see   class-peptide-news-llm-prompt-builder.php
 * @see   class-peptide-news-llm-ajax.php
 */
class Peptide_News_LLM {

	/** @var int Maximum elapsed seconds before stopping a batch. */
	const BATCH_TIMEOUT = 120;

	/**
	 * Check whether LLM processing is enabled and configured.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$api_key = Peptide_News_LLM_Client::get_api_key();
		return ! empty( $api_key ) && (bool) get_option( 'peptide_news_llm_enabled', 0 );
	}

	/** Proxy — @see Peptide_News_LLM_Client::is_valid_model() */
	public static function is_valid_model( string $model ): bool {
		return Peptide_News_LLM_Client::is_valid_model( $model );
	}

	/** Proxy — @see Peptide_News_LLM_Client::call() */
	public static function call_openrouter( string $api_key, string $model, string $prompt ) {
		return Peptide_News_LLM_Client::call( $api_key, $model, $prompt );
	}

	/** Proxy — @see Peptide_News_LLM_Client::call_with_usage() */
	public static function call_openrouter_with_usage( string $api_key, string $model, string $prompt ): array {
		return Peptide_News_LLM_Client::call_with_usage( $api_key, $model, $prompt );
	}

	/**
	 * Process a single article: extract keywords and generate a summary.
	 *
	 * Skips articles that already have both tags and ai_summary unless $force is true.
	 * Checks budget before each API call and logs costs via Cost_Tracker.
	 *
	 * Side effects: up to 2 OpenRouter API calls, 1 DB update, logger writes.
	 *
	 * @param object $article  Database row with id, title, excerpt, content, categories.
	 * @param bool   $force    Re-process even if already analyzed.
	 * @return array            Results with 'keywords', 'summary', and 'success' keys.
	 */
	public static function process_article( object $article, bool $force = false ): array {
		if ( ! self::is_enabled() ) {
			return array( 'keywords' => '', 'summary' => '', 'success' => false );
		}

		$results     = array( 'keywords' => '', 'summary' => '', 'success' => false, 'errors' => array() );
		$api_key     = Peptide_News_LLM_Client::get_api_key();
		$article_id  = (int) $article->id;

		// --- Keyword extraction ---
		$kw_model = get_option( 'peptide_news_llm_keywords_model', 'google/gemini-2.0-flash-001' );
		if ( $force || empty( $article->tags ) ) {
			$kw = self::run_llm_task( $api_key, $kw_model, 'keywords', $article_id, Peptide_News_LLM_Prompt_Builder::keywords( $article ) );
			if ( null !== $kw['content'] ) {
				$results['keywords'] = self::sanitize_keywords( $kw['content'] );
			}
			$results['errors'] = array_merge( $results['errors'], $kw['errors'] );
		}

		// --- Summarization ---
		$sm_model = get_option( 'peptide_news_llm_summary_model', 'google/gemini-2.0-flash-001' );
		if ( $force || empty( $article->ai_summary ) ) {
			$sm = self::run_llm_task( $api_key, $sm_model, 'summary', $article_id, Peptide_News_LLM_Prompt_Builder::summary( $article ) );
			if ( null !== $sm['content'] ) {
				$results['summary'] = sanitize_textarea_field( wp_strip_all_tags( $sm['content'] ) );
			}
			$results['errors'] = array_merge( $results['errors'], $sm['errors'] );
		}

		$results['success'] = ! empty( $results['keywords'] ) || ! empty( $results['summary'] );

		if ( $results['success'] ) {
			self::save_results( $article_id, $results, $force );
		}

		return $results;
	}

	/**
	 * Run a single LLM task (keywords or summary) with budget gating and cost logging.
	 *
	 * @param string $api_key    Decrypted OpenRouter key.
	 * @param string $model      Model ID from settings.
	 * @param string $task_type  'keywords' or 'summary' — used for cost logging and log messages.
	 * @param int    $article_id Article DB row ID.
	 * @param string $prompt     Ready-to-send prompt text.
	 * @return array{content: string|null, errors: string[]}
	 */
	private static function run_llm_task( string $api_key, string $model, string $task_type, int $article_id, string $prompt ): array {
		$out = array( 'content' => null, 'errors' => array() );

		if ( ! Peptide_News_LLM_Client::is_valid_model( $model ) ) {
			$out['errors'][] = 'Invalid ' . $task_type . ' model ID: ' . $model;
			Peptide_News_Logger::error( 'Invalid ' . $task_type . ' model ID: ' . $model, 'llm' );
			return $out;
		}

		// Budget gate.
		if ( class_exists( 'Peptide_News_Cost_Tracker' ) && Peptide_News_Cost_Tracker::is_budget_exceeded() ) {
			$out['errors'][] = 'Monthly LLM budget exceeded — ' . $task_type . ' skipped.';
			Peptide_News_Logger::warning( 'Budget exceeded, skipping ' . $task_type . ' for article #' . $article_id, 'cost' );
			return $out;
		}

		$response = Peptide_News_LLM_Client::call_with_usage( $api_key, $model, $prompt );

		if ( ! is_wp_error( $response['content'] ) ) {
			$out['content'] = $response['content'];
			Peptide_News_Logger::debug( ucfirst( $task_type ) . ' completed for article #' . $article_id, 'llm' );
		} else {
			$out['errors'][] = ucfirst( $task_type ) . ' (' . $model . '): ' . $response['content']->get_error_message();
			Peptide_News_Logger::error( ucfirst( $task_type ) . ' failed for article #' . $article_id . ': ' . $response['content']->get_error_message(), 'llm' );
		}

		// Log cost regardless of success — failed calls still consume tokens.
		if ( class_exists( 'Peptide_News_Cost_Tracker' ) && ! empty( $response['usage'] ) ) {
			Peptide_News_Cost_Tracker::log_api_call( $model, $task_type, $response['usage'], $article_id, $response['request_id'] ?? '', $response['cost'] ?? 0.0 );
		}

		return $out;
	}

	/**
	 * Process all unanalyzed articles (called after a fetch cycle).
	 *
	 * Time-guarded; respects admin "max per cycle" cap unless overridden.
	 *
	 * @param int  $batch_size      Max articles to process per run.
	 * @param bool $override_limit  Ignore the admin cap (used by bulk-generate).
	 * @return int                   Articles successfully processed.
	 */
	public static function process_unanalyzed( int $batch_size = 10, bool $override_limit = false ): int {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		if ( ! $override_limit ) {
			$max_per_cycle = absint( get_option( 'peptide_news_llm_max_articles', 10 ) );
			if ( $max_per_cycle < 1 ) {
				$max_per_cycle = 10;
			}
			$batch_size = min( $batch_size, $max_per_cycle );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$articles = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, excerpt, content, categories, tags, ai_summary
			 FROM {$table}
			 WHERE is_active = 1
			   AND ( tags = '' OR ai_summary = '' OR ai_summary IS NULL )
			 ORDER BY fetched_at DESC
			 LIMIT %d",
			$batch_size
		) );

		$processed   = 0;
		$last_errors = array();
		$start_time  = time();

		foreach ( $articles as $article ) {
			if ( ( time() - $start_time ) >= self::BATCH_TIMEOUT ) {
				self::log_error( 'Batch timeout reached after ' . $processed . ' articles. Remaining will process next cycle.' );
				break;
			}

			$result = self::process_article( $article );

			if ( ! empty( $result['success'] ) ) {
				++$processed;
			}
			if ( ! empty( $result['errors'] ) ) {
				$last_errors = $result['errors'];
			}

			if ( next( $articles ) !== false ) {
				usleep( 500000 ); // 0.5s between articles.
			}
		}

		if ( $processed > 0 ) {
			self::clear_article_cache();
		}

		update_option( 'peptide_news_last_llm_process', array(
			'time'      => current_time( 'mysql' ),
			'processed' => $processed,
			'attempted' => count( $articles ),
			'errors'    => $last_errors,
		) );

		if ( $processed > 0 ) {
			Peptide_News_Logger::info( sprintf( 'AI batch complete: %d/%d articles processed.', $processed, count( $articles ) ), 'llm' );
		} elseif ( count( $articles ) > 0 ) {
			Peptide_News_Logger::warning( sprintf( 'AI batch: 0/%d articles succeeded.', count( $articles ) ), 'llm' );
		}

		return $processed;
	}

	/**
	 * Backward-compatible proxy — delegates to Peptide_News_LLM_Ajax.
	 *
	 * @see class-peptide-news-llm-ajax.php
	 */
	public static function ajax_generate_summaries(): void {
		Peptide_News_LLM_Ajax::generate_summaries();
	}

	/**
	 * Sanitize and normalize keyword output from the LLM.
	 *
	 * @param string $raw  Raw comma-separated keywords.
	 * @return string       Cleaned, deduplicated, comma-separated list.
	 */
	private static function sanitize_keywords( string $raw ): string {
		$raw = wp_strip_all_tags( $raw );
		$raw = preg_replace( '/^\d+[\.\)]\s*/m', '', $raw );
		$raw = preg_replace( '/^[-*\x{2022}]\s*/mu', '', $raw );

		$keywords = array_map( 'trim', explode( ',', $raw ) );
		$keywords = array_map( 'sanitize_text_field', $keywords );
		$keywords = array_filter( $keywords );
		$keywords = array_unique( $keywords );
		$keywords = array_slice( $keywords, 0, 10 );

		return implode( ', ', $keywords );
	}

	/**
	 * Save LLM results to the database (cache cleared by caller per-batch).
	 *
	 * @param int   $article_id
	 * @param array $results  Array with 'keywords' and 'summary'.
	 * @param bool  $force    Overwrite existing values.
	 */
	private static function save_results( int $article_id, array $results, bool $force = false ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$update = array();
		$format = array();

		if ( ! empty( $results['keywords'] ) ) {
			$update['tags'] = $results['keywords'];
			$format[]       = '%s';
		}
		if ( ! empty( $results['summary'] ) ) {
			$update['ai_summary'] = $results['summary'];
			$format[]             = '%s';
		}

		if ( ! empty( $update ) ) {
			$wpdb->update( $table, $update, array( 'id' => $article_id ), $format, array( '%d' ) );
		}
	}

	/**
	 * Clear all article transient caches.
	 */
	private static function clear_article_cache(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
					OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_peptide_news_articles_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_peptide_news_articles_' ) . '%'
			)
		);
	}

	/**
	 * Log an error to the WordPress debug log.
	 *
	 * @param string $message
	 */
	private static function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Peptide News LLM] ' . $message );
		}
	}
}
