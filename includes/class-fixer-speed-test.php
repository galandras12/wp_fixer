<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in (default OFF) graphical speed test: separately tracks how long it
 * takes admin-capable users specifically to log in, and how long the pages
 * they subsequently visit take to render - so a site owner can see their
 * own real-world admin experience over time, not just a single measurement.
 *
 * Samples are capped independently of the main diagnostics log (see
 * Fixer_DB::prune_type()) so frequent admin page views can never crowd out
 * the login-diagnostic rows the rest of the plugin relies on.
 */
class Fixer_Speed_Test {

	const TYPE_LOGIN    = 'speedtest_login';
	const TYPE_PAGELOAD = 'speedtest_pageload';

	const LOGIN_RETENTION    = 50;
	const PAGELOAD_RETENTION = 200;

	public static function init() {
		$opts = Fixer_Settings::get_options();
		if ( empty( $opts['opt_speed_test_enabled'] ) ) {
			return;
		}
		add_action( 'wp_login', array( __CLASS__, 'maybe_record_login' ), PHP_INT_MAX, 2 );
		add_action( 'shutdown', array( __CLASS__, 'maybe_record_pageload' ) );
	}

	public static function maybe_record_login( $user_login, $user = null ) {
		if ( ! ( $user instanceof WP_User ) ) {
			$user = get_user_by( 'login', $user_login );
		}
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return;
		}
		if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return;
		}

		$ms = ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
		Fixer_Logger::log_speedtest( self::TYPE_LOGIN, $ms, $user->user_login );
		Fixer_DB::prune_type( self::TYPE_LOGIN, self::LOGIN_RETENTION );
	}

	public static function maybe_record_pageload() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! empty( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return;
		}

		$ms  = ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		Fixer_Logger::log_speedtest( self::TYPE_PAGELOAD, $ms, $uri );
		Fixer_DB::prune_type( self::TYPE_PAGELOAD, self::PAGELOAD_RETENTION );
	}

	/**
	 * @return object[] oldest-first, so charts read left-to-right chronologically.
	 */
	public static function get_samples( $type, $limit = 100 ) {
		global $wpdb;
		$table   = Fixer_DB::table_name();
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d", $type, $limit ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
		return $results ? array_reverse( $results ) : array();
	}

	public static function stats( array $samples ) {
		if ( empty( $samples ) ) {
			return null;
		}
		$values = array_map(
			function ( $s ) {
				return (float) $s->duration_ms;
			},
			$samples
		);
		return array(
			'count' => count( $values ),
			'avg'   => array_sum( $values ) / count( $values ),
			'min'   => min( $values ),
			'max'   => max( $values ),
		);
	}

	/**
	 * Dependency-free inline SVG bar chart - no chart library, nothing to
	 * load from a CDN. Values are individually escaped; safe to echo as-is.
	 */
	public static function render_chart( array $samples, $good_ms = 1000, $warn_ms = 3000 ) {
		if ( empty( $samples ) ) {
			return '<p><em>' . esc_html__( 'Még nincs adat - ez a diagram az első admin bejelentkezés/oldalbetöltés után töltődik fel.', 'fixer' ) . '</em></p>';
		}

		$values     = array_map(
			function ( $s ) {
				return (float) $s->duration_ms;
			},
			$samples
		);
		$max_value  = max( array_merge( $values, array( $warn_ms * 1.1 ) ) );
		$bar_width  = 8;
		$gap        = 2;
		$height     = 160;
		$width      = count( $values ) * ( $bar_width + $gap );
		$warn_y     = $height - ( $warn_ms / $max_value ) * $height;
		$good_y     = $height - ( $good_ms / $max_value ) * $height;

		$svg  = '<svg viewBox="0 0 ' . esc_attr( max( 1, $width ) ) . ' ' . esc_attr( $height ) . '" width="100%" height="' . esc_attr( $height ) . '" preserveAspectRatio="none" class="fixer-chart" role="img">';
		$svg .= sprintf( '<line x1="0" y1="%1$s" x2="%2$s" y2="%1$s" stroke="#c99a06" stroke-dasharray="4,3" stroke-width="1" />', esc_attr( round( $good_y, 1 ) ), esc_attr( $width ) );
		$svg .= sprintf( '<line x1="0" y1="%1$s" x2="%2$s" y2="%1$s" stroke="#c0392b" stroke-dasharray="4,3" stroke-width="1" />', esc_attr( round( $warn_y, 1 ) ), esc_attr( $width ) );

		foreach ( $values as $i => $v ) {
			$bar_height = max( 1, ( $v / $max_value ) * $height );
			$x          = $i * ( $bar_width + $gap );
			$y          = $height - $bar_height;
			$color      = $v >= $warn_ms ? '#c0392b' : ( $v >= $good_ms ? '#c99a06' : '#1a6b34' );
			$svg       .= sprintf(
				'<rect x="%s" y="%s" width="%s" height="%s" fill="%s"><title>%s ms</title></rect>',
				esc_attr( $x ),
				esc_attr( round( $y, 1 ) ),
				esc_attr( $bar_width ),
				esc_attr( round( $bar_height, 1 ) ),
				esc_attr( $color ),
				esc_attr( round( $v, 1 ) )
			);
		}
		$svg .= '</svg>';
		return $svg;
	}
}
