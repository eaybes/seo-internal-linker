<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SIL_Settings {

	const OPTION_KEY = 'sil_settings';

	public static function defaults() {
		return array(
			'open_in_new_tab' => false,
		);
	}

	public static function get() {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return wp_parse_args( $settings, self::defaults() );
	}

	public static function update( array $settings ) {
		$clean = array(
			'open_in_new_tab' => ! empty( $settings['open_in_new_tab'] ),
		);
		update_option( self::OPTION_KEY, $clean );
	}
}
