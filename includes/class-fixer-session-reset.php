<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small "stuck? clear my session" link under the login form. Covers both
 * sides of a stuck login: client-side (cookies/localStorage/sessionStorage/
 * Cache API left over from a previous broken attempt) and server-side (the
 * visitor's own WP auth cookies and, if present, their PHP session file -
 * relevant since a PHP session lock held by another request is one of the
 * known causes of a login that hangs for this specific visitor).
 */
class Fixer_Session_Reset {

	const QUERY_VAR = 'fixer_reset_session';

	public static function init() {
		$opts = Fixer_Settings::get_options();
		if ( empty( $opts['opt_session_reset_button'] ) ) {
			return;
		}
		add_action( 'login_init', array( __CLASS__, 'maybe_handle_reset' ) );
		add_action( 'login_footer', array( __CLASS__, 'render_button' ) );
	}

	public static function maybe_handle_reset() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_clear_auth_cookie();

		$cookie_names = array_unique(
			array_filter(
				array(
					defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : '',
					defined( 'AUTH_COOKIE' ) ? AUTH_COOKIE : '',
					defined( 'SECURE_AUTH_COOKIE' ) ? SECURE_AUTH_COOKIE : '',
					defined( 'TEST_COOKIE' ) ? TEST_COOKIE : '',
					session_name(),
				)
			)
		);
		foreach ( $cookie_names as $name ) {
			setcookie( $name, '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
			if ( SITECOOKIEPATH !== COOKIEPATH ) {
				setcookie( $name, '', time() - YEAR_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN );
			}
			unset( $_COOKIE[ $name ] );
		}

		// If the visitor's browser sent a PHP session cookie, resume that exact
		// session just long enough to destroy it - this is what actually clears
		// a stuck/locked session file, not just the cookie pointing at it.
		if ( '' === session_id() && isset( $_COOKIE[ session_name() ] ) ) {
			$sid = preg_replace( '/[^a-zA-Z0-9,\-]/', '', wp_unslash( $_COOKIE[ session_name() ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( $sid ) {
				session_id( $sid );
				@session_start(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		if ( '' !== session_id() ) {
			session_destroy();
		}

		wp_safe_redirect( remove_query_arg( self::QUERY_VAR, wp_login_url() ) );
		exit;
	}

	public static function render_button() {
		$url = add_query_arg( self::QUERY_VAR, '1', wp_login_url() );
		?>
		<p id="fixer-session-reset" style="text-align:center;margin-top:1em;">
			<a href="<?php echo esc_url( $url ); ?>" id="fixer-session-reset-link">
				<?php esc_html_e( 'Beragadt a bejelentkezés? Kattints ide a munkamenet törléséhez', 'fixer' ); ?>
			</a>
		</p>
		<script>
		( function () {
			var link = document.getElementById( 'fixer-session-reset-link' );
			if ( ! link ) {
				return;
			}
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = link.getAttribute( 'href' );
				try {
					document.cookie.split( ';' ).forEach( function ( c ) {
						var name = c.split( '=' )[0].trim();
						if ( name ) {
							document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
						}
					} );
				} catch ( err ) {}
				try { window.localStorage && window.localStorage.clear(); } catch ( err ) {}
				try { window.sessionStorage && window.sessionStorage.clear(); } catch ( err ) {}
				try {
					if ( window.caches && caches.keys ) {
						caches.keys().then( function ( names ) {
							names.forEach( function ( n ) { caches.delete( n ); } );
						} );
					}
				} catch ( err ) {}
				try {
					if ( navigator.serviceWorker && navigator.serviceWorker.getRegistrations ) {
						navigator.serviceWorker.getRegistrations().then( function ( regs ) {
							regs.forEach( function ( r ) { r.unregister(); } );
						} );
					}
				} catch ( err ) {}
				window.location.href = target;
			} );
		} )();
		</script>
		<?php
	}
}
