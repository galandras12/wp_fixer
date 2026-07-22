<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether the current request is "a login attempt" - i.e. the moment
 * right after the user submits username + password, which is exactly the
 * window where slow plugin hooks cause the 30-50 second freeze.
 */
class Fixer_Request_Detector {

	private static $is_login_request = null;
	private static $request_id       = null;

	public static function is_login_request() {
		if ( null !== self::$is_login_request ) {
			return self::$is_login_request;
		}

		$result = false;

		// Standard wp-login.php form submission.
		if ( ! empty( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow']
			&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
			&& ( isset( $_POST['log'] ) || isset( $_POST['pwd'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		) {
			$result = true;
		}

		// AJAX login (e.g. Login With Ajax and similar plugins going through admin-ajax.php).
		if ( ! $result && wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( '' !== $action ) {
				$opts = Fixer_Settings::get_options();

				foreach ( $opts['login_ajax_actions'] as $needle ) {
					if ( '' !== $needle && false !== stripos( $action, $needle ) ) {
						$result = true;
						break;
					}
				}

				if ( ! $result && ! empty( $opts['auto_detect_ajax_login'] ) && false !== stripos( $action, 'login' ) ) {
					$result = true;
				}
			}
		}

		return self::$is_login_request = $result;
	}

	/**
	 * A short id shared by every diagnostic row logged during this single request,
	 * so the admin page can group them as "one login attempt".
	 */
	public static function request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = substr( md5( uniqid( '', true ) ), 0, 12 );
		}
		return self::$request_id;
	}
}
