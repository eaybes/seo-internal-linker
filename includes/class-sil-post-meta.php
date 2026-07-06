<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post/page controls rendered in the editor sidebar:
 *
 * Linking:
 *   - Pages: opt-in checkbox to enable automatic linking (off by default).
 *   - Posts: opt-out checkbox to disable automatic linking (on by default).
 *   - Both: a checklist to re-enable / disable individual phrases on this content.
 *
 * TL;DR:
 *   - Posts: opt-out checkbox to disable TL;DR generation (on by default).
 *   - Pages: opt-in checkbox to enable TL;DR generation (off by default).
 *   - Both: preview of current bullets + a "Clear TL;DR" button.
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

		$is_page = ( 'page' === $post->post_type );

		// ----------------------------------------------------------------
		// Internal linking controls
		// ----------------------------------------------------------------
		echo '<p style="font-weight:600;margin:0 0 6px;">' . esc_html__( 'Internal linking', 'sil' ) . '</p>';

		if ( $is_page ) {
			$enabled = '1' === get_post_meta( $post->ID, SIL_Linker::META_ENABLED, true );
			?>
			<label>
				<input type="checkbox" name="sil_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable auto-linking on this page', 'sil' ); ?>
			</label>
			<?php
		} else {
			$disabled = '1' === get_post_meta( $post->ID, SIL_Linker::META_DISABLED, true );
			?>
			<label>
				<input type="checkbox" name="sil_disabled" value="1" <?php checked( $disabled ); ?> />
				<?php esc_html_e( 'Disable auto-linking on this post', 'sil' ); ?>
			</label>
			<?php
		}

		$skip_list = get_post_meta( $post->ID, SIL_Linker::META_SKIP_PHRASES, true );
		$skip_list = is_array( $skip_list ) ? array_map( 'absint', $skip_list ) : array();
		$phrases   = SIL_DB::get_all();

		if ( ! empty( $phrases ) ) {
			echo '<p style="margin:10px 0 4px;font-style:italic;font-size:12px;">'
				. esc_html__( 'Disabled phrases on this content:', 'sil' )
				. '</p>';
			echo '<div style="max-height:140px;overflow:auto;border:1px solid #ddd;padding:4px 8px;border-radius:4px;">';
			foreach ( $phrases as $phrase ) {
				$is_skipped = in_array( (int) $phrase->id, $skip_list, true );
				printf(
					'<label style="display:block;font-size:12px;padding:2px 0;"><input type="checkbox" name="sil_skip_phrases[]" value="%1$d" %2$s /> %3$s</label>',
					(int) $phrase->id,
					checked( $is_skipped, true, false ),
					esc_html( $phrase->phrase )
				);
			}
			echo '</div>';
			echo '<p style="font-size:11px;color:#666;margin:4px 0 0;">'
				. esc_html__( 'Manually removed links are added here automatically.', 'sil' )
				. '</p>';
		}

		echo '<hr style="margin:14px 0;" />';

		// ----------------------------------------------------------------
		// TL;DR controls
		// ----------------------------------------------------------------
		$settings    = SIL_Settings::get();
		$has_api_key = ! empty( $settings['gemini_api_key'] );
		$section_name = ! empty( $settings['tldr_section_name'] )
			? $settings['tldr_section_name']
			: 'TL;DR 😎';

		echo '<p style="font-weight:600;margin:0 0 6px;">'
			. esc_html( $section_name )
			. ' ' . esc_html__( 'Summary', 'sil' ) . '</p>';

		if ( ! $has_api_key ) {
			echo '<p style="font-size:12px;color:#888;">'
				. esc_html__( 'Add a Gemini API key in the plugin settings to enable automatic TL;DR generation.', 'sil' )
				. '</p>';
		} else {
			if ( $is_page ) {
				$tldr_enabled = '1' === get_post_meta( $post->ID, SIL_TLDR::META_ENABLED, true );
				?>
				<label>
					<input type="checkbox" name="sil_tldr_enabled" value="1" <?php checked( $tldr_enabled ); ?> />
					<?php esc_html_e( 'Enable TL;DR summary on this page', 'sil' ); ?>
				</label>
				<?php
			} else {
				$tldr_disabled = '1' === get_post_meta( $post->ID, SIL_TLDR::META_DISABLED, true );
				?>
				<label>
					<input type="checkbox" name="sil_tldr_disabled" value="1" <?php checked( $tldr_disabled ); ?> />
					<?php esc_html_e( 'Disable TL;DR summary on this post', 'sil' ); ?>
				</label>
				<?php
			}
		}

		// Show Clear button only when bullets exist (summary is visible on the post itself).
		$bullets = get_post_meta( $post->ID, SIL_TLDR::META_BULLETS, true );
		if ( ! empty( $bullets ) && is_array( $bullets ) ) {
			$redirect_to = add_query_arg(
				array(
					'post'   => $post->ID,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:6px 0 0;">
				<?php wp_nonce_field( SIL_Admin::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="sil_clear_tldr" />
				<input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
				<p style="font-size:12px;color:#2a9d4e;margin:0 0 6px;">&#10003; <?php esc_html_e( 'Summary is live on the post.', 'sil' ); ?></p>
				<button type="submit" class="button button-small"
					onclick="return confirm('<?php echo esc_js( __( 'Clear TL;DR for this post?', 'sil' ) ); ?>');">
					<?php esc_html_e( 'Clear TL;DR', 'sil' ); ?>
				</button>
			</form>
			<?php
		} elseif ( $has_api_key ) {
			echo '<p style="font-size:12px;color:#888;margin:6px 0 0;">'
				. esc_html__( 'No TL;DR yet — it will be generated on the next save.', 'sil' )
				. '</p>';
		}
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$is_page = ( get_post_type( $post_id ) === 'page' );

		// --- Linking ---
		if ( $is_page ) {
			update_post_meta( $post_id, SIL_Linker::META_ENABLED, isset( $_POST['sil_enabled'] ) ? '1' : '0' );
		} else {
			update_post_meta( $post_id, SIL_Linker::META_DISABLED, isset( $_POST['sil_disabled'] ) ? '1' : '0' );
		}

		$skip_list = isset( $_POST['sil_skip_phrases'] ) && is_array( $_POST['sil_skip_phrases'] )
			? array_map( 'absint', wp_unslash( $_POST['sil_skip_phrases'] ) )
			: array();
		update_post_meta( $post_id, SIL_Linker::META_SKIP_PHRASES, $skip_list );

		// --- TL;DR ---
		if ( $is_page ) {
			update_post_meta( $post_id, SIL_TLDR::META_ENABLED, isset( $_POST['sil_tldr_enabled'] ) ? '1' : '0' );
		} else {
			update_post_meta( $post_id, SIL_TLDR::META_DISABLED, isset( $_POST['sil_tldr_disabled'] ) ? '1' : '0' );
		}
	}
}
