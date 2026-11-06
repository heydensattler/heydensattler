# heydensattler.com

Website von HeydenSattler (Immobilienmakler in Augsburg). Die README beschreibt alles im Einzelnen; hier stehen
Ziel, Entscheidungen und der aktuelle Stand. Sprache im Repository: Deutsch (Texte, Bezeichner, Kommentare,
Commit-Nachrichten). Das Repository ist öffentlich (`heydensattler/heydensattler` auf GitHub): keine Zugangsdaten,
Servernamen oder Schlüssel in Dateien oder Commits.

## Zwei Fassungen derselben Seite

- `site/` – die statische Seite (bis 05.11.2026 Vorschau auf GitHub Pages, jetzt nur noch lokal), mit `scripts/angebote.py` (Propstack) und
  `scripts/blog.py` (Markdown-Blog). Referenz für Aussehen und Inhalt.
- `wordpress/heydensattler/` – dieselbe Seite als WordPress-Theme für das WordPress bei IONOS. Es muss die statische
  Seite genau wiedergeben („so wie sie ist“): HTML, JSON-LD, Sitemap, `llms.txt`, `robots.txt`, Feed. Gemeinsam sind
  nur CSS und JavaScript (`site/assets/`, `scripts/theme.sh` kopiert sie ins Theme). Seit die Vorschau aus ist
  (05.11.2026), ändern sich Texte und Vorlagen nur noch im Theme; `site/` bleibt auf dem Stand von damals.
