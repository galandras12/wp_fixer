<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the optional persistent object cache (Redis/Memcached): writing
 * the isolated connection-config file, copying/removing the drop-in, and
 * the test-connection / flush admin actions. Never touches wp-config.php.
 */
class Fixer_Object_Cache {

	const CONFIG_FILE = 'fixer-object-cache-config.php';
	const MARKER       = '@fixer-object-cache-dropin';

	public static function init() {
		add_action( 'admin_post_fixer_oc_enable', array( __CLASS__, 'handle_enable' ) );
		add_action( 'admin_post_fixer_oc_disable', array( __CLASS__, 'handle_disable' ) );
		add_action( 'admin_post_fixer_oc_flush', array( __CLASS__, 'handle_flush' ) );
		add_action( 'admin_post_fixer_oc_test', array( __CLASS__, 'handle_test' ) );
	}

	public static function dropin_path() {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	public static function config_path() {
		return WP_CONTENT_DIR . '/' . self::CONFIG_FILE;
	}

	/** @return string 'missing'|'ours'|'foreign' */
	public static function dropin_status() {
		$path = self::dropin_path();
		if ( ! file_exists( $path ) ) {
			return 'missing';
		}
		$head = file_get_contents( $path, false, null, 0, 2000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false !== $head && false !== strpos( $head, self::MARKER ) ) {
			return 'ours';
		}
		return 'foreign';
	}

	public static function is_connected_this_request() {
		global $wp_object_cache;
		return is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'is_connected' ) && $wp_object_cache->is_connected();
	}

	public static function connection_config_from_options( array $opts ) {
		return array(
			'backend'  => $opts['object_cache_backend'],
			'host'     => $opts['object_cache_host'],
			'port'     => $opts['object_cache_port'],
			'password' => $opts['object_cache_password'],
			'database' => $opts['object_cache_database'],
			'prefix'   => $opts['object_cache_prefix'],
		);
	}

	public static function handle_enable() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_oc_enable' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}

		if ( 'foreign' === self::dropin_status() ) {
			self::redirect_with_message( 'error', __( 'Egy másik objektum-gyorsítótár drop-in már aktív (wp-content/object-cache.php) - azt előbb távolítsd el, különben nem biztonságos felülírni.', 'fixer' ) );
		}

		$opts = Fixer_Settings::get_options();
		if ( empty( $opts['object_cache_backend'] ) || 'none' === $opts['object_cache_backend'] ) {
			self::redirect_with_message( 'error', __( 'Előbb válassz háttértárat (Redis vagy Memcached), és mentsd el a kapcsolati adatokat.', 'fixer' ) );
		}

		if ( ! self::write_config_file( self::connection_config_from_options( $opts ) ) ) {
			self::redirect_with_message( 'error', __( 'Nem sikerült írni a wp-content mappába (fájljogosultsági probléma).', 'fixer' ) );
		}

		if ( ! @copy( FIXER_DIR . 'includes/object-cache-dropin.php', self::dropin_path() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::redirect_with_message( 'error', __( 'Nem sikerült létrehozni a wp-content/object-cache.php fájlt (fájljogosultsági probléma).', 'fixer' ) );
		}

		self::redirect_with_message( 'success', __( 'Objektum-gyorsítótár bekapcsolva. Töltsd újra ezt az oldalt a kapcsolat állapotának ellenőrzéséhez.', 'fixer' ) );
	}

	public static function handle_disable() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_oc_disable' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}

		if ( 'ours' === self::dropin_status() ) {
			@unlink( self::dropin_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( file_exists( self::config_path() ) ) {
			@unlink( self::config_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		self::redirect_with_message( 'success', __( 'Objektum-gyorsítótár kikapcsolva - a WordPress visszaállt a beépített, kérésenkénti gyorsítótárra.', 'fixer' ) );
	}

	public static function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_oc_flush' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		self::redirect_with_message( 'success', __( 'Gyorsítótár ürítve.', 'fixer' ) );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fixer_oc_test' ) ) {
			wp_die( esc_html__( 'Nincs jogosultság.', 'fixer' ) );
		}

		require_once FIXER_DIR . 'includes/class-fixer-object-cache-tester.php';

		$config = array(
			'backend'  => isset( $_POST['object_cache_backend'] ) ? sanitize_key( wp_unslash( $_POST['object_cache_backend'] ) ) : 'redis',
			'host'     => isset( $_POST['object_cache_host'] ) ? sanitize_text_field( wp_unslash( $_POST['object_cache_host'] ) ) : '',
			'port'     => isset( $_POST['object_cache_port'] ) ? (int) $_POST['object_cache_port'] : 0,
			'password' => isset( $_POST['object_cache_password'] ) ? (string) wp_unslash( $_POST['object_cache_password'] ) : '',
			'database' => isset( $_POST['object_cache_database'] ) ? sanitize_text_field( wp_unslash( $_POST['object_cache_database'] ) ) : '',
		);

		$result = Fixer_Object_Cache_Tester::test( $config );
		set_transient( 'fixer_oc_test_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'page' => Fixer_Admin_Page::PAGE_SLUG, 'tab' => 'object-cache' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function get_and_clear_test_result() {
		$key    = 'fixer_oc_test_' . get_current_user_id();
		$result = get_transient( $key );
		if ( false !== $result ) {
			delete_transient( $key );
		}
		return $result ? $result : null;
	}

	private static function write_config_file( array $config ) {
		$export   = var_export( $config, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$contents = "<?php\n// " . self::MARKER . " - generated by Fixer, do not edit (will be overwritten).\nreturn {$export};\n";
		return false !== @file_put_contents( self::config_path(), $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	private static function redirect_with_message( $type, $message ) {
		set_transient( 'fixer_oc_msg_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => Fixer_Admin_Page::PAGE_SLUG, 'tab' => 'object-cache' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function get_and_clear_message() {
		$key = 'fixer_oc_msg_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( false !== $msg ) {
			delete_transient( $key );
		}
		return $msg ? $msg : null;
	}
}
