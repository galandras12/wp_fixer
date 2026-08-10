<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain-text log downloads for each tab's data, named
 * {Modul}-{YYYY}-{MM}-{DD}-{HH}-{MM}-{SS}-log.txt.
 */
class Fixer_Log_Export {

	/**
	 * @return array<string, array{label:string, types:string[]}>
	 */
	private static function modules() {
		return array(
			'login'     => array(
				'label' => 'Bejelentkezes',
				'types' => array( 'hook_timing', 'http_capped', 'mail_deferred', 'hook_deferred', 'php_wall_time', 'php_wall_time_total' ),
			),
			'server'    => array(
				'label' => 'SzerverDiagnosztika',
				'types' => array( Fixer_Server_Speed_Test::TYPE_WEB, Fixer_Server_Speed_Test::TYPE_PHP, Fixer_Server_Speed_Test::TYPE_DB ),
			),
			'speedtest' => array(
				'label' => 'Sebessegteszt',
				'types' => array( Fixer_Speed_Test::TYPE_LOGIN, Fixer_Speed_Test::TYPE_PAGELOAD ),
			),
		);
	}

	public static function init() {
		add_action( 'admin_post_fixer_download_log', array( __CLASS__, 'handle' ) );
	}

	public static function download_url( $module ) {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => 'fixer_download_log', 'module' => $module ),
				admin_url( 'admin-post.php' )
			),
			'fixer_download_log'
		);
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_download_log' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}

		$modules = self::modules();
		$module  = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $modules[ $module ] ) ) {
			wp_die( esc_html__( 'Ismeretlen napló modul.', 'fixer' ) );
		}
		$label = $modules[ $module ]['label'];
		$types = $modules[ $module ]['types'];

		global $wpdb;
		$table        = Fixer_DB::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type IN ({$placeholders}) ORDER BY id ASC", $types ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		);

		$content  = self::build_text( $label, $rows, 'server' === $module );
		$filename = sprintf( '%s-%s-log.txt', $label, current_time( 'Y-m-d-H-i-s' ) );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function build_text( $label, $rows, $include_server_checks ) {
		$lines   = array();
		$lines[] = 'Fixer - ' . $label . ' naplo';
		$lines[] = 'Generalva: ' . current_time( 'mysql' );
		$lines[] = str_repeat( '=', 70 );
		$lines[] = '';

		if ( $include_server_checks ) {
			$lines[] = '--- Szerver diagnosztikai ellenorzesek ---';
			foreach ( Fixer_Server_Info::get_checks() as $check ) {
				$lines[] = sprintf( '[%s] %s: %s', strtoupper( $check['status'] ), $check['label'], $check['value'] );
			}
			$lines[] = '';
			$lines[] = '--- Valaszido-teszt tortenete ---';
		}

		$lines[] = sprintf( '%-20s %-24s %-20s %-24s %-40s %10s', 'Idopont', 'Tipus', 'Plugin', 'Hook', 'Reszlet', 'Ido (ms)' );
		$lines[] = str_repeat( '-', 70 );

		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'%-20s %-24s %-20s %-24s %-40s %10s',
				$row->created_at,
				$row->type,
				$row->plugin_name ? $row->plugin_name : $row->plugin_slug,
				$row->hook_name,
				mb_strimwidth( (string) $row->detail, 0, 40, '...' ),
				null !== $row->duration_ms ? $row->duration_ms : '-'
			);
		}
		if ( empty( $rows ) ) {
			$lines[] = '(nincs adat)';
		}

		return implode( "\n", $lines ) . "\n";
	}
}
