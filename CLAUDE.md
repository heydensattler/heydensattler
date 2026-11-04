# heydensattler.com

Website von HeydenSattler (Immobilienmakler in Augsburg). Die README beschreibt alles im Einzelnen; hier stehen
Ziel, Entscheidungen und der aktuelle Stand. Sprache im Repository: Deutsch (Texte, Bezeichner, Kommentare,
Commit-Nachrichten). Das Repository ist öffentlich (`heydensattler/heydensattler` auf GitHub): keine Zugangsdaten,
Servernamen oder Schlüssel in Dateien oder Commits.

## Zwei Fassungen derselben Seite

- `site/` – die statische Seite (Vorschau auf GitHub Pages), mit `scripts/angebote.py` (Propstack) und
  `scripts/blog.py` (Markdown-Blog). Referenz für Aussehen und Inhalt.
- `wordpress/heydensattler/` – dieselbe Seite als WordPress-Theme für das WordPress bei IONOS. Es muss die statische
  Seite genau wiedergeben („so wie sie ist“): HTML, JSON-LD, Sitemap, `llms.txt`, `robots.txt`, Feed. Gemeinsam sind
  nur CSS und JavaScript (`site/assets/`, `scripts/theme.sh` kopiert sie ins Theme). Texte und Vorlagen ändern sich
  in beiden, solange beide laufen.
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
- Läuft mit PHP 8.0 (live) und 8.3; keine Sprachmittel neuer als PHP 8.0.

## Deploy

- Ein Tag `v…` auf einem Commit von `main` spielt das Theme per SFTP auf heydensattler.com
  (`.github/workflows/theme.yml`): Version = Tag, PHP-Syntax mit 8.0, Host-Schlüssel gegen den von IONOS
  veröffentlichten, Upload in einen neuen Ordner und Tausch, danach Prüfung der Version in `style.css`.
- Zugang im GitHub-Environment `production`: `IONOS_SFTP_HOST`, `IONOS_SFTP_USER`, `IONOS_SFTP_PASSWORD`,
  `IONOS_THEME_DIR`. Die Werte stehen im IONOS-Kundenbereich (Vertrag „IONOS WP Business“ → Hosting → SFTP & SSH;
  Hauptbenutzer mit SFTP + SSH; WordPress liegt unter `/clickandbuilds/Heydensattler/`).
- Tags nur, wenn Sven es will. Die Logs der Läufe sind öffentlich, darum Diagnosen ohne Servernamen
  (`::notice::`-Hinweise sind ohne Anmeldung über die API lesbar).

## Stand (04.11.2026)

- Theme fertig, gegen die statische Seite verglichen, von Agenten geprüft (alle Befunde behoben), committet.
- Deploy funktioniert: `v2026.11.04.5` liegt auf dem Server unter `wp-content/themes/heydensattler`, ist aber
  **nicht aktiviert** – live läuft noch das alte Theme Sway (sway-child) mit WPBakery, Slider Revolution,
  KeyDesign Addon, Redux, WooCommerce 7.4.2 und onOffice. Die Tags `.1` bis `.4` waren Fehlversuche ohne Upload.
- Offen für den Umstieg (Sven, in WordPress):
  1. Die 8 Demo-Beiträge und die Demoseiten von Sway löschen (sonst erscheinen sie im Blog).
  2. Theme „HeydenSattler“ aktivieren, im Admin-Hinweis „Jetzt einrichten“ (Start- und Beitragsseite, Permalinks
     `/blog/%postname%/`, fehlende Seiten).
  3. Propstack-Schlüssel unter Einstellungen → Angebote, dann „Jetzt aktualisieren“.
  4. Danach Plugins des alten Themes prüfen und abschalten.
- Offen bei IONOS: PHP von 8.0 auf 8.3 umstellen (PHP Extended Support kostet laut Preisliste rund 15,62 € im
  Monat); einen Cronjob auf `wp-cron.php` alle 15 Minuten mit `DISABLE_WP_CRON` (der Tarif hat Cronjobs).
- Wenn das Theme live ist: `site/` und die GitHub-Pages-Vorschau abschalten, damit es die Seite nur einmal gibt.

## Ziel und offene Entscheidungen

- Ziel: Das Theme liegt auf GitHub und wird dort von Claude bearbeitet (Cloud-Workspace); ein Tag spielt es nach
  IONOS (erledigt). Inhalte in WordPress bearbeitet Claude über ein MCP.
- MCP (noch nicht eingerichtet): Empfehlung aus der Recherche vom 04.11.2026 – offizielles Plugin „MCP Adapter“
  (vor 1.0) mit eigenen Abilities im Theme, eigener WordPress-Benutzer mit wenigen Rechten (Entwürfe, kein
  Veröffentlichen, kein Löschen) und Anwendungspasswort. Claude Code kann es per Header nutzen; claude.ai braucht
  OAuth, das der Adapter noch nicht hat. Erst auf einer Kopie testen.
- Offen: Bleiben die Texte von Startseite, Vermieten und Verkaufen im Theme (Änderung über GitHub und Tag;
  Empfehlung für jetzt) oder ziehen sie nach WordPress um, damit das MCP sie ändern kann?
- Offen: Darf Claude über das MCP veröffentlichen oder nur Entwürfe anlegen (Empfehlung: nur Entwürfe)?
