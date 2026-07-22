<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for the plugin's single options array (option name: fixer_options).
 */
class Fixer_Settings {

	const OPTION_KEY = 'fixer_options';

	public static function defaults() {
		return array(
			'master_enabled'        => true,
			'profiler_enabled'      => true,
			'http_guard_enabled'    => true,
			'http_guard_timeout'    => 3,
			'mail_queue_enabled'    => true,
			'deferral_enabled'      => true,
			'deferred_slugs'        => array(),
			'login_ajax_actions'    => array( 'ajaxlogin', 'lwa_ajax_login', 'lwa_login' ),
			'auto_detect_ajax_login' => true,
			'guard_background_requests'  => true,
			'background_ajax_actions'    => array(),
			'auto_detect_background_ajax' => true,
			'log_retention'         => 500,
		);
	}

	public static function get_options() {
		$options = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $options, self::defaults() );
	}

	public static function update_options( array $options ) {
		update_option( self::OPTION_KEY, $options );
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$clean['master_enabled']         = ! empty( $input['master_enabled'] );
		$clean['profiler_enabled']       = ! empty( $input['profiler_enabled'] );
		$clean['http_guard_enabled']     = ! empty( $input['http_guard_enabled'] );
		$clean['http_guard_timeout']     = isset( $input['http_guard_timeout'] ) ? min( 30, max( 1, (int) $input['http_guard_timeout'] ) ) : $defaults['http_guard_timeout'];
		$clean['mail_queue_enabled']     = ! empty( $input['mail_queue_enabled'] );
		$clean['deferral_enabled']       = ! empty( $input['deferral_enabled'] );
		$clean['auto_detect_ajax_login'] = ! empty( $input['auto_detect_ajax_login'] );
		$clean['guard_background_requests']   = ! empty( $input['guard_background_requests'] );
		$clean['auto_detect_background_ajax'] = ! empty( $input['auto_detect_background_ajax'] );

		$clean['background_ajax_actions'] = array();
		if ( ! empty( $input['background_ajax_actions'] ) ) {
			$raw = is_array( $input['background_ajax_actions'] ) ? $input['background_ajax_actions'] : explode( ',', (string) $input['background_ajax_actions'] );
			foreach ( $raw as $item ) {
				$item = trim( sanitize_text_field( $item ) );
				if ( '' !== $item ) {
					$clean['background_ajax_actions'][] = $item;
				}
			}
		}

		$clean['deferred_slugs'] = array();
		if ( ! empty( $input['deferred_slugs'] ) && is_array( $input['deferred_slugs'] ) ) {
			foreach ( $input['deferred_slugs'] as $slug ) {
				$clean['deferred_slugs'][] = sanitize_key( $slug );
			}
		}

		$clean['login_ajax_actions'] = array();
		if ( ! empty( $input['login_ajax_actions'] ) ) {
			$raw = is_array( $input['login_ajax_actions'] ) ? $input['login_ajax_actions'] : explode( ',', (string) $input['login_ajax_actions'] );
			foreach ( $raw as $item ) {
				$item = trim( sanitize_text_field( $item ) );
				if ( '' !== $item ) {
					$clean['login_ajax_actions'][] = $item;
				}
			}
		}
		if ( empty( $clean['login_ajax_actions'] ) ) {
			$clean['login_ajax_actions'] = $defaults['login_ajax_actions'];
		}

		$clean['log_retention'] = isset( $input['log_retention'] ) ? min( 5000, max( 50, (int) $input['log_retention'] ) ) : $defaults['log_retention'];

		return $clean;
	}
}
