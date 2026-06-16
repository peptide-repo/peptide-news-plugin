<?php
declare( strict_types=1 );
/**
 * Rule definitions and evaluation engine for the content filter.
 *
 * Contains default keyword/domain blocklists and the scoring logic that
 * classifies articles as clean, borderline, or promotional. The main
 * Content_Filter orchestrator delegates per-article evaluation here.
 *
 * Called by: Peptide_News_Content_Filter::filter_articles().
 * Dependencies: none (pure logic + WP get_option for user overrides).
 *
 * @since 2.6.0
 * @see   class-peptide-news-content-filter.php — Orchestrator and LLM fallback.
 */
class Peptide_News_Content_Filter_Rules {

	/** @var array Default title keywords indicating promotional content. */
	private static $default_title_keywords = array(
		'press release', 'sponsored', 'sponsored content', 'sponsored post',
		'advertisement', 'advertorial', 'paid content', 'paid post',
		'partner content', 'promoted', 'promoted content', 'brand spotlight',
		'product launch', 'now available', 'launches new', 'announces new',
		'announces partnership', 'signs agreement', 'signs deal',
		'receives fda', 'receives approval', 'ipo filing',
		'stock alert', 'investor alert', 'market report',
	);

	/** @var array Default body keywords (weighted — multiple matches increase confidence). */
	private static $default_body_keywords = array(
		'press release', 'for immediate release', 'media contact',
		'about the company', 'about us', 'forward-looking statements',
		'safe harbor', 'investor relations',
		'nasdaq:', 'nyse:', 'otc:', 'tsx:', 'stock symbol',
		'disclaimer:', 'this is a paid', 'this article is sponsored',
		'this content is sponsored', 'brought to you by', 'in partnership with',
		'paid promotion', 'affiliate link',
		'use code', 'use coupon', 'discount code', 'promo code',
		'shop now', 'buy now', 'order now', 'limited time offer',
		'act now', 'subscribe now', 'sign up today',
		'free trial', 'money-back guarantee',
	);

	/** @var array Domains known to distribute press releases and promotional content. */
	private static $default_blocked_domains = array(
		'prnewswire.com', 'businesswire.com', 'globenewswire.com',
		'accesswire.com', 'cision.com', 'pr.com', 'prweb.com',
		'newswire.com', 'einpresswire.com', 'issuewire.com',
		'send2press.com', 'prlog.org', '24-7pressrelease.com',
		'openpr.com', 'webwire.com', 'newsfilecorp.com',
		'marketwatch.com/press-release', 'yahoo.com/press-release',
	);

	/** @var array URL path fragments that indicate promotional content. */
	private static $promo_paths = array(
		'/press-release', '/press-releases', '/sponsored',
		'/advertorial', '/partner-content', '/paid-content',
		'/promo/', '/advertisement/',
	);

