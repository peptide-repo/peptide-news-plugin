<?php
declare( strict_types=1 );
/**
 * The core plugin class.
 *
 * Maintains the list of hooks and coordinates all modules.
 *
 * @since 1.0.0
 */
class Peptide_News {

	/** @var Peptide_News_Loader */
	protected $loader;

	/** @var string */
	protected $plugin_name;

	/** @var string */
	protected $version;

	public function __construct() {
		$this->version     = PEPTIDE_NEWS_VERSION;
		$this->plugin_name = 'peptide-news';

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_cron_hooks();
		$this->define_rest_hooks();
	}

	/**
	 * Load all required files.
	 */
	private function load_dependencies(): void {
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-loader.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-activator.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-deactivator.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-encryption.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-google-url-resolver.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-pubmed-extractor.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-extractor.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-source-resolver.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-rss-source.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-fetcher.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-analytics-reports.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-analytics.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-rest-articles.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-rest-worker.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-rest-api.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm-client.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm-prompt-builder.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm-ajax.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-llm.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-logger.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-filter-rules.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-filter.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-cost-reporter.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-cost-tracker.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-assets.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-menu.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-filter-field-renderers.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-field-renderers.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-settings.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-log-viewer.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-settings-page.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-pn-admin-dashboard-pages.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'admin/class-peptide-news-admin.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'public/class-peptide-news-public.php';

		$this->loader = new Peptide_News_Loader();
	}

	/**
	 * Register admin-side hooks.
	 */
	private function define_admin_hooks(): void {
		$admin = new Peptide_News_Admin( $this->plugin_name, $this->version );

		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $admin, 'add_admin_menu' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
	}

	/**
	 * Register public-facing hooks.
	 */
	private function define_public_hooks(): void {
		$public = new Peptide_News_Public( $this->plugin_name, $this->version );

		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $public, 'enqueue_scripts' );
		$this->loader->add_action( 'init', $public, 'register_shortcode' );
		$this->loader->add_action( 'widgets_init', $public, 'register_widget' );
		$this->loader->add_action( 'wp_ajax_peptide_news_track_click', $public, 'handle_click_tracking' );
		$this->loader->add_action( 'wp_ajax_nopriv_peptide_news_track_click', $public, 'handle_click_tracking' );
	}

	/**
	 * Register WP-Cron hooks for scheduled fetching.
	 */
	private function define_cron_hooks(): void {
		$fetcher = new Peptide_News_Fetcher();

		$this->loader->add_action( 'peptide_news_cron_fetch', $fetcher, 'fetch_all_sources' );
		$this->loader->add_filter( 'cron_schedules', $fetcher, 'add_custom_cron_schedules' );
		$this->loader->add_action( 'wp_ajax_peptide_news_backfill_sources', $fetcher, 'ajax_backfill_sources' );
		$this->loader->add_action( 'wp_ajax_peptide_news_generate_summaries', 'Peptide_News_LLM', 'ajax_generate_summaries' );
		$this->loader->add_action( 'wp_ajax_peptide_news_get_logs', 'Peptide_News_Logger', 'ajax_get_logs' );
		$this->loader->add_action( 'wp_ajax_peptide_news_clear_logs', 'Peptide_News_Logger', 'ajax_clear_logs' );
		$this->loader->add_action( 'wp_ajax_peptide_news_delete_articles', 'Peptide_News_Rest_API', 'ajax_delete_articles' );
		$this->loader->add_action( 'wp_ajax_peptide_news_get_cost_data', 'Peptide_News_Cost_Tracker', 'ajax_get_cost_data' );
	}

	/**
	 * Register REST API hooks.
	 */
	private function define_rest_hooks(): void {
		$rest = new Peptide_News_Rest_API();

		$this->loader->add_action( 'rest_api_init', $rest, 'register_routes' );
	}

	/**
	 * Run the loader to execute all registered hooks.
	 */
	public function run(): void {
		$this->loader->run();
	}

	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	public function get_version(): string {
		return $this->version;
	}

	public function get_loader(): Peptide_News_Loader {
		return $this->loader;
	}
}
