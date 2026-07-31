<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standalone connection test for the admin page - deliberately does NOT
 * reuse the classes from includes/object-cache-dropin.php, because that
 * file may already be loaded (as the live wp-content/object-cache.php
 * drop-in) by the time this runs, and requiring it again would fatal with
 * a "cannot redeclare class" error.
 */
class Fixer_Object_Cache_Tester {

	public static function test( array $config ) {
		$start = microtime( true );

		try {
			if ( 'redis' === $config['backend'] ) {
				return self::test_redis( $config, $start );
			}
			if ( 'memcached' === $config['backend'] ) {
				return self::test_memcached( $config, $start );
			}
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Ismeretlen háttértár típus.', 'fixer' ),
		);
	}

	private static function test_redis( array $config, $start ) {
		if ( ! class_exists( 'Redis' ) ) {
			return array(
				'success' => false,
				'message' => __( 'A Redis PHP kiterjesztés (phpredis) nincs telepítve a szerveren.', 'fixer' ),
			);
		}

		$redis = new \Redis();
		$host  = ! empty( $config['host'] ) ? $config['host'] : '127.0.0.1';
		$port  = ! empty( $config['port'] ) ? (int) $config['port'] : 6379;

		$connected = ( 0 === strpos( $host, '/' ) )
			? $redis->connect( $host, 0, 1.5 )
			: $redis->connect( $host, $port, 1.5 );

		if ( ! $connected ) {
			return array(
				'success' => false,
				'message' => __( 'Nem sikerült kapcsolódni a megadott Redis szerverhez.', 'fixer' ),
			);
		}

		if ( ! empty( $config['password'] ) && ! $redis->auth( $config['password'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'A Redis jelszó helytelen.', 'fixer' ),
			);
		}

		if ( isset( $config['database'] ) && '' !== $config['database'] ) {
			$redis->select( (int) $config['database'] );
		}

		if ( ! $redis->ping() ) {
			return array(
				'success' => false,
				'message' => __( 'A Redis szerver nem válaszolt a ping-re.', 'fixer' ),
			);
		}

		$info = $redis->info();
		$redis->close();
		$ms = round( ( microtime( true ) - $start ) * 1000, 1 );

		return array(
			'success' => true,
			/* translators: %s: connection time in ms */
			'message' => sprintf( __( 'Sikeres kapcsolódás (%s ms).', 'fixer' ), $ms ),
			'info'    => array(
				__( 'Verzió', 'fixer' )     => isset( $info['redis_version'] ) ? $info['redis_version'] : '?',
				__( 'Memóriahasználat', 'fixer' ) => isset( $info['used_memory'] ) ? size_format( (int) $info['used_memory'] ) : '?',
				__( 'Kliensek', 'fixer' )   => isset( $info['connected_clients'] ) ? $info['connected_clients'] : '?',
				__( 'Üzemidő', 'fixer' )    => isset( $info['uptime_in_seconds'] ) ? human_time_diff( 0, (int) $info['uptime_in_seconds'] ) : '?',
			),
		);
	}

	private static function test_memcached( array $config, $start ) {
		if ( ! class_exists( 'Memcached' ) ) {
			return array(
				'success' => false,
				'message' => __( 'A Memcached PHP kiterjesztés nincs telepítve a szerveren.', 'fixer' ),
			);
		}

		$memcached = new \Memcached();
		$host      = ! empty( $config['host'] ) ? $config['host'] : '127.0.0.1';
		$port      = ! empty( $config['port'] ) ? (int) $config['port'] : 11211;

		$memcached->addServer( $host, $port );
		$stats      = @$memcached->getStats(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$server_key = "{$host}:{$port}";

		if ( empty( $stats ) || ! isset( $stats[ $server_key ] ) || 0 === (int) $stats[ $server_key ]['pid'] ) {
			return array(
				'success' => false,
				'message' => __( 'Nem sikerült kapcsolódni a megadott Memcached szerverhez.', 'fixer' ),
			);
		}

		$s  = $stats[ $server_key ];
		$ms = round( ( microtime( true ) - $start ) * 1000, 1 );

		return array(
			'success' => true,
			/* translators: %s: connection time in ms */
			'message' => sprintf( __( 'Sikeres kapcsolódás (%s ms).', 'fixer' ), $ms ),
			'info'    => array(
				__( 'Verzió', 'fixer' )           => isset( $s['version'] ) ? $s['version'] : '?',
				__( 'Memóriahasználat', 'fixer' ) => isset( $s['bytes'] ) ? size_format( (int) $s['bytes'] ) : '?',
				__( 'Kliensek', 'fixer' )         => isset( $s['curr_connections'] ) ? $s['curr_connections'] : '?',
				__( 'Üzemidő', 'fixer' )          => isset( $s['uptime'] ) ? human_time_diff( 0, (int) $s['uptime'] ) : '?',
			),
		);
	}
}
