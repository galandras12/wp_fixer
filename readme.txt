=== Fixer ===
Contributors: fixer
Tags: login, performance, wp-login, smtp, debug
Requires at least: 5.7
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Megszünteti a bejelentkező képernyő felhasználónév/jelszó megadása utáni 30-50 másodperces beragadását.

== Description ==

A Fixer egyetlen célra készült: megszüntetni azt a jól ismert jelenséget, amikor a bejelentkezés
gomb megnyomása után a WordPress bejelentkező oldal 30-50 másodpercig "lefagy", mielőtt továbbengedné
a felhasználót. Ennek szinte mindig az az oka, hogy valamelyik aktív plugin a bejelentkezés
pillanatában (a `wp_login` esemény körül) egy lassú, szinkron műveletet végez: kimenő SMTP email
küldés, külső API hívás (statisztika, IP-ellenőrzés, geolokáció), vagy nehéz adatbázis-írás.

A plugin négy, egymástól függetlenül ki- és bekapcsolható modulból áll:

1. **Diagnosztika / időmérés** – minden bejelentkezéskor leméri, hogy a bejelentkezéshez kapcsolódó
   hookokon (`authenticate`, `wp_login`, `set_auth_cookie` stb.) melyik plugin melyik funkciója mennyi
   ideig futott, és ezt egy táblázatban mutatja a Beállítások → Fixer oldalon. Ez önmagában semmin nem
   változtat, csak megmutatja, melyik plugin a bűnös.
2. **Kimenő HTTP kérések korlátozása** – bejelentkezés közben minden kimenő HTTP kérés időtúllépését
   lekorlátozza (alapértelmezetten 3 másodpercre), hogy egy beragadt külső API hívás ne tarthassa fel
   a bejelentkezést a WordPress alapértelmezett 30 másodperces időtúllépéséig.
3. **Bejelentkezési emailek háttérbe tétele** – a bejelentkezés alatt kiküldött értesítő emaileket
   (pl. "valaki bejelentkezett" típusú üzenetek) nem azonnal, hanem egy háttérfolyamatban küldi el.
4. **Pluginok háttérbe tétele** – a felismert, bejelentkezéshez kapcsolódó pluginfunkciók (napló,
   online állapot stb.) egyenként kiválaszthatók, hogy a bejelentkezés lezajlása után, külön
   háttérkérésben fussanak le, ne a bejelentkezés közben. A tényleges hitelesítés (felhasználónév/
   jelszó ellenőrzése) ettől mindig érintetlen marad.

A Beállítások → Fixer oldalon két további fül is található:

* **Szerver diagnosztika** – csak tájékoztató jellegű, semmit nem módosít: PHP memória- és
  időkorlátok, OPcache és állandó objektum-gyorsítótár állapota, adatbázis-tábla méretek,
  automatikusan betöltött ("autoload") beállítások mérete (a legnagyobbak listájával), ütemezett
  WP-Cron események és lejárt tranzitensek száma. Ez mutatja meg, hogy egy lassulás oka a
  WordPress-en belül vagy a szerver/tárhely oldalán keresendő-e.
* **Teljesítmény** – az egész oldal (nem csak a bejelentkezés) betöltését gyorsító, egyenként
  ki/be kapcsolható optimalizálások: emoji-szkriptek és felesleges `<head>` elemek eltávolítása,
  képek lusta betöltése, új feltöltésű JPEG képek tömörítése, a Heartbeat API ritkítása/kikapcsolása
  a látogatói oldalakon, bejegyzés-revíziók korlátozása, és az XML-RPC letiltása.
* A "Teljesítmény" fülön egy "Ismert plugin-hibaüzenetek elnémítása" beállítás is található:
  konkrét, ártalmatlan, de zajos "Deprecated" hibaüzeneteket némít el a hibalogból (pl. a Login
  With Ajax `color.php` fájljának PHP 8.1+ alatti figyelmeztetéseit), anélkül, hogy a plugin
  fájljaihoz hozzá kellene nyúlni - így egy pluginfrissítés sem írja felül. Csak a beállított
  fájlnév/üzenet mintákra illeszkedő bejegyzéseket némítja el, minden más hibát és figyelmeztetést
  változatlanul hagy.

Két új fül:

