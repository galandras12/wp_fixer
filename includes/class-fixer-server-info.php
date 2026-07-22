<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only server/PHP/DB diagnostics: the things that most commonly slow
 * down every page load on WordPress (not just login) - low memory/execution
 * limits, missing OPcache/object cache, bloated autoloaded options, DB bloat,
 * cron/transient buildup, debug mode left on in production.
 *
 * Never changes anything by itself; each check just returns a status so the
 * admin page can flag what's worth fixing (mostly at server/hosting level).
 */
class Fixer_Server_Info {

	const STATUS_OK   = 'ok';
	const STATUS_WARN = 'warn';
	const STATUS_BAD  = 'bad';

	/**
	 * @return array<int, array{label:string,value:string,status:string,hint:string}>
	 */
	public static function get_checks() {
		global $wpdb, $wp_version;

		$checks = array();

		// --- PHP ---
		$checks[] = array(
			'label'  => __( 'PHP verzió', 'fixer' ),
			'value'  => PHP_VERSION,
			'status' => version_compare( PHP_VERSION, '8.0', '<' ) ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'A régebbi PHP verziók lassabbak és biztonsági javításokat sem kapnak; 8.1+ ajánlott.', 'fixer' ),
		);

		$memory_limit = self::to_bytes( ini_get( 'memory_limit' ) );
		$checks[]     = array(
			'label'  => __( 'PHP memory_limit', 'fixer' ),
			'value'  => ini_get( 'memory_limit' ),
			'status' => ( $memory_limit > 0 && $memory_limit < 128 * MB_IN_BYTES ) ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( '256M vagy több ajánlott, főleg ha sok plugin (Jetpack, Wordfence, Ultimate Member) fut egyszerre.', 'fixer' ),
		);

		$checks[] = array(
			'label'  => __( 'Jelenlegi memóriacsúcs (ezen a kérésen)', 'fixer' ),
			'value'  => size_format( memory_get_peak_usage( true ) ),
			'status' => self::STATUS_OK,
			'hint'   => __( 'Tájékoztató jellegű - mennyi memóriát használt ez az admin oldal.', 'fixer' ),
		);

		$wp_mem = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : __( 'nincs beállítva (WP alapérték)', 'fixer' );
		$checks[] = array(
			'label'  => 'WP_MEMORY_LIMIT',
			'value'  => (string) $wp_mem,
			'status' => self::STATUS_OK,
			'hint'   => __( 'A wp-config.php-ban állítható be, ha egy plugin "allowed memory size exhausted" hibát ad.', 'fixer' ),
		);

