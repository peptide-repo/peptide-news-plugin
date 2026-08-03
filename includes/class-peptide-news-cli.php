<?php
declare( strict_types=1 );
/**
 * WP-CLI Commands for Peptide News Aggregator.
 *
 * Provides command-line access for high-performance cron operations,
 * background fetching, AI article generation, and URL resolution
 * on dedicated servers and VPS environments (e.g., Hostinger KVM8).
 *
 * @package PeptideNews
 * @since 2.6.0
 */

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

/**
 * Manage Peptide News Aggregator fetching, LLM processing, and source resolution.
 */
class Peptide_News_CLI extends WP_CLI_Command {

	/**
	 * Fetch new articles from configured RSS sources.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide-news fetch
	 *
	 * @when after_wp_load
	 */
	public function fetch( array $args = array(), array $assoc_args = array() ): void {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'log' ) ) {
			WP_CLI::log( 'Starting RSS feed fetch...' );
		}
		$start     = microtime( true );
		$new_count = Peptide_News_Fetcher::fetch_all_sources();
		$duration  = round( microtime( true ) - $start, 2 );

		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'success' ) ) {
			WP_CLI::success( sprintf( 'Fetched %d new articles in %s seconds.', $new_count, $duration ) );
		}
	}

	/**
	 * Run the 3-Step AI Research & Article Generation pipeline on unanalyzed articles.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Number of articles to process. Defaults to 15 in CLI mode.
	 *
	 * [--force]
	 * : Ignore the admin max articles per cycle limit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide-news process-llm --batch=20 --force
	 *
	 * @when after_wp_load
	 */
	public function process_llm( array $args = array(), array $assoc_args = array() ): void {
		if ( ! Peptide_News_LLM::is_enabled() ) {
			if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'error' ) ) {
				WP_CLI::error( 'LLM processing is disabled or missing an API key.' );
			}
			return;
		}

		$batch_size = isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 15;
		if ( $batch_size < 1 ) {
			$batch_size = 15;
		}
		$force = isset( $assoc_args['force'] );

		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'log' ) ) {
			WP_CLI::log( sprintf( 'Processing up to %d unanalyzed articles through 3-Step AI Pipeline...', $batch_size ) );
		}
		$start     = microtime( true );
		$processed = Peptide_News_LLM::process_unanalyzed( $batch_size, $force );
		$duration  = round( microtime( true ) - $start, 2 );

		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'success' ) ) {
			WP_CLI::success( sprintf( 'Successfully generated AI summaries for %d articles in %s seconds.', $processed, $duration ) );
		}
	}

	/**
	 * Resolve Google News redirect URLs and update database sources.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of unresolved URLs to resolve. Default 20.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peptide-news backfill-sources --limit=50
	 *
	 * @when after_wp_load
	 */
	public function backfill_sources( array $args = array(), array $assoc_args = array() ): void {
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;
		if ( $limit < 1 ) {
			$limit = 20;
		}

		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'log' ) ) {
			WP_CLI::log( sprintf( 'Resolving up to %d redirect URLs...', $limit ) );
		}
		$start    = microtime( true );
		$resolved = Peptide_News_Source_Resolver::backfill( $limit );
		$duration = round( microtime( true ) - $start, 2 );

		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'success' ) ) {
			WP_CLI::success( sprintf( 'Resolved %d URLs in %s seconds.', $resolved, $duration ) );
		}
	}
}
