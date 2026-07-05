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
			return;
		}

		if ( ! $this->should_process( $post_id, $post ) ) {
			return;
		}

		$settings = SIL_Settings::get();
		if ( empty( $settings['anthropic_api_key'] ) ) {
			return;
		}

		$plain_text = wp_strip_all_tags( $post->post_content );
		if ( '' === trim( $plain_text ) ) {
			return;
		}

		self::$processing_ids[] = $post_id;

		$bullets = $this->call_api( $post->post_title, $plain_text, $settings['anthropic_api_key'] );

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

		$prompt = "You are an SEO and AEO (Answer Engine Optimization) expert. "
			. "Read the article below and write a TL;DR summary as bullet points.\n\n"
			. "Rules:\n"
			. "- Write up to 5 bullets, at least 1\n"
			. "- Each bullet is a concise, complete sentence (under 130 characters)\n"
			. "- Use a friendly, conversational but informative tone\n"
			. "- Include important keywords naturally for SEO\n"
			. "- Write each bullet as a standalone fact or takeaway, optimised for "
			. "voice search and featured snippets (AEO)\n"
			. "- Do NOT use markdown (no **, no -, no #)\n"
			. "- Return ONLY the bullet lines, one per line, with no intro or outro\n\n"
			. "Article title: {$title}\n\n"
			. "Article content:\n{$truncated}";

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body' => wp_json_encode(
					array(
						'model'      => 'claude-haiku-4-5-20251001',
						'max_tokens' => 512,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = isset( $body['content'][0]['text'] ) ? trim( $body['content'][0]['text'] ) : '';

		if ( '' === $text ) {
			return array();
		}

		$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );
		$bullets = array_slice( $lines, 0, 5 );

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
