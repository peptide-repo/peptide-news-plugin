<?php
declare( strict_types=1 );
/**
 * Fetches news from multiple sources and stores them in the database.
 *
 * Orchestrates the fetch cycle: RSS feeds (via RSS_Source), NewsAPI,
 * content filtering, deduplication, AI analysis, pruning, and caching.
 * Triggered by WP-Cron on the configured schedule.
 *
 * @since 1.0.0
 * @see   class-peptide-news-rss-source.php       RSS feed fetcher.
 * @see   class-peptide-news-source-resolver.php   Source name resolution.
 * @see   class-peptide-news-content-filter.php    Ad/promo filtering.
 * @see   class-peptide-news-llm.php               AI analysis post-fetch.
 */
class Peptide_News_Fetcher {

	/**
	 * Add custom WP-Cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_custom_cron_schedules( array $schedules ): array {
		$schedules['every_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 Minutes', 'peptide-news' ),
		);
		$schedules['every_thirty_minutes'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 Minutes', 'peptide-news' ),
		);
		$schedules['every_four_hours'] = array(
			'interval' => 14400,
			'display'  => __( 'Every 4 Hours', 'peptide-news' ),
		);
		$schedules['every_six_hours'] = array(
			'interval' => 21600,
			'display'  => __( 'Every 6 Hours', 'peptide-news' ),
		);
		return $schedules;
	}

	/**
	 * Master fetch method — called by WP-Cron.
	 *
	 * Uses a transient lock to prevent concurrent executions.
	 * Side effects: DB writes, HTTP requests, transient operations,
	 * option updates, delegates to LLM processing.
	 */
	public function fetch_all_sources(): void {
		$lock_key = 'peptide_news_fetch_lock';
		if ( get_transient( $lock_key ) ) {
			Peptide_News_Logger::warning( 'Fetch already in progress — skipping this cycle.', 'fetch' );
			return;
		}
		set_transient( $lock_key, true, 300 );

		Peptide_News_Logger::info( 'Fetch cycle started.', 'fetch' );

		$articles = array();

		// Fetch from RSS feeds.
		if ( get_option( 'peptide_news_rss_enabled', 1 ) ) {
			$articles = array_merge( $articles, Peptide_News_RSS_Source::fetch() );
		}

		// Fetch from NewsAPI.
		if ( get_option( 'peptide_news_newsapi_enabled', 0 ) ) {
			$api_key_raw = get_option( 'peptide_news_newsapi_key', '' );
			$api_key     = class_exists( 'Peptide_News_Encryption' )
				? Peptide_News_Encryption::decrypt( $api_key_raw )
				: $api_key_raw;
			if ( ! empty( $api_key ) ) {
				$articles = array_merge( $articles, $this->fetch_newsapi( $api_key ) );
			}
		}

		// Filter out ads, press releases, and promotional content.
		$pre_filter_count = count( $articles );
		if ( class_exists( 'Peptide_News_Content_Filter' ) && Peptide_News_Content_Filter::is_enabled() ) {
			$articles = Peptide_News_Content_Filter::filter_articles( $articles );
		}
		$filtered_out = $pre_filter_count - count( $articles );

		// Store articles (deduplication handled by hash).
		$stored = 0;
		foreach ( $articles as $article ) {
			if ( $this->store_article( $article ) ) {
				++$stored;
			}
		}

		if ( $stored > 0 ) {
			$this->clear_article_cache();
		}

		update_option( 'peptide_news_last_fetch', array(
			'time'         => current_time( 'mysql' ),
			'found'        => $pre_filter_count,
			'filtered_out' => $filtered_out,
			'new_stored'   => $stored,
		) );

		Peptide_News_Logger::info( sprintf(
			'Fetch complete: %d found, %d filtered out, %d new stored.',
			$pre_filter_count, $filtered_out, $stored
		), 'fetch' );

		// Run AI analysis on newly fetched articles.
		if ( class_exists( 'Peptide_News_LLM' ) && Peptide_News_LLM::is_enabled() ) {
			$llm_batch_size = absint( get_option( 'peptide_news_llm_max_articles', 10 ) );
			$llm_processed  = Peptide_News_LLM::process_unanalyzed( $llm_batch_size );

			$fetch_log = get_option( 'peptide_news_last_fetch' );
			if ( is_array( $fetch_log ) ) {
				$fetch_log['ai_processed'] = $llm_processed;
				update_option( 'peptide_news_last_fetch', $fetch_log );
			}
		}

		$this->prune_old_articles();
		delete_transient( $lock_key );
	}

