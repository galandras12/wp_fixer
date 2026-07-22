<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $opts */
/** @var array $discovery */
/** @var array $log */

$type_labels = array(
	'hook_timing'    => __( 'Időmérés', 'fixer' ),
	'http_capped'    => __( 'Korlátozott HTTP hívás', 'fixer' ),
	'mail_deferred'  => __( 'Halasztott email', 'fixer' ),
	'hook_deferred'  => __( 'Háttérbe tett hook', 'fixer' ),
);

// Group the log by request, and work out the slowest contributor per request.
$by_request = array();
foreach ( $log as $row ) {
	$by_request[ $row->request_id ][] = $row;
}

$requests_summary = array();
foreach ( $by_request as $request_id => $rows ) {
	$total    = 0;
	$slowest  = null;
	$when     = $rows[0]->created_at;
	foreach ( $rows as $row ) {
		if ( 'hook_timing' === $row->type && null !== $row->duration_ms ) {
			$total += (float) $row->duration_ms;
			if ( null === $slowest || (float) $row->duration_ms > $slowest->duration_ms ) {
				$slowest = $row;
			}
		}
	}
	$requests_summary[] = array(
		'request_id' => $request_id,
		'when'       => $when,
		'total_ms'   => $total,
		'slowest'    => $slowest,
		'rows'       => $rows,
	);
}
usort(
	$requests_summary,
	function ( $a, $b ) {
		return strcmp( $b['when'], $a['when'] );
	}
);

