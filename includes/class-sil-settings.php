<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SIL_Settings {

	const OPTION_KEY = 'sil_settings';

	public static function defaults() {
		return array(
			'open_in_new_tab'    => false,
			'tldr_section_name'  => 'TL;DR 😎',
			'anthropic_api_key'  => '',
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
		$existing = self::get();

		$clean = array(
			'open_in_new_tab'   => ! empty( $settings['open_in_new_tab'] ),
			'tldr_section_name' => isset( $settings['tldr_section_name'] )
				? sanitize_text_field( wp_unslash( $settings['tldr_section_name'] ) )
				: $existing['tldr_section_name'],
			// API key: preserve existing value if field was left blank (password
			// fields are often submitted empty when the user isn't changing them).
			'anthropic_api_key' => ( isset( $settings['anthropic_api_key'] ) && '' !== trim( $settings['anthropic_api_key'] ) )
				? sanitize_text_field( wp_unslash( $settings['anthropic_api_key'] ) )
				: $existing['anthropic_api_key'],
		);

		update_option( self::OPTION_KEY, $clean );
	}
}
