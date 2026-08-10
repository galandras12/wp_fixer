<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-demand, 3-stage server response test shown on the "Szerver
 * diagnosztika" tab: web server (a static file, no PHP involved), PHP
 * server (a standalone PHP file that skips the WordPress bootstrap), and
 * database (a direct query round-trip). Each run's three numbers are
 * logged, so a history/trend chart builds up over repeated runs.
 *
 * This only measures the server's own stack from itself (a loopback HTTP
 * request for the first two stages) - it's not a substitute for an
 * external network speed test, and the admin page copy says so.
 */
class Fixer_Server_Speed_Test {

	const TYPE_WEB = 'server_stage_web';
	const TYPE_PHP = 'server_stage_php';
	const TYPE_DB  = 'server_stage_db';

	const RETENTION = 100;

	public static function init() {
		add_action( 'admin_post_fixer_run_server_speedtest', array( __CLASS__, 'handle_run' ) );
	}

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_run_server_speedtest' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}

		self::run_and_log();

		wp_safe_redirect( add_query_arg( array( 'page' => Fixer_Admin_Page::PAGE_SLUG, 'tab' => 'server' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function run_and_log() {
		$web = self::measure_web();
		$php = self::measure_php();
		$db  = self::measure_db();

		Fixer_Logger::log_speedtest( self::TYPE_WEB, $web['ms'], $web['ok'] ? __( 'sikeres', 'fixer' ) : $web['error'] );
		Fixer_Logger::log_speedtest( self::TYPE_PHP, $php['ms'], $php['ok'] ? __( 'sikeres', 'fixer' ) : $php['error'] );
		Fixer_Logger::log_speedtest( self::TYPE_DB, $db['ms'], $db['ok'] ? __( 'sikeres', 'fixer' ) : $db['error'] );

		Fixer_DB::prune_type( self::TYPE_WEB, self::RETENTION );
		Fixer_DB::prune_type( self::TYPE_PHP, self::RETENTION );
		Fixer_DB::prune_type( self::TYPE_DB, self::RETENTION );

		return compact( 'web', 'php', 'db' );
	}

	private static function measure_web() {
		// Cache-busted static file - the web server can answer this without
		// ever handing the request to PHP.
		return self::measure_http( FIXER_URL . 'assets/admin.css?fixer_bust=' . time() );
	}

	private static function measure_php() {
		// A standalone file that skips wp-load.php entirely - isolates raw
		// PHP + web server time from WordPress's own bootstrap overhead.
		return self::measure_http( FIXER_URL . 'includes/php-ping.php?fixer_bust=' . time() );
	}

	private static function measure_http( $url ) {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		$ms = ( microtime( true ) - $start ) * 1000;

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'ms'    => $ms,
				'error' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return array(
				'ok'    => false,
				'ms'    => $ms,
				/* translators: %d: HTTP status code */
				'error' => sprintf( __( 'HTTP %d hiba (lehet, hogy egy biztonsági plugin blokkolja a közvetlen fájlelérést)', 'fixer' ), $code ),
			);
		}

		return array(
			'ok'    => true,
			'ms'    => $ms,
			'error' => '',
		);
	}

	private static function measure_db() {
		global $wpdb;
		$start  = microtime( true );
		$result = $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ms     = ( microtime( true ) - $start ) * 1000;

		if ( null === $result ) {
			return array(
				'ok'    => false,
				'ms'    => $ms,
				'error' => __( 'Az adatbázis-lekérdezés sikertelen volt.', 'fixer' ),
			);
		}

		return array(
			'ok'    => true,
			'ms'    => $ms,
			'error' => '',
		);
	}

	/**
	 * @return object[] oldest-first, so charts read left-to-right chronologically.
	 */
	public static function get_samples( $type, $limit = 50 ) {
		global $wpdb;
		$table   = Fixer_DB::table_name();
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d", $type, $limit ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
		return $results ? array_reverse( $results ) : array();
	}
}
