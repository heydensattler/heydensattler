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
- `site/assets/` – gemeinsames CSS und JavaScript aller Seiten, `galerie.js` nur auf den Angebotsseiten
- `docs/screenshots/` – Vorher/Nachher und Ansichten der Seiten (Desktop 1440 px, Handy 390 px)
- `scripts/schriften.sh` – holt die lizenzierten Söhne-Schriften vom Live-Server (nicht im Repository)
- `scripts/angebote.py` – erzeugt Angebote und Detailseiten aus Propstack (siehe unten)
- `scripts/beispiel-angebote.json` – drei Beispielobjekte in der Struktur der Propstack-API V2
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
nie Eigentümer-, Makler- oder Kontaktdaten; „Preis auf Anfrage“, wenn der Preis in Propstack verborgen ist.

**Lokal ansehen ohne Schlüssel** (schreibt nach `_beispiel/`, nie nach `site/`):

```bash
python3 scripts/angebote.py --beispiel
```

```bash
python3 -m http.server 8731 --directory _beispiel
```

Mit `PROPSTACK_PORTAL_ID=4711` davor zeigt das Beispiel den Filter nach Portal.

## Corporate Identity

| Rolle | Wert |
| --- | --- |
| Überschriften | Söhne Breit Kräftig (`SecondaryCustomFont`) |
| Text | Söhne Buch (`PrimaryCustomFont`), 15/24 px |
| Dunkelgrün | `#163C37` |
| Mint (Buttons) | `#69E1A4` |
| Türkis (Akzente) | `#66C0AA` |
| Textgrau | `#696969` |

Die Schriften müssen von derselben Domain wie die Seite kommen, sonst blockiert der Browser sie.

## Offen

- Die eingecheckte Startseite enthält noch die Angebote vom 27.10.2026 (mit Links zu Propstack); im Build ersetzt
  sie `scripts/angebote.py`.
- Impressum und Datenschutzerklärung verlinken noch auf die bestehenden Seiten.
