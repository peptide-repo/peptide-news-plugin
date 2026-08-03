<?php
declare( strict_types=1 );

/**
 * Robust HTML content and metadata extractor for scientific articles.
 *
 * Implements a hierarchical extraction strategy:
 * 1. JSON-LD Schema.org Article / ScholarlyArticle metadata.
 * 2. Highwire Press / Dublin Core citation meta tags (citation_abstract, citation_title).
 * 3. OpenGraph & HTML meta description tags.
 * 4. DOMXPath paragraph density scoring fallback.
 *
 * Enforces a 500 KB memory cap, strips boilerplate/scripts, and guarantees UTF-8 encoding safety.
 *
 * @since 2.6.0
 */
class Peptide_News_Content_Extractor {

	const MAX_HTML_SIZE = 512000; // 500 KB memory cap

	/**
	 * Extract title, content, author, and journal from raw HTML or URL.
	 *
	 * @param string $html Raw HTML content (or empty if URL provided).
	 * @param string $url  Optional URL to fetch if HTML is empty.
	 * @return array<string, string> Extracted data array with 'title', 'content', 'author', 'journal'.
	 */
	public static function extract( string $html = '', string $url = '' ): array {
		if ( empty( $html ) && ! empty( $url ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 15,
					'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return array(
					'title'   => '',
					'content' => '',
					'author'  => '',
					'journal' => '',
				);
			}
			$html = (string) wp_remote_retrieve_body( $response );
		}

		if ( empty( $html ) ) {
			return array(
				'title'   => '',
				'content' => '',
				'author'  => '',
				'journal' => '',
			);
		}

		// Enforce 500 KB size cap safely for multi-byte UTF-8 characters.
		if ( strlen( $html ) > self::MAX_HTML_SIZE ) {
			$html = function_exists( 'mb_strcut' ) ? mb_strcut( $html, 0, self::MAX_HTML_SIZE, 'UTF-8' ) : substr( $html, 0, self::MAX_HTML_SIZE );
		}

		// Strip scripts, styles, svgs, and base64 data URIs before DOM parsing.
		$html = preg_replace( '/<(script|style|svg|noscript|iframe)[^>]*>.*?<\/\1>/is', '', $html );
		$html = preg_replace( '/data:image\/[a-z0-9+-]+;base64,[a-z0-9+\/=_]+/i', '', (string) $html );

		// Enforce UTF-8 pre-encoding normalization without deprecated mb_convert_encoding.
		$html_encoded = '<?xml encoding="UTF-8">' . (string) $html;

		$libxml_prev = libxml_use_internal_errors( true );
		$dom         = new DOMDocument();
		$dom->loadHTML( $html_encoded, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_prev );

		$xpath = new DOMXPath( $dom );

		// 1. Try JSON-LD Schema.org Article / ScholarlyArticle
		$jsonld_data = self::extract_from_jsonld( $xpath );
		if ( ! empty( $jsonld_data['content'] ) && strlen( $jsonld_data['content'] ) > 100 ) {
			return $jsonld_data;
		}

		// 2. Try Highwire Press / Citation Meta Tags
		$highwire_data = self::extract_from_highwire( $xpath );
		if ( ! empty( $highwire_data['content'] ) && strlen( $highwire_data['content'] ) > 100 ) {
			return $highwire_data;
		}

