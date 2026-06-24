<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SIL_Linker {

	private static $instance = null;
	private $is_saving       = false;

	const META_DISABLED       = '_sil_disabled';       // posts: opt-out
	const META_ENABLED        = '_sil_enabled';        // pages: opt-in
	const META_SKIP_PHRASES   = '_sil_skip_phrases';   // phrase IDs manually unlinked on this post
	const META_ACTIVE_PHRASES = '_sil_active_phrases'; // phrase IDs currently auto-linked on this post

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post', array( $this, 'maybe_process_post' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'release_usage_on_delete' ) );
	}

	/**
	 * Keeps usage_count accurate when a post carrying auto-links is deleted
	 * outright (trashing alone doesn't remove content, so it's left alone).
	 */
	public function release_usage_on_delete( $post_id ) {
		foreach ( $this->get_id_meta_list( $post_id, self::META_ACTIVE_PHRASES ) as $id ) {
			SIL_DB::decrement_usage( $id );
		}
	}

	/**
	 * Whether automatic linking should run on this post.
	 */
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

	public function maybe_process_post( $post_id, $post, $update ) {
		if ( $this->is_saving ) {
			return;
		}

		if ( ! $this->should_process( $post_id, $post ) ) {
			return;
		}

		$this->relink_post( $post_id );
	}

	/**
	 * Re-runs linking on a single post, replacing any previously auto-inserted
	 * links so phrase/target changes (or deletions) take effect. Phrases the
	 * editor manually unlinked on this post (detected) or explicitly disabled
	 * (via the post meta box) are skipped.
	 */
	public function relink_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$raw_content      = $post->post_content;
		$ids_before       = $this->extract_link_ids( $raw_content );
		$previously_active = $this->get_id_meta_list( $post_id, self::META_ACTIVE_PHRASES );

		// Phrases that were active last time we ran but no longer appear as
		// a sil-link now were manually removed by the editor; remember that.
		$manually_removed = array_diff( $previously_active, $ids_before );
		if ( ! empty( $manually_removed ) ) {
			$skip_list = array_unique( array_merge( $this->get_id_meta_list( $post_id, self::META_SKIP_PHRASES ), $manually_removed ) );
			update_post_meta( $post_id, self::META_SKIP_PHRASES, $skip_list );
			// These were already gone from the raw content (the editor deleted
			// the <a> tag directly), so strip_auto_links() below won't see them
			// to decrement usage_count itself - do it here instead.
			foreach ( $manually_removed as $id ) {
				SIL_DB::decrement_usage( $id );
			}
		}

		$skip_list = $this->get_id_meta_list( $post_id, self::META_SKIP_PHRASES );

		$phrases = array_filter(
			SIL_DB::get_all(),
			function ( $phrase ) use ( $skip_list ) {
				return ! in_array( (int) $phrase->id, $skip_list, true );
			}
		);

		list( $clean_content, $removed_ids ) = $this->strip_auto_links( $raw_content );
		foreach ( $removed_ids as $id ) {
			SIL_DB::decrement_usage( $id );
		}

		$new_content = $this->link_phrases( $clean_content, $phrases );

		$ids_after = $this->extract_link_ids( $new_content );
		update_post_meta( $post_id, self::META_ACTIVE_PHRASES, array_values( $ids_after ) );

		if ( $new_content === $raw_content ) {
			return;
		}

		$this->is_saving = true;
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			)
		);
		$this->is_saving = false;
	}

	private function get_id_meta_list( $post_id, $key ) {
		$list = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_map( 'absint', $list );
	}

	private function extract_link_ids( $content ) {
		if ( false === strpos( $content, 'data-sil-id' ) ) {
			return array();
		}
		preg_match_all( '/data-sil-id="(\d+)"/', $content, $matches );
		return array_unique( array_map( 'absint', $matches[1] ) );
	}

	/**
	 * Removes any <a class="sil-link"> wrappers added by this plugin, leaving
	 * the plain phrase text behind, so re-processing starts from a clean slate.
	 * Returns [ $clean_content, $removed_phrase_ids ].
	 */
	public function strip_auto_links( $content ) {
		if ( false === strpos( $content, 'sil-link' ) ) {
			return array( $content, array() );
		}

		$dom = $this->load_html( $content );
		if ( ! $dom ) {
			return array( $content, array() );
		}

		$root  = $this->get_root( $dom );
		$xpath = new DOMXPath( $dom );
		$links = $xpath->query( './/a[contains(concat(" ", normalize-space(@class), " "), " sil-link ")]', $root );

		$removed_ids = array();
		foreach ( $links as $link ) {
			$id = $link->getAttribute( 'data-sil-id' );
			if ( '' !== $id ) {
				$removed_ids[] = absint( $id );
			}
			$text_node = $dom->createTextNode( $link->textContent );
			$link->parentNode->replaceChild( $text_node, $link );
		}

		return array( $this->save_inner_html( $dom, $root ), array_unique( $removed_ids ) );
	}

	/**
	 * Walks the post content and links the first plain-text occurrence of
	 * each phrase. A phrase that already appears inside an existing link or
	 * a heading is considered "used" and is skipped entirely.
	 */
	public function link_phrases( $content, $phrases ) {
		if ( empty( $phrases ) || '' === trim( $content ) ) {
			return $content;
		}

		$dom = $this->load_html( $content );
		if ( ! $dom ) {
			return $content;
		}

		$root  = $this->get_root( $dom );
		$xpath = new DOMXPath( $dom );

		foreach ( $phrases as $phrase ) {
			$phrase_text = trim( $phrase->phrase );
			if ( '' === $phrase_text ) {
				continue;
			}

			$text_nodes = $xpath->query( './/text()', $root );

			foreach ( $text_nodes as $node ) {
				$pos = mb_strpos( $node->nodeValue, $phrase_text );
				if ( false === $pos ) {
					continue;
				}

				if ( $this->is_inside_excluded( $node ) ) {
					// Already appears inside a link/heading: count it as
					// "used" and don't add another link for this phrase.
					break;
				}

				$this->wrap_occurrence( $dom, $node, $pos, $phrase_text, $phrase );
				SIL_DB::increment_usage( $phrase->id );
				break;
			}
		}

		return $this->save_inner_html( $dom, $root );
	}

	private function wrap_occurrence( $dom, $node, $pos, $phrase_text, $phrase ) {
		$haystack = $node->nodeValue;
		$before   = mb_substr( $haystack, 0, $pos );
		$after    = mb_substr( $haystack, $pos + mb_strlen( $phrase_text ) );

		// Defense in depth: re-validate the stored URL has an allowed scheme
		// before it ever gets written into post content.
		$safe_url = esc_url( $phrase->target_url, array( 'http', 'https' ) );
		if ( '' === $safe_url ) {
			return;
		}

		$settings = SIL_Settings::get();

		$anchor = $dom->createElement( 'a' );
		$anchor->setAttribute( 'href', $safe_url );
		$anchor->setAttribute( 'class', 'sil-link' );
		$anchor->setAttribute( 'data-sil-id', (string) absint( $phrase->id ) );
		if ( ! empty( $settings['open_in_new_tab'] ) ) {
			$anchor->setAttribute( 'target', '_blank' );
			$anchor->setAttribute( 'rel', 'noopener noreferrer' );
		}
		$anchor->appendChild( $dom->createTextNode( $phrase_text ) );

		$parent = $node->parentNode;
		$parent->insertBefore( $dom->createTextNode( $before ), $node );
		$parent->insertBefore( $anchor, $node );
		$parent->insertBefore( $dom->createTextNode( $after ), $node );
		$parent->removeChild( $node );
	}

	private $excluded_tags = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style' );

	private function is_inside_excluded( $node ) {
		$ancestor = $node->parentNode;
		while ( $ancestor && XML_ELEMENT_NODE === $ancestor->nodeType ) {
			if ( in_array( strtolower( $ancestor->nodeName ), $this->excluded_tags, true ) ) {
				return true;
			}
			$ancestor = $ancestor->parentNode;
		}
		return false;
	}

	private function load_html( $content ) {
		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$prev_entity_state = null;
		// libxml_disable_entity_loader() is deprecated/no-op on PHP 8+ because
		// modern libxml already disables external entity loading by default;
		// only call it on PHP < 8 where that default isn't guaranteed.
		if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$prev_entity_state = libxml_disable_entity_loader( true );
		}
		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="sil-root">' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		if ( null !== $prev_entity_state ) {
			libxml_disable_entity_loader( $prev_entity_state );
		}

		if ( ! $this->get_root( $dom ) ) {
			return null;
		}

		return $dom;
	}

	private function get_root( $dom ) {
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( "//*[@id='sil-root']" );
		return $nodes->length ? $nodes->item( 0 ) : null;
	}

	private function save_inner_html( $dom, $root ) {
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}
}
