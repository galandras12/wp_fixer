<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only instrumentation: wraps every callback attached to the hooks that
 * run during authentication and times them individually. This never changes
 * behaviour, it only measures - so it's safe to leave on permanently and is
 * the tool for finding out *which* plugin is actually the slow one.
 */
class Fixer_Profiler {

	const HOOKS = array(
		'authenticate',
		'wp_authenticate_user',
		'wp_login',
		'wp_login_failed',
		'set_auth_cookie',
		'set_logged_in_cookie',
	);

	public static function maybe_instrument() {
		$opts = Fixer_Settings::get_options();

		if ( empty( $opts['profiler_enabled'] ) || ! Fixer_Request_Detector::is_login_request() ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			self::wrap_hook( $hook );
		}
	}

	private static function wrap_hook( $hook ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$original = $cb['function'];
				$accepted = $cb['accepted_args'];

				remove_filter( $hook, $original, $priority );

				add_filter(
					$hook,
					function ( ...$args ) use ( $original, $hook ) {
						$start  = microtime( true );
						$result = call_user_func_array( $original, $args );
						Fixer_Logger::log_timing( $hook, $original, ( microtime( true ) - $start ) * 1000 );
						return $result;
					},
					$priority,
					$accepted
				);
			}
		}
	}
}
