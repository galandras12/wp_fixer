<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up all Fixer modules. Each module still checks its own on/off
 * setting, but nothing at all is registered when the master switch is off.
 */
class Fixer_Core {

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'load' ), 1 );

		// Admin UI (and the object cache enable/disable/test actions) must
		// always be available, even with the master switch off - the object
		// cache drop-in, once installed, also keeps working independently of
		// it (it loads before any plugin, Fixer included).
		if ( is_admin() ) {
			Fixer_Admin_Page::init();
			Fixer_Object_Cache::init();
		}
	}

	public static function load() {
		$opts = Fixer_Settings::get_options();

		if ( empty( $opts['master_enabled'] ) ) {
			return;
		}

		// Filters that must be in place before the login POST is processed later
		// in the request. Registering them here (plugins_loaded) is early enough
		// regardless of module load order, since they only fire when something
		// later actually calls wp_mail() / wp_remote_*().
		Fixer_Http_Guard::init();
		Fixer_Mail_Queue::init();
		Fixer_Hook_Deferral::init();
		Fixer_Optimizer::init();
		Fixer_Error_Filter::init();
		Fixer_Session_Reset::init();
		Fixer_Login_Speed::init();
		Fixer_Speed_Test::init();

		// Needs to run after every other plugin has registered its own hooks,
		// so it can see (and, for the deferral engine, safely remove) them.
		add_action( 'init', array( __CLASS__, 'late_setup' ), PHP_INT_MAX );
	}

	public static function late_setup() {
		// Order matters: the deferral engine must strip a deferred plugin's
		// *original* callback off the hook first. If the profiler wrapped it
		// first, the deferral engine would see the profiler's own closure
		// (file: class-fixer-profiler.php) instead of the plugin's, always
		// resolve it to "fixer", and never match any admin-selected plugin -
		// silently making the "Pluginok háttérbe tétele" list a no-op.
		Fixer_Hook_Deferral::maybe_setup();
		Fixer_Profiler::maybe_instrument();
	}
}
