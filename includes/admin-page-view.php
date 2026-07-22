<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $opts */
/** @var array $discovery */
/** @var array $log */
/** @var string $tab */
/** @var array $server_checks */
/** @var array $autoload_options */

$type_labels = array(
	'hook_timing'   => __( 'Időmérés', 'fixer' ),
	'http_capped'   => __( 'Korlátozott HTTP hívás', 'fixer' ),
	'mail_deferred' => __( 'Halasztott email', 'fixer' ),
	'hook_deferred' => __( 'Háttérbe tett hook', 'fixer' ),
	'php_wall_time' => __( 'PHP feldolgozási idő', 'fixer' ),
);

// Group the log by request, and work out the slowest contributor per request.
$by_request = array();
foreach ( $log as $row ) {
	$by_request[ $row->request_id ][] = $row;
}

$requests_summary = array();
foreach ( $by_request as $request_id => $rows ) {
	$total     = 0;
	$slowest   = null;
	$wall_time = null;
	$when      = $rows[0]->created_at;
	foreach ( $rows as $row ) {
		if ( 'hook_timing' === $row->type && null !== $row->duration_ms ) {
			$total += (float) $row->duration_ms;
			if ( null === $slowest || (float) $row->duration_ms > $slowest->duration_ms ) {
				$slowest = $row;
			}
		} elseif ( 'php_wall_time' === $row->type ) {
			$wall_time = $row;
		}
	}
	$requests_summary[] = array(
		'request_id' => $request_id,
		'when'       => $when,
		'total_ms'   => $total,
		'slowest'    => $slowest,
		'wall_time'  => $wall_time,
		'rows'       => $rows,
	);
}
usort(
	$requests_summary,
	function ( $a, $b ) {
		return strcmp( $b['when'], $a['when'] );
	}
);

