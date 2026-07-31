<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fixer_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'fixer_options' );
delete_option( 'fixer_mail_queue' );
delete_option( 'fixer_login_assets_seen' );

wp_clear_scheduled_hook( 'fixer_dispatch_queued_mail' );

// Only remove the object cache drop-in/config if they're actually ours.
$dropin = WP_CONTENT_DIR . '/object-cache.php';
if ( file_exists( $dropin ) ) {
	$head = file_get_contents( $dropin, false, null, 0, 2000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false !== $head && false !== strpos( $head, '@fixer-object-cache-dropin' ) ) {
		unlink( $dropin );
	}
}
$config_file = WP_CONTENT_DIR . '/fixer-object-cache-config.php';
if ( file_exists( $config_file ) ) {
	unlink( $config_file );
}
