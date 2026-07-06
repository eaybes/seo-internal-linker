<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a friendly, SEO/AEO-optimised TL;DR bullet list for posts (and
 * optionally pages) using the Claude API. Bullets are stored in post meta and
 * injected at the top of the post content via the_content filter so they never
 * interfere with SIL_Linker's post_content rewriting.
 */
class SIL_TLDR {

	private static $instance = null;

	// Tracks post IDs currently being processed to prevent double-firing
	// when SIL_Linker's wp_update_post call triggers a nested save_post.
	private static $processing_ids = array();

	const META_DISABLED = '_sil_tldr_disabled'; // posts: opt-out  ('1')
	const META_ENABLED  = '_sil_tldr_enabled';  // pages: opt-in   ('1')
	const META_BULLETS  = '_sil_tldr_bullets';  // stored bullet array

	// Maximum words of post content sent to the API to stay well inside
	// the token budget while still capturing the full article.
	const MAX_WORDS = 700;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post', array( $this, 'maybe_generate' ), 25, 3 );
		add_filter( 'the_content', array( $this, 'prepend_tldr' ) );
		add_action( 'wp_head', array( $this, 'output_styles' ) );
	}

	// -------------------------------------------------------------------------
	// Public helpers (used by meta box and admin)
	// -------------------------------------------------------------------------

	public function should_process( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( 'post' === $post->post_type ) {
			return '1' !== get_post_meta( $post_id, self::META_DISABLED, true );
		}

		if ( 'page' === $post->post_type ) {
			return '1' === get_post_meta( $post_id, self::META_ENABLED, true );
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Generation
	// -------------------------------------------------------------------------

	public function maybe_generate( $post_id, $post, $update ) {
		if ( in_array( $post_id, self::$processing_ids, true ) ) {
			error_log( "SIL_TLDR: skipping post {$post_id} — already processing" );
			return;
		}

		if ( ! $this->should_process( $post_id, $post ) ) {
			error_log( "SIL_TLDR: should_process returned false for post {$post_id} (type={$post->post_type}, status={$post->post_status})" );
			return;
		}

		$settings = SIL_Settings::get();
		if ( empty( $settings['gemini_api_key'] ) ) {
			error_log( "SIL_TLDR: no Gemini API key configured" );
			return;
		}

		$plain_text = wp_strip_all_tags( $post->post_content );
		if ( '' === trim( $plain_text ) ) {
			error_log( "SIL_TLDR: post {$post_id} has empty content after stripping tags" );
			return;
		}

		error_log( "SIL_TLDR: calling Gemini API for post {$post_id}" );

		self::$processing_ids[] = $post_id;

		$bullets = $this->call_api( $post->post_title, $plain_text, $settings['gemini_api_key'] );

		self::$processing_ids = array_values(
			array_filter( self::$processing_ids, function ( $id ) use ( $post_id ) {
				return $id !== $post_id;
			} )
		);

		if ( ! empty( $bullets ) ) {
			update_post_meta( $post_id, self::META_BULLETS, $bullets );
		}
	}

	private function call_api( $title, $plain_text, $api_key ) {
		$truncated = wp_trim_words( $plain_text, self::MAX_WORDS );

		$system_instruction = "You are an SEO and AEO expert that writes article summaries.\n"
			. "Output format: exactly 5 plain-text lines, one per line, nothing else.\n"
			. "Each line is one complete summary sentence.\n"
			. "No bullet characters, no numbers, no markdown, no intro, no outro.\n"
			. "Detect the language of the article and write in that same language.\n"
			. "Each sentence must be under 150 characters, keyword-rich, and self-contained.";

		$user_message = "Write a 5-line TL;DR summary for the following article.\n\n"
			. "Title: {$title}\n\n"
			. "Article:\n{$truncated}";

		// API key is passed as a URL query parameter per Google's auth model.
		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
			. rawurlencode( $api_key );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array( array( 'text' => $system_instruction ) ),
						),
						'contents' => array(
							array(
								'role'  => 'user',
								'parts' => array( array( 'text' => $user_message ) ),
							),
						),
						'generationConfig' => array(
							'maxOutputTokens' => 1024,
							'temperature'     => 0.3,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'SIL_TLDR: wp_remote_post error — ' . $response->get_error_message() );
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			error_log( "SIL_TLDR: Gemini API returned HTTP {$code} — " . wp_remote_retrieve_body( $response ) );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = isset( $body['candidates'][0]['content']['parts'][0]['text'] )
			? trim( $body['candidates'][0]['content']['parts'][0]['text'] )
			: '';

		if ( '' === $text ) {
			error_log( 'SIL_TLDR: Gemini returned empty text. Body: ' . wp_remote_retrieve_body( $response ) );
			return array();
		}

		error_log( 'SIL_TLDR: Gemini returned ' . count( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) ) . ' bullets' );

		$lines = array_values( array_filter( array_map( function( $line ) {
			$line = trim( $line );
			// Strip leading markdown bullet/number characters Gemini sometimes adds.
			$line = preg_replace( '/^[\*\-•]\s+/', '', $line );
			$line = preg_replace( '/^\d+[\.\)]\s+/', '', $line );
			// Strip residual bold markers.
			$line = str_replace( array( '**', '__' ), '', $line );
			return trim( $line );
		}, explode( "\n", $text ) ) ) );
		$bullets = array_slice( $lines, 0, 5 );

		// If fewer than 5 bullets returned, log it for debugging.
		if ( count( $bullets ) < 5 ) {
			error_log( 'SIL_TLDR: only ' . count( $bullets ) . ' bullets returned — expected 5' );
		}

		return $bullets;
	}

	// -------------------------------------------------------------------------
	// Front-end rendering
	// -------------------------------------------------------------------------

	public function prepend_tldr( $content ) {
		if ( ! is_singular() || is_admin() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! $this->should_process( $post_id, $post ) ) {
			return $content;
		}

		$bullets = get_post_meta( $post_id, self::META_BULLETS, true );
		if ( empty( $bullets ) || ! is_array( $bullets ) ) {
			return $content;
		}

		$settings = SIL_Settings::get();
		$label    = ! empty( $settings['tldr_section_name'] )
			? $settings['tldr_section_name']
			: 'TL;DR 😎';

		// Schema.org ItemList gives AI/answer engines a clear signal that
		// these are the key takeaways from the article.
		$html  = '<section class="sil-tldr-box" aria-label="' . esc_attr( $label ) . '" '
			. 'itemscope itemtype="https://schema.org/ItemList">';
		$html .= '<p class="sil-tldr-title"><strong>' . esc_html( $label ) . '</strong></p>';
		$html .= '<ul>';

		foreach ( $bullets as $i => $bullet ) {
			$html .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">'
				. '<meta itemprop="position" content="' . ( $i + 1 ) . '" />'
				. esc_html( $bullet )
				. '</li>';
		}

		$html .= '</ul></section>';

		return $html . $content;
	}

	public function output_styles() {
		if ( ! is_singular() ) {
			return;
		}
		?>
		<style id="sil-tldr-styles">
		.sil-tldr-box {
			border: 2px solid #d0d0d0;
			border-radius: 8px;
			padding: 16px 20px 12px;
			margin: 0 0 28px;
			background: #f9f9f9;
		}
		.sil-tldr-title {
			margin: 0 0 10px;
			font-size: 1em;
			display: block;
		}
		.sil-tldr-box ul {
			margin: 0;
			padding-left: 22px;
		}
		.sil-tldr-box li {
			margin-bottom: 6px;
			line-height: 1.55;
		}
		</style>
		<?php
	}
}