$latest = isset( $requests_summary[0] ) ? $requests_summary[0] : null;
?>
<div class="wrap fixer-wrap">
	<h1><?php esc_html_e( 'Fixer – lassú bejelentkezés javítása', 'fixer' ); ?></h1>
	<p><?php esc_html_e( 'Ez a plugin a bejelentkező képernyő felhasználónév/jelszó megadása utáni elhúzódó (30-50 másodperces) beragadását próbálja megszüntetni. Minden funkció külön ki- és bekapcsolható.', 'fixer' ); ?></p>

	<?php if ( isset( $_GET['fixer_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A napló törölve.', 'fixer' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $latest ) : ?>
		<div class="notice notice-info fixer-summary">
			<p>
				<strong><?php esc_html_e( 'Utolsó rögzített bejelentkezés:', 'fixer' ); ?></strong>
				<?php echo esc_html( $latest['when'] ); ?> —
				<?php
				printf(
					/* translators: %s: measured duration in milliseconds */
					esc_html__( 'mért hook-idő összesen: %s ms', 'fixer' ),
					esc_html( round( $latest['total_ms'], 1 ) )
				);
				?>
				<?php if ( $latest['slowest'] ) : ?>
					—
					<?php
					printf(
						/* translators: 1: plugin name, 2: hook name, 3: duration in ms */
						esc_html__( 'a leglassabb: %1$s a(z) „%2$s” hook-on (%3$s ms)', 'fixer' ),
						esc_html( $latest['slowest']->plugin_name ? $latest['slowest']->plugin_name : $latest['slowest']->plugin_slug ),
						esc_html( $latest['slowest']->hook_name ),
						esc_html( $latest['slowest']->duration_ms )
					);
					?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'fixer_options_group' ); ?>

		<h2 class="title"><?php esc_html_e( 'Fő kapcsoló', 'fixer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Fixer bekapcsolva', 'fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fixer_options[master_enabled]" value="1" <?php checked( $opts['master_enabled'] ); ?> />
						<?php esc_html_e( 'Ha ez ki van kapcsolva, a plugin egyáltalán nem avatkozik be semmibe.', 'fixer' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Diagnosztika', 'fixer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Időmérés (profiler)', 'fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fixer_options[profiler_enabled]" value="1" <?php checked( $opts['profiler_enabled'] ); ?> />
						<?php esc_html_e( 'Minden bejelentkezéskor lemért, hogy melyik plugin melyik funkciója mennyi ideig futott. Nem változtat semmin, csak mér.', 'fixer' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Kimenő HTTP kérések korlátozása', 'fixer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bekapcsolva', 'fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fixer_options[http_guard_enabled]" value="1" <?php checked( $opts['http_guard_enabled'] ); ?> />
						<?php esc_html_e( 'Bejelentkezés közben minden kimenő HTTP kérés (pl. egy plugin által hívott külső API) időtúllépését lekorlátozza, hogy egy beragadt külső hívás ne tarthassa fel 30-50 másodpercig a bejelentkezést.', 'fixer' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fixer_http_timeout"><?php esc_html_e( 'Maximális várakozás (másodperc)', 'fixer' ); ?></label></th>
				<td>
					<input type="number" min="1" max="30" id="fixer_http_timeout" name="fixer_options[http_guard_timeout]" value="<?php echo esc_attr( $opts['http_guard_timeout'] ); ?>" class="small-text" />
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Bejelentkezési email értesítések háttérbe tétele', 'fixer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bekapcsolva', 'fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fixer_options[mail_queue_enabled]" value="1" <?php checked( $opts['mail_queue_enabled'] ); ?> />
						<?php esc_html_e( 'A bejelentkezés alatt kiküldött emaileket (pl. „valaki bejelentkezett” értesítők) nem azonnal, hanem egy háttérfolyamatban küldi el, hogy az SMTP kapcsolat felépülése ne lassítsa a bejelentkezést.', 'fixer' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Ajax bejelentkezés felismerése', 'fixer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatikus felismerés', 'fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fixer_options[auto_detect_ajax_login]" value="1" <?php checked( $opts['auto_detect_ajax_login'] ); ?> />
						<?php esc_html_e( 'Minden „login” szót tartalmazó admin-ajax művelet bejelentkezésnek számít (pl. Login With Ajax).', 'fixer' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fixer_ajax_actions"><?php esc_html_e( 'Egyéb ajax művelet nevek', 'fixer' ); ?></label></th>
				<td>
					<input type="text" id="fixer_ajax_actions" name="fixer_options[login_ajax_actions]" value="<?php echo esc_attr( implode( ', ', $opts['login_ajax_actions'] ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Vesszővel elválasztva, ha egy plugin más ajax action nevet használ a bejelentkezéshez.', 'fixer' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Pluginok háttérbe tétele bejelentkezéskor', 'fixer' ); ?></h2>
		<p><?php esc_html_e( 'Az alábbi, jelenleg aktív pluginok kapcsolódnak a bejelentkezés utáni eseményekbe (napló, értesítés, online állapot stb.). A bejelölt pluginoknál ezek a funkciók nem a bejelentkezés közben, hanem közvetlenül utána, egy háttérkérésben futnak le – maga a bejelentkezés (felhasználónév/jelszó ellenőrzése) ettől függetlenül, változatlanul azonnal lezajlik.', 'fixer' ); ?></p>
		<p class="description">
			<?php esc_html_e( 'Figyelem: biztonsági pluginoknál (pl. Wordfence) a sikertelen bejelentkezést rögzítő funkció áthelyezése azt jelenti, hogy az adott plugin néhány tized másodperccel később értesül a próbálkozásról. A tényleges bejelentkezés-védelem (jelszó ellenőrzése, a plugin saját kérésszám-korlátozása) ettől nem gyengül, de ha bizonytalan vagy benne, csak a nem biztonsági jellegű pluginoknál (napló, statisztika, online állapot) használd ezt a funkciót.', 'fixer' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bekapcsolva', 'fixer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fixer_options[deferral_enabled]" value="1" <?php checked( $opts['deferral_enabled'] ); ?> />
						<?php esc_html_e( 'Engedélyezi az alább kiválasztott pluginok háttérbe tételét.', 'fixer' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php if ( empty( $discovery ) ) : ?>
			<p><em><?php esc_html_e( 'Egyelőre nincs felismert plugin a bejelentkezési hookokon (wp_login, wp_login_failed, set_logged_in_cookie). Ez a lista bejelentkezés után frissül.', 'fixer' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped fixer-discovery-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Háttérbe tétel', 'fixer' ); ?></th>
						<th><?php esc_html_e( 'Plugin', 'fixer' ); ?></th>
						<th><?php esc_html_e( 'Érintett hookok', 'fixer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $discovery as $slug => $info ) : ?>
					<tr>
						<td>
							<input type="checkbox" name="fixer_options[deferred_slugs][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $opts['deferred_slugs'], true ) ); ?> />
						</td>
						<td><?php echo esc_html( $info['label'] ); ?> <code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo esc_html( implode( ', ', array_keys( $info['hooks'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php submit_button( __( 'Beállítások mentése', 'fixer' ) ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Napló (utolsó bejelentkezési kísérletek)', 'fixer' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
		<?php wp_nonce_field( 'fixer_clear_log' ); ?>
		<input type="hidden" name="action" value="fixer_clear_log" />
		<?php submit_button( __( 'Napló törlése', 'fixer' ), 'delete', 'submit', false ); ?>
	</form>

	<?php if ( empty( $log ) ) : ?>
		<p><em><?php esc_html_e( 'Még nincs rögzített adat. Jelentkezz be egyszer, hogy legyen mit mérni.', 'fixer' ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Időpont', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Típus', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Plugin', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Hook', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Részlet', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Idő (ms)', 'fixer' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $log as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td><?php echo esc_html( isset( $type_labels[ $row->type ] ) ? $type_labels[ $row->type ] : $row->type ); ?></td>
					<td><?php echo esc_html( $row->plugin_name ? $row->plugin_name : $row->plugin_slug ); ?></td>
					<td><code><?php echo esc_html( $row->hook_name ); ?></code></td>
					<td><?php echo esc_html( $row->detail ); ?></td>
					<td><?php echo null !== $row->duration_ms ? esc_html( $row->duration_ms ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
