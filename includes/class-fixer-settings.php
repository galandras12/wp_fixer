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

			'opt_disable_emoji'     => true,
			'opt_clean_head'        => true,
			'opt_lazy_images'       => true,
			'opt_heartbeat_control' => true,
			'heartbeat_interval'    => 60,
			'opt_limit_revisions'   => true,
			'revisions_to_keep'     => 5,
			'opt_jpeg_quality'      => false,
			'jpeg_quality'          => 82,
			'opt_disable_xmlrpc'    => false,

			'opt_suppress_deprecated_noise' => true,
			'deprecated_suppress_patterns'  => array( 'login-with-ajax/assets/php/color.php' ),

			'object_cache_backend'  => 'none',
			'object_cache_host'     => '127.0.0.1',
			'object_cache_port'     => 6379,
			'object_cache_password' => '',
			'object_cache_database' => '',
			'object_cache_prefix'   => 'fixer',

			'opt_session_reset_button' => true,

			'opt_trim_login_assets'    => false,
			'login_trim_slugs'         => array(),
			'opt_login_resource_hints' => true,
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

		$clean['opt_disable_emoji']     = ! empty( $input['opt_disable_emoji'] );
		$clean['opt_clean_head']        = ! empty( $input['opt_clean_head'] );
		$clean['opt_lazy_images']       = ! empty( $input['opt_lazy_images'] );
		$clean['opt_heartbeat_control'] = ! empty( $input['opt_heartbeat_control'] );
		$clean['heartbeat_interval']    = isset( $input['heartbeat_interval'] ) ? min( 300, max( 15, (int) $input['heartbeat_interval'] ) ) : $defaults['heartbeat_interval'];
		$clean['opt_limit_revisions']   = ! empty( $input['opt_limit_revisions'] );
		$clean['revisions_to_keep']     = isset( $input['revisions_to_keep'] ) ? min( 100, max( 0, (int) $input['revisions_to_keep'] ) ) : $defaults['revisions_to_keep'];
		$clean['opt_jpeg_quality']      = ! empty( $input['opt_jpeg_quality'] );
		$clean['jpeg_quality']          = isset( $input['jpeg_quality'] ) ? min( 92, max( 60, (int) $input['jpeg_quality'] ) ) : $defaults['jpeg_quality'];
		$clean['opt_disable_xmlrpc']    = ! empty( $input['opt_disable_xmlrpc'] );

		$clean['opt_suppress_deprecated_noise'] = ! empty( $input['opt_suppress_deprecated_noise'] );

		$clean['deprecated_suppress_patterns'] = array();
		if ( ! empty( $input['deprecated_suppress_patterns'] ) ) {
			$raw = is_array( $input['deprecated_suppress_patterns'] ) ? $input['deprecated_suppress_patterns'] : preg_split( '/[\r\n]+/', (string) $input['deprecated_suppress_patterns'] );
			foreach ( $raw as $item ) {
				$item = trim( sanitize_text_field( $item ) );
				if ( '' !== $item ) {
					$clean['deprecated_suppress_patterns'][] = $item;
				}
			}
		}

		$clean['object_cache_backend'] = in_array( $input['object_cache_backend'] ?? '', array( 'none', 'redis', 'memcached' ), true ) ? $input['object_cache_backend'] : $defaults['object_cache_backend'];
		$clean['object_cache_host']     = isset( $input['object_cache_host'] ) ? sanitize_text_field( $input['object_cache_host'] ) : $defaults['object_cache_host'];
		$clean['object_cache_port']     = isset( $input['object_cache_port'] ) ? min( 65535, max( 1, (int) $input['object_cache_port'] ) ) : $defaults['object_cache_port'];
		$clean['object_cache_password'] = isset( $input['object_cache_password'] ) ? (string) $input['object_cache_password'] : '';
		$clean['object_cache_database'] = isset( $input['object_cache_database'] ) && '' !== $input['object_cache_database'] ? (string) (int) $input['object_cache_database'] : '';
		$clean['object_cache_prefix']   = isset( $input['object_cache_prefix'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['object_cache_prefix'] ) : $defaults['object_cache_prefix'];
		if ( '' === $clean['object_cache_prefix'] ) {
			$clean['object_cache_prefix'] = $defaults['object_cache_prefix'];
		}

		$clean['opt_session_reset_button'] = ! empty( $input['opt_session_reset_button'] );

		$clean['opt_trim_login_assets']    = ! empty( $input['opt_trim_login_assets'] );
		$clean['opt_login_resource_hints'] = ! empty( $input['opt_login_resource_hints'] );

		$clean['login_trim_slugs'] = array();
		if ( ! empty( $input['login_trim_slugs'] ) && is_array( $input['login_trim_slugs'] ) ) {
			foreach ( $input['login_trim_slugs'] as $slug ) {
				$clean['login_trim_slugs'][] = sanitize_key( $slug );
			}
		}

		return $clean;
	}
}
