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

		// Runs at the very start of the hook's callback chain, so it captures
		// how much of the total time was spent *inside this PHP request* up to
		// the point authentication was decided - as opposed to time spent
		// before PHP even started (network, webserver queueing, PHP-FPM worker
		// starvation). If this number is much smaller than what the browser
		// measured, the bottleneck is not inside WordPress at all.
		add_action( 'wp_login', array( __CLASS__, 'log_wall_time' ), 0, 0 );
		add_action( 'wp_login_failed', array( __CLASS__, 'log_wall_time' ), 0, 0 );
	}

	public static function log_wall_time() {
		if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return;
		}
		$ms = ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
		Fixer_Logger::log_php_wall_time( $ms );
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
