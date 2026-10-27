"""Baut die Vorschau für GitHub Pages aus site/.

GitHub Pages liefert ein Projekt unter einem Unterpfad aus (/heydensattler/), die Seiten verlinken aber
absolut auf / wie später auf heydensattler.com. Dieses Skript kopiert site/ nach _site/ und
- setzt den Unterpfad vor alle absoluten Pfade (href, src, srcset, imagesrcset, CSS url()),
- sperrt die Vorschau für Suchmaschinen (noindex, robots.txt), damit sie nicht mit heydensattler.com
  konkurriert.

Aufruf: python3 scripts/pages.py /heydensattler
"""
import re
import shutil
import sys
from pathlib import Path

wurzel = Path(__file__).resolve().parent.parent
quelle = wurzel / "site"
ziel = wurzel / "_site"
basis = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else ""

if ziel.exists():
    shutil.rmtree(ziel)
shutil.copytree(quelle, ziel)


def mit_basis(pfad):
    # Nur absolute Pfade auf derselben Domain, keine //cdn-Adressen
    if pfad.startswith("/") and not pfad.startswith("//"):
        return basis + pfad
    return pfad


def srcset(wert):
    return ", ".join(
        " ".join([mit_basis(teile[0])] + teile[1:])
        for teile in (eintrag.split() for eintrag in wert.split(","))
        if teile
    )


def html(text):
    text = re.sub(r'\b(href|src)="([^"]*)"', lambda m: f'{m[1]}="{mit_basis(m[2])}"', text)
    text = re.sub(r'\b(srcset|imagesrcset)="([^"]*)"', lambda m: f'{m[1]}="{srcset(m[2])}"', text)
    text = re.sub(
        r'<meta name="robots" content="[^"]*">',
        '<meta name="robots" content="noindex, nofollow">',
        text,
    )
    return text


def css(text):
    return re.sub(r'url\("(/[^"]*)"\)', lambda m: f'url("{mit_basis(m[1])}")', text)


for datei in ziel.rglob("*.html"):
    datei.write_text(html(datei.read_text(encoding="utf-8")), encoding="utf-8")
for datei in ziel.rglob("*.css"):
    datei.write_text(css(datei.read_text(encoding="utf-8")), encoding="utf-8")

(ziel / "robots.txt").write_text(
    "# Vorschau auf GitHub Pages, nicht indexieren. Die echte robots.txt liegt in site/.\n"
    "User-agent: *\nDisallow: /\n",
    encoding="utf-8",
)
# Ohne Jekyll ausliefern, sonst ignoriert Pages Ordner mit Unterstrich
(ziel / ".nojekyll").write_text("", encoding="utf-8")

print(f"_site/ gebaut, Unterpfad: {basis or '/'}")
