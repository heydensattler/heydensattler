"""Bereitet die Beispielbeiträge aus scripts/beispiel-blog/ für das Test-WordPress vor (nur für einrichten.sh).

Schreibt nach wordpress/test/_import/: je Beitrag den Text als HTML, die Bilder und import.sh, das die Beiträge mit
WP-CLI anlegt (Datum, Auszug, Autor, Beitragsbild, Entwurf). Der Beitrag mit Datum 2027 wird geplant, der Entwurf
bleibt Entwurf: beide dürfen auf der Website nirgends erscheinen.
"""
import re
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import markdown

TEST = Path(__file__).resolve().parent
QUELLE = TEST.parent.parent / "scripts" / "beispiel-blog"
ZIEL = TEST / "_import"


def kopf_lesen(text):
    _, kopf, rumpf = text.split("---", 2)
    felder = {}
    for zeile in kopf.splitlines():
        zeile = zeile.strip()
        if not zeile or zeile.startswith("#"):
            continue
        schluessel, wert = zeile.split(":", 1)
        wert = wert.strip()
        if len(wert) >= 2 and wert[0] == wert[-1] and wert[0] in "\"'":
            wert = wert[1:-1]
        felder[schluessel.strip()] = wert
    return felder, rumpf


def slug_aus(titel):
    t = titel.lower()
    for alt, neu in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss")):
        t = t.replace(alt, neu)
    return re.sub(r"[^a-z0-9]+", "-", t).strip("-")


def sh(wert):
    return "'" + str(wert).replace("'", "'\\''") + "'"


def main():
    shutil.rmtree(ZIEL, ignore_errors=True)
    (ZIEL / "bilder").mkdir(parents=True)
    dateien = sorted(p for p in QUELLE.glob("*.md") if not p.name.startswith("_"))
    beitraege = [(p, *kopf_lesen(p.read_text(encoding="utf-8"))) for p in dateien]
    slugs = {p.name: f.get("slug") or slug_aus(f["title"]) for p, f, _ in beitraege}

    # Bilder werden an ihren Beitrag gehängt, wie beim Hochladen im Editor: dann sind die eines geplanten Beitrags
    # oder Entwurfs nicht öffentlich, und im Text bekommen sie wp-image-<ID> (WordPress ergänzt Maße und srcset)
    zeilen = ["set -e", "cd /var/www/html",
              'bild() { datei=$1; beitrag=$2; shift 2; wp media import "/arbeit/_import/bilder/$datei" --post_id="$beitrag" --porcelain "$@"; }']
    for pfad, felder, rumpf in beitraege:
        slug = slugs[pfad.name]
        rumpf = re.sub(r"<!--.*?-->", "", rumpf, flags=re.S)
        html = markdown.markdown(rumpf, extensions=["extra", "sane_lists"], output_format="html")
        # Zeilenumbrüche im Absatz sind nur Umbrüche der Quelldatei (WordPress machte daraus <br>)
        html = re.sub(r"<p>(.*?)</p>", lambda m: "<p>" + m[1].replace("\n", " ") + "</p>", html, flags=re.S)
        html = re.sub(r'href="([^":/]+\.md)"', lambda m: f'href="/blog/{slugs.get(m[1], m[1])}/"', html)

        bilder = []

        def bild(m):
            name = Path(m[1]).name
            shutil.copy(pfad.parent / m[1], ZIEL / "bilder" / name)
            bilder.append(name)
            return f'class="{{{{KLASSE:{name}}}}}" src="{{{{BILD:{name}}}}}"'

        html = re.sub(r'src="(bilder/[^"]+)"', bild, html)
        html = re.sub(r'<p>(<img [^>]*?)(?: title="([^"]*)")?\s*/?></p>',
                      lambda m: f"<figure>{m[1]}>" + (f"<figcaption>{m[2]}</figcaption>" if m[2] else "") + "</figure>", html)
        (ZIEL / f"{slug}.html").write_text(html, encoding="utf-8")

        datum = felder["publish_date"] + (" 00:00" if len(felder["publish_date"]) == 10 else "") + ":00"
        status = "draft" if felder.get("draft") == "true" else "publish"
        argumente = [f"--post_title={sh(felder['title'])}", f"--post_name={slug}", f"--post_date={sh(datum)}",
                     f"--post_status={status}"]
        if felder.get("description"):
            argumente.append(f"--post_excerpt={sh(felder['description'])}")
        zeilen.append(f"# {pfad.name}")
        zeilen.append(f"pid=$(wp post create {' '.join(argumente)} --porcelain)")
        if felder.get("author"):
            zeilen.append(f"wp post meta update $pid hs_autor {felder['author']} >/dev/null")
        if felder.get("image"):
            name = Path(felder["image"]).name
            shutil.copy(pfad.parent / felder["image"], ZIEL / "bilder" / name)
            zeilen.append(f"fid=$(bild {name} $pid --featured_image); "
                          f"wp post meta update $fid _wp_attachment_image_alt {sh(felder.get('image_alt', ''))} >/dev/null")
        zeilen.append(f"cp /arbeit/_import/{slug}.html /tmp/{slug}.html")
        for name in bilder:
            zeilen.append(f'id=$(bild {name} $pid); sed -i -e "s#{{{{BILD:{name}}}}}#$(wp eval "echo wp_get_attachment_url($id);")#g" '
                          f'-e "s#{{{{KLASSE:{name}}}}}#wp-image-$id#g" /tmp/{slug}.html')
        zeilen.append(f"wp post update $pid /tmp/{slug}.html --quiet")
        # WordPress setzt das Änderungsdatum beim Speichern selbst; hier steht das aus der Datei, sonst das Datum
        if felder.get("updated"):
            wert = felder["updated"]
            geaendert = datetime.strptime(wert if len(wert) > 10 else wert + " 00:00", "%Y-%m-%d %H:%M")
            gmt = geaendert.replace(tzinfo=ZoneInfo("Europe/Berlin")).astimezone(timezone.utc)
            setzen = f"post_modified='{geaendert:%Y-%m-%d %H:%M:%S}', post_modified_gmt='{gmt:%Y-%m-%d %H:%M:%S}'"
        else:
            setzen = "post_modified=post_date, post_modified_gmt=post_date_gmt"
        zeilen.append(f'wp db query "UPDATE $(wp db prefix)posts SET {setzen} WHERE ID=$pid"')
        zeilen.append(f'echo "  /blog/{slug}/ ({status}, {felder["publish_date"]})"')

    (ZIEL / "import.sh").write_text("\n".join(zeilen) + "\n", encoding="utf-8")
    print(f"{len(beitraege)} Beispielbeiträge nach {ZIEL.relative_to(TEST.parent.parent)}/")


if __name__ == "__main__":
    sys.exit(main())
