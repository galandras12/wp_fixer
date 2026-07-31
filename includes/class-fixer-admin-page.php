<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings -> Fixer admin page: master switch, per-module toggles, the
 * auto-discovered list of plugins hooking into the login process (with a
 * per-plugin "defer to background" checkbox), and the diagnostics log.
 */
class Fixer_Admin_Page {

	const PAGE_SLUG = 'fixer-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_fixer_clear_log', array( __CLASS__, 'handle_clear_log' ) );
		add_action( 'admin_post_fixer_delete_expired_transients', array( __CLASS__, 'handle_delete_expired_transients' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'fixer-admin', FIXER_URL . 'assets/admin.css', array(), FIXER_VERSION );
	}

	public static function add_menu() {
		add_options_page(
			__( 'Fixer beállítások', 'fixer' ),
			__( 'Fixer', 'fixer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		register_setting(
			'fixer_options_group',
			Fixer_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Fixer_Settings', 'sanitize' ),
				'default'           => Fixer_Settings::defaults(),
			)
		);
	}

	public static function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_clear_log' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}
		Fixer_Logger::clear();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'fixer_cleared' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handle_delete_expired_transients() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_delete_expired_transients' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}

		global $wpdb;
		$timeout_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$deleted = 0;
		foreach ( $timeout_rows as $timeout_name ) {
			$transient = preg_replace( '/^_transient_timeout_/', '', $timeout_name );
			if ( delete_transient( $transient ) ) {
				$deleted++;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'tab' => 'server', 'fixer_transients_deleted' => $deleted ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts       = Fixer_Settings::get_options();
		$discovery  = Fixer_Hook_Deferral::discover();
		$log        = Fixer_Logger::get_recent( 300 );
		$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'login', 'server', 'performance', 'object-cache' ), true ) ) {
			$tab = 'login';
		}
		$server_checks    = 'server' === $tab ? Fixer_Server_Info::get_checks() : array();
		$autoload_options = 'server' === $tab ? Fixer_Server_Info::get_largest_autoloaded_options() : array();

		$login_assets_seen = 'performance' === $tab ? Fixer_Login_Speed::get_seen_assets() : array();

		$oc_status       = 'object-cache' === $tab ? Fixer_Object_Cache::dropin_status() : 'missing';
		$oc_connected    = 'object-cache' === $tab ? Fixer_Object_Cache::is_connected_this_request() : false;
		$oc_test_result  = 'object-cache' === $tab ? Fixer_Object_Cache::get_and_clear_test_result() : null;
		$oc_message      = 'object-cache' === $tab ? Fixer_Object_Cache::get_and_clear_message() : null;

		require FIXER_DIR . 'includes/admin-page-view.php';
	}
}
