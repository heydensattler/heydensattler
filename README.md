# heydensattler.com

Neue Website von HeydenSattler, Immobilienmakler in Augsburg. Look und Inhalte entsprechen der bestehenden
Corporate Identity (Schriften, Farben, Bilder); neu sind das responsive Layout, schnellere Bilder sowie SEO und GEO
(strukturierte Daten, FAQ, `llms.txt`).

## Aufbau

- `site/` – was auf den Server kommt. Statisches HTML, kein Build-Schritt.
  - `index.html` – Startseite
  - `leistungen/vermieten/`, `leistungen/verkaufen/` – Leistungsseiten (gleiche Adressen wie bisher)
  - `robots.txt`, `sitemap.xml`, `llms.txt`
  - `wp-content/uploads/2022/05/` – Hero-Bild als WebP/JPEG und die Schriften, unter denselben Pfaden wie auf dem
    bisherigen Server
  - `404.html` – Seite für nicht mehr vorhandene Adressen (z. B. verkaufte Angebote)
  - `angebote/<slug>/` – eine Detailseite pro Immobilie; entsteht beim Build und ist nicht im Repository
  - `blog/` – Blogübersicht, Beiträge und Feed; entsteht beim Build aus `inhalte/blog/` und ist nicht im Repository
- `inhalte/blog/` – die Blogbeiträge als Markdown, Bilder in `inhalte/blog/bilder/`, Vorlage `_vorlage.md`
- `site/assets/` – gemeinsames CSS und JavaScript aller Seiten, `galerie.js` nur auf den Angebotsseiten
- `docs/screenshots/` – Vorher/Nachher und Ansichten der Seiten (Desktop 1440 px, Handy 390 px)
- `scripts/schriften.sh` – holt die lizenzierten Söhne-Schriften vom Live-Server (nicht im Repository)
- `scripts/angebote.py` – erzeugt Angebote und Detailseiten aus Propstack (siehe unten)
- `scripts/beispiel-angebote.json` – drei Beispielobjekte in der Struktur der Propstack-API V2
- `scripts/blog.py` – erzeugt den Blog aus `inhalte/blog/` (siehe unten), `scripts/beispiel-blog/` Beispielbeiträge
- `requirements.txt` – Python-Markdown in fester Version, für `scripts/blog.py` und die Beispielbeiträge des Test-WordPress
- `.github/workflows/theme.yml` – spielt das Theme bei einem Tag `v…` auf heydensattler.com,
  `.github/workflows/wp-cron.yml` stößt dort alle 15 Minuten WP-Cron an
