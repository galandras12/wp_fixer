<?php
/**
 * Fixer persistent object cache drop-in (Redis / Memcached).
 *
 * Managed by the Fixer plugin's "Objektum-gyorsítótár" tab - re-running the
 * "Bekapcsolás" action there overwrites this file with the current version.
 *
 * Safe to delete at any time: WordPress automatically falls back to its own
 * built-in, request-only cache the moment this file is gone, no other step
 * needed. The class below is itself defensive the same way - if Redis/
 * Memcached is unreachable or the PHP extension is missing, every method
 * quietly behaves exactly like WordPress's default cache instead of causing
 * an error, so a broken cache server never takes the site down with it.
 *
 * @fixer-object-cache-dropin 1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the actual connection to Redis/Memcached. Every public method is
 * wrapped so a connection problem turns into "no backend" (null return /
 * false) instead of a thrown error reaching WordPress.
 */
class Fixer_Object_Cache_Connection {

	private $client;
	private $type;
	private $prefix;

	public static function connect() {
		$config_file = WP_CONTENT_DIR . '/fixer-object-cache-config.php';
		if ( ! file_exists( $config_file ) ) {
			return null;
		}

		$config = include $config_file;
		if ( ! is_array( $config ) || empty( $config['backend'] ) ) {
			return null;
		}

		try {
			if ( 'redis' === $config['backend'] && class_exists( 'Redis' ) ) {
				return self::connect_redis( $config );
			}
			if ( 'memcached' === $config['backend'] && class_exists( 'Memcached' ) ) {
				return self::connect_memcached( $config );
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	private static function connect_redis( array $config ) {
		$redis = new \Redis();
		$host    = ! empty( $config['host'] ) ? $config['host'] : '127.0.0.1';
		$port    = ! empty( $config['port'] ) ? (int) $config['port'] : 6379;
		$timeout = 1.0;

		$connected = ( 0 === strpos( $host, '/' ) )
			? $redis->connect( $host, 0, $timeout )
			: $redis->connect( $host, $port, $timeout );

		if ( ! $connected ) {
			return null;
		}

		if ( ! empty( $config['password'] ) ) {
			if ( ! $redis->auth( $config['password'] ) ) {
				return null;
			}
		}

		if ( isset( $config['database'] ) && '' !== $config['database'] ) {
			$redis->select( (int) $config['database'] );
		}

		if ( ! $redis->ping() ) {
			return null;
		}

		$instance         = new self();
		$instance->client = $redis;
		$instance->type   = 'redis';
		$instance->prefix = ! empty( $config['prefix'] ) ? $config['prefix'] : 'fixer';
		return $instance;
	}

	private static function connect_memcached( array $config ) {
		$memcached = new \Memcached();
		$host = ! empty( $config['host'] ) ? $config['host'] : '127.0.0.1';
		$port = ! empty( $config['port'] ) ? (int) $config['port'] : 11211;

		$memcached->addServer( $host, $port );
		$stats = @$memcached->getStats(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( empty( $stats ) ) {
			return null;
		}
		$server_key = "{$host}:{$port}";
		if ( ! isset( $stats[ $server_key ] ) || 0 === (int) $stats[ $server_key ]['pid'] ) {
			return null;
		}

		$instance         = new self();
		$instance->client = $memcached;
		$instance->type   = 'memcached';
		$instance->prefix = ! empty( $config['prefix'] ) ? $config['prefix'] : 'fixer';
		return $instance;
	}

	private function full_key( $key ) {
		return $this->prefix . ':' . $key;
	}

	public function get( $key, &$found ) {
		try {
			if ( 'redis' === $this->type ) {
				$value = $this->client->get( $this->full_key( $key ) );
				$found = ( false !== $value );
				return $found ? maybe_unserialize( $value ) : false;
			}
			$value = $this->client->get( $this->full_key( $key ) );
			$found = ( \Memcached::RES_NOTFOUND !== $this->client->getResultCode() );
			return $found ? $value : false;
		} catch ( \Throwable $e ) {
			$found = false;
			return false;
		}
	}

	public function set( $key, $value, $expire ) {
		try {
			$expire = max( 0, (int) $expire );
			if ( 'redis' === $this->type ) {
				$payload = maybe_serialize( $value );
				return $expire > 0
					? (bool) $this->client->setex( $this->full_key( $key ), $expire, $payload )
					: (bool) $this->client->set( $this->full_key( $key ), $payload );
			}
			return (bool) $this->client->set( $this->full_key( $key ), $value, $expire );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function delete( $key ) {
		try {
			if ( 'redis' === $this->type ) {
				return $this->client->del( $this->full_key( $key ) ) > 0;
			}
			return (bool) $this->client->delete( $this->full_key( $key ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function incr( $key, $offset ) {
		try {
			if ( 'redis' === $this->type ) {
				return $offset >= 0
					? $this->client->incrBy( $this->full_key( $key ), $offset )
					: $this->client->decrBy( $this->full_key( $key ), abs( $offset ) );
			}
			return $offset >= 0
				? $this->client->increment( $this->full_key( $key ), $offset )
				: $this->client->decrement( $this->full_key( $key ), abs( $offset ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Full/group flush is implemented as a version-salt bump rather than
	 * FLUSHDB/flush(): the Redis/Memcached instance may be shared with other
	 * sites or applications on the same host, and indiscriminately wiping it
	 * would be destructive to data Fixer doesn't own. Orphaned keys under the
	 * old salt simply age out under the server's own eviction policy.
	 */
	public function bump_salt( $salt_key ) {
		try {
			$new_salt = (string) wp_rand( 1, PHP_INT_MAX ); // phpcs:ignore
			if ( 'redis' === $this->type ) {
				$this->client->set( $this->full_key( $salt_key ), $new_salt );
			} else {
				$this->client->set( $this->full_key( $salt_key ), $new_salt, 0 );
			}
			return $new_salt;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function get_salt( $salt_key ) {
		try {
			if ( 'redis' === $this->type ) {
				$value = $this->client->get( $this->full_key( $salt_key ) );
			} else {
				$value = $this->client->get( $this->full_key( $salt_key ) );
			}
			return false !== $value && null !== $value ? (string) $value : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}

/**
 * WordPress-compatible object cache: same public surface as WP core's own
 * WP_Object_Cache, with persistent groups routed through
 * Fixer_Object_Cache_Connection and a request-local array layer in front of
 * it (so repeat reads within one request never leave PHP).
 */
class Fixer_WP_Object_Cache {

	private $cache               = array();
	private $cache_hits          = 0;
	private $cache_misses        = 0;
	private $global_groups       = array();
	private $non_persistent_groups = array();
	private $multisite;
	private $blog_prefix;
	private $backend;
	private $global_salt = null;
	private $group_salts = array();

	public function __construct() {
		$this->multisite   = is_multisite();
		$this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
		$this->backend      = Fixer_Object_Cache_Connection::connect();
	}

	private function has_backend() {
		return null !== $this->backend;
	}

	private function is_persistent( $group ) {
		return $this->has_backend() && ! in_array( $group, $this->non_persistent_groups, true );
	}

	private function salt_for( $group ) {
		if ( ! $this->has_backend() ) {
			return '';
		}
		if ( null === $this->global_salt ) {
			$this->global_salt = $this->backend->get_salt( 'fixer_cache_salt' );
			if ( null === $this->global_salt ) {
				$this->global_salt = $this->backend->bump_salt( 'fixer_cache_salt' );
			}
		}
		if ( ! isset( $this->group_salts[ $group ] ) ) {
			$salt_key = 'fixer_cache_salt_' . $group;
			$salt     = $this->backend->get_salt( $salt_key );
			if ( null === $salt ) {
				$salt = $this->backend->bump_salt( $salt_key );
			}
			$this->group_salts[ $group ] = $salt;
		}
		return $this->global_salt . ':' . $this->group_salts[ $group ];
	}

	private function key( $key, $group ) {
		$prefix = ( $this->multisite && ! in_array( $group, $this->global_groups, true ) ) ? $this->blog_prefix : '';
		return $this->salt_for( $group ) . ':' . $prefix . $group . ':' . $key;
	}

	private function exists( $key, $group ) {
		return isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] );
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( wp_suspend_cache_addition() ) {
			return false;
		}
		$group = $group ? $group : 'default';

		if ( $this->already_exists( $key, $group ) ) {
			return false;
		}
		return $this->set( $key, $data, $group, $expire );
	}

	/**
	 * Unlike exists(), this also asks the persistent backend - relevant
	 * because the value may have been written by a different request.
	 */
	private function already_exists( $key, $group ) {
		if ( $this->exists( $key, $group ) ) {
			return true;
		}
		if ( $this->is_persistent( $group ) ) {
			$this->backend->get( $this->key( $key, $group ), $found );
			return (bool) $found;
		}
		return false;
	}

	public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
		$results = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $results;
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$group = $group ? $group : 'default';
		if ( ! $this->already_exists( $key, $group ) ) {
			return false;
		}
		return $this->set( $key, $data, $group, $expire );
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$group = $group ? $group : 'default';
		if ( is_object( $data ) ) {
			$data = clone $data;
		}
		$this->cache[ $group ][ $key ] = $data;

		if ( $this->is_persistent( $group ) ) {
			$this->backend->set( $this->key( $key, $group ), $data, $expire );
		}
		return true;
	}

	public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
		$results = array();
		foreach ( $data as $key => $value ) {
			$results[ $key ] = $this->set( $key, $value, $group, $expire );
		}
		return $results;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$group = $group ? $group : 'default';

		if ( $this->exists( $key, $group ) && ! $force ) {
			$found = true;
			$this->cache_hits++;
			$value = $this->cache[ $group ][ $key ];
			return is_object( $value ) ? clone $value : $value;
		}

		if ( $this->is_persistent( $group ) ) {
			$value = $this->backend->get( $this->key( $key, $group ), $backend_found );
			if ( $backend_found ) {
				$found = true;
				$this->cache_hits++;
				$this->cache[ $group ][ $key ] = $value;
				return is_object( $value ) ? clone $value : $value;
			}
		}

		$found = false;
		$this->cache_misses++;
		return false;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$results = array();
		foreach ( $keys as $key ) {
			$results[ $key ] = $this->get( $key, $group, $force );
		}
		return $results;
	}

	public function delete( $key, $group = 'default' ) {
		$group = $group ? $group : 'default';
		if ( ! $this->exists( $key, $group ) && ! $this->is_persistent( $group ) ) {
			return false;
		}
		unset( $this->cache[ $group ][ $key ] );
		if ( $this->is_persistent( $group ) ) {
			$this->backend->delete( $this->key( $key, $group ) );
		}
		return true;
	}

	public function delete_multiple( array $keys, $group = 'default' ) {
		$results = array();
		foreach ( $keys as $key ) {
			$results[ $key ] = $this->delete( $key, $group );
		}
		return $results;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$group = $group ? $group : 'default';
		if ( ! $this->exists( $key, $group ) ) {
			return false;
		}
		$value = (int) $this->cache[ $group ][ $key ] + (int) $offset;
		$value = max( 0, $value );
		$this->cache[ $group ][ $key ] = $value;
		if ( $this->is_persistent( $group ) ) {
			$this->backend->set( $this->key( $key, $group ), $value, 0 );
		}
		return $value;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, -$offset, $group );
	}

	public function flush( $delay = 0 ) {
		$this->cache = array();
		if ( $this->has_backend() ) {
			$this->global_salt  = $this->backend->bump_salt( 'fixer_cache_salt' );
			$this->group_salts = array();
		}
		return true;
	}

	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	public function flush_group( $group ) {
		unset( $this->cache[ $group ] );
		if ( $this->has_backend() ) {
			$this->group_salts[ $group ] = $this->backend->bump_salt( 'fixer_cache_salt_' . $group );
		}
		return true;
	}

	public function add_global_groups( $groups ) {
		$groups              = (array) $groups;
		$this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
	}

	public function add_non_persistent_groups( $groups ) {
		$groups                       = (array) $groups;
		$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, $groups ) );
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = $this->multisite ? (int) $blog_id . ':' : '';
	}

	public function reset() {
		$this->switch_to_blog( get_current_blog_id() );
	}

	public function close() {
		return true;
	}

	public function stats() {
		return array(
			'hits'    => $this->cache_hits,
			'misses'  => $this->cache_misses,
			'backend' => $this->has_backend(),
		);
	}

	/** Whether a live Redis/Memcached connection actually backs this request. */
	public function is_connected() {
		return $this->has_backend();
	}
}

global $wp_object_cache;
$wp_object_cache = new Fixer_WP_Object_Cache();

function wp_cache_init() {
	global $wp_object_cache;
	if ( ! ( $wp_object_cache instanceof Fixer_WP_Object_Cache ) ) {
		$wp_object_cache = new Fixer_WP_Object_Cache();
	}
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $data, $group, $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group, $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_flush( $delay = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->flush( $delay );
}

function wp_cache_flush_runtime() {
	global $wp_object_cache;
	return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group( $group );
}

function wp_cache_supports( $feature ) {
	return in_array(
		$feature,
		array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ),
		true
	);
}

function wp_cache_close() {
	global $wp_object_cache;
	return $wp_object_cache->close();
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_reset() {
	global $wp_object_cache;
	$wp_object_cache->reset();
}