* **Objektum-gyorsítótár** – állandó Redis/Memcached objektum-gyorsítótár teljes admin
  felülettel: kapcsolódási adatok (host, port, jelszó, adatbázis-index, kulcs-előtag),
  "Kapcsolat tesztelése" gomb élő szerverinfóval, "Bekapcsolás"/"Kikapcsolás" (egy kattintással
  visszavonható), és "Gyorsítótár ürítése". A bekapcsolás egy önálló, biztonságos drop-in fájlt
  (`wp-content/object-cache.php`) hoz létre, ami Redis/Memcached hiányában vagy hibája esetén
  automatikusan, hiba nélkül visszaesik a WordPress beépített viselkedésére - sosem a wp-config.php-t
  módosítja.
* A "Bejelentkezés" fülön egy **"beragadt a bejelentkezés?" gomb** jelenik meg a bejelentkező
  űrlap alatt: törli a böngésző sütijeit, local/session storage-át és service worker regisztrációit,
  a szerver oldalon pedig a bejelentkezési sütiket és - ha van - a látogató PHP munkamenetét is.
* A "Teljesítmény" fülön új szakasz: a bejelentkező oldalon (wp-login.php) ténylegesen betöltött
  plugin-szkriptek/stílusok automatikus felismerése valós látogatásokból, egyenkénti kikapcsolási
  lehetőséggel csak a bejelentkező oldalra vonatkozóan, plusz preconnect erőforrás-javaslatok a
  megmaradó külső fájlokhoz.

== Installation ==

1. Töltsd fel a plugin mappáját a `/wp-content/plugins/fixer` könyvtárba.
2. Aktiváld a Pluginok menüben.
3. Nyisd meg a Beállítások → Fixer oldalt, és állítsd be, mely funkciók legyenek bekapcsolva.
4. Jelentkezz ki és be egyszer, hogy a diagnosztikai napló megteljen adattal, majd a "Pluginok
   háttérbe tétele" listában válaszd ki, melyik plugin(oka)t szeretnéd háttérbe tenni.

== Changelog ==

= 1.4.0 =
* Új "Objektum-gyorsítótár" fül: teljes admin felület állandó Redis/Memcached gyorsítótárhoz (kapcsolódási adatok, kapcsolat-teszt, be/kikapcsolás, ürítés), biztonságos, önmagában is hibatűrő drop-in fájllal - sosem nyúl a wp-config.php-hoz.
* Új "beragadt a bejelentkezés?" gomb a bejelentkező oldalon: böngésző- és szerveroldali munkamenet-/süti-tisztítás egy kattintással.
* Új, valós látogatásokból tanuló funkció: a bejelentkező oldalon felesleges plugin-szkriptek/stílusok egyenkénti kikapcsolása, plusz preconnect javaslatok a megmaradó külső fájlokhoz.

= 1.3.0 =
* Új beállítás: konkrét, ismert "Deprecated" hibaüzeneteket elnémító, mintaillesztésen alapuló hibaszűrő (alapból a Login With Ajax `color.php`-jának PHP 8.1+ figyelmeztetéseire állítva). Csak az egyező üzeneteket némítja el, minden más hiba/figyelmeztetés - és bármelyik másik plugin saját hibakezelője - érintetlen marad.

= 1.2.0 =
* Új "Szerver diagnosztika" fül: PHP memória/idő limitek, OPcache, objektum-gyorsítótár, autoload méret és legnagyobb bejegyzések, adatbázis-tábla méretek, cron- és tranziens-számláló, lejárt tranzitensek törlése gombbal.
* Új "Teljesítmény" fül: emoji-szkriptek és felesleges head-elemek eltávolítása, képek lusta betöltése, új JPEG feltöltések tömörítése, Heartbeat ritkítás/kikapcsolás, revízió-korlátozás, XML-RPC letiltása - mind egyenként ki/be kapcsolható.

= 1.1.0 =
* Új diagnosztika: PHP feldolgozási idő mérése (REQUEST_TIME_FLOAT alapján), hogy kiderüljön, a késés a WordPress kódjában vagy azt megelőzően (hálózat, szerver sor, PHP-FPM workerhiány) keletkezik-e.
* A HTTP időkorlát és az email-halasztás mostantól a WP Heartbeat, a WP-Cron és a felismert "online állapot" / szinkronizációs ajax kérésekre is kiterjed, mivel ezek is lefoglalhatnak egy PHP-workert vagy munkamenet-zárat a bejelentkezés elől.

= 1.0.0 =
* Első kiadás.
