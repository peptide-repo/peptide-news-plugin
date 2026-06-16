<?php
/**
 * Plugin Name:       Peptide News Aggregator
 * Plugin URI:        https://github.com/peptiderepo/peptide-news-plugin
 * Description:       Aggregates and displays the latest peptide research news from multiple sources with click analytics and trend reporting.
 * Version:           2.5.0
 * Author:            peptiderepo
 * Author URI:        https://github.com/peptiderepo
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       peptide-news
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

declare( strict_types=1 );

// Abort if called directly.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version — follows SemVer.
 */
define( 'PEPTIDE_NEWS_VERSION', '2.5.0' );
define( 'PEPTIDE_NEWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PEPTIDE_NEWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PEPTIDE_NEWS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Runs during plugin activation.
 */
function peptide_news_activate(): void {
	require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-activator.php';
	Peptide_News_Activator::activate();
}

/**
 * Runs during plugin deactivation.
 */
function peptide_news_deactivate(): void {
	require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-deactivator.php';
	Peptide_News_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'peptide_news_activate' );
register_deactivation_hook( __FILE__, 'peptide_news_deactivate' );

/**
 * Core plugin class that orchestrates all hooks and dependencies.
 */
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news.php';

/**
 * Check for database upgrades on every admin load.
 * This handles existing installs that upgrade from 1.0.0 to 1.1.0
 * without re-activating the plugin.
 */
function peptide_news_check_db_upgrade(): void {
	$installed_version = get_option( 'peptide_news_db_version', '1.0.0' );
	if ( version_compare( $installed_version, PEPTIDE_NEWS_VERSION, '<' ) ) {
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-activator.php';
		require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-logger.php';
		Peptide_News_Activator::activate();
	}
}
add_action( 'plugins_loaded', 'peptide_news_check_db_upgrade' );

/**
 * Begins execution of the plugin.
 */
function peptide_news_run(): void {
	$plugin = new Peptide_News();
	$plugin->run();
}

peptide_news_run();
