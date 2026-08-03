<?php
declare( strict_types=1 );
/**
 * Fired during plugin activation.
 *
 * Creates database tables and schedules the cron event.
 *
 * @since 1.0.0
 */
class Peptide_News_Activator {

	/**
	 * Create custom tables and schedule cron.
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		self::schedule_cron();

		// Create the log table (idempotent via dbDelta).
		if ( class_exists( 'Peptide_News_Logger' ) ) {
			Peptide_News_Logger::create_table();
		}

		// Create the LLM cost tracking table (idempotent via dbDelta).
		if ( class_exists( 'Peptide_News_Cost_Tracker' ) ) {
			Peptide_News_Cost_Tracker::create_table();
		}
	}

	/**
	 * Create the articles and analytics database tables.
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Articles table — stores fetched news data for analysis.
		$articles_table = $wpdb->prefix . 'peptide_news_articles';
		$sql_articles   = "CREATE TABLE {$articles_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(100) NOT NULL DEFAULT '',
			source_url VARCHAR(2048) NOT NULL DEFAULT '',
			title TEXT NOT NULL,
			excerpt TEXT NOT NULL,
			content LONGTEXT NOT NULL,
			author VARCHAR(255) NOT NULL DEFAULT '',
			thumbnail_url VARCHAR(2048) NOT NULL DEFAULT '',
			thumbnail_local VARCHAR(512) NOT NULL DEFAULT '',
			published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			categories VARCHAR(500) NOT NULL DEFAULT '',
			tags VARCHAR(500) NOT NULL DEFAULT '',
			language VARCHAR(10) NOT NULL DEFAULT 'en',
			sentiment_score FLOAT DEFAULT NULL,
			ai_summary TEXT NOT NULL,
			hash VARCHAR(64) NOT NULL DEFAULT '',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			rigor_score TINYINT(1) UNSIGNED DEFAULT NULL,
			study_type VARCHAR(50) DEFAULT NULL,
			ai_metadata LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY hash (hash),
			KEY idx_published (published_at),
			KEY idx_source (source),
			KEY idx_active_published (is_active, published_at),
			KEY idx_rigor_score (rigor_score),
			KEY idx_study_type (study_type)
		) {$charset_collate};";

		// Click analytics table — one row per click event.
		$clicks_table = $wpdb->prefix . 'peptide_news_clicks';
		$sql_clicks   = "CREATE TABLE {$clicks_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT(20) UNSIGNED NOT NULL,
			clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent TEXT NOT NULL,
			referrer_url VARCHAR(2048) NOT NULL DEFAULT '',
			page_url VARCHAR(2048) NOT NULL DEFAULT '',
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			country VARCHAR(2) NOT NULL DEFAULT '',
			device_type VARCHAR(20) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_article (article_id),
			KEY idx_clicked_at (clicked_at),
			KEY idx_session (session_id),
			KEY idx_article_date (article_id, clicked_at)
		) {$charset_collate};";

		// Daily aggregates table — for fast trend queries.
		$daily_table = $wpdb->prefix . 'peptide_news_daily_stats';
		$sql_daily   = "CREATE TABLE {$daily_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			article_id BIGINT(20) UNSIGNED NOT NULL,
			stat_date DATE NOT NULL,
			click_count INT UNSIGNED NOT NULL DEFAULT 0,
			unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY idx_article_date (article_id, stat_date),
			KEY idx_date (stat_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql_articles );
		dbDelta( $sql_clicks );
		dbDelta( $sql_daily );

		// Add foreign key constraints if they don't exist.
		self::add_foreign_key_constraints( $articles_table, $clicks_table, $daily_table );

		update_option( 'peptide_news_db_version', PEPTIDE_NEWS_VERSION );
	}

	/**
	 * Set sensible default option values.
	 */
	private static function set_default_options(): void {
		$defaults = array(
			'fetch_interval'       => 'twicedaily',
			'articles_count'       => 10,
			'newsapi_key'          => '',
			'newsapi_enabled'      => 0,
			'rss_feeds'            => implode( "\n", array(
				'https://news.google.com/rss/search?q=(%22peptide+research%22+OR+%22peptide+therapeutics%22+OR+%22peptide+drug+discovery%22+OR+%22macrocyclic+peptides%22+OR+%22GLP-1+receptor%22)+-prnewswire+-businesswire+-globenewswire+-accesswire+-openpr+-cision+-einpresswire+-newswire+-newsfilecorp+-gossip+-celebrity+-kardashian+-hollywood+-supplement+-vendor+-clinic+-%22press+release%22+-%22sponsored+post%22+-%22market+report%22+-cagr&hl=en-US&gl=US&ceid=US:en',
				'https://pubmed.ncbi.nlm.nih.gov/rss/search/1sUlVGjEXm1YN9QiGPblv_-bwB51JCtyLwVX85CVGDKCHFFR9Q/?limit=50&utm_campaign=pubmed-2&fc=20240101000000',
				'https://connect.biorxiv.org/biorxiv_xml.php?subject=biochemistry+pharmacology_and_toxicology+bioengineering+synthetic_biology',
				'https://connect.medrxiv.org/medrxiv_xml.php?subject=endocrinology+pharmacology_and_therapeutics',
				'https://onlinelibrary.wiley.com/feed/10991387/most-recent',
				'https://www.nature.com/nchembio.rss',
				'https://www.nature.com/nrd.rss',
				'https://www.nature.com/natbiomedeng.rss',
				'https://www.sciencedaily.com/rss/matter_energy/biochemistry.xml',
				'https://www.sciencedaily.com/rss/health_medicine/pharmacology.xml',
				'https://www.biospace.com/rss/',
				'https://www.statnews.com/feed/',
				'https://endpts.com/feed/',
				'https://www.fiercebiotech.com/rss/Fierce%20Biotech%20Homepage/xml',
			) ),
			'rss_enabled'          => 1,
			'search_keywords'      => '("peptide research" OR "peptide therapeutics" OR "peptide drug discovery" OR "macrocyclic peptides" OR "GLP-1 receptor" OR "BPC-157" OR "GHK-Cu") NOT (prnewswire OR businesswire OR globenewswire OR accesswire OR openpr OR "press release" OR "market report" OR "sponsored post" OR celebrity OR gossip OR kardashian OR supplement OR vendor)',
			'thumbnail_fallback'   => PEPTIDE_NEWS_PLUGIN_URL . 'public/images/default-thumb.png',
			'article_retention'    => 90,
			'analytics_retention'  => 365,
			'anonymize_ip'         => 1,
			'llm_enabled'          => 0,
			'openrouter_api_key'   => '',
			'llm_keywords_model'   => 'google/gemini-2.0-flash-001',
			'llm_summary_model'    => 'google/gemini-2.0-flash-001',
			'llm_max_articles'     => 10,
			'monthly_budget'       => 0,
			'budget_mode'          => 'disabled',
			'cost_retention'       => 365,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( "peptide_news_{$key}" ) ) {
				add_option( "peptide_news_{$key}", $value );
			}
		}

