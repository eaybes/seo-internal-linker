<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SIL_DB {

	const DB_VERSION_OPTION = 'sil_db_version';
	const DB_VERSION        = '2';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'sil_phrases';
	}

	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phrase VARCHAR(255) NOT NULL,
			target_url VARCHAR(2048) NOT NULL,
			usage_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phrase (phrase)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Runs dbDelta() if the schema version has drifted, so upgrades (e.g. the
	 * usage_count column) apply without requiring deactivate/reactivate.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	public static function get_all() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY CHAR_LENGTH(phrase) DESC, phrase ASC" );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Validates and normalizes a phrase/URL pair. Returns WP_Error on failure.
	 */
	public static function sanitize_input( $phrase, $url ) {
		$phrase = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $phrase ) ) );
		// Only allow http/https (or scheme-relative/relative) targets -
		// blocks javascript:, data:, vbscript: and similar XSS vectors.
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );

		if ( '' === $phrase ) {
			return new WP_Error( 'sil_invalid_phrase', __( 'Phrase cannot be empty.', 'sil' ) );
		}

		if ( mb_strlen( $phrase ) > 255 ) {
			return new WP_Error( 'sil_phrase_too_long', __( 'Phrase is too long (max 255 characters).', 'sil' ) );
		}

		if ( '' === $url ) {
			return new WP_Error( 'sil_invalid_url', __( 'Target URL must be a valid http(s) URL.', 'sil' ) );
		}

		return array( $phrase, $url );
	}

	public static function add( $phrase, $url ) {
		global $wpdb;

		$clean = self::sanitize_input( $phrase, $url );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		list( $phrase, $url ) = $clean;

		$result = $wpdb->insert(
			self::table_name(),
			array(
				'phrase'     => $phrase,
				'target_url' => $url,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'sil_db_error', __( 'Could not save phrase (it may already exist).', 'sil' ) );
		}

		return true;
	}

	public static function update( $id, $phrase, $url ) {
		global $wpdb;

		$clean = self::sanitize_input( $phrase, $url );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		list( $phrase, $url ) = $clean;

		$result = $wpdb->update(
			self::table_name(),
			array(
				'phrase'     => $phrase,
				'target_url' => $url,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'sil_db_error', __( 'Could not update phrase (it may already exist).', 'sil' ) );
		}

		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table_name(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	public static function increment_usage( $id ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET usage_count = usage_count + 1 WHERE id = %d", absint( $id ) ) );
	}

	public static function decrement_usage( $id ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET usage_count = GREATEST(0, usage_count - 1) WHERE id = %d", absint( $id ) ) );
	}
}
