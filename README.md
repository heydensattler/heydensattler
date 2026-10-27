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
- `docs/screenshots/` – Vorher/Nachher und Ansichten der Seiten
- `scripts/schriften.sh` – holt die lizenzierten Söhne-Schriften vom Live-Server (nicht im Repository)

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
