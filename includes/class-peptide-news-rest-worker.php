<?php
declare( strict_types=1 );
/**
 * REST API Endpoints for VPS Worker Synchronization.
 *
 * Exposes authenticated endpoints allowing an external VPS worker
 * (e.g., running on Hostinger KVM8) to fetch pending articles,
 * run AI generation and URL resolution offboard, and sync completed
 * data or ingest new articles back into the shared WordPress server.
 *
 * @package PeptideNews
 * @since 2.6.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Handle REST API routes for VPS Worker integration (/worker/*).
 */
class Peptide_News_Rest_Worker {

	/**
	 * Check whether the incoming request is authenticated as an admin
	 * OR bears a valid VPS worker secret token.
	 *
	 * GET requests (pending) require the token as a query parameter so
	 * cached responses are namespaced by the secret; POST requests accept
	 * the X-Peptide-News-Token header (or query token) or an admin session.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_worker_permissions( $request ) {
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );

		$token = get_option( 'peptide_news_vps_token', '' );
		if ( ! empty( $token ) && class_exists( 'Peptide_News_Encryption' ) ) {
			$decrypted = Peptide_News_Encryption::decrypt( (string) $token );
			if ( ! empty( $decrypted ) ) {
				$token = $decrypted;
			}
		}

		if ( WP_REST_Server::READABLE === $request->get_method() ) {
			$auth_token = (string) $request->get_param( 'token' );
		} else {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}
			$auth_token = (string) $request->get_header( 'x_peptide_news_token' );
			if ( empty( $auth_token ) ) {
				$auth_token = (string) $request->get_param( 'token' );
			}
		}

		if ( ! empty( $token ) && ! empty( $auth_token ) && hash_equals( (string) $token, $auth_token ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Invalid or missing VPS worker authentication token.', 'peptide-news' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET /peptide-news/v1/worker/pending
	 *
	 * Returns articles that have an empty ai_summary and are marked active.
	 *
	 * @param WP_REST_Request $request REST Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pending_articles( WP_REST_Request $request ) {
		global $wpdb;
		$limit  = absint( $request->get_param( 'limit' ) ?: 20 );
		$limit  = min( max( $limit, 1 ), 100 );
		$table  = $wpdb->prefix . 'peptide_news_articles';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, content, source_url, source_name, pub_date
				 FROM {$table}
				 WHERE (ai_summary IS NULL OR ai_summary = '') AND is_active = 1
				 ORDER BY pub_date DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return rest_ensure_response( array(
			'success' => true,
			'count'   => count( $results ),
			'data'    => $results,
		) );
	}

	/**
	 * POST /peptide-news/v1/worker/update
	 *
	 * Updates an article with AI summaries, tags, rigor score, study type, and metadata.
	 *
	 * @param WP_REST_Request $request REST Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_article( WP_REST_Request $request ) {
		global $wpdb;
		$id    = absint( $request->get_param( 'id' ) );
		$table = $wpdb->prefix . 'peptide_news_articles';

		if ( empty( $id ) ) {
			return new WP_Error( 'invalid_id', __( 'Missing article ID.', 'peptide-news' ), array( 'status' => 400 ) );
		}

		$update = array();
		$format = array();

		if ( null !== $request->get_param( 'tags' ) ) {
			$update['tags'] = sanitize_text_field( $request->get_param( 'tags' ) );
			$format[]       = '%s';
		}
		if ( null !== $request->get_param( 'ai_summary' ) ) {
			$update['ai_summary'] = wp_kses_post( $request->get_param( 'ai_summary' ) );
			$format[]             = '%s';
		}
		if ( null !== $request->get_param( 'rigor_score' ) ) {
			$update['rigor_score'] = absint( $request->get_param( 'rigor_score' ) );
			$format[]              = '%d';
		}
		if ( null !== $request->get_param( 'study_type' ) ) {
			$update['study_type'] = sanitize_text_field( $request->get_param( 'study_type' ) );
			$format[]             = '%s';
		}
		if ( null !== $request->get_param( 'is_active' ) ) {
			$update['is_active'] = absint( $request->get_param( 'is_active' ) );
			$format[]            = '%d';
		}
		if ( null !== $request->get_param( 'source_url' ) && esc_url_raw( $request->get_param( 'source_url' ) ) ) {
			$update['source_url'] = esc_url_raw( $request->get_param( 'source_url' ) );
			$format[]             = '%s';
		}
		if ( null !== $request->get_param( 'ai_metadata' ) ) {
			$meta = $request->get_param( 'ai_metadata' );
			$update['ai_metadata'] = is_array( $meta ) ? wp_json_encode( $meta ) : (string) $meta;
			$format[]              = '%s';
		}

		if ( empty( $update ) ) {
			return new WP_Error( 'rest_empty_payload', 'No fields to update.', array( 'status' => 400 ) );
		}

		$updated = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		if ( false === $updated ) {
			return new WP_Error( 'rest_db_error', 'Database update failed.', array( 'status' => 500 ) );
		}

		// Clear transient cache so front-end displays updated article.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_peptide_news_articles_' ) . '%'
			)
		);

		return rest_ensure_response( array(
			'success' => true,
			'id'      => $id,
			'updated' => true,
		) );
	}

	/**
	 * POST /peptide-news/v1/worker/ingest
	 *
	 * Bulk-ingest new articles scraped or processed on an external VPS worker.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function ingest_articles( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$articles = $request->get_param( 'articles' );
		if ( ! is_array( $articles ) ) {
			return new WP_Error( 'rest_invalid_payload', 'Expected array of articles.', array( 'status' => 400 ) );
		}

		$ingested = 0;
		foreach ( $articles as $art ) {
			if ( empty( $art['title'] ) || empty( $art['source_url'] ) ) {
				continue;
			}
			$source_url = esc_url_raw( $art['source_url'] );
			$exists     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_url = %s", $source_url ) );
			if ( $exists ) {
				continue;
			}

			$inserted = $wpdb->insert(
				$table,
				array(
					'title'        => sanitize_text_field( $art['title'] ),
					'source_url'   => $source_url,
					'excerpt'      => sanitize_textarea_field( $art['excerpt'] ?? '' ),
					'content'      => wp_kses_post( $art['content'] ?? '' ),
					'published_at' => ! empty( $art['published_at'] ) ? $art['published_at'] : current_time( 'mysql' ),
					'fetched_at'   => current_time( 'mysql' ),
					'categories'   => sanitize_text_field( $art['categories'] ?? 'general' ),
					'tags'         => sanitize_text_field( $art['tags'] ?? '' ),
					'ai_summary'   => wp_kses_post( $art['ai_summary'] ?? '' ),
					'rigor_score'  => absint( $art['rigor_score'] ?? 0 ),
					'study_type'   => sanitize_text_field( $art['study_type'] ?? '' ),
					'ai_metadata'  => is_array( $art['ai_metadata'] ?? null ) ? wp_json_encode( $art['ai_metadata'] ) : (string) ( $art['ai_metadata'] ?? '' ),
					'is_active'    => 1,
				)
			);
			if ( $inserted ) {
				++$ingested;
			}
		}

		if ( $ingested > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_peptide_news_articles_' ) . '%'
				)
			);
		}

		return rest_ensure_response( array(
			'success'  => true,
			'ingested' => $ingested,
		) );
	}
}
