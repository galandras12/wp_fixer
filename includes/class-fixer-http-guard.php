<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Many "slow login" cases are actually a blocking outbound HTTP request
 * (an API/telemetry/geolocation/anti-spam call some plugin makes on wp_login)
 * hanging until its timeout - the default WP timeout is often set to 30s by
 * plugins, which lines up exactly with the reported 30-50 second freeze.
 *
 * This caps the timeout of any outbound HTTP request made while a login is
 * being processed, so a stalled remote call can no longer block the login
 * response for more than a few seconds.
 */
class Fixer_Http_Guard {

	public static function init() {
		add_filter( 'http_request_args', array( __CLASS__, 'cap_timeout' ), 5, 2 );
	}

	public static function cap_timeout( $args, $url ) {
		$opts = Fixer_Settings::get_options();

		if ( empty( $opts['http_guard_enabled'] ) || ! Fixer_Request_Detector::is_login_request() ) {
			return $args;
		}

		$cap = max( 1, (int) $opts['http_guard_timeout'] );

		if ( isset( $args['timeout'] ) && (float) $args['timeout'] > $cap ) {
			$origin = self::find_caller();
			Fixer_Logger::log_http_cap( $url, $args['timeout'], $cap, $origin['slug'], $origin['label'] );
			$args['timeout'] = $cap;
		}

		return $args;
	}

	private static function find_caller() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			if ( preg_match( '#/wp-content/plugins/([^/]+)#', $frame['file'], $m ) && FIXER_SLUG !== $m[1] ) {
				return array(
					'slug'  => $m[1],
					'label' => Fixer_Reflection_Helper::plugin_label_from_slug( $m[1] ),
				);
			}
			if ( strpos( $frame['file'], '/wp-content/mu-plugins/' ) !== false ) {
				return array( 'slug' => 'mu-plugin', 'label' => __( 'Must-use plugin', 'fixer' ) );
			}
			if ( strpos( $frame['file'], '/wp-content/themes/' ) !== false ) {
				return array( 'slug' => 'theme', 'label' => __( 'Aktív téma', 'fixer' ) );
			}
		}

		return array( 'slug' => 'unknown', 'label' => __( 'Ismeretlen forrás', 'fixer' ) );
	}
}
