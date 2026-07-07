<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SIL_Admin {

	private static $instance = null;
	const NONCE_ACTION = 'sil_admin_action';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_sil_save_phrase', array( $this, 'handle_save_phrase' ) );
		add_action( 'admin_post_sil_delete_phrase', array( $this, 'handle_delete_phrase' ) );
		add_action( 'admin_post_sil_rescan_all', array( $this, 'handle_rescan_all' ) );
		add_action( 'admin_post_sil_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_sil_clear_tldr', array( $this, 'handle_clear_tldr' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'SEO Internal Linker', 'sil' ),
			__( 'Internal Linker', 'sil' ),
			'manage_options',
			'sil-phrases',
			array( $this, 'render_page' ),
			'dashicons-admin-links'
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sil' ) );
		}

		$phrases    = SIL_DB::get_all();
		$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing    = $editing_id ? SIL_DB::get( $editing_id ) : null;
		$settings   = SIL_Settings::get();
		$rescan     = SIL_Rescan::instance();
		$progress   = $rescan->get_progress();
		$is_running = $rescan->is_running();

		$error = isset( $_GET['sil_error'] ) ? sanitize_text_field( wp_unslash( $_GET['sil_error'] ) ) : '';

		?>
		<div class="wrap" dir="auto">
			<h1><?php esc_html_e( 'SEO Internal Linker', 'sil' ); ?></h1>
			<p><?php esc_html_e( 'Define phrases and their target link. The first time a phrase appears in a post (or an enabled page), it will automatically be turned into a link.', 'sil' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved successfully.', 'sil' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Phrase deleted.', 'sil' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rescanned'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rescan started in the background. Progress is shown below.', 'sil' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['tldr_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'TL;DR cleared for that post.', 'sil' ); ?></p></div>
			<?php endif; ?>

			<!-- ============================================================ -->
			<!-- Phrase form                                                    -->
			<!-- ============================================================ -->
			<h2><?php echo $editing ? esc_html__( 'Edit phrase', 'sil' ) : esc_html__( 'Add new phrase', 'sil' ); ?></h2>

			<!-- SEO tip -->
			<div style="background:#fff8e1;border-left:4px solid #ffc107;padding:12px 16px;margin:0 0 16px;border-radius:0 4px 4px 0;max-width:700px;">
				<p style="margin:0;font-size:13px;">
					<strong><?php esc_html_e( '💡 Tip: Choose your anchor text strategically.', 'sil' ); ?></strong><br />
					<?php esc_html_e( 'Use clear, specific phrases instead of broad words or just brand names. Pick phrases that describe the linked page well and have a good chance of ranking in search results.', 'sil' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="sil_save_phrase" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>" />
				<table class="form-table">
					<tr>
						<th><label for="sil_phrase"><?php esc_html_e( 'Phrase', 'sil' ); ?></label></th>
						<td><input type="text" id="sil_phrase" name="phrase" class="regular-text" maxlength="255" required value="<?php echo esc_attr( $editing ? $editing->phrase : '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="sil_url"><?php esc_html_e( 'Target URL', 'sil' ); ?></label></th>
						<td>
							<input type="url" id="sil_url" name="target_url" class="regular-text" required pattern="https?://.+" value="<?php echo esc_attr( $editing ? $editing->target_url : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Must be an http(s) URL.', 'sil' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Update phrase', 'sil' ) : __( 'Add phrase', 'sil' ) ); ?>
			</form>

			<hr />

			<!-- ============================================================ -->
			<!-- Phrases table                                                  -->
			<!-- ============================================================ -->
			<h2><?php esc_html_e( 'Existing phrases', 'sil' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Phrase', 'sil' ); ?></th>
						<th><?php esc_html_e( 'Target URL', 'sil' ); ?></th>
						<th style="width:110px;"><?php esc_html_e( 'Active links', 'sil' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Actions', 'sil' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $phrases ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No phrases yet.', 'sil' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $phrases as $p ) : ?>
							<tr>
								<td><?php echo esc_html( $p->phrase ); ?></td>
								<td><a href="<?php echo esc_url( $p->target_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $p->target_url ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( (int) $p->usage_count ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'sil-phrases', 'edit' => $p->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'sil' ); ?></a>
									|
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sil_delete_phrase&id=' . absint( $p->id ) ), self::NONCE_ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this phrase?', 'sil' ) ); ?>');"><?php esc_html_e( 'Delete', 'sil' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<hr />

			<!-- ============================================================ -->
			<!-- Settings                                                       -->
			<!-- ============================================================ -->
			<h2><?php esc_html_e( 'Settings', 'sil' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="sil_save_settings" />
				<table class="form-table">

					<tr>
						<th><?php esc_html_e( 'Link behavior', 'sil' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="open_in_new_tab" value="1" <?php checked( ! empty( $settings['open_in_new_tab'] ) ); ?> />
								<?php esc_html_e( 'Open auto-inserted links in a new tab', 'sil' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th colspan="2">
							<h3 style="margin:10px 0 0;"><?php esc_html_e( 'TL;DR Auto-Summary', 'sil' ); ?></h3>
						</th>
					</tr>

					<tr>
						<th><label for="sil_tldr_name"><?php esc_html_e( 'Section heading', 'sil' ); ?></label></th>
						<td>
							<input type="text" id="sil_tldr_name" name="tldr_section_name" class="regular-text"
								value="<?php echo esc_attr( $settings['tldr_section_name'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Label shown at the top of the TL;DR box on each post (default: "TL;DR 😎").', 'sil' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><label for="sil_api_key"><?php esc_html_e( 'Gemini API key', 'sil' ); ?></label></th>
						<td>
							<input type="password" id="sil_api_key" name="gemini_api_key"
								class="regular-text" autocomplete="new-password"
								placeholder="<?php echo ! empty( $settings['gemini_api_key'] ) ? esc_attr__( '(saved — leave blank to keep)', 'sil' ) : ''; ?>"
								value="" />
							<p class="description">
								<?php esc_html_e( 'Google Gemini API key used to generate TL;DR summaries. Leave blank to keep the existing key. Without a key, TL;DR generation is silently skipped.', 'sil' ); ?>
							</p>
							<?php if ( ! empty( $settings['gemini_api_key'] ) ) : ?>
								<p class="description" style="color:#2ea44f;">&#10003; <?php esc_html_e( 'API key is set.', 'sil' ); ?></p>
							<?php else : ?>
								<p class="description" style="color:#cf222e;">&#9888; <?php esc_html_e( 'No API key — TL;DR generation is disabled.', 'sil' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

				</table>
				<?php submit_button( __( 'Save settings', 'sil' ), 'secondary' ); ?>
			</form>

			<hr />

			<!-- ============================================================ -->
			<!-- Rescan                                                         -->
			<!-- ============================================================ -->
			<h2><?php esc_html_e( 'Rescan existing content', 'sil' ); ?></h2>
			<p><?php esc_html_e( 'Re-applies the current phrase list to all posts (and pages with internal linking enabled). Runs in the background in small batches so it will not time out on large sites.', 'sil' ); ?></p>

			<?php if ( $is_running ) : ?>
				<p>
					<strong><?php esc_html_e( 'Rescan in progress:', 'sil' ); ?></strong>
					<?php
					printf(
						/* translators: 1: processed count, 2: total count */
						esc_html__( '%1$d of %2$d processed.', 'sil' ),
						(int) $progress['processed'],
						(int) $progress['total']
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sil-phrases' ) ); ?>"><?php esc_html_e( 'Refresh', 'sil' ); ?></a>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="sil_rescan_all" />
					<?php submit_button( __( 'Rescan all content now', 'sil' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	public function handle_save_phrase() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sil' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$phrase = isset( $_POST['phrase'] ) ? wp_unslash( $_POST['phrase'] ) : '';
		$url    = isset( $_POST['target_url'] ) ? wp_unslash( $_POST['target_url'] ) : '';

		$result = $id ? SIL_DB::update( $id, $phrase, $url ) : SIL_DB::add( $phrase, $url );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'sil-phrases', 'sil_error' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sil-phrases&updated=1' ) );
		exit;
	}

	public function handle_delete_phrase() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sil' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id ) {
			SIL_DB::delete( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=sil-phrases&deleted=1' ) );
		exit;
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sil' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		SIL_Settings::update( $_POST );

		wp_safe_redirect( admin_url( 'admin.php?page=sil-phrases&updated=1' ) );
		exit;
	}

	public function handle_rescan_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sil' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		SIL_Rescan::instance()->start();

		wp_safe_redirect( admin_url( 'admin.php?page=sil-phrases&rescanned=1' ) );
		exit;
	}

	/**
	 * Clears the stored TL;DR bullets for a single post (accessible via the
	 * post edit screen's meta box "Clear TL;DR" button).
	 */
	public function handle_clear_tldr() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sil' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=sil-phrases' );

		if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
			delete_post_meta( $post_id, SIL_TLDR::META_BULLETS );
		}

		// Return to the post edit screen if possible, otherwise the plugin page.
		wp_safe_redirect( add_query_arg( 'tldr_cleared', '1', $redirect ) );
		exit;
	}
}
