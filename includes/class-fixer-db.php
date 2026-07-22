<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and maintains the fixer_log table used for diagnostics
 * (hook timings, capped HTTP requests, deferred mail, deferred hooks).
 */
class Fixer_DB {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'fixer_log';
	}

	public static function activate() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			request_id VARCHAR(32) NOT NULL DEFAULT '',
			type VARCHAR(20) NOT NULL DEFAULT '',
			plugin_slug VARCHAR(191) NOT NULL DEFAULT '',
			plugin_name VARCHAR(191) NOT NULL DEFAULT '',
			hook_name VARCHAR(191) NOT NULL DEFAULT '',
			detail TEXT NULL,
			duration_ms FLOAT NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY type (type)
		) {$charset_collate};";

		dbDelta( $sql );

		add_option( 'fixer_options', Fixer_Settings::defaults() );
	}

	public static function prune( $keep = 500 ) {
		global $wpdb;
		$table = self::table_name();
		$keep  = max( 50, (int) $keep );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > $keep ) {
			$excess = $count - $keep;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess ) );
		}
	}
}
