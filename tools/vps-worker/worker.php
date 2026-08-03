<?php
declare( strict_types=1 );
/**
 * Standalone VPS Worker for Peptide News Aggregator (v2.6.0).
 *
 * Designed to run on a dedicated Linux VPS (e.g., Hostinger KVM8) via cron
 * or command line. Communicates with your shared hosting WordPress server
 * via authenticated REST API (/wp-json/peptide-news/v1/worker/*) to offload
 * heavy RSS fetching, scraping, URL resolving, and 3-Step AI Article
 * generation without shared hosting CGI timeouts or IP rate limits.
 *
 * Usage:
 *   php worker.php --url="https://your-wordpress-site.com" --token="SECRET_VPS_TOKEN" --action="process-llm" --batch=20
 *   php worker.php --url="https://your-wordpress-site.com" --token="SECRET_VPS_TOKEN" --action="pending"
 *
 * @package PeptideNews
 * @since   2.6.0
 */

$options = getopt( '', array( 'url:', 'token:', 'action:', 'batch::', 'openrouter_key::', 'model::', 'help::' ) );

$wp_url  = rtrim( (string) ( $options['url'] ?? getenv( 'WP_URL' ) ?: '' ), '/' );
$token   = (string) ( $options['token'] ?? getenv( 'VPS_TOKEN' ) ?: '' );
$action  = (string) ( $options['action'] ?? getenv( 'WORKER_ACTION' ) ?: 'pending' );
$batch   = (int) ( $options['batch'] ?? getenv( 'BATCH_SIZE' ) ?: 20 );
$api_key = (string) ( $options['openrouter_key'] ?? getenv( 'OPENROUTER_KEY' ) ?: '' );
$model   = (string) ( $options['model'] ?? getenv( 'OPENROUTER_MODEL' ) ?: 'google/gemini-2.0-flash-001' );

if ( isset( $options['help'] ) || empty( $wp_url ) || empty( $token ) ) {
	echo "Peptide News VPS Worker (v2.6.0)\n";
	echo "---------------------------------\n";
	echo "Usage: php worker.php --url=https://site.com --token=YOUR_TOKEN --action=[action]\n";
	echo "Or set environment variables (WP_URL, VPS_TOKEN, WORKER_ACTION, OPENROUTER_KEY, BATCH_SIZE) for Coolify/Docker.\n\n";
	echo "Required configuration:\n";
	echo "  --url / WP_URL               WordPress site URL (e.g. https://yoursite.com)\n";
	echo "  --token / VPS_TOKEN          VPS Worker Token configured in WP Admin Settings\n";
	echo "  --action / WORKER_ACTION     Action to perform:\n";
	echo "                                 - 'pending': Fetch and list pending articles from WordPress\n";
	echo "                                 - 'process-llm': Pull pending articles, run 3-Step AI Pipeline, and sync back to WP\n\n";
	echo "Optional configuration:\n";
	echo "  --batch=<n> / BATCH_SIZE     Batch size for processing (default: 20)\n";
	echo "  --openrouter_key / OPENROUTER_KEY OpenRouter API key (if running LLM on VPS directly)\n";
	echo "  --model / OPENROUTER_MODEL   OpenRouter model ID (default: google/gemini-2.0-flash-001)\n";
	exit( 0 );
}

function vps_log( string $msg ): void {
	echo '[' . date( 'Y-m-d H:i:s' ) . "] {$msg}\n";
}

function wp_rest_call( string $url, string $token, string $method = 'GET', array $payload = array() ) {
	$ch = curl_init();
	$headers = array(
		'X-Peptide-News-Token: ' . $token,
		'Accept: application/json',
	);

	if ( $method === 'POST' ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		$json = json_encode( $payload );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $json );
		$headers[] = 'Content-Type: application/json';
	}

	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );

	$response = curl_exec( $ch );
	$status   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err      = curl_error( $ch );
	curl_close( $ch );

	if ( $response === false || ! empty( $err ) ) {
		vps_log( "ERROR: REST API cURL request to {$url} failed: {$err}" );
		return null;
	}

	if ( $status >= 400 ) {
		vps_log( "ERROR: REST API request to {$url} returned HTTP {$status}: {$response}" );
		return null;
	}

	return json_decode( (string) $response, true );
}

if ( $action === 'pending' ) {
	vps_log( "Fetching pending articles from {$wp_url}/wp-json/peptide-news/v1/worker/pending ..." );
	$res = wp_rest_call( "{$wp_url}/wp-json/peptide-news/v1/worker/pending?limit={$batch}", $token );
	if ( ! $res || empty( $res['success'] ) ) {
		vps_log( "Failed to retrieve pending articles." );
		exit( 1 );
	}
	vps_log( "Found " . $res['count'] . " pending articles." );
	foreach ( $res['data'] as $art ) {
		vps_log( " - [#{$art['id']}] {$art['title']}" );
	}
	exit( 0 );
}

