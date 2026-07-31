<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login-page-specific loading speed: wp-login.php is meant to be a tiny,
 * minimal template, but plugins often enqueue their full front-end script/
 * style stack there too. This discovers (from real page views, accumulated
 * over time) which plugins load assets on the login page specifically, lets
 * the admin opt individual ones out, and adds preconnect/dns-prefetch hints
 * for whatever external hosts remain in use.
 */
class Fixer_Login_Speed {

	const SEEN_OPTION = 'fixer_login_assets_seen';

	private static $external_hosts = array();

	public static function init() {
		$opts = Fixer_Settings::get_options();

		// Discovery (and, if opted in, dequeuing) always runs on the login
		// page - it's cheap and read-only by default, and it's the only way
		// the admin page ever gets real data to show.
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'discover_and_trim' ), PHP_INT_MAX - 1 );

		if ( ! empty( $opts['opt_login_resource_hints'] ) ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'collect_external_hosts' ), PHP_INT_MAX );
			add_filter( 'wp_resource_hints', array( __CLASS__, 'add_resource_hints' ), 10, 2 );
		}
	}

	private static function plugin_slug_from_src( $src ) {
		if ( ! $src ) {
			return null;
		}
		if ( preg_match( '#/wp-content/plugins/([^/]+)#', $src, $m ) && FIXER_SLUG !== $m[1] ) {
			return $m[1];
		}
		return null;
	}

	public static function discover_and_trim() {
		$opts       = Fixer_Settings::get_options();
		$trim_slugs = ! empty( $opts['opt_trim_login_assets'] ) ? $opts['login_trim_slugs'] : array();
		$seen       = get_option( self::SEEN_OPTION, array() );
		$changed    = false;

		foreach ( array( 'script' => wp_scripts(), 'style' => wp_styles() ) as $type => $registry ) {
			foreach ( $registry->queue as $handle ) {
				if ( empty( $registry->registered[ $handle ] ) ) {
					continue;
				}
				$src  = $registry->registered[ $handle ]->src;
				$slug = self::plugin_slug_from_src( $src );
				if ( ! $slug ) {
					continue;
				}

				if ( empty( $seen[ $slug ]['handles'][ $handle ] ) ) {
					$seen[ $slug ]['label']            = Fixer_Reflection_Helper::plugin_label_from_slug( $slug );
					$seen[ $slug ]['handles'][ $handle ] = $type;
					$changed                            = true;
				}

				if ( in_array( $slug, $trim_slugs, true ) ) {
					if ( 'script' === $type ) {
						wp_dequeue_script( $handle );
						wp_deregister_script( $handle );
					} else {
						wp_dequeue_style( $handle );
						wp_deregister_style( $handle );
					}
				}
			}
		}

		if ( $changed ) {
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}

	public static function collect_external_hosts() {
		$opts       = Fixer_Settings::get_options();
		$trim_slugs = ! empty( $opts['opt_trim_login_assets'] ) ? $opts['login_trim_slugs'] : array();
		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$hosts      = array();

		foreach ( array( wp_scripts(), wp_styles() ) as $registry ) {
			foreach ( $registry->queue as $handle ) {
				if ( empty( $registry->registered[ $handle ] ) ) {
					continue;
				}
				$src  = $registry->registered[ $handle ]->src;
				$slug = self::plugin_slug_from_src( $src );
				if ( $slug && in_array( $slug, $trim_slugs, true ) ) {
					continue; // being dequeued anyway, no need to preconnect for it.
				}
				$host = wp_parse_url( $src, PHP_URL_HOST );
				if ( $host && $host !== $site_host ) {
					$hosts[ $host ] = true;
				}
			}
		}

		self::$external_hosts = array_keys( $hosts );
	}

	public static function add_resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type || empty( self::$external_hosts ) ) {
			return $urls;
		}
		foreach ( self::$external_hosts as $host ) {
			$urls[] = array( 'href' => 'https://' . $host, 'crossorigin' => '' );
		}
		return $urls;
	}

	/**
	 * @return array<string, array{label:string, handles: array<string,string>}>
	 */
	public static function get_seen_assets() {
		return get_option( self::SEEN_OPTION, array() );
	}
}
