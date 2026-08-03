<?php
declare( strict_types=1 );
/**
 * Settings field rendering.
 *
 * Provides render methods for all 25+ WordPress settings fields
 * across 7 sections. Used by Peptide_News_Admin_Settings during
 * add_settings_field() calls.
 *
 * @since 2.5.0
 * @see Peptide_News_Admin_Settings — Calls these renderers
 */
class Peptide_News_Admin_Field_Renderers {

	/**
	 * Get a masked version of an API key showing only the last 4 characters.
	 *
	 * Decrypts the stored value first (handles both encrypted and legacy
	 * plaintext keys transparently).
	 *
	 * @param string $key Stored API key (encrypted or plaintext).
	 * @return string Masked key (e.g., ••••••••XXXX).
	 */
	public static function mask_api_key( string $key ): string {
		if ( empty( $key ) ) {
			return '';
		}

		// Decrypt before masking so we show the real key's last 4 chars.
		if ( class_exists( 'Peptide_News_Encryption' ) ) {
			$key = Peptide_News_Encryption::decrypt( $key );
		}

		$len = strlen( $key );
		if ( $len <= 4 ) {
			return str_repeat( '•', $len );
		}
		return str_repeat( '•', $len - 4 ) . substr( $key, -4 );
	}

	public static function render_fetch_interval_field(): void {
		$value   = get_option( 'peptide_news_fetch_interval', 'twicedaily' );
		$options = array(
			'every_fifteen_minutes' => __( 'Every 15 minutes', 'peptide-news' ),
			'every_thirty_minutes'  => __( 'Every 30 minutes', 'peptide-news' ),
			'hourly'                => __( 'Hourly', 'peptide-news' ),
			'every_four_hours'      => __( 'Every 4 hours', 'peptide-news' ),
			'every_six_hours'       => __( 'Every 6 hours', 'peptide-news' ),
			'twicedaily'            => __( 'Twice daily', 'peptide-news' ),
			'daily'                 => __( 'Daily', 'peptide-news' ),
		);

		echo '<select name="peptide_news_fetch_interval" id="peptide_news_fetch_interval">';
		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'How often to check for new articles. After changing, deactivate and reactivate the plugin.', 'peptide-news' ) . '</p>';
	}

	public static function render_articles_count_field(): void {
		$value = get_option( 'peptide_news_articles_count', 10 );
		printf(
			'<input type="number" name="peptide_news_articles_count" value="%d" min="1" max="100" class="small-text" />',
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'Number of articles to display in the shortcode/widget.', 'peptide-news' ) . '</p>';
	}

	public static function render_search_keywords_field(): void {
		$value = get_option( 'peptide_news_search_keywords', '' );
		printf(
			'<input type="text" name="peptide_news_search_keywords" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Comma-separated keywords for NewsAPI searches.', 'peptide-news' ) . '</p>';
	}

	public static function render_rss_enabled_field(): void {
		$value = get_option( 'peptide_news_rss_enabled', 1 );
		printf(
			'<label><input type="checkbox" name="peptide_news_rss_enabled" value="1" %s /> %s</label>',
			checked( $value, 1, false ),
			esc_html__( 'Fetch articles from RSS feeds', 'peptide-news' )
		);
	}

	public static function render_rss_feeds_field(): void {
		$value = get_option( 'peptide_news_rss_feeds', '' );
		printf(
			'<textarea name="peptide_news_rss_feeds" rows="6" class="large-text code">%s</textarea>',
			esc_textarea( $value )
		);
	}

	public static function render_newsapi_enabled_field(): void {
		$value = get_option( 'peptide_news_newsapi_enabled', 0 );
		printf(
			'<label><input type="checkbox" name="peptide_news_newsapi_enabled" value="1" %s /> %s</label>',
			checked( $value, 1, false ),
			esc_html__( 'Fetch articles from NewsAPI.org', 'peptide-news' )
		);
	}

	public static function render_newsapi_key_field(): void {
		$value = get_option( 'peptide_news_newsapi_key', '' );
		$display_value = ! empty( $value ) ? self::mask_api_key( $value ) : '';
		printf(
			'<input type="password" name="peptide_news_newsapi_key" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $display_value ),
			esc_attr__( 'Enter new API key to change', 'peptide-news' )
		);
	}

	public static function render_ncbi_key_field(): void {
		$value         = get_option( 'peptide_news_ncbi_api_key', '' );
		$display_value = ! empty( $value ) ? self::mask_api_key( $value ) : '';
		printf(
			'<input type="password" name="peptide_news_ncbi_api_key" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $display_value ),
			esc_attr__( 'Enter NCBI API key (optional)', 'peptide-news' )
		);
	}

	public static function render_vps_token_field(): void {
		$value         = get_option( 'peptide_news_vps_token', '' );
		$display_value = ! empty( $value ) ? self::mask_api_key( $value ) : '';
		printf(
			'<input type="password" name="peptide_news_vps_token" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $display_value ),
			esc_attr__( 'Enter secret VPS token (optional)', 'peptide-news' )
		);
	}

	public static function render_article_retention_field(): void {
		$value = get_option( 'peptide_news_article_retention', 90 );
		printf(
			'<input type="number" name="peptide_news_article_retention" value="%d" min="7" max="3650" class="small-text" />',
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'How long to keep fetched articles before deactivating them.', 'peptide-news' ) . '</p>';
	}

	public static function render_retention_field(): void {
		$value = get_option( 'peptide_news_analytics_retention', 365 );
		printf(
			'<input type="number" name="peptide_news_analytics_retention" value="%d" min="30" max="3650" class="small-text" />',
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'How long to keep click analytics data.', 'peptide-news' ) . '</p>';
	}

	public static function render_anonymize_ip_field(): void {
		$value = get_option( 'peptide_news_anonymize_ip', 1 );
		printf(
			'<label><input type="checkbox" name="peptide_news_anonymize_ip" value="1" %s /> %s</label>',
			checked( $value, 1, false ),
			esc_html__( 'Anonymize IP addresses for GDPR compliance', 'peptide-news' )
		);
	}

	// ── Filter field proxies — @see Peptide_News_Admin_Filter_Field_Renderers ──

	public static function render_filter_enabled_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_enabled_field(); }
	public static function render_filter_sensitivity_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_sensitivity_field(); }
	public static function render_filter_llm_enabled_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_llm_enabled_field(); }
	public static function render_filter_llm_model_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_llm_model_field(); }
	public static function render_filter_title_keywords_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_title_keywords_field(); }
	public static function render_filter_body_keywords_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_body_keywords_field(); }
	public static function render_filter_blocked_domains_field(): void { Peptide_News_Admin_Filter_Field_Renderers::render_filter_blocked_domains_field(); }

	public static function render_llm_enabled_field(): void {
		$value = get_option( 'peptide_news_llm_enabled', 0 );
		printf(
			'<label><input type="checkbox" name="peptide_news_llm_enabled" value="1" %s /> %s</label>',
			checked( $value, 1, false ),
			esc_html__( 'Analyze articles with AI during each fetch cycle', 'peptide-news' )
		);
	}

	public static function render_openrouter_key_field(): void {
		$value = get_option( 'peptide_news_openrouter_api_key', '' );
		$display_value = ! empty( $value ) ? self::mask_api_key( $value ) : '';
		printf(
			'<input type="password" name="peptide_news_openrouter_api_key" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $display_value ),
			esc_attr__( 'Enter new API key to change', 'peptide-news' )
		);
		echo '<p class="description">' . wp_kses(
			__( 'Get an API key at <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.', 'peptide-news' ),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
	}

	public static function render_llm_keywords_model_field(): void {
		$value = get_option( 'peptide_news_llm_keywords_model', 'google/gemini-2.0-flash-001' );
		printf(
			'<input type="text" name="peptide_news_llm_keywords_model" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'OpenRouter model ID for keyword extraction (e.g., google/gemini-2.0-flash-001, openai/gpt-4o-mini).', 'peptide-news' ) . '</p>';
	}

	public static function render_llm_summary_model_field(): void {
		$value = get_option( 'peptide_news_llm_summary_model', 'google/gemini-2.0-flash-001' );
		printf(
			'<input type="text" name="peptide_news_llm_summary_model" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'OpenRouter model ID for article summarization (e.g., google/gemini-2.0-flash-001, anthropic/claude-3.5-sonnet).', 'peptide-news' ) . '</p>';
	}

	public static function render_llm_max_articles_field(): void {
		$value = get_option( 'peptide_news_llm_max_articles', 10 );
		printf(
			'<input type="number" name="peptide_news_llm_max_articles" value="%d" min="1" max="50" class="small-text" />',
			absint( $value )
		);
		echo '<p class="description">' . esc_html__( 'Maximum number of articles to analyze with AI per fetch cycle. Controls API costs and prevents cron timeouts.', 'peptide-news' ) . '</p>';
	}

	public static function render_monthly_budget_field(): void {
		$value = (float) get_option( 'peptide_news_monthly_budget', 0.0 );
		printf(
			'<input type="number" name="peptide_news_monthly_budget" value="%.2f" min="0" step="0.01" class="small-text" style="width:100px;" />',
			$value
		);
		echo '<p class="description">' . esc_html__( 'Set to 0 to disable budget limits. The plugin will stop making API calls when this amount is reached (if enforcement is enabled).', 'peptide-news' ) . '</p>';
	}

	public static function render_budget_mode_field(): void {
		$value = get_option( 'peptide_news_budget_mode', 'disabled' );
		$modes = array(
			'disabled'  => __( 'Disabled — track costs only, no limits', 'peptide-news' ),
			'warn_only' => __( 'Warn only — log alerts at 50%, 80%, 100%', 'peptide-news' ),
			'hard_stop' => __( 'Hard stop — block API calls when budget is reached', 'peptide-news' ),
		);
		echo '<select name="peptide_news_budget_mode">';
		foreach ( $modes as $mode => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $mode ),
				selected( $value, $mode, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public static function render_cost_retention_field(): void {
		$value = absint( get_option( 'peptide_news_cost_retention', 365 ) );
		printf(
			'<input type="number" name="peptide_news_cost_retention" value="%d" min="30" max="3650" class="small-text" />',
			$value
		);
		echo '<p class="description">' . esc_html__( 'How long to keep detailed cost records. Minimum 30 days.', 'peptide-news' ) . '</p>';
	}
}
