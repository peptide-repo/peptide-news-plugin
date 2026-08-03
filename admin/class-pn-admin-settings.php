<?php
declare( strict_types=1 );
/**
 * Settings registration and sanitization.
 *
 * Registers all WordPress settings (7 sections, 25+ fields) and provides
 * sanitization callbacks. Field rendering is delegated to
 * Peptide_News_Admin_Field_Renderers for modularity.
 *
 * @since 2.5.0
 * @see Peptide_News_Admin_Field_Renderers — For field rendering
 * @see Peptide_News_Encryption — For API key encryption
 * @see Peptide_News_Cost_Tracker — For budget status in section header
 */
class Peptide_News_Admin_Settings {

	/**
	 * Register all WordPress settings and sections.
	 *
	 * @return void
	 */
	public function register_settings(): void {

		// --- General section ---
		add_settings_section(
			'peptide_news_general',
			__( 'General Settings', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how and when peptide news articles are fetched.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'fetch_interval', __( 'Fetch Interval', 'peptide-news' ), 'render_fetch_interval_field', 'peptide_news_general' );
		$this->add_setting( 'articles_count', __( 'Articles to Display', 'peptide-news' ), 'render_articles_count_field', 'peptide_news_general' );
		$this->add_setting( 'search_keywords', __( 'Search Keywords', 'peptide-news' ), 'render_search_keywords_field', 'peptide_news_general' );

		// --- RSS section ---
		add_settings_section(
			'peptide_news_rss',
			__( 'RSS Feed Sources', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Add RSS feed URLs, one per line.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'rss_enabled', __( 'Enable RSS Feeds', 'peptide-news' ), 'render_rss_enabled_field', 'peptide_news_rss' );
		$this->add_setting( 'rss_feeds', __( 'RSS Feed URLs', 'peptide-news' ), 'render_rss_feeds_field', 'peptide_news_rss' );

		// --- NewsAPI section ---
		add_settings_section(
			'peptide_news_newsapi',
			__( 'NewsAPI.org', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Optional. Get a free API key at newsapi.org.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'newsapi_enabled', __( 'Enable NewsAPI', 'peptide-news' ), 'render_newsapi_enabled_field', 'peptide_news_newsapi' );
		$this->add_setting( 'newsapi_key', __( 'API Key', 'peptide-news' ), 'render_newsapi_key_field', 'peptide_news_newsapi' );

		// --- NCBI E-utilities section ---
		add_settings_section(
			'peptide_news_ncbi_section',
			__( 'NCBI PubMed E-utilities', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Optional. An NCBI API key raises PubMed rate limits from 3 req/sec to 10 req/sec.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'ncbi_api_key', __( 'NCBI API Key', 'peptide-news' ), 'render_ncbi_key_field', 'peptide_news_ncbi_section' );

		// --- VPS Worker section ---
		add_settings_section(
			'peptide_news_vps_section',
			__( 'VPS Worker Integration', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Optional. Set a secret token to allow an external VPS worker to sync articles via REST API without admin login.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'vps_token', __( 'VPS Worker Token', 'peptide-news' ), 'render_vps_token_field', 'peptide_news_vps_section' );

		// --- Analytics section ---
		add_settings_section(
			'peptide_news_analytics_section',
			__( 'Analytics', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Configure click tracking and data retention.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'article_retention', __( 'Article Retention (days)', 'peptide-news' ), 'render_article_retention_field', 'peptide_news_analytics_section' );
		$this->add_setting( 'analytics_retention', __( 'Analytics Data Retention (days)', 'peptide-news' ), 'render_retention_field', 'peptide_news_analytics_section' );
		$this->add_setting( 'anonymize_ip', __( 'Anonymize IPs', 'peptide-news' ), 'render_anonymize_ip_field', 'peptide_news_analytics_section' );

		// --- Content Filter section ---
		add_settings_section(
			'peptide_news_filter_section',
			__( 'Content Filter', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Filter out ads, press releases, and promotional content during fetch. Articles that match the rules are discarded before being saved.', 'peptide-news' ) . '</p>';
				$last_run = get_option( 'peptide_news_filter_last_run' );
				if ( $last_run && is_array( $last_run ) ) {
					printf(
						'<p class="description"><strong>%s:</strong> %s | %s: %d | %s: %d | %s: %d | %s: %d</p>',
						esc_html__( 'Last filter run', 'peptide-news' ),
						esc_html( $last_run['time'] ),
						esc_html__( 'Evaluated', 'peptide-news' ),
						absint( $last_run['total'] ),
						esc_html__( 'Removed', 'peptide-news' ),
						absint( $last_run['removed'] ),
						esc_html__( 'LLM checked', 'peptide-news' ),
						absint( $last_run['llm_checked'] ),
						esc_html__( 'Passed', 'peptide-news' ),
						absint( $last_run['passed'] )
					);
				}
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'filter_enabled', __( 'Enable Content Filter', 'peptide-news' ), 'render_filter_enabled_field', 'peptide_news_filter_section' );
		$this->add_setting( 'filter_sensitivity', __( 'Filter Sensitivity', 'peptide-news' ), 'render_filter_sensitivity_field', 'peptide_news_filter_section' );
		$this->add_setting( 'filter_llm_enabled', __( 'LLM Classification', 'peptide-news' ), 'render_filter_llm_enabled_field', 'peptide_news_filter_section' );
		$this->add_setting( 'filter_llm_model', __( 'Filter LLM Model', 'peptide-news' ), 'render_filter_llm_model_field', 'peptide_news_filter_section' );
		$this->add_setting( 'filter_title_keywords', __( 'Title Keywords', 'peptide-news' ), 'render_filter_title_keywords_field', 'peptide_news_filter_section' );
		$this->add_setting( 'filter_body_keywords', __( 'Body Keywords', 'peptide-news' ), 'render_filter_body_keywords_field', 'peptide_news_filter_section' );
		$this->add_setting( 'filter_blocked_domains', __( 'Blocked Domains', 'peptide-news' ), 'render_filter_blocked_domains_field', 'peptide_news_filter_section' );

		// --- AI / LLM section ---
		add_settings_section(
			'peptide_news_llm_section',
			__( 'AI Analysis (OpenRouter)', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Configure LLM-powered keyword extraction and article summarization via OpenRouter.', 'peptide-news' ) . '</p>';
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'llm_enabled', __( 'Enable AI Analysis', 'peptide-news' ), 'render_llm_enabled_field', 'peptide_news_llm_section' );
		$this->add_setting( 'openrouter_api_key', __( 'OpenRouter API Key', 'peptide-news' ), 'render_openrouter_key_field', 'peptide_news_llm_section' );
		$this->add_setting( 'llm_keywords_model', __( 'Keywords Model', 'peptide-news' ), 'render_llm_keywords_model_field', 'peptide_news_llm_section' );
		$this->add_setting( 'llm_summary_model', __( 'Summary Model', 'peptide-news' ), 'render_llm_summary_model_field', 'peptide_news_llm_section' );
		$this->add_setting( 'llm_max_articles', __( 'Max Articles per Cycle', 'peptide-news' ), 'render_llm_max_articles_field', 'peptide_news_llm_section' );

		// --- Cost Tracking / Budget section ---
		add_settings_section(
			'peptide_news_cost_section',
			__( 'Cost Tracking & Budget', 'peptide-news' ),
			function () {
				echo '<p>' . esc_html__( 'Monitor and control LLM API spending. Set a monthly budget to prevent unexpected charges.', 'peptide-news' ) . '</p>';
				if ( class_exists( 'Peptide_News_Cost_Tracker' ) ) {
					$month_spend = Peptide_News_Cost_Tracker::get_current_month_spend();
					$budget      = (float) get_option( 'peptide_news_monthly_budget', 0.0 );
					printf(
						'<p class="description"><strong>%s:</strong> $%.4f',
						esc_html__( 'This month\'s spend', 'peptide-news' ),
						$month_spend
					);
					if ( $budget > 0 ) {
						printf(
							' / $%.2f (%.1f%%)',
							$budget,
							( $month_spend / $budget ) * 100
						);
					}
					echo '</p>';
				}
			},
			'peptide-news-settings'
		);

		$this->add_setting( 'monthly_budget', __( 'Monthly Budget (USD)', 'peptide-news' ), 'render_monthly_budget_field', 'peptide_news_cost_section' );
		$this->add_setting( 'budget_mode', __( 'Budget Enforcement', 'peptide-news' ), 'render_budget_mode_field', 'peptide_news_cost_section' );
		$this->add_setting( 'cost_retention', __( 'Cost Log Retention (days)', 'peptide-news' ), 'render_cost_retention_field', 'peptide_news_cost_section' );
	}

	/**
	 * Helper: register a single setting with a field callback.
	 *
	 * @param string $key Setting key (without prefix).
	 * @param string $label Field label.
	 * @param string $callback Render method name in Peptide_News_Admin_Field_Renderers.
	 * @param string $section Settings section ID.
	 * @return void
	 */
	private function add_setting( string $key, string $label, string $callback, string $section ): void {
		$option_name = "peptide_news_{$key}";

		$sanitize_cb = $this->get_sanitize_callback( $key );

		register_setting( 'peptide_news_settings_group', $option_name, array(
			'sanitize_callback' => $sanitize_cb,
		) );

		add_settings_field(
			$option_name,
			$label,
			array( 'Peptide_News_Admin_Field_Renderers', $callback ),
			'peptide-news-settings',
			$section
		);
	}

	/**
	 * Return the appropriate sanitize callback for a given setting key.
	 *
	 * @param string $key Setting key (without prefix).
	 * @return callable
	 */
	private function get_sanitize_callback( string $key ) {
		$callbacks = array(
			'fetch_interval'        => 'sanitize_text_field',
			'articles_count'        => 'absint',
			'search_keywords'       => 'sanitize_text_field',
			'rss_enabled'           => 'absint',
			'rss_feeds'             => 'sanitize_textarea_field',
			'newsapi_enabled'       => 'absint',
			'newsapi_key'           => function ( $value ) {
				return $this->sanitize_api_key( $value, 'peptide_news_newsapi_key' );
			},
			'ncbi_api_key'          => function ( $value ) {
				return $this->sanitize_api_key( $value, 'peptide_news_ncbi_api_key' );
			},
			'vps_token'             => function ( $value ) {
				return $this->sanitize_api_key( $value, 'peptide_news_vps_token' );
			},
			'article_retention'     => 'absint',
			'analytics_retention'   => 'absint',
			'anonymize_ip'          => 'absint',
			'filter_enabled'        => 'absint',
			'filter_sensitivity'    => 'sanitize_text_field',
			'filter_llm_enabled'    => 'absint',
			'filter_llm_model'      => array( $this, 'sanitize_model_id' ),
			'filter_title_keywords' => 'sanitize_textarea_field',
			'filter_body_keywords'  => 'sanitize_textarea_field',
			'filter_blocked_domains' => 'sanitize_textarea_field',
			'llm_enabled'           => 'absint',
			'openrouter_api_key'    => function ( $value ) {
				return $this->sanitize_api_key( $value, 'peptide_news_openrouter_api_key' );
			},
			'llm_keywords_model'    => array( $this, 'sanitize_model_id' ),
			'llm_summary_model'     => array( $this, 'sanitize_model_id' ),
			'llm_max_articles'      => 'absint',
			'monthly_budget'        => function ( $value ) {
				return max( 0.0, (float) $value );
			},
			'budget_mode'           => function ( $value ) {
				$valid = array( 'disabled', 'hard_stop', 'warn_only' );
				return in_array( $value, $valid, true ) ? $value : 'disabled';
			},
			'cost_retention'        => function ( $value ) {
				return max( 30, absint( $value ) );
			},
		);

		return isset( $callbacks[ $key ] ) ? $callbacks[ $key ] : 'sanitize_text_field';
	}

	/**
	 * Sanitize and validate an OpenRouter model ID.
	 *
	 * @param string $value Raw input.
	 * @return string Sanitized model ID or default.
	 */
	public function sanitize_model_id( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( ! empty( $value ) && class_exists( 'Peptide_News_LLM' ) && ! Peptide_News_LLM::is_valid_model( $value ) ) {
			add_settings_error(
				'peptide_news_settings_group',
				'invalid_model',
				sprintf(
					/* translators: %s: model ID */
					__( 'Invalid model ID format: %s. Expected format: provider/model-name', 'peptide-news' ),
					esc_html( $value )
				),
				'error'
			);
			return 'google/gemini-2.0-flash-001';
		}
		return $value;
	}

	/**
	 * Sanitize and encrypt API keys before storage.
	 *
	 * If a masked key is submitted (••••••••XXXX format), the existing
	 * encrypted value is preserved. New plaintext keys are encrypted
	 * via Peptide_News_Encryption before being written to wp_options.
	 *
	 * @param string $value Raw input from the settings form.
	 * @param string $option_name The wp_options key for this API key.
	 * @return string Encrypted API key or existing stored value.
	 */
	public function sanitize_api_key( string $value, string $option_name ): string {
		$value = sanitize_text_field( $value );
		if ( empty( $value ) ) {
			return '';
		}

		// If masked format detected (starts with bullets), keep existing stored value.
		if ( mb_strpos( $value, '••••••' ) === 0 ) {
			return get_option( $option_name, '' );
		}

		// Encrypt the new plaintext key before storing.
		if ( class_exists( 'Peptide_News_Encryption' ) ) {
			return Peptide_News_Encryption::encrypt( $value );
		}

		return $value;
	}
}
