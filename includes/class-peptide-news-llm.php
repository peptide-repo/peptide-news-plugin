<?php
declare( strict_types=1 );
/**
 * LLM integration orchestrator — 3-Step AI Research & Article Generation Pipeline.
 *
 * Implements:
 * Step 1: Scientific Rigor & Study Classification Gatekeeper (rejects < 3 or promotional spam).
 * Step 2: Structured Scientific Data Extraction & Keyword Tagging (with stateful checkpointing).
 * Step 3: Journalistic News Brief Generation in Markdown (with link/image sanitization).
 *
 * Delegates prompts to LLM_Prompt_Builder and HTTP to LLM_Client.
 * Triggered by cron (via Fetcher), WP-CLI, and admin bulk-generate AJAX.
 *
 * @since 1.1.0
 * @see   class-peptide-news-llm-client.php
 * @see   class-peptide-news-llm-prompt-builder.php
 * @see   class-peptide-news-llm-ajax.php
 */
class Peptide_News_LLM {

	/** @var int Maximum elapsed seconds before stopping a batch on shared hosting (safe under 60s CGI limit). */
	const BATCH_TIMEOUT = 50;

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
	 * Process a single article through the 3-Step AI Pipeline.
	 *
	 * Step 1: Scientific Rigor Gatekeeper (rejects low-signal/promotional spam).
	 * Step 2: Structured Data Extraction (checkpoints progress to DB).
	 * Step 3: Journalistic Markdown News Brief Generation.
	 *
	 * Skips articles that already have both tags and ai_summary unless $force is true.
	 * Checks budget before each API call and logs costs via Cost_Tracker.
	 *
	 * @param object $article  Database row with id, title, excerpt, content, categories.
	 * @param bool   $force    Re-process even if already analyzed.
	 * @return array            Results with 'keywords', 'summary', 'rigor_score', 'study_type', 'success' keys.
	 */
	public static function process_article( object $article, bool $force = false ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'keywords'    => '',
				'summary'     => '',
				'rigor_score' => null,
				'study_type'  => null,
				'ai_metadata' => array(),
				'success'     => false,
				'errors'      => array(),
			);
		}

		$results = array(
			'keywords'    => '',
			'summary'     => '',
			'rigor_score' => null,
			'study_type'  => null,
			'ai_metadata' => array(),
			'success'     => false,
			'errors'      => array(),
		);

		$api_key    = Peptide_News_LLM_Client::get_api_key();
		$article_id = (int) $article->id;

		$title   = trim( $article->title ?? '' );
		$content = trim( $article->content ?? '' );
		if ( empty( $content ) && ! empty( $article->excerpt ) ) {
			$content = trim( $article->excerpt );
		}

		$metadata = isset( $article->ai_metadata ) && is_string( $article->ai_metadata ) ? json_decode( $article->ai_metadata, true ) : array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$kw_model = get_option( 'peptide_news_llm_keywords_model', 'google/gemini-2.0-flash-001' );
		$sm_model = get_option( 'peptide_news_llm_summary_model', 'google/gemini-2.0-flash-001' );

		// --- Step 1: Scientific Rigor & Study Classification Gatekeeper ---
		if ( $force || empty( $metadata['classification'] ) ) {
			$messages = Peptide_News_LLM_Prompt_Builder::study_classification( $title, $content );
			$schema   = Peptide_News_LLM_Prompt_Builder::get_classification_schema();
			$step1    = self::run_structured_llm_task( $api_key, $kw_model, 'classification', $article_id, $messages, $schema );

			if ( ! empty( $step1['data'] ) ) {
				$study_type  = sanitize_text_field( $step1['data']['study_type'] ?? 'Unknown' );
				$rigor_score = absint( $step1['data']['rigor_score'] ?? 0 );

				$results['study_type']  = $study_type;
				$results['rigor_score'] = $rigor_score;
				$metadata['classification'] = $step1['data'];

				// Gatekeeper check: reject promotional spam or low-rigor studies (< 3)
				if ( 'Low-Quality/Promotional/Spam' === $study_type || $rigor_score < 3 ) {
					$metadata['status']     = 'rejected';
					$results['ai_metadata'] = $metadata;
					$results['errors'][]    = 'Article rejected by scientific rigor gatekeeper (Score: ' . $rigor_score . ', Type: ' . $study_type . ').';
					self::save_rejection( $article_id, $rigor_score, $study_type, $metadata );
					return $results;
				}
			} else {
				$results['errors'] = array_merge( $results['errors'], $step1['errors'] );
			}
		} else {
			$results['study_type']  = isset( $metadata['classification']['study_type'] ) ? sanitize_text_field( $metadata['classification']['study_type'] ) : null;
			$results['rigor_score'] = isset( $metadata['classification']['rigor_score'] ) ? absint( $metadata['classification']['rigor_score'] ) : null;

			// Stateful Checkpointing after classification so Step 1 is never re-run or re-billed on timeout
			$metadata['status']     = 'classified';
			$results['ai_metadata'] = $metadata;
			self::save_checkpoint( $article_id, null, $results['rigor_score'], $results['study_type'], $metadata );
		}

		// --- Step 2: Structured Scientific Data Extraction & Keyword Tagging ---
		$extracted_data = array();
		if ( $force || empty( $metadata['extraction'] ) ) {
			$messages = Peptide_News_LLM_Prompt_Builder::data_extraction( $title, $content );
			$schema   = Peptide_News_LLM_Prompt_Builder::get_extraction_schema();
			$step2    = self::run_structured_llm_task( $api_key, $kw_model, 'extraction', $article_id, $messages, $schema );

			if ( ! empty( $step2['data'] ) ) {
				$extracted_data         = $step2['data'];
				$metadata['extraction'] = $extracted_data;

				$kw_array = $extracted_data['keywords'] ?? array();
				if ( is_array( $kw_array ) ) {
					$results['keywords'] = self::sanitize_keywords( implode( ', ', $kw_array ) );
				} else {
					$results['keywords'] = self::sanitize_keywords( (string) $kw_array );
				}

				// Stateful Checkpointing after extraction
				$metadata['status']     = 'extracted';
				$results['ai_metadata'] = $metadata;
				self::save_checkpoint( $article_id, $results['keywords'], $results['rigor_score'], $results['study_type'], $metadata );
			} else {
				$results['errors'] = array_merge( $results['errors'], $step2['errors'] );
				// Fallback: Legacy keyword extraction if Step 2 failed
				if ( empty( $results['keywords'] ) && empty( $article->tags ) ) {
					$kw = self::run_llm_task( $api_key, $kw_model, 'keywords', $article_id, Peptide_News_LLM_Prompt_Builder::keywords( $article ) );
					if ( null !== $kw['content'] ) {
						$results['keywords'] = self::sanitize_keywords( (string) $kw['content'] );
					}
					$results['errors'] = array_merge( $results['errors'], $kw['errors'] );
				}
			}
		} else {
			$extracted_data      = $metadata['extraction'];
			$results['keywords'] = ! empty( $article->tags ) ? $article->tags : '';
		}

		// --- Step 3: Journalistic News Brief Generation ---
		if ( $force || empty( $article->ai_summary ) ) {
			$messages = Peptide_News_LLM_Prompt_Builder::article_generation( $title, $content, $extracted_data );
			$sm       = self::run_llm_task( $api_key, $sm_model, 'summary', $article_id, $messages );

			if ( null !== $sm['content'] ) {
				$summary = $sm['content'];
				// Sanitize Markdown syntax: remove images and dangerous link schemes
				$summary = preg_replace( '/!\[([^\]]*)\]\([^\)]*\)/', '', $summary );
				$summary = preg_replace( '/\[([^\]]+)\]\((?:javascript:|data:|vbscript:)[^\)]*\)/i', '$1', (string) $summary );
				$results['summary'] = trim( (string) $summary );

				$metadata['status']     = 'completed';
				$results['ai_metadata'] = $metadata;
			} else {
				$results['errors'] = array_merge( $results['errors'], $sm['errors'] );
			}
		} else {
			$results['summary'] = $article->ai_summary;
		}

		$results['success'] = ! empty( $results['keywords'] ) || ! empty( $results['summary'] );

		if ( $results['success'] ) {
			self::save_results( $article_id, $results, $metadata, $force );
		}

		return $results;
	}

	/**
	 * Run a single LLM task (keywords or summary) with budget gating and cost logging.
	 *
	 * @param string       $api_key    Decrypted OpenRouter key.
	 * @param string       $model      Model ID from settings.
	 * @param string       $task_type  'keywords' or 'summary' — used for cost logging and log messages.
	 * @param int          $article_id Article DB row ID.
	 * @param string|array $prompt     Ready-to-send prompt text or messages array.
	 * @return array{content: string|null, errors: string[]}
	 */
	private static function run_llm_task( string $api_key, string $model, string $task_type, int $article_id, $prompt ): array {
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

		if ( is_array( $prompt ) ) {
			$response = Peptide_News_LLM_Client::call_messages( $api_key, $model, $prompt, 800, 0.3 );
		} else {
			$response = Peptide_News_LLM_Client::call_with_usage( $api_key, $model, $prompt );
		}

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
	 * Run a structured JSON schema LLM task with budget gating and cost logging.
	 *
	 * @param string  $api_key         Decrypted OpenRouter key.
	 * @param string  $model           Model ID from settings.
	 * @param string  $task_type       'classification' or 'extraction'.
	 * @param int     $article_id      Article DB row ID.
	 * @param array   $messages        Messages array.
	 * @param array   $response_format JSON schema format array.
	 * @return array{content: string|null, data: array|null, errors: string[]}
	 */
	private static function run_structured_llm_task(
		string $api_key,
		string $model,
		string $task_type,
		int $article_id,
		array $messages,
		array $response_format
	): array {
		$out = array( 'content' => null, 'data' => null, 'errors' => array() );

		if ( ! Peptide_News_LLM_Client::is_valid_model( $model ) ) {
			$out['errors'][] = 'Invalid ' . $task_type . ' model ID: ' . $model;
			Peptide_News_Logger::error( 'Invalid ' . $task_type . ' model ID: ' . $model, 'llm' );
			return $out;
		}

		if ( class_exists( 'Peptide_News_Cost_Tracker' ) && Peptide_News_Cost_Tracker::is_budget_exceeded() ) {
			$out['errors'][] = 'Monthly LLM budget exceeded — ' . $task_type . ' skipped.';
			Peptide_News_Logger::warning( 'Budget exceeded, skipping ' . $task_type . ' for article #' . $article_id, 'cost' );
			return $out;
		}

		$response = Peptide_News_LLM_Client::call_messages( $api_key, $model, $messages, 800, 0.3, $response_format );

		if ( ! is_wp_error( $response['content'] ) ) {
			$out['content'] = $response['content'];
			$decoded        = json_decode( (string) $response['content'], true );
			if ( is_array( $decoded ) ) {
				$out['data'] = $decoded;
			} else {
				$out['errors'][] = ucfirst( $task_type ) . ' returned invalid JSON format.';
			}
			Peptide_News_Logger::debug( ucfirst( $task_type ) . ' completed for article #' . $article_id, 'llm' );
		} else {
			$out['errors'][] = ucfirst( $task_type ) . ' (' . $model . '): ' . $response['content']->get_error_message();
			Peptide_News_Logger::error( ucfirst( $task_type ) . ' failed for article #' . $article_id . ': ' . $response['content']->get_error_message(), 'llm' );
		}

		if ( class_exists( 'Peptide_News_Cost_Tracker' ) && ! empty( $response['usage'] ) ) {
			Peptide_News_Cost_Tracker::log_api_call( $model, $task_type, $response['usage'], $article_id, $response['request_id'] ?? '', $response['cost'] ?? 0.0 );
		}

		return $out;
	}

	/**
	 * Process all unanalyzed articles (called after a fetch cycle).
	 *
	 * Time-guarded; respects admin "max per cycle" cap unless overridden.
	 * Adjusts timeout and default batch size when running under WP-CLI.
	 *
	 * @param int  $batch_size     Max articles to process per run.
	 * @param bool $override_limit Ignore the admin cap (used by bulk-generate).
	 * @return int                  Articles successfully processed.
	 */
	public static function process_unanalyzed( int $batch_size = 10, bool $override_limit = false ): int {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		$is_cli  = defined( 'WP_CLI' ) && WP_CLI;
		$timeout = $is_cli ? 600 : 35; // 35s max for shared hosting CGI 60s safety margin

		if ( ! $override_limit ) {
			$max_per_cycle = absint( get_option( 'peptide_news_llm_max_articles', $is_cli ? 15 : 10 ) );
			if ( $max_per_cycle < 1 ) {
				$max_per_cycle = $is_cli ? 15 : 10;
			}
			$batch_size = min( $batch_size, $max_per_cycle );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$articles = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, excerpt, content, categories, tags, ai_summary, rigor_score, study_type, ai_metadata
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
			if ( ( time() - $start_time ) >= $timeout ) {
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
	 * @param string $raw Raw comma-separated keywords.
	 * @return string      Cleaned, deduplicated, comma-separated list.
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
	 * Save rejected article state to the database when scientific rigor is too low.
	 *
	 * @param int    $article_id
	 * @param int    $rigor_score
	 * @param string $study_type
	 * @param array  $metadata
	 */
	private static function save_rejection( int $article_id, int $rigor_score, string $study_type, array $metadata ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$wpdb->update(
			$table,
			array(
				'is_active'   => 0,
				'rigor_score' => $rigor_score,
				'study_type'  => $study_type,
				'ai_metadata' => wp_json_encode( $metadata ),
			),
			array( 'id' => $article_id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
		Peptide_News_Logger::info( sprintf( 'Article #%d rejected by gatekeeper (Score: %d, Type: %s)', $article_id, $rigor_score, $study_type ), 'llm' );
	}

	/**
	 * Save intermediate extraction state to DB to prevent re-running earlier steps if later steps timeout.
	 *
	 * @param int      $article_id
	 * @param string   $keywords
	 * @param int|null $rigor_score
	 * @param string|null $study_type
	 * @param array    $metadata
	 */
	private static function save_checkpoint( int $article_id, string $keywords, ?int $rigor_score, ?string $study_type, array $metadata ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$update = array(
			'ai_metadata' => wp_json_encode( $metadata ),
		);
		$format = array( '%s' );

		if ( ! empty( $keywords ) ) {
			$update['tags'] = $keywords;
			$format[]       = '%s';
		}
		if ( null !== $rigor_score ) {
			$update['rigor_score'] = $rigor_score;
			$format[]              = '%d';
		}
		if ( null !== $study_type ) {
			$update['study_type'] = $study_type;
			$format[]             = '%s';
		}

		$wpdb->update( $table, $update, array( 'id' => $article_id ), $format, array( '%d' ) );
	}

	/**
	 * Save LLM results to the database (cache cleared by caller per-batch).
	 *
	 * @param int   $article_id
	 * @param array $results  Array with 'keywords', 'summary', 'rigor_score', 'study_type'.
	 * @param array $metadata Structured AI metadata.
	 * @param bool  $force    Overwrite existing values.
	 */
	private static function save_results( int $article_id, array $results, array $metadata = array(), bool $force = false ): void {
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
		if ( null !== ( $results['rigor_score'] ?? null ) ) {
			$update['rigor_score'] = (int) $results['rigor_score'];
			$format[]              = '%d';
		}
		if ( null !== ( $results['study_type'] ?? null ) ) {
			$update['study_type'] = $results['study_type'];
			$format[]             = '%s';
		}
		if ( ! empty( $metadata ) ) {
			$update['ai_metadata'] = wp_json_encode( $metadata );
			$format[]              = '%s';
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