if ( $action === 'process-llm' ) {
	if ( empty( $api_key ) ) {
		vps_log( "ERROR: --openrouter_key is required for --action=process-llm" );
		exit( 1 );
	}
	vps_log( "Pulling up to {$batch} pending articles from WordPress..." );
	$res = wp_rest_call( "{$wp_url}/wp-json/peptide-news/v1/worker/pending?limit={$batch}", $token );
	if ( ! $res || empty( $res['success'] ) || empty( $res['data'] ) ) {
		vps_log( "No pending articles found or failed to retrieve." );
		exit( 0 );
	}

	foreach ( $res['data'] as $art ) {
		vps_log( "Processing Article #{$art['id']}: {$art['title']} ..." );

		// Step 1: Study Classification
		$class_prompt = "Classify this scientific article and assign a rigor score (1-10).\nTitle: {$art['title']}\nContent: " . substr( strip_tags( $art['content'] ), 0, 2000 );
		$class_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'study_type'  => array( 'type' => 'string' ),
				'rigor_score' => array( 'type' => 'integer' ),
				'is_spam'     => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'study_type', 'rigor_score', 'is_spam' ),
			'additionalProperties' => false,
		);

		// Helper for OpenRouter API with retry & exponential backoff on HTTP 429/5xx
		$call_llm = function( array $messages, array $schema ) use ( $api_key, $model ) {
			$body = array(
				'model'           => $model,
				'messages'        => $messages,
				'response_format' => array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => 'peptide_schema',
						'strict' => true,
						'schema' => $schema,
					),
				),
			);
			$json_body = json_encode( $body );

			$max_retries = 3;
			for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
				$ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' );
				curl_setopt_array( $ch, array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $json_body,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER     => array(
						'Authorization: Bearer ' . $api_key,
						'Content-Type: application/json',
					),
					CURLOPT_TIMEOUT        => 120,
				) );
				$resp        = curl_exec( $ch );
				$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				$err         = curl_error( $ch );
				curl_close( $ch );

				if ( $resp === false || ! empty( $err ) ) {
					vps_log( "   [OpenRouter cURL Error] {$err} (attempt {$attempt}/{$max_retries})" );
				} elseif ( $status_code === 429 || $status_code >= 500 ) {
					vps_log( "   [OpenRouter HTTP {$status_code}] Rate limited or server error (attempt {$attempt}/{$max_retries})" );
				} else {
					$data = json_decode( (string) $resp, true );
					if ( ! empty( $data['choices'][0]['message']['content'] ) ) {
						return json_decode( $data['choices'][0]['message']['content'], true );
					}
					return null;
				}

				if ( $attempt < $max_retries ) {
					sleep( (int) pow( 2, $attempt ) );
				}
			}
			return null;
		};

		$classification = $call_llm( array( array( 'role' => 'user', 'content' => $class_prompt ) ), $class_schema );
		if ( ! $classification ) {
			vps_log( " - LLM classification failed for Article #{$art['id']}." );
			continue;
		}

		$rigor_score = (int) ( $classification['rigor_score'] ?? 5 );
		$study_type  = $classification['study_type'] ?? 'Comprehensive Review';

		if ( ( ! empty( $classification['is_spam'] ) && $classification['is_spam'] ) || $rigor_score < 3 ) {
			vps_log( " - Rejection Gatekeeper: Score {$rigor_score} (< 3). Rejecting article." );
			wp_rest_call( "{$wp_url}/wp-json/peptide-news/v1/worker/update", $token, 'POST', array(
				'id'          => $art['id'],
				'rigor_score' => $rigor_score,
				'study_type'  => 'Rejected (' . $study_type . ')',
				'ai_summary'  => 'Rejected by Scientific Rigor Gatekeeper (Score < 3).',
				'tags'        => 'rejected',
				'is_active'   => 0,
			) );
			continue;
		}

		// Step 2 & 3: Extraction & Summary Generation
		$gen_prompt = "Write a 300-word Markdown science news brief with standard headers (## Executive Summary, ## Scientific Context & Mechanism, ## Key Findings & Data, ## Clinical/Research Relevance, ## Limitations & Caveats) for:\nTitle: {$art['title']}\nContent: " . substr( strip_tags( $art['content'] ), 0, 4000 );
		$gen_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'markdown_summary' => array( 'type' => 'string' ),
				'tags'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
			'required'             => array( 'markdown_summary', 'tags' ),
			'additionalProperties' => false,
		);

		$generation = $call_llm( array( array( 'role' => 'user', 'content' => $gen_prompt ) ), $gen_schema );
		if ( ! $generation ) {
			vps_log( " - LLM summary generation failed for Article #{$art['id']}." );
			continue;
		}

		$tags_str   = implode( ', ', $generation['tags'] ?? array( 'peptide research' ) );
		$summary    = $generation['markdown_summary'] ?? '';

		vps_log( " - Successfully generated summary ({$rigor_score}/10, {$study_type}). Updating WordPress..." );
		$update_res = wp_rest_call( "{$wp_url}/wp-json/peptide-news/v1/worker/update", $token, 'POST', array(
			'id'          => $art['id'],
			'rigor_score' => $rigor_score,
			'study_type'  => $study_type,
			'tags'        => $tags_str,
			'ai_summary'  => $summary,
			'ai_metadata' => array( 'classification' => $classification, 'vps_processed_at' => date( 'c' ) ),
		) );

		if ( $update_res && ! empty( $update_res['success'] ) ) {
			vps_log( " - Article #{$art['id']} synced successfully." );
		}
	}
	exit( 0 );
}

vps_log( "Unknown action: {$action}" );
exit( 1 );
