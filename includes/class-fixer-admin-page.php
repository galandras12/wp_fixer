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

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts      = Fixer_Settings::get_options();
		$discovery = Fixer_Hook_Deferral::discover();
		$log       = Fixer_Logger::get_recent( 300 );

		require FIXER_DIR . 'includes/admin-page-view.php';
	}
}