	/**
	 * Fetch articles from NewsAPI.org.
	 *
	 * Side effects: one outbound HTTP GET (30 s timeout).
	 *
	 * @param string $api_key Decrypted NewsAPI key.
	 * @return array[] Article data arrays.
	 */
	private function fetch_newsapi( string $api_key ): array {
		$keywords = get_option( 'peptide_news_search_keywords', 'peptide research' );

		$url = add_query_arg( array(
			'q'        => urlencode( $keywords ),
			'language' => 'en',
			'sortBy'   => 'publishedAt',
			'pageSize' => 50,
			'apiKey'   => $api_key,
		), 'https://newsapi.org/v2/everything' );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
			'headers' => array( 'User-Agent' => 'PeptideNewsWP/' . PEPTIDE_NEWS_VERSION ),
		) );

		if ( is_wp_error( $response ) ) {
			Peptide_News_Logger::error( 'NewsAPI fetch failed: ' . $response->get_error_message(), 'fetch' );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['articles'] ) || 'ok' !== ( $body['status'] ?? '' ) ) {
			Peptide_News_Logger::error( 'NewsAPI returned no articles or error status.', 'fetch' );
			return array();
		}

		$articles = array();
		foreach ( $body['articles'] as $item ) {
			$pub_date = isset( $item['publishedAt'] )
				? gmdate( 'Y-m-d H:i:s', strtotime( $item['publishedAt'] ) )
				: current_time( 'mysql' );

			$articles[] = array(
				'source'        => sanitize_text_field( $item['source']['name'] ?? 'NewsAPI' ),
				'source_url'    => esc_url_raw( $item['url'] ?? '' ),
				'title'         => sanitize_text_field( $item['title'] ?? '' ),
				'excerpt'       => wp_trim_words( sanitize_text_field( $item['description'] ?? '' ), 40 ),
				'content'       => wp_kses_post( $item['content'] ?? '' ),
				'author'        => sanitize_text_field( $item['author'] ?? '' ),
				'thumbnail_url' => '',
				'published_at'  => $pub_date,
				'categories'    => '',
				'tags'          => '',
				'language'      => 'en',
			);
		}

		return $articles;
	}

	/**
	 * Store an article in the database. Deduplication via SHA-256 hash of URL.
	 *
	 * @param array $article Article data array.
	 * @return bool True if new article was inserted.
	 */
	private function store_article( array $article ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'peptide_news_articles';
		$hash  = hash( 'sha256', $article['source_url'] );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE hash = %s",
			$hash
		) );

		if ( $exists ) {
			return false;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'source'        => $article['source'],
				'source_url'    => $article['source_url'],
				'title'         => $article['title'],
				'excerpt'       => $article['excerpt'],
				'content'       => $article['content'],
				'author'        => $article['author'],
				'thumbnail_url' => $article['thumbnail_url'],
				'published_at'  => $article['published_at'],
				'fetched_at'    => current_time( 'mysql' ),
				'categories'    => $article['categories'],
				'tags'          => $article['tags'],
				'language'      => $article['language'],
				'hash'          => $hash,
				'is_active'     => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Remove articles older than the retention period.
	 */
	private function prune_old_articles(): void {
		global $wpdb;

		$retention_days = (int) get_option( 'peptide_news_article_retention', 90 );
		$cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		$table = $wpdb->prefix . 'peptide_news_articles';
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET is_active = 0 WHERE published_at < %s",
			$cutoff
		) );
	}

	/**
	 * Clear all article transient caches (front-end and REST API).
	 */
	private function clear_article_cache(): void {
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
	 * Backward-compatible proxy for source backfill AJAX.
	 *
	 * @see Peptide_News_Source_Resolver::ajax_backfill()
	 */
	public function ajax_backfill_sources(): void {
		Peptide_News_Source_Resolver::ajax_backfill();
	}
}
