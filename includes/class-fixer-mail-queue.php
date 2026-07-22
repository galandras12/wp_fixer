<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "New login" / "new device" style notification emails are frequently sent
 * synchronously (over SMTP) right inside the wp_login hook, so the whole
 * SMTP handshake (DNS, connect, TLS, auth) blocks the login response.
 *
 * This intercepts wp_mail() calls that happen while a login is being
 * processed, queues them, and re-sends them a moment later through a
 * non-blocking background request - so the user's browser gets redirected
 * immediately while the email goes out right after, in the background.
 */
class Fixer_Mail_Queue {

	const CRON_HOOK = 'fixer_dispatch_queued_mail';
	const OPTION    = 'fixer_mail_queue';

	private static $bypass = false;

	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'maybe_queue' ), 10, 2 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'dispatch' ) );
	}

	public static function maybe_queue( $short_circuit, $atts ) {
		$opts = Fixer_Settings::get_options();

		if ( self::$bypass || empty( $opts['mail_queue_enabled'] ) || ! Fixer_Request_Detector::is_login_request() ) {
			return $short_circuit;
		}

		$queue   = get_option( self::OPTION, array() );
		$queue[] = $atts;
		update_option( self::OPTION, $queue, false );

		Fixer_Logger::log_mail_deferred( $atts );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
		spawn_cron();

		return true; // Short-circuits wp_mail(): tells the caller "sent" without blocking on it now.
	}

	public static function dispatch() {
		$queue = get_option( self::OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}
		delete_option( self::OPTION );

		self::$bypass = true;
		foreach ( $queue as $atts ) {
			wp_mail(
				isset( $atts['to'] ) ? $atts['to'] : '',
				isset( $atts['subject'] ) ? $atts['subject'] : '',
				isset( $atts['message'] ) ? $atts['message'] : '',
				isset( $atts['headers'] ) ? $atts['headers'] : array(),
				isset( $atts['attachments'] ) ? $atts['attachments'] : array()
			);
		}
		self::$bypass = false;
	}
}
