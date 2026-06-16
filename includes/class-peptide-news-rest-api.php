<?php
declare( strict_types=1 );
/**
 * REST API route registration and analytics endpoints.
 *
 * Base route: /wp-json/peptide-news/v1/
 *
 * Endpoints:
 *   GET /articles          — list stored articles (delegated to Rest_Articles)
 *   GET /analytics/top     — top articles by clicks
 *   GET /analytics/trends  — daily click trend data
 *   GET /analytics/topics  — popular topics/categories
 *   GET /analytics/devices — device breakdown
 *   GET /analytics/sources — source performance
 *   GET /analytics/export  — CSV export of raw click data
 *
 * Called by: Peptide_News::define_rest_hooks() via rest_api_init.
 * Dependencies: Peptide_News_Rest_Articles, Peptide_News_Analytics, Peptide_News_Logger.
 *
 * @since 1.0.0
 * @see   class-peptide-news-rest-articles.php — Article listing and source-suffix cleanup.
 * @see   class-peptide-news-analytics.php     — Analytics query layer.
 */
class Peptide_News_Rest_API {

	const API_NAMESPACE = 'peptide-news/v1';

	/** @var Peptide_News_Rest_Articles */
	private $articles_handler;

	public function __construct() {
		$this->articles_handler = new Peptide_News_Rest_Articles();
	}

	/** Register all REST routes. */
	public function register_routes(): void {
		// Public: list articles.
		register_rest_route( self::API_NAMESPACE, '/articles', array(
			'methods'             => 'GET',
			'callback'            => array( $this->articles_handler, 'get_articles' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'count' => array( 'default' => 10, 'sanitize_callback' => 'absint' ),
				'page'  => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
			),
		) );

		// Admin-only analytics endpoints.
		$analytics_routes = array(
			'/analytics/top'     => 'get_top_articles',
			'/analytics/trends'  => 'get_trends',
			'/analytics/topics'  => 'get_topics',
			'/analytics/devices' => 'get_devices',
			'/analytics/sources' => 'get_sources',
			'/analytics/export'  => 'get_export',
		);

		foreach ( $analytics_routes as $route => $callback ) {
			register_rest_route( self::API_NAMESPACE, $route, array(
				'methods'             => 'GET',
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => $this->get_date_range_args(),
			) );
		}
	}

	/**
	 * Verify the request comes from an admin user.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_admin_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access analytics data.', 'peptide-news' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/** Common date-range argument definitions for analytics endpoints. */
	private function get_date_range_args(): array {
		return array(
			'start_date' => array(
				'default'           => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_date_format' ),
			),
			'end_date' => array(
				'default'           => gmdate( 'Y-m-d' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_date_format' ),
			),
			'limit' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
		);
	}

	/** Validate that a parameter is a Y-m-d date string. */
	public function validate_date_format( $value, $request, $param ) {
		if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return new WP_Error(
				'rest_invalid_date',
				/* translators: %s: REST API parameter name */
				sprintf( __( 'Invalid date format for %s. Expected Y-m-d.', 'peptide-news' ), $param ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/** GET /analytics/top */
	public function get_top_articles( $request ) {
		return rest_ensure_response( Peptide_News_Analytics::get_top_articles(
			$request->get_param( 'start_date' ), $request->get_param( 'end_date' ), $request->get_param( 'limit' )
		) );
	}

	/** GET /analytics/trends */
	public function get_trends( $request ) {
		return rest_ensure_response( Peptide_News_Analytics::get_click_trends(
			$request->get_param( 'start_date' ), $request->get_param( 'end_date' )
		) );
	}

	/** GET /analytics/topics */
	public function get_topics( $request ) {
		return rest_ensure_response( Peptide_News_Analytics::get_popular_topics(
			$request->get_param( 'start_date' ), $request->get_param( 'end_date' ), $request->get_param( 'limit' )
		) );
	}

	/** GET /analytics/devices */
	public function get_devices( $request ) {
		return rest_ensure_response( Peptide_News_Analytics::get_device_breakdown(
			$request->get_param( 'start_date' ), $request->get_param( 'end_date' )
		) );
	}

	/** GET /analytics/sources */
	public function get_sources( $request ) {
		return rest_ensure_response( Peptide_News_Analytics::get_source_performance(
			$request->get_param( 'start_date' ), $request->get_param( 'end_date' )
		) );
	}

	/** GET /analytics/export — returns CSV-ready data as JSON. */
	public function get_export( $request ) {
		$data = Peptide_News_Analytics::export_clicks_csv(
			$request->get_param( 'start_date' ), $request->get_param( 'end_date' )
		);

		if ( empty( $data ) ) {
			return rest_ensure_response( array( 'message' => 'No data for the selected period.' ) );
		}

		return rest_ensure_response( array(
			'headers' => array_keys( $data[0] ),
			'rows'    => $data,
			'count'   => count( $data ),
		) );
	}

	/**
	 * AJAX handler: delete one or more articles by ID.
	 *
	 * Side effects: nonce/capability check, DB delete, cache clear, logger write.
	 */
	public static function ajax_delete_articles(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.', 403 );
		}

		$raw_ids = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		if ( empty( $raw_ids ) ) {
			wp_send_json_error( 'No article IDs provided.' );
		}

		$ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( 'Invalid article IDs.' );
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'peptide_news_articles';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			)
		);

		if ( false === $deleted ) {
			wp_send_json_error( 'Database error.' );
		}

		delete_transient( 'peptide_news_articles_1_10' );
		Peptide_News_Logger::info( 'Deleted ' . $deleted . ' article(s): IDs ' . implode( ', ', $ids ), 'admin' );

		wp_send_json_success( array( 'deleted' => $deleted, 'ids' => $ids ) );
	}
}
