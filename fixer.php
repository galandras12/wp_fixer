<?php
/**
 * Plugin Name:       Fixer
 * Plugin URI:        https://github.com/galandras12/wp_fixer
 * Description:       Megszünteti a bejelentkezési képernyő elhúzódó beragadását, szerver diagnosztikát ad (memória, PHP limitek, OPcache, adatbázis), és egyenként ki/be kapcsolható optimalizálásokkal gyorsítja az oldal betöltését. Beállítások → Fixer.
 * Version:           1.5.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Fixer
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fixer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FIXER_VERSION', '1.5.0' );
define( 'FIXER_FILE', __FILE__ );
define( 'FIXER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FIXER_URL', plugin_dir_url( __FILE__ ) );
define( 'FIXER_SLUG', 'fixer' );

require_once FIXER_DIR . 'includes/class-fixer-db.php';
require_once FIXER_DIR . 'includes/class-fixer-settings.php';
require_once FIXER_DIR . 'includes/class-fixer-logger.php';
require_once FIXER_DIR . 'includes/class-fixer-reflection-helper.php';
require_once FIXER_DIR . 'includes/class-fixer-request-detector.php';
require_once FIXER_DIR . 'includes/class-fixer-profiler.php';
require_once FIXER_DIR . 'includes/class-fixer-http-guard.php';
require_once FIXER_DIR . 'includes/class-fixer-mail-queue.php';
require_once FIXER_DIR . 'includes/class-fixer-hook-deferral.php';
require_once FIXER_DIR . 'includes/class-fixer-server-info.php';
require_once FIXER_DIR . 'includes/class-fixer-optimizer.php';
require_once FIXER_DIR . 'includes/class-fixer-error-filter.php';
require_once FIXER_DIR . 'includes/class-fixer-object-cache.php';
require_once FIXER_DIR . 'includes/class-fixer-session-reset.php';
require_once FIXER_DIR . 'includes/class-fixer-login-speed.php';
require_once FIXER_DIR . 'includes/class-fixer-speed-test.php';
require_once FIXER_DIR . 'includes/class-fixer-admin-page.php';
require_once FIXER_DIR . 'includes/class-fixer-core.php';

register_activation_hook( FIXER_FILE, array( 'Fixer_DB', 'activate' ) );

Fixer_Core::init();
