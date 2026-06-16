<?php
declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
$prefix = $wpdb->prefix;

// Drop custom tables (order matters: child tables first due to FK constraints).
$wpdb->query( "DROP TABLE IF EXISTS {$prefix}peptide_news_clicks" );
$wpdb->query( "DROP TABLE IF EXISTS {$prefix}peptide_news_daily_stats" );
$wpdb->query( "DROP TABLE IF EXISTS {$prefix}peptide_news_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$prefix}peptide_news_llm_costs" );
$wpdb->query( "DROP TABLE IF EXISTS {$prefix}peptide_news_articles" );

// Delete all plugin options.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'peptide\_news\_%'"
);

// Remove transient caches.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_peptide\_news\_%' OR option_name LIKE '_transient_timeout_peptide\_news\_%'"
);

// Clear scheduled events.
wp_clear_scheduled_hook( 'peptide_news_cron_fetch' );