$latest    = isset( $requests_summary[0] ) ? $requests_summary[0] : null;
$base_url  = admin_url( 'options-general.php?page=' . Fixer_Admin_Page::PAGE_SLUG );
$status_labels = array(
	Fixer_Server_Info::STATUS_OK   => __( 'Rendben', 'fixer' ),
	Fixer_Server_Info::STATUS_WARN => __( 'Érdemes megnézni', 'fixer' ),
	Fixer_Server_Info::STATUS_BAD  => __( 'Figyelmeztetés', 'fixer' ),
);
?>
<div class="wrap fixer-wrap">
	<h1><?php esc_html_e( 'Fixer', 'fixer' ); ?></h1>
	<p><?php esc_html_e( 'Lassú bejelentkezés javítása, szerver diagnosztika, és a betöltési sebességet javító, egyenként ki/be kapcsolható optimalizálások.', 'fixer' ); ?></p>

	<?php if ( isset( $_GET['fixer_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A napló törölve.', 'fixer' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['fixer_transients_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: number of deleted transients */
					esc_html__( '%d lejárt tranzitens törölve.', 'fixer' ),
					(int) $_GET['fixer_transients_deleted'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'login', $base_url ) ); ?>" class="nav-tab <?php echo 'login' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Bejelentkezés', 'fixer' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'server', $base_url ) ); ?>" class="nav-tab <?php echo 'server' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Szerver diagnosztika', 'fixer' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'performance', $base_url ) ); ?>" class="nav-tab <?php echo 'performance' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Teljesítmény', 'fixer' ); ?></a>
	</h2>

	<?php if ( 'login' === $tab && $latest ) : ?>
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
			<?php if ( $latest['wall_time'] ) : ?>
				<p>
					<strong><?php esc_html_e( 'PHP feldolgozási idő (a szerveren belül):', 'fixer' ); ?></strong>
					<?php echo esc_html( $latest['wall_time']->duration_ms ); ?> ms
					<br />
					<em>
						<?php esc_html_e( 'Ha ez a szám sokkal kisebb, mint amennyit a böngésződ mért (pl. 300 ms a 98 másodperc helyett), a késés nem a WordPress kódjában keletkezik, hanem még mielőtt a PHP elkezdte volna feldolgozni a kérést (hálózat, tűzfal, vagy a szerveren várakozó, lefoglalt PHP workerek). Ebben az esetben a szerver/tárhely oldali beállításokat kell megnézni - lásd a "Szerver diagnosztika" fület.', 'fixer' ); ?>
					</em>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( 'server' !== $tab ) : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'fixer_options_group' ); ?>

		<div <?php echo 'login' === $tab ? '' : 'style="display:none;"'; ?>>

			<h2 class="title"><?php esc_html_e( 'Fő kapcsoló', 'fixer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Fixer bekapcsolva', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[master_enabled]" value="1" <?php checked( $opts['master_enabled'] ); ?> />
							<?php esc_html_e( 'Ha ez ki van kapcsolva, a plugin egyáltalán nem avatkozik be semmibe (a Teljesítmény fülön lévő optimalizálások is leállnak).', 'fixer' ); ?>
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

			<h2 class="title"><?php esc_html_e( 'Háttérkérések védelme (Heartbeat, WP-Cron, online állapot)', 'fixer' ); ?></h2>
			<p><?php esc_html_e( 'Előfordulhat, hogy nem maga a bejelentkezési kérés lassú, hanem egy vele egy időben futó másik kérés (pl. böngészőben nyitva hagyott lap "Heartbeat" lekérdezése, az Ultimate Member – Online plugin online állapot lekérdezése, vagy egy Jetpack szinkronizáció) foglalja le a szerver egy PHP-workerét vagy a munkamenet-zárat, és emiatt kell várnia a bejelentkezésnek. Ez a beállítás ezekre a háttérkérésekre is kiterjeszti a fenti HTTP időkorlátot és email-halasztást.', 'fixer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Bekapcsolva', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[guard_background_requests]" value="1" <?php checked( $opts['guard_background_requests'] ); ?> />
							<?php esc_html_e( 'A WP Heartbeat és a WP-Cron kérések automatikusan védve vannak, ha ez be van kapcsolva.', 'fixer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatikus felismerés', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[auto_detect_background_ajax]" value="1" <?php checked( $opts['auto_detect_background_ajax'] ); ?> />
							<?php esc_html_e( '„online”, „presence”, „sync” vagy „status” szót tartalmazó admin-ajax műveletek automatikusan háttérkérésnek számítanak.', 'fixer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fixer_bg_actions"><?php esc_html_e( 'Egyéb ajax művelet nevek', 'fixer' ); ?></label></th>
					<td>
						<input type="text" id="fixer_bg_actions" name="fixer_options[background_ajax_actions]" value="<?php echo esc_attr( implode( ', ', $opts['background_ajax_actions'] ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Vesszővel elválasztva, ha tudod egy plugin pontos ajax action nevét (pl. az Ultimate Member – Online saját lekérdezéséét).', 'fixer' ); ?></p>
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

		</div>

		<div <?php echo 'performance' === $tab ? '' : 'style="display:none;"'; ?>>

			<h2 class="title"><?php esc_html_e( 'Kép- és tartalom-optimalizálás', 'fixer' ); ?></h2>
			<p><?php esc_html_e( 'Ezek a beállítások az egész oldal (nem csak a bejelentkezés) betöltési sebességét javítják: felesleges szkriptek/stílusok eltávolítása, képek lusta betöltése, és a képek tömörítése. Mindegyik visszakapcsolható, és semmi meglévőt nem töröl vagy módosít visszamenőleg.', 'fixer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Emoji szkriptek kikapcsolása', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_disable_emoji]" value="1" <?php checked( $opts['opt_disable_emoji'] ); ?> />
							<?php esc_html_e( 'Eltávolítja a WordPress emoji-felismerő szkriptjét és stílusát minden oldalról (a modern böngészők natívan tudják kezelni az emojikat, erre nincs szükség).', 'fixer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Felesleges <head> elemek eltávolítása', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_clean_head]" value="1" <?php checked( $opts['opt_clean_head'] ); ?> />
							<?php esc_html_e( 'RSD-link, Windows Live Writer manifest, shortlink, oEmbed felismerési linkek és a generátor meta-tag eltávolítása - ezekre a legtöbb oldalnak nincs szüksége.', 'fixer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Képek lusta betöltése (lazy load)', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_lazy_images]" value="1" <?php checked( $opts['opt_lazy_images'] ); ?> />
							<?php esc_html_e( 'A tartalomban lévő, még jelöletlen képekhez hozzáadja a loading="lazy" attribútumot, hogy a böngésző csak akkor töltse be a képet, amikor a látogató odaér.', 'fixer' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Új képek tömörítése feltöltéskor', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_jpeg_quality]" value="1" <?php checked( $opts['opt_jpeg_quality'] ); ?> />
							<?php esc_html_e( 'A mostantól feltöltött JPEG képeknél alacsonyabb tömörítési minőséget használ, hogy kisebb, gyorsabban betöltődő fájlok jöjjenek létre. A már feltöltött képeket nem érinti.', 'fixer' ); ?>
						</label>
						<br />
						<label>
							<?php esc_html_e( 'Minőség (60-92, alapértelmezett WP érték: 82):', 'fixer' ); ?>
							<input type="number" min="60" max="92" name="fixer_options[jpeg_quality]" value="<?php echo esc_attr( $opts['jpeg_quality'] ); ?>" class="small-text" />
						</label>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Háttérterhelés csökkentése', 'fixer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Heartbeat gyakoriságának csökkentése', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_heartbeat_control]" value="1" <?php checked( $opts['opt_heartbeat_control'] ); ?> />
							<?php esc_html_e( 'Ritkítja a WordPress "Heartbeat" AJAX lekérdezéseit, és teljesen kikapcsolja a látogatói (nem admin) oldalakon - kevesebb szerverterhelés, ami közvetve a bejelentkezésnek is segít.', 'fixer' ); ?>
						</label>
						<br />
						<label>
							<?php esc_html_e( 'Gyakoriság adminban (másodperc, min. 15):', 'fixer' ); ?>
							<input type="number" min="15" max="300" name="fixer_options[heartbeat_interval]" value="<?php echo esc_attr( $opts['heartbeat_interval'] ); ?>" class="small-text" />
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bejegyzés-revíziók korlátozása', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_limit_revisions]" value="1" <?php checked( $opts['opt_limit_revisions'] ); ?> />
							<?php esc_html_e( 'A mostantól mentett bejegyzéseknél csak ennyi korábbi revíziót tart meg - a meglévő revíziókat nem törli.', 'fixer' ); ?>
						</label>
						<br />
						<label>
							<?php esc_html_e( 'Megtartott revíziók száma:', 'fixer' ); ?>
							<input type="number" min="0" max="100" name="fixer_options[revisions_to_keep]" value="<?php echo esc_attr( $opts['revisions_to_keep'] ); ?>" class="small-text" />
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'XML-RPC letiltása', 'fixer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fixer_options[opt_disable_xmlrpc]" value="1" <?php checked( $opts['opt_disable_xmlrpc'] ); ?> />
							<?php esc_html_e( 'Letiltja az xmlrpc.php végpontot (gyakori célpontja durva erővel próbálkozó (brute-force) támadásoknak).', 'fixer' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Figyelem: a Jetpack egyes funkciói használhatják az XML-RPC-t. Ha a Jetpack valamelyik funkciója leáll ennek bekapcsolása után, kapcsold ki újra.', 'fixer' ); ?>
						</p>
					</td>
				</tr>
			</table>

		</div>

		<?php submit_button( __( 'Beállítások mentése', 'fixer' ) ); ?>
	</form>
	<?php endif; ?>

	<?php if ( 'login' === $tab ) : ?>
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
	<?php endif; ?>

	<?php if ( 'server' === $tab ) : ?>
		<p><?php esc_html_e( 'Ez a lista csak tájékoztat - semmit nem kapcsol be vagy ki automatikusan. A legtöbb itt jelzett dolog a szerver/tárhely beállításától függ, nem a WordPress-től.', 'fixer' ); ?></p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ellenőrzés', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Érték', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Állapot', 'fixer' ); ?></th>
					<th><?php esc_html_e( 'Magyarázat', 'fixer' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $server_checks as $check ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
					<td><?php echo esc_html( $check['value'] ); ?></td>
					<td>
						<span class="fixer-status fixer-status-<?php echo esc_attr( $check['status'] ); ?>">
							<?php echo esc_html( isset( $status_labels[ $check['status'] ] ) ? $status_labels[ $check['status'] ] : $check['status'] ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $check['hint'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Legnagyobb automatikusan betöltött (autoload) beállítások', 'fixer' ); ?></h2>
		<p><?php esc_html_e( 'Ezeket MINDEN oldalbetöltés (a bejelentkezés is) beolvassa az adatbázisból induláskor. Ha egy plugin itt szokatlanul nagy méretű bejegyzést hagyott, érdemes lehet a pluginnál vagy a fejlesztőjénél utánanézni.', 'fixer' ); ?></p>
		<?php if ( empty( $autoload_options ) ) : ?>
			<p><em><?php esc_html_e( 'Nincs adat.', 'fixer' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Beállítás neve', 'fixer' ); ?></th>
						<th><?php esc_html_e( 'Méret', 'fixer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $autoload_options as $option ) : ?>
					<tr>
						<td><code><?php echo esc_html( $option->name ); ?></code></td>
						<td><?php echo esc_html( size_format( (int) $option->size ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Karbantartás', 'fixer' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'fixer_delete_expired_transients' ); ?>
			<input type="hidden" name="action" value="fixer_delete_expired_transients" />
			<?php submit_button( __( 'Lejárt tranzitensek törlése', 'fixer' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
</div>
