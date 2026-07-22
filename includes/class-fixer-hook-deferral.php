<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the admin pick specific plugins whose *non-critical* login side
 * effects (activity logging, "you just logged in" notifications, online
 * status updates, ...) should be moved out of the login request entirely.
 *
 * Only hooks that fire strictly after authentication has already succeeded
 * or failed are eligible (see HOOKS below) - never the `authenticate` /
 * `wp_authenticate_user` / `set_auth_cookie` hooks that decide whether the
 * login is valid. That way Fixer can never itself break a login.
 *
 * Mechanism: at 'init' (after every plugin registered its hooks) the
 * callbacks belonging to opted-in plugins are removed from the hook before
 * it fires, and a non-blocking loopback request to admin-ajax.php is fired
 * instead, which re-runs just those callbacks (and only those) a moment
 * later, in a separate request that doesn't hold up the user's browser.
 */
class Fixer_Hook_Deferral {

	const HOOKS          = array( 'wp_login', 'wp_login_failed', 'set_logged_in_cookie' );
	const REPLAY_ACTION  = 'fixer_replay_hook';
	const TRANSIENT_PREFIX = 'fixer_replay_';

	private static $replay_job   = null;
	private static $replay_token = '';

	public static function init() {
		add_action( 'wp_ajax_' . self::REPLAY_ACTION, array( __CLASS__, 'handle_replay' ) );
		add_action( 'wp_ajax_nopriv_' . self::REPLAY_ACTION, array( __CLASS__, 'handle_replay' ) );
	}

	/**
	 * Called from Fixer_Core::late_setup() on 'init' at PHP_INT_MAX, i.e.
	 * after every plugin has registered its own hooks for this request.
	 */
	public static function maybe_setup() {
		if ( self::is_replay_request() ) {
			self::load_replay_job();
			self::isolate_for_replay();
			return;
		}

		$opts = Fixer_Settings::get_options();
		if ( empty( $opts['deferral_enabled'] ) || empty( $opts['deferred_slugs'] ) || ! Fixer_Request_Detector::is_login_request() ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			self::strip_and_queue( $hook, $opts['deferred_slugs'] );
		}
	}

	private static function is_replay_request() {
		return wp_doing_ajax() && isset( $_REQUEST['action'] ) && self::REPLAY_ACTION === $_REQUEST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Scans the eligible hooks and returns which currently-active plugins
	 * have callbacks on them, for display (and opt-in) on the admin page.
	 * Read-only, safe to call any time (e.g. rendering wp-admin).
	 *
	 * @return array<string, array{label:string, hooks:array<string,int>}>
	 */
	public static function discover() {
		global $wp_filter;
		$found = array();

		foreach ( self::HOOKS as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$desc = Fixer_Reflection_Helper::describe_callback( $cb['function'] );
					if ( ! $desc || in_array( $desc['slug'], array( 'core', FIXER_SLUG ), true ) ) {
						continue;
					}
					if ( ! isset( $found[ $desc['slug'] ] ) ) {
						$found[ $desc['slug'] ] = array(
							'label' => $desc['label'],
							'hooks' => array(),
						);
					}
					$found[ $desc['slug'] ]['hooks'][ $hook ] = ( isset( $found[ $desc['slug'] ]['hooks'][ $hook ] ) ? $found[ $desc['slug'] ]['hooks'][ $hook ] : 0 ) + 1;
				}
			}
		}

		return $found;
	}

	private static function strip_and_queue( $hook, array $deferred_slugs ) {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}

		$removed_any = false;

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$desc = Fixer_Reflection_Helper::describe_callback( $cb['function'] );
				if ( $desc && in_array( $desc['slug'], $deferred_slugs, true ) ) {
					remove_filter( $hook, $cb['function'], $priority );
					$removed_any = true;
					Fixer_Logger::log_hook_deferred( $hook, $desc );
				}
			}
		}

		if ( $removed_any ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook, $deferred_slugs ) {
					self::schedule_replay( $hook, $args, $deferred_slugs );
				},
				PHP_INT_MAX - 100,
				10
			);
		}
	}

	private static function schedule_replay( $hook, array $args, array $deferred_slugs ) {
		$token = wp_generate_password( 32, false, false );

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'hook'  => $hook,
				'args'  => self::pack_args( $args ),
				'slugs' => $deferred_slugs,
			),
			60
		);

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action'      => self::REPLAY_ACTION,
					'fixer_token' => $token,
				),
			)
		);
	}

	/**
	 * WP_User objects are stored by ID and rehydrated on replay so we never
	 * rely on serializing a live object across the request boundary.
	 */
	private static function pack_args( array $args ) {
		foreach ( $args as $i => $value ) {
			if ( $value instanceof WP_User ) {
				$args[ $i ] = array( '__fixer_user_id' => $value->ID );
			}
		}
		return $args;
	}

	private static function unpack_args( array $args ) {
		foreach ( $args as $i => $value ) {
			if ( is_array( $value ) && isset( $value['__fixer_user_id'] ) ) {
				$args[ $i ] = get_user_by( 'id', $value['__fixer_user_id'] );
			}
		}
		return $args;
	}

	private static function load_replay_job() {
		$token = isset( $_REQUEST['fixer_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['fixer_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return;
		}

		$job = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! $job ) {
			return;
		}
		delete_transient( self::TRANSIENT_PREFIX . $token );

		self::$replay_job   = $job;
		self::$replay_token  = $token;
	}

	/**
	 * Removes every callback on the replayed hook *except* the ones belonging
	 * to the target plugin(s), so replaying the hook here can't double-run
	 * anything that already ran (or was itself deferred) in the original request.
	 */
	private static function isolate_for_replay() {
		if ( ! self::$replay_job ) {
			return;
		}

		global $wp_filter;
		$hook = self::$replay_job['hook'];

		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$desc = Fixer_Reflection_Helper::describe_callback( $cb['function'] );
				$keep = $desc && in_array( $desc['slug'], self::$replay_job['slugs'], true );
				if ( ! $keep ) {
					remove_filter( $hook, $cb['function'], $priority );
				}
			}
		}
	}

	public static function handle_replay() {
		if ( ! self::$replay_job ) {
			wp_die();
		}

		$args = self::unpack_args( self::$replay_job['args'] );
		do_action_ref_array( self::$replay_job['hook'], $args );

		wp_die();
	}
}