- Prüfen: `wordpress/test/einrichten.sh` (Docker, PHP 8.0, http://localhost:8733) und dann die Seiten mit
  `python3 scripts/angebote.py --beispiel` und `.venv/bin/python scripts/blog.py --beispiel --ordner _beispiel`
  (Ausgabe in `_beispiel/`) vergleichen. Abweichen dürfen nur absolute statt relative Adressen, Bilder aus der
  Mediathek und WordPress' eigene `<link>`/`<script>`-Tags.

## Was im Theme wo herkommt

- Startseite, Vermieten, Verkaufen, 404: Vorlagen im Theme (Texte, FAQ und JSON-LD aus denselben Listen).
- Blog: WordPress-Beiträge (Auszug = description, Beitragsbild = image, Feld „Autor“). Geplante Beiträge, Entwürfe
  und Beiträge mit Passwort erscheinen in keiner Liste, keinem Feed, keiner Sitemap; Links auf sie bleiben Text.
- Impressum, Datenschutz: normale Seiten; ihre WPBakery-Shortcodes (`[vc_row]` …) blendet das Theme aus.
- Angebote: das Theme lädt sie alle 30 Minuten selbst aus Propstack (WP-Cron), Einstellungen → Angebote oder
  Konstanten `HS_PROPSTACK_*` in `wp-config.php`. Seiten lesen nur den gespeicherten Stand.
- Nach dem Hochladen stellt sich das Theme selbst ein: fehlt eine seiner Adressregeln (`hs_regel()`), liest es die
  Regeln neu ein. Kein Klick in WordPress nötig.
- Läuft live mit PHP 8.3 (seit 05.11.2026); keine Sprachmittel neuer als PHP 8.0, der Deploy prüft mit 8.0.

## Deploy

- Ein Tag `v…` auf einem Commit von `main` spielt das Theme per SFTP auf heydensattler.com
  (`.github/workflows/theme.yml`): Version = Tag, PHP-Syntax mit 8.0, Host-Schlüssel gegen den von IONOS
  veröffentlichten, Upload in einen neuen Ordner und Tausch, danach Prüfung der Version in `style.css`.
- Zugang im GitHub-Environment `production`: `IONOS_SFTP_HOST`, `IONOS_SFTP_USER`, `IONOS_SFTP_PASSWORD`,
  `IONOS_THEME_DIR`. Die Werte stehen im IONOS-Kundenbereich (Vertrag „IONOS WP Business“ → Hosting → SFTP & SSH;
  Hauptbenutzer mit SFTP + SSH; WordPress liegt unter `/clickandbuilds/Heydensattler/`).
- Tags nur, wenn Sven es will. Die Logs der Läufe sind öffentlich, darum Diagnosen ohne Servernamen
  (`::notice::`-Hinweise sind ohne Anmeldung über die API lesbar).

## Stand (05.11.2026)

- Das Theme „HeydenSattler“ ist live (zuletzt `v2026.11.05.7`) und lädt die Angebote selbst aus Propstack. Auf
  den Seiten laden keine Plugins mehr etwas; `/blog/` gibt 404 (noch keine Beiträge).
- Seit 05.11.: Der Preis ist nur bei „Preis auf Anfrage“ (`price_on_inquiry`) verborgen; `show_price_in_expose`
  zählt nicht, die API meldete es bei Objekten mit sichtbarem Preis als aus. Favicon ist das alte Website-Icon von
  2022 (grünes HS-Zeichen, transparent; Apple-Touch-Icon mit weißem Grund). `/favicon.ico` erreicht WordPress bei
  IONOS nie, darum legt der Deploy die Datei in den Hauptordner (die alte liegt dort als `favicon.ico.alt`).
- Aufgeräumt am 05.11.: Die Plugins von Sway (WooCommerce, WPBakery, Slider Revolution, KeyDesign Addon, Redux,
  onOffice, Contact Form 7, Envato Market) und die Themes Sway/Sway Child sind gelöscht; übrig sind Really Simple
  SSL und Twenty Twenty-Five als Reserve. Die Subdomain `tour` (Ogulo-Rundgänge) ist gelöscht. PHP 8.3 auf
  heydensattler.com und `s912022626.online.de`; der Zusatzartikel „PHP 8.0 Extended Support“ ist zum 25.11.2026
  gekündigt. Jede Adresse über http oder einen anderen Namen leitet per 301 auf https://heydensattler.com.
- WP-Cron: Cronjobs bei IONOS gehen im Tarif nur täglich, darum ruft `.github/workflows/wp-cron.yml` alle
  15 Minuten `wp-cron.php` auf (ohne `DISABLE_WP_CRON`, damit Besuche es weiter anstoßen, falls GitHub den Workflow
  pausiert).
- Offen in WordPress: die inaktiven Plugins Breadcrumb NavXT, Custom Fonts und Simple Banner löschen (das Theme
  braucht sie nicht). Demo-Beiträge und alte Seiten liegen seit 06.11. im Papierkorb; der ist noch zu leeren.
- www (05.11.): Der DNS-Eintrag `www` ist ein A-Eintrag auf den IONOS-Server (vorher CNAME auf Perspective). Ein
  AAAA-Eintrag für www geht bei IONOS nicht, ohne den A-Eintrag zu verlieren. Das Zertifikat gilt für
  `*.heydensattler.com`; das Theme leitet jeden anderen Namen per 301 auf heydensattler.com (`grundlagen.php`).
- Vorschau auf GitHub Pages seit 05.11. abgeschaltet (`pages.yml`, `scripts/pages.py` entfernt). `site/` bleibt
  Quelle für `site/assets/`; Texte und Vorlagen ändern sich nur noch im Theme.
- `404.php` unterscheidet seit 05.11. verschwundene Angebote (`/angebote/…`) von anderen Adressen. Workflows nutzen
  `actions/checkout@v5` (Node.js 24) und `ubuntu-24.04`. Offen: `favicon.ico.alt` (WordPress-Logo) im Hauptordner
  auf dem Server kann weg.

## Ziel und offene Entscheidungen

- Ziel: Das Theme liegt auf GitHub und wird dort von Claude bearbeitet (Cloud-Workspace); ein Tag spielt es nach
  IONOS (erledigt). Inhalte in WordPress bearbeitet Claude über ein MCP.
- MCP (eingerichtet 06.11.2026, `v2026.11.06.1`): offizielles Plugin „MCP Adapter“ 0.7.1, eigene Abilities im Theme (`inc/mcp.php`:
  Beiträge auflisten, lesen, anlegen/ändern/veröffentlichen, Bilder hochladen; nichts löschen, keine Seiten), eigener
  WordPress-Benutzer `claude` mit Rolle „Autor“ (entschieden: darf veröffentlichen) und Anwendungspasswort. Claude
  Code nutzt es per Basic-Auth-Header auf `/wp-json/mcp/mcp-adapter-default-server`; claude.ai braucht OAuth, das
  der Adapter noch nicht hat. In Claude Code als Server `heydensattler-wp` (Benutzerebene); der Adapter bietet drei
  Werkzeuge (`mcp-adapter-discover-abilities`, `-get-ability-info`, `-execute-ability`), die Fähigkeiten heißen
  `heydensattler/…`.
- Offen: Bleiben die Texte von Startseite, Vermieten und Verkaufen im Theme (Änderung über GitHub und Tag;
  Empfehlung für jetzt) oder ziehen sie nach WordPress um, damit das MCP sie ändern kann?
