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

		// Two markers, not one: the first runs at the very start of wp_login
		// (before any plugin's own wp_login callback), so it shows time-to-
		// authenticate. The second runs dead last, after every plugin's own
		// wp_login-hooked side effect (logging, notifications, online status)
		// has also run - which is what a browser actually measures. The gap
		// between the two numbers is exactly the part the Hook Deferral
		// engine (Bejelentkezés fül) can move to the background.
		add_action( 'wp_login', array( __CLASS__, 'log_wall_time_to_auth' ), 0, 0 );
		add_action( 'wp_login_failed', array( __CLASS__, 'log_wall_time_to_auth' ), 0, 0 );
		add_action( 'wp_login', array( __CLASS__, 'log_wall_time_total' ), PHP_INT_MAX, 0 );
		add_action( 'wp_login_failed', array( __CLASS__, 'log_wall_time_total' ), PHP_INT_MAX, 0 );
	}

	public static function log_wall_time_to_auth() {
		self::log_wall_time(
			'php_wall_time',
			__( 'PHP feldolgozási idő a hitelesítés eldöléséig (a wp_login hook elejéig). Ha ez sokkal kisebb, mint amennyit a böngésző mutatott, a maradék idő a wp_login-hoz kötött plugin-funkciókban (lásd a "teljes" sort) vagy még a PHP indulása előtt (hálózat, szerver sor) keletkezik.', 'fixer' )
		);
	}

	public static function log_wall_time_total() {
		self::log_wall_time(
			'php_wall_time_total',
			__( 'Teljes PHP feldolgozási idő, beleértve az ÖSSZES wp_login-hoz kötött plugin-funkciót (napló, értesítés, online állapot stb.) is - ez áll legközelebb ahhoz, amit a böngésző ténylegesen mér.', 'fixer' )
		);
	}

	private static function log_wall_time( $type, $detail ) {
		if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return;
		}
		$ms = ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
		Fixer_Logger::log_php_wall_time( $ms, $type, $detail );
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
