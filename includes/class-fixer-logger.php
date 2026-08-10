<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buffers diagnostic entries in memory during the request and writes them
 * in a single batch on 'shutdown', so logging itself never adds latency
 * to the login request we're trying to speed up.
 */
class Fixer_Logger {

	private static $buffer = array();
	private static $registered = false;

	public static function log_timing( $hook, $callback, $duration_ms ) {
		$desc = Fixer_Reflection_Helper::describe_callback( $callback );
		self::add(
			'hook_timing',
			$desc ? $desc['slug'] : '',
			$desc ? $desc['label'] : '',
			$hook,
			$desc ? $desc['name'] : (string) $callback,
			round( $duration_ms, 2 )
		);
	}

	public static function log_http_cap( $url, $original_timeout, $capped_timeout, $slug, $label ) {
		self::add(
			'http_capped',
			$slug,
			$label,
			'http_request_args',
			sprintf( '%s (timeout %ss -> %ss)', $url, $original_timeout, $capped_timeout ),
			null
		);
	}

	public static function log_mail_deferred( $atts ) {
		$to = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : (string) $atts['to'];
		self::add(
			'mail_deferred',
			'',
			'',
			'pre_wp_mail',
			sprintf( '%s: %s', $to, isset( $atts['subject'] ) ? $atts['subject'] : '' ),
			null
		);
	}

	public static function log_php_wall_time( $ms, $type = 'php_wall_time', $detail = '' ) {
		self::add(
			$type,
			'',
			'',
			'wp_login',
			$detail,
			round( $ms, 1 )
		);
	}

	public static function log_hook_deferred( $hook, $desc ) {
		self::add(
			'hook_deferred',
			$desc['slug'],
			$desc['label'],
			$hook,
			$desc['name'],
			null
		);
	}

	private static function add( $type, $slug, $label, $hook, $detail, $duration_ms ) {
		self::$buffer[] = array(
			'created_at'  => current_time( 'mysql' ),
			'request_id'  => Fixer_Request_Detector::request_id(),
			'type'        => $type,
			'plugin_slug' => (string) $slug,
			'plugin_name' => (string) $label,
			'hook_name'   => (string) $hook,
			'detail'      => (string) $detail,
			'duration_ms' => $duration_ms,
		);

		if ( ! self::$registered ) {
			self::$registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	public static function flush() {
		if ( empty( self::$buffer ) ) {
			return;
		}
		global $wpdb;
		$table = Fixer_DB::table_name();

		foreach ( self::$buffer as $row ) {
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::$buffer = array();

		$opts = Fixer_Settings::get_options();
		Fixer_DB::prune( $opts['log_retention'] );
	}

	public static function get_recent( $limit = 200 ) {
		global $wpdb;
		$table = Fixer_DB::table_name();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function get_recent_requests( $limit = 20 ) {
		global $wpdb;
		$table = Fixer_DB::table_name();
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT request_id FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function clear() {
		global $wpdb;
		$table = Fixer_DB::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