		$checks[] = array(
			'label'  => __( 'Max. lefutási idő (max_execution_time)', 'fixer' ),
			'value'  => ini_get( 'max_execution_time' ) . ' s',
			'status' => ( (int) ini_get( 'max_execution_time' ) > 0 && (int) ini_get( 'max_execution_time' ) < 30 ) ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'Ha ennél kisebb, mint amennyi egy lassú plugin művelethez kellene, a kérés hibával (nem csak lassan) áll le.', 'fixer' ),
		);

		$checks[] = array(
			'label'  => __( 'Feltölthető fájlméret (upload_max_filesize / post_max_size)', 'fixer' ),
			'value'  => ini_get( 'upload_max_filesize' ) . ' / ' . ini_get( 'post_max_size' ),
			'status' => self::STATUS_OK,
			'hint'   => __( 'A Big File Uploads plugin ennél nagyobb fájlokat csak darabolva tud feltölteni.', 'fixer' ),
		);

		$checks[] = array(
			'label'  => 'max_input_vars',
			'value'  => (string) ini_get( 'max_input_vars' ),
			'status' => ( (int) ini_get( 'max_input_vars' ) < 1000 ) ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'Sok egyéni mezőt tartalmazó űrlapoknál (pl. Ultimate Member) alacsony érték csendben levágja az adatokat.', 'fixer' ),
		);

		// --- OPcache ---
		if ( function_exists( 'opcache_get_status' ) && ( ini_get( 'opcache.enable' ) || ini_get( 'opcache.enable_cli' ) ) ) {
			$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $status && ! empty( $status['opcache_enabled'] ) ) {
				$hit_rate = isset( $status['opcache_statistics']['opcache_hit_rate'] ) ? round( $status['opcache_statistics']['opcache_hit_rate'], 1 ) : null;
				$checks[] = array(
					'label'  => 'OPcache',
					'value'  => null !== $hit_rate ? sprintf( __( 'aktív, találati arány: %s%%', 'fixer' ), $hit_rate ) : __( 'aktív', 'fixer' ),
					'status' => ( null !== $hit_rate && $hit_rate < 90 ) ? self::STATUS_WARN : self::STATUS_OK,
					'hint'   => __( 'Az OPcache lefordított PHP kódot tart memóriában - ez minden oldalbetöltést gyorsít, nem csak a bejelentkezést.', 'fixer' ),
				);
			} else {
				$checks[] = array(
					'label'  => 'OPcache',
					'value'  => __( 'telepítve, de kikapcsolva', 'fixer' ),
					'status' => self::STATUS_WARN,
					'hint'   => __( 'Bekapcsolása (opcache.enable=1 a php.ini-ben) jelentősen gyorsítja az egész oldalt. Ehhez tárhely-szolgáltatói/szerver beállítás kell.', 'fixer' ),
				);
			}
		} else {
			$checks[] = array(
				'label'  => 'OPcache',
				'value'  => __( 'nem elérhető', 'fixer' ),
				'status' => self::STATUS_WARN,
				'hint'   => __( 'OPcache nélkül minden kérésnél újra kell értelmezni a teljes PHP kódot - ez komoly, folyamatos lassulást okoz. Kérdezd a tárhelyszolgáltatót.', 'fixer' ),
			);
		}

		// --- Persistent object cache ---
		$checks[] = array(
			'label'  => __( 'Állandó objektum-gyorsítótár (Redis/Memcached)', 'fixer' ),
			'value'  => wp_using_ext_object_cache() ? __( 'aktív', 'fixer' ) : __( 'nincs (csak alapértelmezett, kérésenkénti gyorsítótár)', 'fixer' ),
			'status' => wp_using_ext_object_cache() ? self::STATUS_OK : self::STATUS_WARN,
			'hint'   => __( 'Állandó objektum-gyorsítótár nélkül minden kérés újra lekérdezi az adatbázisból ugyanazokat az adatokat. Sok apró lekérdezést végző pluginnál (Ultimate Member, Jetpack) ez sokat számít.', 'fixer' ),
		);

		// --- Autoloaded options ---
		// WP 6.6+ added extra autoload values ('on', 'auto-on', ...) alongside the
		// legacy 'yes'/'no' - excluding the "not autoloaded" values works on both.
		$autoload_size = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no', 'off', 'auto-off')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$checks[]      = array(
			'label'  => __( 'Automatikusan betöltött beállítások (autoload) mérete', 'fixer' ),
			'value'  => size_format( $autoload_size ),
			'status' => $autoload_size > 3 * MB_IN_BYTES ? self::STATUS_BAD : ( $autoload_size > MB_IN_BYTES ? self::STATUS_WARN : self::STATUS_OK ),
			'hint'   => __( 'Ezt MINDEN egyes oldalbetöltés (a bejelentkezés is) beolvassa az adatbázisból, mielőtt bármi más történne. 1 MB fölött már érdemes megnézni a "legnagyobb autoload beállítások" listát lentebb.', 'fixer' ),
		);

		// --- DB table sizes ---
		$table_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS name, TABLE_ROWS AS rows_est, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s, %s, %s, %s)",
				DB_NAME,
				$wpdb->options,
				$wpdb->postmeta,
				$wpdb->usermeta,
				$wpdb->posts
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $table_rows ) {
			foreach ( $table_rows as $t ) {
				$checks[] = array(
					'label'  => sprintf( /* translators: %s: table name */ __( 'Tábla: %s', 'fixer' ), $t->name ),
					'value'  => sprintf( '%s sor, %s', number_format_i18n( (int) $t->rows_est ), size_format( (int) $t->size_bytes ) ),
					'status' => self::STATUS_OK,
					'hint'   => __( 'Tájékoztató jellegű méret- és sorszám becslés.', 'fixer' ),
				);
			}
		}

		// --- Cron ---
		$cron_events = _get_cron_array();
		$cron_count  = 0;
		if ( is_array( $cron_events ) ) {
			foreach ( $cron_events as $events_at_time ) {
				foreach ( $events_at_time as $hooks ) {
					$cron_count += count( $hooks );
				}
			}
		}
		$checks[] = array(
			'label'  => __( 'Ütemezett WP-Cron események száma', 'fixer' ),
			'value'  => number_format_i18n( $cron_count ),
			'status' => $cron_count > 200 ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'Szokatlanul magas szám (több száz) gyakran egy plugin hibásan felhalmozott, le nem futó eseményeire utal.', 'fixer' ),
		);

		// --- Expired transients ---
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$checks[] = array(
			'label'  => __( 'Lejárt tranzitensek (átmeneti adatok) az adatbázisban', 'fixer' ),
			'value'  => number_format_i18n( $expired ),
			'status' => $expired > 500 ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'Ezek fel nem szabadított, lejárt gyorsítótár-bejegyzések, lentebb egy gombbal törölhetők.', 'fixer' ),
		);

		// --- Debug mode ---
		$debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$checks[] = array(
			'label'  => 'WP_DEBUG',
			'value'  => $debug_on ? __( 'bekapcsolva', 'fixer' ) : __( 'kikapcsolva', 'fixer' ),
			'status' => $debug_on ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'Éles oldalon ajánlott kikapcsolva tartani - a hibalogolás pluginonként apró, de folyamatos többletmunkát jelent.', 'fixer' ),
		);

		// --- Active plugin count ---
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$checks[]       = array(
			'label'  => __( 'Aktív pluginok száma', 'fixer' ),
			'value'  => number_format_i18n( count( $active_plugins ) ),
			'status' => count( $active_plugins ) > 40 ? self::STATUS_WARN : self::STATUS_OK,
			'hint'   => __( 'Minden aktív plugin kódja lefut minden egyes kérésen (bejelentkezéskor is) - minél több van, annál nagyobb az esély ütközésre és lassulásra.', 'fixer' ),
		);

		return $checks;
	}

	/**
	 * @return array<int, array{name:string,size:int}> largest autoloaded options, biggest first.
	 */
	public static function get_largest_autoloaded_options( $limit = 15 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name AS name, LENGTH(option_value) AS size
				 FROM {$wpdb->options}
				 WHERE autoload NOT IN ('no', 'off', 'auto-off')
				 ORDER BY size DESC
				 LIMIT %d",
				$limit
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function to_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		switch ( $unit ) {
			case 'g':
				return (int) ( $number * 1024 * 1024 * 1024 );
			case 'm':
				return (int) ( $number * 1024 * 1024 );
			case 'k':
				return (int) ( $number * 1024 );
			default:
				return (int) $number;
		}
	}
}
