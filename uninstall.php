<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fixer_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'fixer_options' );
delete_option( 'fixer_mail_queue' );

wp_clear_scheduled_hook( 'fixer_dispatch_queued_mail' );
