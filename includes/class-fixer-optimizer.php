<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * General front-end performance fixes, each independently toggleable.
 * Everything here is a well-established, reversible technique that only
 * removes unused output or trims resource usage going forward - nothing
 * here rewrites existing files, retroactively touches the media library,
 * or can break a plugin's own functionality if switched off again.
 */
class Fixer_Optimizer {

	public static function init() {
		$opts = Fixer_Settings::get_options();

		if ( ! empty( $opts['opt_disable_emoji'] ) ) {
			self::disable_emoji();
		}

		if ( ! empty( $opts['opt_clean_head'] ) ) {
			self::clean_head();
		}

		if ( ! empty( $opts['opt_lazy_images'] ) ) {
			add_filter( 'the_content', array( __CLASS__, 'add_missing_lazy_loading' ), 20 );
		}

		if ( ! empty( $opts['opt_heartbeat_control'] ) ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'slow_down_heartbeat' ) );
			if ( ! is_admin() ) {
				add_action( 'init', array( __CLASS__, 'deregister_front_end_heartbeat' ), 1 );
			}
		}

		if ( ! empty( $opts['opt_limit_revisions'] ) ) {
			add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'limit_revisions' ) );
		}

		if ( ! empty( $opts['opt_jpeg_quality'] ) ) {
			add_filter( 'jpeg_quality', array( __CLASS__, 'jpeg_quality' ) );
			add_filter( 'wp_editor_set_quality', array( __CLASS__, 'jpeg_quality_editor' ), 10, 2 );
		}

		if ( ! empty( $opts['opt_disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
	}

	private static function disable_emoji() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( __CLASS__, 'remove_emoji_tinymce_plugin' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	public static function remove_emoji_tinymce_plugin( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	public static function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$urls = array_filter(
				$urls,
				function ( $url ) {
					return false === strpos( $url, 's.w.org' );
				}
			);
		}
		return $urls;
	}

	private static function clean_head() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}

	public static function add_missing_lazy_loading( $content ) {
		if ( empty( $content ) || false === strpos( $content, '<img' ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/<img\s[^>]*>/i',
			function ( $matches ) {
				$tag = $matches[0];
				if ( false !== strpos( $tag, 'loading=' ) ) {
					return $tag;
				}
				return substr_replace( $tag, ' loading="lazy"', 4, 0 );
			},
			$content
		);
	}

	public static function slow_down_heartbeat( $settings ) {
		$opts               = Fixer_Settings::get_options();
		$settings['interval'] = max( 15, (int) $opts['heartbeat_interval'] );
		return $settings;
	}

	public static function deregister_front_end_heartbeat() {
		wp_deregister_script( 'heartbeat' );
	}

	public static function limit_revisions() {
		$opts = Fixer_Settings::get_options();
		return max( 0, (int) $opts['revisions_to_keep'] );
	}

	public static function jpeg_quality() {
		$opts = Fixer_Settings::get_options();
		return min( 92, max( 60, (int) $opts['jpeg_quality'] ) );
	}

	public static function jpeg_quality_editor( $quality, $mime_type ) {
		if ( 'image/jpeg' === $mime_type ) {
			return self::jpeg_quality();
		}
		return $quality;
	}
}