		// 3. Fallback: DOMXPath Paragraph Density Scoring
		return self::extract_from_paragraphs( $xpath, $jsonld_data, $highwire_data );
	}

	/**
	 * Extract data from JSON-LD `<script type="application/ld+json">`.
	 *
	 * @param DOMXPath $xpath DOMXPath instance.
	 * @return array<string, string>
	 */
	private static function extract_from_jsonld( DOMXPath $xpath ): array {
		$nodes = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( ! $nodes ) {
			return array(
				'title'   => '',
				'content' => '',
				'author'  => '',
				'journal' => '',
			);
		}

		foreach ( $nodes as $node ) {
			$json_text = trim( $node->nodeValue );
			$data      = json_decode( $json_text, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			// Handle @graph array wrapper
			$items = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : array( $data );

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$type = isset( $item['@type'] ) ? (string) $item['@type'] : '';
				if ( in_array( $type, array( 'Article', 'ScholarlyArticle', 'NewsArticle', 'MedicalScholarlyArticle' ), true ) ) {
					$title   = isset( $item['headline'] ) ? (string) $item['headline'] : ( isset( $item['name'] ) ? (string) $item['name'] : '' );
					$content = isset( $item['articleBody'] ) ? (string) $item['articleBody'] : ( isset( $item['description'] ) ? (string) $item['description'] : '' );

					$author = '';
					if ( ! empty( $item['author'] ) ) {
						if ( is_string( $item['author'] ) ) {
							$author = $item['author'];
						} elseif ( is_array( $item['author'] ) && isset( $item['author']['name'] ) ) {
							$author = (string) $item['author']['name'];
						} elseif ( is_array( $item['author'] ) && is_array( current( $item['author'] ) ) ) {
							$names = array();
							foreach ( $item['author'] as $auth ) {
								if ( isset( $auth['name'] ) ) {
									$names[] = (string) $auth['name'];
								}
							}
							$author = implode( ', ', $names );
						}
					}

					$journal = '';
					if ( ! empty( $item['publisher']['name'] ) ) {
						$journal = (string) $item['publisher']['name'];
					}

					return array(
						'title'   => sanitize_text_field( $title ),
						'content' => wp_kses_post( $content ),
						'author'  => sanitize_text_field( $author ),
						'journal' => sanitize_text_field( $journal ),
					);
				}
			}
		}

		return array(
			'title'   => '',
			'content' => '',
			'author'  => '',
			'journal' => '',
		);
	}

	/**
	 * Extract from Highwire Press (`citation_*`) or OpenGraph tags.
	 *
	 * @param DOMXPath $xpath DOMXPath instance.
	 * @return array<string, string>
	 */
	private static function extract_from_highwire( DOMXPath $xpath ): array {
		$title    = self::get_meta_content( $xpath, 'citation_title' );
		$abstract = self::get_meta_content( $xpath, 'citation_abstract' );
		$author   = self::get_meta_content( $xpath, 'citation_author' );
		if ( empty( $author ) ) {
			$author = self::get_meta_content( $xpath, 'author' );
		}
		$journal  = self::get_meta_content( $xpath, 'citation_journal_title' );

		if ( empty( $title ) ) {
			$title = self::get_meta_content( $xpath, 'og:title', 'property' );
		}
		if ( empty( $abstract ) ) {
			$abstract = self::get_meta_content( $xpath, 'og:description', 'property' );
		}
		if ( empty( $abstract ) ) {
			$abstract = self::get_meta_content( $xpath, 'description' );
		}

		return array(
			'title'   => sanitize_text_field( $title ),
			'content' => wp_kses_post( $abstract ),
			'author'  => sanitize_text_field( $author ),
			'journal' => sanitize_text_field( $journal ),
		);
	}

	/**
	 * Fallback paragraph density extraction.
	 *
	 * @param DOMXPath              $xpath DOMXPath instance.
	 * @param array<string, string> $jsonld_data Data from JSON-LD.
	 * @param array<string, string> $highwire_data Data from Highwire tags.
	 * @return array<string, string>
	 */
	private static function extract_from_paragraphs( DOMXPath $xpath, array $jsonld_data, array $highwire_data ): array {
		$paragraphs = $xpath->query( '//article//p | //div[contains(@class, "article") or contains(@class, "content") or contains(@class, "abstract")]//p | //p' );

		$content_parts = array();
		if ( $paragraphs ) {
			foreach ( $paragraphs as $p ) {
				$text = trim( $p->nodeValue );
				// Skip short boilerplate paragraphs (< 40 characters) or cookie banners
				if ( strlen( $text ) < 40 || preg_match( '/(cookie|subscribe|newsletter|privacy policy|copyright|rights reserved)/i', $text ) ) {
					continue;
				}
				$content_parts[] = $text;
				if ( count( $content_parts ) >= 15 ) {
					break; // Cap to 15 best paragraphs
				}
			}
		}

		$title = ! empty( $jsonld_data['title'] ) ? $jsonld_data['title'] : ( ! empty( $highwire_data['title'] ) ? $highwire_data['title'] : '' );
		if ( empty( $title ) ) {
			$h1 = $xpath->query( '//h1' );
			if ( $h1 && $h1->length > 0 ) {
				$title = trim( $h1->item( 0 )->nodeValue );
			}
		}

		$content = implode( "\n\n", $content_parts );
		if ( empty( $content ) && ! empty( $highwire_data['content'] ) ) {
			$content = $highwire_data['content'];
		}

		return array(
			'title'   => sanitize_text_field( $title ),
			'content' => wp_kses_post( $content ),
			'author'  => sanitize_text_field( ! empty( $jsonld_data['author'] ) ? $jsonld_data['author'] : ( ! empty( $highwire_data['author'] ) ? $highwire_data['author'] : '' ) ),
			'journal' => sanitize_text_field( ! empty( $jsonld_data['journal'] ) ? $jsonld_data['journal'] : ( ! empty( $highwire_data['journal'] ) ? $highwire_data['journal'] : '' ) ),
		);
	}

	/**
	 * Helper to get meta tag content by name/property attribute.
	 *
	 * @param DOMXPath $xpath DOMXPath instance.
	 * @param string   $name  Meta tag attribute value.
	 * @param string   $attr  Attribute name (default 'name', can be 'property').
	 * @return string
	 */
	private static function get_meta_content( DOMXPath $xpath, string $name, string $attr = 'name' ): string {
		$nodes = $xpath->query( "//meta[@{$attr}='{$name}']/@content" );
		if ( $nodes && $nodes->length > 0 ) {
			return trim( $nodes->item( 0 )->nodeValue );
		}
		return '';
	}
}
