<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post/page controls:
 * - Pages: opt-in checkbox to enable automatic linking (off by default).
 * - Posts: opt-out checkbox to disable automatic linking (on by default).
 * - Both: a checklist to re-enable phrases the editor previously unlinked
 *   (or to manually disable specific phrases) on this piece of content.
 */
class SIL_Post_Meta {

	private static $instance = null;
	const NONCE_FIELD  = 'sil_post_meta_nonce';
	const NONCE_ACTION = 'sil_save_post_meta';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_post', array( $this, 'save' ) );
		add_action( 'save_post_page', array( $this, 'save' ) );
	}

	public function add_meta_box() {
		add_meta_box(
			'sil_post_meta_box',
			__( 'SEO Internal Linker', 'sil' ),
			array( $this, 'render' ),
			array( 'post', 'page' ),
			'side',
			'default'
		);
	}

	public function render( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( 'page' === $post->post_type ) {
			$enabled = '1' === get_post_meta( $post->ID, SIL_Linker::META_ENABLED, true );
			?>
			<label>
				<input type="checkbox" name="sil_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable automatic SEO internal linking on this page', 'sil' ); ?>
			</label>
			<?php
		} else {
			$disabled = '1' === get_post_meta( $post->ID, SIL_Linker::META_DISABLED, true );
			?>
			<label>
				<input type="checkbox" name="sil_disabled" value="1" <?php checked( $disabled ); ?> />
				<?php esc_html_e( 'Disable automatic SEO internal linking on this post', 'sil' ); ?>
			</label>
			<?php
		}

		$skip_list = get_post_meta( $post->ID, SIL_Linker::META_SKIP_PHRASES, true );
		$skip_list = is_array( $skip_list ) ? array_map( 'absint', $skip_list ) : array();
		$phrases   = SIL_DB::get_all();

		if ( ! empty( $phrases ) ) {
			echo '<hr /><p>' . esc_html__( 'Disabled phrases on this content (uncheck to re-enable):', 'sil' ) . '</p>';
			echo '<div style="max-height:160px;overflow:auto;">';
			foreach ( $phrases as $phrase ) {
				$is_skipped = in_array( (int) $phrase->id, $skip_list, true );
				printf(
					'<label style="display:block;"><input type="checkbox" name="sil_skip_phrases[]" value="%1$d" %2$s /> %3$s</label>',
					(int) $phrase->id,
					checked( $is_skipped, true, false ),
					esc_html( $phrase->phrase )
				);
			}
			echo '</div>';
			echo '<p class="description">' . esc_html__( 'If you manually remove an auto-link, it is added here automatically so it stays removed.', 'sil' ) . '</p>';
		}
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['sil_enabled'] ) ) {
			update_post_meta( $post_id, SIL_Linker::META_ENABLED, '1' );
		} elseif ( get_post_type( $post_id ) === 'page' ) {
			update_post_meta( $post_id, SIL_Linker::META_ENABLED, '0' );
		}

		if ( isset( $_POST['sil_disabled'] ) ) {
			update_post_meta( $post_id, SIL_Linker::META_DISABLED, '1' );
		} elseif ( get_post_type( $post_id ) === 'post' ) {
			update_post_meta( $post_id, SIL_Linker::META_DISABLED, '0' );
		}

		$skip_list = isset( $_POST['sil_skip_phrases'] ) && is_array( $_POST['sil_skip_phrases'] )
			? array_map( 'absint', wp_unslash( $_POST['sil_skip_phrases'] ) )
			: array();

		update_post_meta( $post_id, SIL_Linker::META_SKIP_PHRASES, $skip_list );
	}
}