- `wordpress/heydensattler/` – dieselbe Website als WordPress-Theme (siehe [WordPress-Theme](#wordpress-theme)),
  `wordpress/test/` ein lokales WordPress zum Testen
- `scripts/theme.sh` – baut das installierbare Theme nach `_theme/` (Ordner und ZIP)

## Vorschau auf GitHub Pages (abgeschaltet)

Bis zum 05.11.2026 lief `site/` als Vorschau auf GitHub Pages. Seit das Theme live ist, gibt es die Seite nur noch
auf heydensattler.com; Workflow und Build-Skript sind entfernt. `site/` bleibt als Quelle für CSS und JavaScript
(`scripts/theme.sh` kopiert `site/assets/` ins Theme) und lässt sich weiter lokal ansehen (siehe unten). Texte und
Vorlagen ändern sich nur noch im Theme.

## Lokal ansehen

```bash
scripts/schriften.sh
python3 -m http.server 8731 --directory site
```

Dann http://localhost:8731 öffnen.

## Angebote aus Propstack

Beim Build (und alle 30 Minuten) lädt `scripts/angebote.py` die Objekte aus der Propstack-API und schreibt:

- auf der Startseite die Liste unter `#angebote` und das JSON-LD `ItemList`, jeweils nur zwischen den Markierungen
  `<!-- angebote:…:start -->` und `<!-- angebote:…:ende -->` (von Hand dort Geändertes wird überschrieben),
- pro Objekt eine Detailseite `/angebote/<objektart>-<ort>-<objektnummer>/` mit Galerie (Bilder und Grundrisse),
  Eckdaten, Energieausweis, Beschreibung, Ausstattung, Lage, Kontakt, Open-Graph-Vorschau und JSON-LD
  `RealEstateListing`. Die Adresse ändert sich nicht, wenn sich der Preis ändert; den Link kann man Kunden schicken,
- die Angebote in `sitemap.xml` und `llms.txt`.

Ein verkauftes Objekt verschwindet beim nächsten Lauf; alte Links zeigen dann `404.html` („Dieses Angebot ist nicht
mehr verfügbar“).

**Schlüssel:** Repository-Secret `PROPSTACK_API_KEY` (Settings → Secrets and variables → Actions). Standard ist die
Propstack-API V1; dafür ist der hinterlegte Schlüssel. Für die API V2 die Repository-Variable `PROPSTACK_API_VERSION`
auf `2` setzen und einen V2-Schlüssel hinterlegen (am besten nur lesend); dann schreibt der Build Name und Rechte des
Schlüssels ins Log und warnt, wenn er schreiben oder Kontakte lesen darf. Ohne Secret bleibt der eingecheckte Stand
der Startseite. Ist Propstack nicht erreichbar, scheitert der Lauf, und die zuletzt veröffentlichte Seite bleibt
online.

**Welche Objekte erscheinen:**

- Ist die Repository-Variable `PROPSTACK_PORTAL_ID` gesetzt (Settings → Secrets and variables → Actions →
  Variables), nur Objekte, die in Propstack auf diesem Portal veröffentlicht sind, und davon nur die Bilder, die für
  dieses Portal freigegeben sind. Dafür die Website in Propstack als eigenes Portal anlegen und Objekte dort wie bei
  ImmoScout freigeben; die ID des Portals steht im Log, wenn die Variable falsch ist. Geht nur mit API V2
  (`PROPSTACK_API_VERSION=2`); mit V1 filtert der Build nach Status und warnt im Log.
- Ohne Variable alle nicht archivierten Objekte im Status „Vermarktung“ oder „Reserviert“. Andere Status über die
  Repository-Variable `PROPSTACK_STATUS` (Namen, kommagetrennt). Das Feld `nonpublic` reicht nicht: Im Konto ist kein
  Status so markiert, auch „Akquise“ (rund 600 Objekte) und „Abgeschlossen“ nicht.
- Sicherheitsgrenze: Würden mehr als 40 Objekte erscheinen, bricht der Lauf ab und die bisherige Seite bleibt online
  (Grenze über `PROPSTACK_MAX_ANGEBOTE`). `python3 scripts/angebote.py --diagnose` (mit Schlüssel) zeigt je Status
  die Anzahl der Objekte, ohne etwas zu schreiben.

In beiden Fällen gilt: nie als privat markierte Bilder, nie Straße, Hausnummer oder Koordinaten (nur PLZ und Ort),
nie Eigentümer-, Makler- oder Kontaktdaten; „Preis auf Anfrage“ nur, wenn in Propstack „Preis auf Anfrage“ gesetzt
ist. „Preis im Exposé anzeigen“ zählt seit dem 05.11.2026 nicht mehr: Die Schnittstelle meldete es bei Objekten als
ausgeschaltet, deren Preis in Propstack sichtbar war, und Propstacks eigene öffentliche Ansicht zeigt den Preis dann auch.

**Lokal ansehen ohne Schlüssel** (schreibt nach `_beispiel/`, nie nach `site/`):

```bash
python3 scripts/angebote.py --beispiel
```

```bash
python3 -m http.server 8731 --directory _beispiel
```

Mit `PROPSTACK_PORTAL_ID=4711` davor zeigt das Beispiel den Filter nach Portal.

## Blog

Beiträge sind Markdown-Dateien in `inhalte/blog/` (Dateien mit `_` am Anfang zählen nicht). Am einfachsten
`inhalte/blog/_vorlage.md` kopieren; dort ist jedes Feld erklärt. Das Front-Matter zwischen den beiden `---`:

| Feld | |
| --- | --- |
| `title` | Pflicht |
| `publish_date` | Pflicht. `2026-11-03` oder `2026-11-03 09:00`, Zeit in Augsburg (Europe/Berlin); nur Datum = 00:00 Uhr |
| `description` | für Suchmaschinen, Vorschau beim Teilen und die Karte; fehlt sie, der Anfang des ersten Absatzes |
| `author` | `cedric-heyden` oder `anna-heyden` (die Personen der Startseite); leer = HeydenSattler |
| `slug` | Adresse `/blog/<slug>/`; ohne Angabe aus dem Titel (ä → ae usw.). Nach dem Erscheinen nicht mehr ändern |
| `image`, `image_alt` | Bild relativ zur Datei, z. B. `bilder/foto.jpg` (JPEG, PNG, WebP, GIF; etwa 1600 px breit) |
| `updated` | Datum der letzten inhaltlichen Änderung |
| `draft` | `true` = Entwurf, erscheint nie |

Im Text: `##` für Zwischenüberschriften, Listen, Zitate (`>`), Tabellen, Bilder (`![Beschreibung](bilder/x.jpg
"Bildunterschrift")`) und Links auf andere Beiträge per Dateiname (`[Text](anderer-beitrag.md)`). HTML-Kommentare
erscheinen nicht auf der Website.

**Wann ein Beitrag erscheint:** `scripts/blog.py` läuft bei jedem Build. Es veröffentlicht nur Beiträge, deren
`publish_date` erreicht ist und die kein Entwurf sind; alles andere steht in keiner erzeugten Datei (keine Seite,
keine Karte auf der Startseite, kein Feed, keine Sitemap, kein `llms.txt`, kein Vor/Zurück-Link, kein Bild). Da der
Build alle 30 Minuten läuft, erscheint ein Beitrag bis zu etwa 30 Minuten nach seinem Termin; GitHub startet
zeitgesteuerte Läufe bei hoher Last manchmal auch später. Wichtig: GitHub pausiert zeitgesteuerte Workflows in
öffentlichen Repositorys nach 60 Tagen ohne Aktivität im Repository. Dann erscheinen geplante Beiträge erst nach dem
nächsten Push oder nach „Enable workflow“ unter Actions → GitHub Pages; GitHub schickt vorher eine E-Mail.

> **Das Repository ist öffentlich.** Eine Markdown-Datei mit künftigem Datum oder `draft: true` ist auf GitHub
> sofort für jeden lesbar, sobald sie gepusht ist, auch wenn die Website sie noch nicht zeigt. Wer das nicht will:
> entweder das Repository privat stellen (GitHub Pages aus privaten Repositorys braucht einen kostenpflichtigen
> Plan, z. B. GitHub Pro oder Team), oder Entwürfe außerhalb des Repositorys schreiben und erst am Tag der
> Veröffentlichung (oder kurz davor) einchecken.

Erzeugt werden `/blog/` (Übersicht, neueste zuerst), `/blog/<slug>/` (Beitrag mit Autor, Datum, Lesedauer, Bild,
Kontakt, Links zum vorherigen und nächsten Beitrag), `/blog/feed.xml` (Atom), auf der Startseite der Abschnitt
„Aktuelles“ mit den drei neuesten Beiträgen, der Menüpunkt „Blog“ in allen Seiten (auch den Angebotsseiten, die den
Kopf der Startseite übernehmen) und die Einträge in `sitemap.xml` und `llms.txt`, jeweils zwischen den Markierungen
`<!-- blog:…:start -->` und `<!-- blog:…:ende -->`. Jeder Beitrag hat Canonical, Open Graph und JSON-LD
`BlogPosting` (Autor und Herausgeber verweisen auf `#cedric-heyden`/`#anna-heyden` und `#firma`) mit
`BreadcrumbList`, die Übersicht `Blog`. Ohne veröffentlichten Beitrag gibt es nichts davon: keinen Menüpunkt, keinen
Abschnitt, keine Seite.

Ist ein Beitrag fehlerhaft (unbekanntes Feld, ungültiges Datum, fehlendes Bild, doppelter Slug), bricht der Build
mit Datei und Zeile im Log ab, und die zuletzt veröffentlichte Seite bleibt online. Das gilt auch für künftige
Beiträge, damit der Fehler nicht erst an ihrem Termin auffällt; Angebote werden so lange ebenfalls nicht
aktualisiert.

**Warum Python-Markdown:** Beiträge schreiben Menschen, nicht Entwickler; Python-Markdown setzt die übliche
Markdown-Schreibweise samt Tabellen, verschachtelten Listen und Sonderfällen zuverlässig um, was ein eigener Parser
erst mühsam lernen müsste. Die Version steht fest in `requirements.txt`, damit sich die Ausgabe nicht von selbst
ändert. Das Front-Matter liest `blog.py` selbst (eine einfache YAML-Teilmenge), mit klaren Fehlermeldungen.

**Lokal ansehen** (Beispiele aus `scripts/beispiel-blog/`, schreibt nach `_beispiel-blog/`, nie nach `site/`):

```bash
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
```

```bash
.venv/bin/python scripts/blog.py --beispiel
```

```bash
python3 -m http.server 8731 --directory _beispiel-blog
```

Mit `--jetzt "2027-01-15 08:00"` zeigt das Beispiel den Stand zu einem anderen Zeitpunkt; mit `--beispiel --inhalte
inhalte/blog` die echten Beiträge, ebenfalls in `_beispiel-blog/`. Zusammen mit den Beispielangeboten:
`python3 scripts/angebote.py --beispiel` und danach `.venv/bin/python scripts/blog.py --beispiel --ordner _beispiel`.

## WordPress-Theme

`wordpress/heydensattler/` ist die Website als Theme für das WordPress von heydensattler.com (IONOS, PHP 8.0).
Seiten, Adressen, strukturierte Daten, Sitemap, `llms.txt` und Feed sind dieselben wie in `site/`, CSS und
JavaScript auch: `scripts/theme.sh` kopiert sie aus `site/assets/` ins Theme.

| Seite | Woher der Inhalt kommt |
| --- | --- |
| Startseite, Vermieten, Verkaufen | Vorlagen im Theme: `front-page.php`, `page-vermieten.php`, `page-verkaufen.php` (Ablauf in `template-parts/leistung.php`). Texte, Fragen und Ablauf dort ändern; das JSON-LD entsteht aus denselben Listen |
| Immobilienwissen (Beiträge) | WordPress-Beiträge: Titel, Textauszug (= `description`), Beitragsbild (= `image`, Alternativtext aus der Mediathek; erscheint auf den Karten der Übersicht und unter „Aktuelles Immobilienwissen“ und als Vorschaubild beim Teilen, nicht über dem Beitragstext; empfohlen 1800 × 1200 px, 3:2) und das Feld „Autor“ in der Seitenleiste (Cedric Heyden, Anna Heyden oder HeydenSattler) |
| Impressum, Datenschutzerklärung | normale Seiten aus dem Editor (`page.php`) |
| Angebote | lädt das Theme selbst aus Propstack (siehe unten) |

Geplante Beiträge erscheinen ab ihrem Datum, Entwürfe nie; vorher stehen sie nirgends (keine Karte, kein Feed, keine
Sitemap, kein Menüpunkt). Ein Link aus einem anderen Beitrag auf einen geplanten Beitrag bleibt bis dahin nur Text,
wie bisher in `blog.py`, ebenso HTML-Kommentare: Sie erscheinen nicht. Beiträge mit Passwort stehen nur unter ihrer
eigenen Adresse (mit dem Passwortfeld von WordPress), nicht in Übersicht, Archiven, Feed, Sitemap, `llms.txt`
oder als Nachbar. Die eigenen Feeds von WordPress (`/feed/`, `/immobilienwissen/feed/`) führen zu `/immobilienwissen/feed.xml`. Ohne
veröffentlichten Beitrag gibt es `/immobilienwissen/`, den Feed und den Menüpunkt nicht.

Der Bereich hieß bis 07.11.2026 „Blog“ unter `/blog/` (Entscheidung der Nutzerin: Name wie die Instagram-Reihe).
Name und Adresse stehen in `HS_BLOG_NAME`/`HS_BLOG_PFAD` (`inc/blog.php`). Alte Adressen `/blog/…` leiten dauerhaft
auf `/immobilienwissen/…` weiter; steht die Website noch auf `/blog/%postname%/`, stellt das Theme sie beim nächsten
Aufruf selbst um (Seite „blog“ wird zu „immobilienwissen“, Permalinks neu).

Die Adressen sind dieselben wie bisher, dazu `/immobilienwissen/feed.xml`, `/sitemap.xml`, `/llms.txt` und `/robots.txt`; die
eigene Sitemap von WordPress (`wp-sitemap.xml`) ist aus. `/leistungen/` führt zu den Leistungen auf der Startseite,
Anhangseiten der Mediathek führen zur Datei.

**Installieren:**

```bash
scripts/theme.sh
```

`_theme/heydensattler.zip` unter Design → Themes → Theme hochladen und aktivieren. Oben im Admin steht dann, was an
den Einstellungen noch fehlt, mit dem Knopf „Jetzt einrichten“: Er legt fehlende Seiten an (Startseite, Immobilienwissen,
Leistungen mit Vermieten und Verkaufen), stellt Start- und Beitragsseite ein und setzt die Beitragsadressen auf
`/immobilienwissen/beitragsname/`. Bestehende Seiten bleiben, wie sie sind, auch unveröffentlichte; der Hinweis nennt sie dann.

Vor dem Umstieg auf heydensattler.com:

- Die 8 Beispielbeiträge des bisherigen Themes Sway („Hello world!“, „Classic“, „Video Post“ …) löschen, sonst
  erscheinen sie im Blog; ebenso die Demoseiten (`/leistungen/team-members/`, `/portfolios/…` usw.).
- Impressum und Datenschutzerklärung stecken in Layout-Shortcodes von WPBakery (`[vc_row]`, `[vc_column_text]` …).
  Das Theme lässt die Hüllen weg und zeigt nur den Text, auch wenn WPBakery später abgeschaltet wird. Plugins, die
  nur zum alten Theme gehören (WPBakery, Slider Revolution, KeyDesign Addon, Redux), danach prüfen und abschalten;
  sie laden sonst weiter ihr CSS und JavaScript.
- Liegt im Hauptverzeichnis des Servers eine echte `robots.txt`, `sitemap.xml` oder `llms.txt`, liefert der Server
  diese statt der des Themes aus: dann löschen.
- PHP braucht die Erweiterung mbstring; fehlt sie, sagt es der Hinweis im Admin. Getestet mit PHP 8.0 und 8.3.

Eine neue Version ist dieselbe ZIP-Datei (WordPress fragt, ob es das Theme ersetzen soll), oder die Dateien werden
über den alten Theme-Ordner kopiert. Danach ist nichts zu tun: Fehlt eine Adressregel des Themes in WordPress oder
lautet sie anders, liest das Theme beim nächsten Aufruf alle Regeln neu ein. Die Version im Theme setzt
`scripts/theme.sh` (Datum und Commit, `-lokal` bei ungespeicherten Änderungen, oder `VERSION=…`).

**Veröffentlichen per Tag:** Ein Tag `v…` auf einem Commit von `main` spielt das Theme auf heydensattler.com
(`.github/workflows/theme.yml`):

```bash
git tag v2026.11.04.1 && git push origin v2026.11.04.1
```

Der Workflow baut das Theme mit dem Tag als Version, prüft die PHP-Syntax mit PHP 8.0, lädt es per SFTP in einen
neuen Ordner und tauscht ihn gegen den alten (der als `heydensattler.alt` bis zum nächsten Mal liegen bleibt). Der
Server muss den Schlüssel haben, den IONOS veröffentlicht. Zum Schluss prüft er, dass
`/wp-content/themes/heydensattler/style.css` die neue Version zeigt. Zugang im GitHub-Environment `production`:
`IONOS_SFTP_HOST`, `IONOS_SFTP_USER`, `IONOS_SFTP_PASSWORD`, `IONOS_THEME_DIR`
(`/clickandbuilds/Heydensattler/wp-content/themes`). Zurück auf eine frühere Version: den Lauf ihres Tags unter
Actions neu starten.

**Angebote:** Das Theme lädt die Angebote alle 30 Minuten aus Propstack (WP-Cron) und speichert sie in WordPress; die
Seiten zeigen immer den zuletzt gespeicherten Stand, Propstack wird beim Aufruf einer Seite nie gefragt. Regeln wie
oben unter „Angebote aus Propstack“: dieselben Filter, dieselbe Obergrenze, nie private Bilder, Adressen oder
Kontaktdaten. Scheitert ein Lauf, bleibt der bisherige Stand online.

- Einstellungen → Angebote: Propstack-Schlüssel, API-Version, Portal-ID, Status und Höchstzahl, dazu „Jetzt
  aktualisieren“, das Protokoll des letzten Laufs und „Diagnose starten“ (wie `angebote.py --diagnose`). Der
  Schlüssel wird nach dem Speichern nie wieder angezeigt.
- Statt im Admin auch in `wp-config.php`, dann ist das Feld gesperrt: `HS_PROPSTACK_API_KEY`,
  `HS_PROPSTACK_API_VERSION`, `HS_PROPSTACK_PORTAL_ID`, `HS_PROPSTACK_STATUS`, `HS_PROPSTACK_MAX_ANGEBOTE`. Der
  Schlüssel gehört nie ins Repository.
- WP-Cron läuft nur, wenn jemand die Website aufruft. Pünktlicher ist, falls der IONOS-Tarif Cronjobs hat, einer, der
  alle 15 Minuten `https://heydensattler.com/wp-cron.php` aufruft, mit `define('DISABLE_WP_CRON', true);` in
  `wp-config.php`.

**Lokal testen** (Docker und die Python-Umgebung aus „Blog“):

```bash
wordpress/test/einrichten.sh
```

Startet WordPress mit PHP 8.0 unter http://localhost:8733 (nur von diesem Rechner aus; Admin unter `/wp-admin/`,
admin / admin), mit den Beispielbeiträgen aus `scripts/beispiel-blog/` (einer geplant, einer Entwurf), den Angeboten
aus `scripts/beispiel-angebote.json` und einer Datenschutzerklärung in WPBakery-Shortcodes. WP-Cron läuft dort über
den Container `cli` jede Minute. Theme und `site/assets/` sind eingehängt; Änderungen gelten beim nächsten Laden der
Seite, neue Adressregeln auch. Von vorn: `docker compose -f wordpress/test/compose.yaml down -v`.

## Corporate Identity

| Rolle | Wert |
| --- | --- |
| Überschriften | Söhne Breit Kräftig (`SecondaryCustomFont`) |
| Text | Söhne Buch (`PrimaryCustomFont`), 15/24 px |
| Dunkelgrün | `#163C37` |
| Mint | `#69E1A4` (nur noch Zierlinien) |
| Buttons | Dunkelgrün `#163C37`, weiße Schrift; Hover Türkis |
| Türkis (Akzente) | `#1F7B64` (CI-Türkis `#66C0AA` abgedunkelt, siehe unten) |
| Textgrau | `#696969` |

Die Schriften müssen von derselben Domain wie die Seite kommen, sonst blockiert der Browser sie.

Das CI-Türkis `#66C0AA` hat auf Weiß nur 2,2:1 Kontrast. Die EU-Norm EN 301 549 verlangt nach WCAG 2.1 AA
4,5:1 für Text und 3:1 für Symbole; die Seite nutzt darum `#1F7B64` (gleicher Farbton, 5,15:1 auf Weiß, 4,54:1 auf
der grauen Fläche). Weiße Schrift auf dem Mint (`#69E1A4`) hätte nur 1,6:1; die Buttons sind darum dunkelgrün
(12,1:1), die Plus-Zeichen im FAQ türkis.

## Offen

- Die eingecheckte Startseite enthält noch die Angebote vom 27.10.2026 (mit Links zu Propstack); im Build ersetzt
  sie `scripts/angebote.py`.
- Impressum und Datenschutzerklärung verlinken noch auf die bestehenden Seiten.
- Ziel für das Theme: Es liegt hier auf GitHub und wird hier bearbeitet; ein Tag spielt es per Pipeline auf das
  WordPress bei IONOS (läuft, das Theme ist live). Die Inhalte in WordPress lassen sich über ein MCP-Plugin aus
  Claude bearbeiten; das ist noch nicht eingerichtet. Offen ist auch, ob die Texte von
  Startseite, Vermieten und Verkaufen im Theme bleiben (Änderung über GitHub und Tag) oder nach WordPress umziehen
  (Änderung über das MCP).
- `site/` ist seit dem 05.11.2026 nicht mehr online; Texte und Vorlagen dort (auch `404.html`) bleiben auf dem
  Stand von damals.
