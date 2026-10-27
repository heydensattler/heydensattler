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
- `requirements.txt` – Python-Markdown in fester Version, nur für `scripts/blog.py`
- `scripts/pages.py` – baut die Vorschau für GitHub Pages (Unterpfad, noindex)
- `.github/workflows/pages.yml` – veröffentlicht `site/` bei jedem Push auf `main` und alle 30 Minuten auf GitHub
  Pages

## Vorschau auf GitHub Pages

https://svenfritsch.github.io/heydensattler/

Die Vorschau ist für Suchmaschinen gesperrt, damit sie nicht mit heydensattler.com konkurriert. Die Söhne-Schriften
lädt der Build nur, wenn die Repository-Variable `SCHRIFTEN_LADEN` auf `true` steht (Settings → Secrets and
variables → Actions → Variables); sonst zeigt die Vorschau die Ersatzschrift. Einmalig nötig: Settings → Pages →
Source „GitHub Actions“.

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
nie Eigentümer-, Makler- oder Kontaktdaten; „Preis auf Anfrage“, wenn in Propstack „Preis auf Anfrage“ gesetzt
oder „Preis im Exposé anzeigen“ ausgeschaltet ist (so entschieden am 27.10.2026, obwohl Propstacks eigene öffentliche
Ansicht den Preis dann trotzdem zeigt).

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

## Corporate Identity

| Rolle | Wert |
| --- | --- |
| Überschriften | Söhne Breit Kräftig (`SecondaryCustomFont`) |
| Text | Söhne Buch (`PrimaryCustomFont`), 15/24 px |
| Dunkelgrün | `#163C37` |
| Mint (Buttons) | `#69E1A4` |
| Türkis (Akzente) | `#1F7B64` (CI-Türkis `#66C0AA` abgedunkelt, siehe unten) |
| Textgrau | `#696969` |

Die Schriften müssen von derselben Domain wie die Seite kommen, sonst blockiert der Browser sie.

Das CI-Türkis `#66C0AA` hat auf Weiß nur 2,2:1 Kontrast. Die EU-Norm EN 301 549 verlangt nach WCAG 2.1 AA
4,5:1 für Text und 3:1 für Symbole; die Seite nutzt darum `#1F7B64` (gleicher Farbton, 5,15:1 auf Weiß, 4,54:1 auf
der grauen Fläche). Das Mint der Buttons (`#69E1A4`) mit weißer Schrift erreicht nur 1,6:1 und ist noch nicht
angepasst.

## Offen

- Die eingecheckte Startseite enthält noch die Angebote vom 27.10.2026 (mit Links zu Propstack); im Build ersetzt
  sie `scripts/angebote.py`.
- Impressum und Datenschutzerklärung verlinken noch auf die bestehenden Seiten.
- `404.html` spricht nur von Angeboten; eine nicht mehr vorhandene Blogadresse zeigt denselben Text.
