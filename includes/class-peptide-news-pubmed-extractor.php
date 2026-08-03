<?php
declare( strict_types=1 );

/**
 * Extracts structured scientific metadata and abstracts from PubMed URLs
 * using the official NCBI E-utilities API (efetch.fcgi).
 *
 * Enforces a mandatory 350ms sleep throttle to comply with NCBI IP rate limits
 * (< 3 req/sec without API key, < 10 req/sec with API key).
 *
 * @since 2.6.0
 */
class Peptide_News_PubMed_Extractor {

	/**
	 * Extract PMID from a PubMed/NCBI URL.
	 *
	 * @param string $url The article URL.
	 * @return string|null The PMID or null if not a PubMed URL.
	 */
	public static function get_pmid( string $url ): ?string {
		if ( preg_match( '/(?:pubmed\.ncbi\.nlm\.nih\.gov|ncbi\.nlm\.nih\.gov\/pubmed)\/(\d+)/i', $url, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Fetch article metadata and structured abstract via NCBI E-utilities API.
	 *
	 * @param string $pmid PubMed article ID.
	 * @return array<string, string> Extracted data array with 'title', 'abstract', 'authors', 'journal'. Empty array on failure.
	 */
	public static function fetch_by_pmid( string $pmid ): array {
		if ( empty( $pmid ) ) {
			return array();
		}

		// Enforce mandatory 350ms sleep throttle (< 3 req/sec per IP).
		usleep( 350000 );

		$args = array(
			'db'      => 'pubmed',
			'id'      => absint( $pmid ),
			'retmode' => 'xml',
			'tool'    => 'peptide-news-plugin',
			'email'   => get_option( 'admin_email', 'admin@example.com' ),
		);

		$api_key = get_option( 'peptide_news_ncbi_api_key', '' );
		if ( ! empty( $api_key ) && is_string( $api_key ) ) {
			$args['api_key'] = trim( $api_key );
		}

		$api_url  = add_query_arg( $args, 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi' );
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'    => 15,
				'user-agent' => 'PeptideNewsBot/2.6.0 (+https://peptiderepo.com)',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Peptide_News_Logger::warning( 'NCBI E-utilities fetch failed for PMID ' . $pmid, 'fetcher' );
			return array();
		}

		$xml_body = wp_remote_retrieve_body( $response );
		return self::parse_efetch_xml( (string) $xml_body );
	}

	/**
	 * Parse NCBI E-utilities PubMed XML response into structured article data.
	 *
	 * @param string $xml_body
	 * @return array
	 */
	public static function parse_efetch_xml( string $xml_body ): array {
		if ( strlen( $xml_body ) > 512000 ) {
			$xml_body = function_exists( 'mb_strcut' ) ? mb_strcut( $xml_body, 0, 512000, 'UTF-8' ) : substr( $xml_body, 0, 512000 );
		}
		$libxml_prev = libxml_use_internal_errors( true );
		$xml         = simplexml_load_string( (string) $xml_body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_prev );

		if ( ! $xml || empty( $xml->PubmedArticle ) ) {
			return array();
		}

		$article = $xml->PubmedArticle[0]->MedlineCitation->Article;
		$title   = (string) $article->ArticleTitle;

		$abstract_parts = array();
		if ( ! empty( $article->Abstract->AbstractText ) ) {
			foreach ( $article->Abstract->AbstractText as $text_node ) {
				$label = (string) $text_node['Label'];
				$text  = trim( (string) $text_node );
				if ( ! empty( $label ) && 'UNLABELLED' !== strtoupper( $label ) ) {
					$abstract_parts[] = '<strong>' . esc_html( ucfirst( strtolower( $label ) ) ) . ':</strong> ' . esc_html( $text );
				} else {
					$abstract_parts[] = esc_html( $text );
				}
			}
		}
		$abstract = implode( "\n\n", $abstract_parts );

		$authors = array();
		if ( ! empty( $article->AuthorList->Author ) ) {
			foreach ( $article->AuthorList->Author as $author ) {
				$name = trim( ((string) $author->ForeName) . ' ' . ((string) $author->LastName) );
				if ( ! empty( $name ) ) {
					$authors[] = $name;
				}
			}
		}

		$journal = '';
		if ( ! empty( $article->Journal->Title ) ) {
			$journal = (string) $article->Journal->Title;
		}

		return array(
			'title'    => sanitize_text_field( $title ),
			'abstract' => wp_kses_post( $abstract ),
			'authors'  => sanitize_text_field( implode( ', ', $authors ) ),
			'journal'  => sanitize_text_field( $journal ),
		);
	}
}