	/**
	 * Evaluate a single article against keyword and domain rules.
	 *
	 * @param array $article          Article data array.
	 * @param array $title_keywords   Pre-lowercased title keyword list.
	 * @param array $body_keywords    Pre-lowercased body keyword list.
	 * @param array $blocked_domains  Pre-lowercased blocked domain list.
	 * @param int   $body_threshold   Minimum body keyword matches to flag.
	 * @return array{verdict: string, rule: string, score: int}
	 */
	public static function evaluate_article( array $article, array $title_keywords, array $body_keywords, array $blocked_domains, int $body_threshold ): array {
		$score = 0;

		$title   = strtolower( $article['title'] ?? '' );
		$excerpt = strtolower( $article['excerpt'] ?? '' );
		$content = strtolower( $article['content'] ?? '' );
		$source  = strtolower( $article['source'] ?? '' );
		$url     = strtolower( $article['source_url'] ?? '' );

		// Rule 1: Blocked source domain.
		foreach ( $blocked_domains as $domain ) {
			if ( empty( $domain ) ) {
				continue;
			}
			if ( false !== strpos( $source, $domain ) || false !== strpos( $url, $domain ) ) {
				return array( 'verdict' => 'promotional', 'rule' => 'blocked_domain:' . $domain, 'score' => 100 );
			}
		}

		// Rule 2: Title keyword match (single strong match is enough).
		foreach ( $title_keywords as $keyword ) {
			if ( empty( $keyword ) ) {
				continue;
			}
			if ( false !== strpos( $title, $keyword ) ) {
				return array( 'verdict' => 'promotional', 'rule' => 'title_keyword:' . $keyword, 'score' => 50 );
			}
		}

		// Rule 3: Body/content keyword accumulation (truncate to 10KB).
		$body_text    = $excerpt . ' ' . mb_substr( $content, 0, 10000 );
		$body_matches = 0;
		$matched_keys = array();

		foreach ( $body_keywords as $keyword ) {
			if ( empty( $keyword ) ) {
				continue;
			}
			if ( false !== strpos( $body_text, $keyword ) ) {
				++$body_matches;
				$matched_keys[] = $keyword;
				$score         += 15;
			}
		}

		if ( $body_matches >= $body_threshold ) {
			return array(
				'verdict' => 'promotional',
				'rule'    => 'body_keywords:' . implode( ',', array_slice( $matched_keys, 0, 3 ) ),
				'score'   => $score,
			);
		}

		// Rule 4: URL path patterns.
		foreach ( self::$promo_paths as $path ) {
			if ( false !== strpos( $url, $path ) ) {
				return array( 'verdict' => 'promotional', 'rule' => 'url_path:' . $path, 'score' => $score + 40 );
			}
		}

		// Borderline: some body matches but not enough to block outright.
		if ( $body_matches >= 1 && $body_matches < $body_threshold ) {
			return array(
				'verdict' => 'borderline',
				'rule'    => 'body_keywords_borderline:' . implode( ',', $matched_keys ),
				'score'   => $score,
			);
		}

		return array( 'verdict' => 'clean', 'rule' => '', 'score' => $score );
	}

	/**
	 * Get the body keyword match threshold based on sensitivity.
	 *
	 * @param string $sensitivity 'strict', 'moderate', or 'lenient'.
	 * @return int Number of body keyword matches required.
	 */
	public static function get_body_threshold( string $sensitivity ): int {
		$thresholds = array( 'strict' => 1, 'moderate' => 2, 'lenient' => 3 );
		return $thresholds[ $sensitivity ] ?? 2;
	}

	/** Get configured title keywords, falling back to defaults. */
	public static function get_title_keywords(): array {
		$custom = get_option( 'peptide_news_filter_title_keywords', '' );
		if ( ! empty( $custom ) ) {
			return array_filter( array_map( 'trim', explode( "\n", $custom ) ) );
		}
		return self::$default_title_keywords;
	}

	/** Get configured body keywords, falling back to defaults. */
	public static function get_body_keywords(): array {
		$custom = get_option( 'peptide_news_filter_body_keywords', '' );
		if ( ! empty( $custom ) ) {
			return array_filter( array_map( 'trim', explode( "\n", $custom ) ) );
		}
		return self::$default_body_keywords;
	}

	/** Get configured blocked domains, falling back to defaults. */
	public static function get_blocked_domains(): array {
		$custom = get_option( 'peptide_news_filter_blocked_domains', '' );
		if ( ! empty( $custom ) ) {
			return array_filter( array_map( 'trim', explode( "\n", $custom ) ) );
		}
		return self::$default_blocked_domains;
	}

	/** Get default title keywords as newline-separated text (for settings UI). */
	public static function get_default_title_keywords_text(): string {
		return implode( "\n", self::$default_title_keywords );
	}

	/** Get default body keywords as newline-separated text (for settings UI). */
	public static function get_default_body_keywords_text(): string {
		return implode( "\n", self::$default_body_keywords );
	}

	/** Get default blocked domains as newline-separated text (for settings UI). */
	public static function get_default_blocked_domains_text(): string {
		return implode( "\n", self::$default_blocked_domains );
	}
}