		// Upgrade legacy default feeds and keywords to high-signal scientific defaults.
		$current_feeds = (string) get_option( 'peptide_news_rss_feeds', '' );
		$legacy_feeds  = 'https://news.google.com/rss/search?q=peptide+research&hl=en-US&gl=US&ceid=US:en' . "\n" .
			'https://pubmed.ncbi.nlm.nih.gov/rss/search/1sUlVGjEXm1YN9QiGPblv_-bwB51JCtyLwVX85CVGDKCHFFR9Q/?limit=20&utm_campaign=pubmed-2&fc=20220101000000';
		if ( $legacy_feeds === $current_feeds ) {
			update_option( 'peptide_news_rss_feeds', $defaults['rss_feeds'] );
		}

		$current_kw = (string) get_option( 'peptide_news_search_keywords', '' );
		if ( 'peptide, peptides, peptide therapy, peptide research, BPC-157, Thymosin, GHK-Cu' === $current_kw ) {
			update_option( 'peptide_news_search_keywords', $defaults['search_keywords'] );
		}
	}

	/**
	 * Schedule the cron event based on the configured interval.
	 */
	private static function schedule_cron(): void {
		$interval = get_option( 'peptide_news_fetch_interval', 'twicedaily' );

		if ( ! wp_next_scheduled( 'peptide_news_cron_fetch' ) ) {
			wp_schedule_event( time(), $interval, 'peptide_news_cron_fetch' );
		}
	}

	/**
	 * Add foreign key constraints to tables.
	 */
	private static function add_foreign_key_constraints( string $articles_table, string $clicks_table, string $daily_table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_clicks_fk = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'article_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1",
				$clicks_table
			)
		);

		if ( empty( $existing_clicks_fk ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE `{$clicks_table}` ADD CONSTRAINT `fk_clicks_article_id` FOREIGN KEY (`article_id`) REFERENCES `{$articles_table}`(`id`) ON DELETE CASCADE"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_stats_fk = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'article_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1",
				$daily_table
			)
		);

		if ( empty( $existing_stats_fk ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE `{$daily_table}` ADD CONSTRAINT `fk_stats_article_id` FOREIGN KEY (`article_id`) REFERENCES `{$articles_table}`(`id`) ON DELETE CASCADE"
			);
		}
	}
}
