=== Fixer ===
Contributors: fixer
Tags: login, performance, wp-login, smtp, debug
Requires at least: 5.7
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Installation ==

1. Töltsd fel a plugin mappáját a `/wp-content/plugins/fixer` könyvtárba.
2. Aktiváld a Pluginok menüben.
3. Nyisd meg a Beállítások → Fixer oldalt, és állítsd be, mely funkciók legyenek bekapcsolva.
4. Jelentkezz ki és be egyszer, hogy a diagnosztikai napló megteljen adattal, majd a "Pluginok
   háttérbe tétele" listában válaszd ki, melyik plugin(oka)t szeretnéd háttérbe tenni.

== Changelog ==

= 1.0.0 =
* Első kiadás.
