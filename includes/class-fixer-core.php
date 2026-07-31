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

		// Admin UI must always be available, even with everything else disabled.
		if ( is_admin() ) {
			Fixer_Admin_Page::init();
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

		// Needs to run after every other plugin has registered its own hooks,
		// so it can see (and, for the deferral engine, safely remove) them.
		add_action( 'init', array( __CLASS__, 'late_setup' ), PHP_INT_MAX );
	}

	public static function late_setup() {
		Fixer_Profiler::maybe_instrument();
		Fixer_Hook_Deferral::maybe_setup();
	}
}
