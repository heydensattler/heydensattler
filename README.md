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
- `site/assets/` – gemeinsames CSS und JavaScript aller Seiten
- `docs/screenshots/` – Vorher/Nachher und Ansichten der Seiten (Desktop 1440 px, Handy 390 px)
- `scripts/schriften.sh` – holt die lizenzierten Söhne-Schriften vom Live-Server (nicht im Repository)
- `scripts/pages.py` – baut die Vorschau für GitHub Pages (Unterpfad, noindex)
- `.github/workflows/pages.yml` – veröffentlicht `site/` bei jedem Push auf `main` auf GitHub Pages

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

- Angebote sind ein Stand vom 27.10.2026; im Betrieb aus der Propstack-API erzeugen.
- Impressum und Datenschutzerklärung verlinken noch auf die bestehenden Seiten.
