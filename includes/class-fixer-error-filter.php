<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quiets specific, known-harmless PHP Deprecated notices coming from
 * third-party plugin files (e.g. Login With Ajax's color.php using
 * implicitly-nullable parameters, which PHP 8.4+ flags as deprecated even
 * though the behaviour is unchanged) - without touching the vendor plugin
 * file itself, so the fix survives plugin updates.
 *
 * Only E_DEPRECATED / E_USER_DEPRECATED notices are ever intercepted, and
 * only when the error's file or message matches one of the configured
 * patterns; everything else (including all real errors/warnings) is passed
 * straight through to PHP's normal handling - and to any error handler a
 * different plugin had already registered.
 */
class Fixer_Error_Filter {

	private static $previous_handler = null;
	private static $has_previous     = false;

	public static function init() {
		$opts = Fixer_Settings::get_options();

		if ( empty( $opts['opt_suppress_deprecated_noise'] ) || empty( $opts['deprecated_suppress_patterns'] ) ) {
			return;
		}

		// Deliberately no type-mask argument here: passing one would make PHP
		// stop calling us (and stop remembering $previous_handler) for every
		// other error type, silently disabling whatever handler another
		// plugin may have already registered for warnings/notices/etc. We
		// register for everything and explicitly re-delegate anything that
		// isn't a match below instead.
		self::$previous_handler = set_error_handler( array( __CLASS__, 'handle_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		self::$has_previous     = null !== self::$previous_handler;
	}

	public static function handle_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		if ( E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno ) {
			$opts = Fixer_Settings::get_options();

			foreach ( $opts['deprecated_suppress_patterns'] as $pattern ) {
				$pattern = trim( $pattern );
				if ( '' !== $pattern && ( false !== strpos( (string) $errfile, $pattern ) || false !== strpos( (string) $errstr, $pattern ) ) ) {
					return true; // Matched: swallow just this notice, nothing is logged for it.
				}
			}
		}

		if ( self::$has_previous ) {
			return call_user_func( self::$previous_handler, $errno, $errstr, $errfile, $errline );
		}

		return false; // No previous handler: let PHP's normal error handling/logging run.
	}
}
