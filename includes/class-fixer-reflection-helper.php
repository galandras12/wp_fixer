<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves which plugin/theme a hook callback belongs to, using Reflection
 * to find the source file behind a callable (function, method, or closure).
 */
class Fixer_Reflection_Helper {

	private static $plugin_names = null;

	/**
	 * @return array{name:string,file:string,slug:string,label:string}|null
	 */
	public static function describe_callback( $callback ) {
		$ref = null;

		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$ref  = new ReflectionMethod( $callback[0], $callback[1] );
				$name = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1];
			} elseif ( is_string( $callback ) && strpos( $callback, '::' ) !== false ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$ref  = new ReflectionMethod( $class, $method );
				$name = $callback;
			} elseif ( $callback instanceof Closure ) {
				$ref  = new ReflectionFunction( $callback );
				$name = 'Closure';
			} elseif ( is_string( $callback ) && function_exists( $callback ) ) {
				$ref  = new ReflectionFunction( $callback );
				$name = $callback;
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$ref  = new ReflectionMethod( $callback, '__invoke' );
				$name = get_class( $callback ) . '::__invoke';
			} else {
				return null;
			}
			$file = $ref->getFileName();
		} catch ( Throwable $e ) {
			return null;
		}

		$slug  = 'core';
		$label = __( 'WordPress mag (core)', 'fixer' );

		if ( $file && preg_match( '#/wp-content/plugins/([^/]+)#', $file, $m ) ) {
			$slug  = $m[1];
			$label = self::plugin_label_from_slug( $slug );
		} elseif ( $file && strpos( $file, '/wp-content/mu-plugins/' ) !== false ) {
			$slug  = 'mu-plugin';
			$label = __( 'Must-use plugin', 'fixer' );
		} elseif ( $file && strpos( $file, '/wp-content/themes/' ) !== false ) {
			$slug  = 'theme';
			$label = __( 'Aktív téma', 'fixer' );
		}

		return array(
			'name'  => $name,
			'file'  => (string) $file,
			'slug'  => $slug,
			'label' => $label,
		);
	}

	public static function plugin_label_from_slug( $slug ) {
		if ( null === self::$plugin_names ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$plugin_names = array();
			foreach ( get_plugins() as $path => $data ) {
				$folder                        = false !== strpos( $path, '/' ) ? strstr( $path, '/', true ) : basename( $path, '.php' );
				self::$plugin_names[ $folder ] = $data['Name'];
			}
		}

		return isset( self::$plugin_names[ $slug ] ) ? self::$plugin_names[ $slug ] : $slug;
	}
}
