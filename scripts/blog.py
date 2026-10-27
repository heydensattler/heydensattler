"""Erzeugt den Blog der Website aus Markdown-Dateien in inhalte/blog/.

Ein Beitrag erscheint erst, wenn sein publish_date (Europe/Berlin) erreicht ist und er kein Entwurf ist. Nur
veröffentlichte Beiträge gelangen in eine erzeugte Datei; die Funktionen unten, die HTML, Feed, Sitemap und
llms.txt schreiben, bekommen ausschließlich diese Liste. Geschrieben werden
- blog/index.html (Übersicht), blog/<slug>/index.html (Beitrag) und blog/feed.xml (Atom),
- die von veröffentlichten Beiträgen benutzten Bilder nach blog/<slug>/,
- auf der Startseite der Abschnitt „Aktuelles“ (neueste drei Beiträge), in allen Seiten mit der Markierung der
  Navigationspunkt „Blog“, in sitemap.xml und llms.txt die Beiträge; jeweils nur zwischen den Markierungen
  <!-- blog:…:start --> und <!-- blog:…:ende -->.
Gibt es keinen veröffentlichten Beitrag, bleiben alle Markierungen leer und es gibt keinen Ordner blog/.

Fehler im Front-Matter, fehlende Bilder oder doppelte Slugs brechen den Lauf ab (mit Datei und Zeile); dann
bleibt die zuletzt veröffentlichte Seite online. Geprüft werden auch künftige Beiträge, damit ein Fehler nicht
erst an ihrem Termin auffällt; Entwürfe nur auf lesbares Front-Matter.

Aufruf:
  python3 scripts/blog.py                  schreibt in site/ (im Build auf GitHub)
  python3 scripts/blog.py --beispiel       Beispiele aus scripts/beispiel-blog/, schreibt in _beispiel-blog/
                                           (Kopie von site/), nie in site/
  python3 scripts/blog.py --ordner ORDNER  schreibt in einen vorhandenen Ordner, z. B. _beispiel nach
                                           angebote.py --beispiel
  --inhalte ORDNER, --jetzt "JJJJ-MM-TT HH:MM"   andere Beiträge oder anderer Zeitpunkt; nie für site/
"""
import argparse
import difflib
import html
import json
import math
import re
import shutil
import struct
import sys
import unicodedata
import urllib.parse
import xml.etree.ElementTree as ET
from datetime import datetime
from pathlib import Path

try:
    import markdown
except ImportError:
    sys.exit("::error::Python-Markdown fehlt: pip install -r requirements.txt")

WURZEL = Path(__file__).resolve().parent.parent
QUELLE = WURZEL / "site"
INHALTE = WURZEL / "inhalte" / "blog"
BEISPIEL = WURZEL / "scripts" / "beispiel-blog"
DOMAIN = "https://heydensattler.com"
FIRMA = DOMAIN + "/#firma"
TELEFON = ("+4982199982940", "0821 999 8294 0")
MAIL = "info@heydensattler.com"
ZEITZONE = "Europe/Berlin"
# Ersatzbild für Open Graph, wenn ein Beitrag kein eigenes Bild hat (Hero der Startseite)
HERO = (DOMAIN + "/wp-content/uploads/2022/05/cover3-1400.jpg", 1400, 772)
TEASER = 3

AUTOREN = {
    "cedric-heyden": {
        "name": "Cedric Heyden", "titel": "M. Eng.", "rolle": "Fachmann für Immobilienbewertung und Marketing",
        "ueber": "Über den Autor",
        "bild": "https://heydensattler.com/wp-content/uploads/2022/08/DSC09558b-e1694595155471-251x300.jpg",
    },
    "anna-heyden": {
        "name": "Anna Heyden", "titel": "M. Eng.", "rolle": "Spezialistin für Vermietung und Verkauf",
        "ueber": "Über die Autorin",
        "bild": "https://heydensattler.com/wp-content/uploads/2023/08/DSC00735-scaled-e1694546678891-251x300.jpg",
    },
}
FELDER = ("title", "publish_date", "description", "author", "slug", "image", "image_alt", "updated", "draft")
BILDTYPEN = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png", ".webp": "image/webp",
             ".gif": "image/gif"}
MONATE = ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober",
          "November", "Dezember"]


class Abbruch(Exception):
    pass


class Fehler(Exception):
    def __init__(self, datei, zeile, text):
        super().__init__(text)
        self.datei, self.zeile, self.text = datei, zeile, text


def log(text):
    print(text, flush=True)


def warnung(datei, text):
    print(f"::warning file={datei}::{text}", flush=True)


def zeitzone():
    try:
        from zoneinfo import ZoneInfo
        return ZoneInfo(ZEITZONE)
    except Exception as fehler:
        # Ohne Zeitzone lässt sich publish_date nicht sicher prüfen: lieber nichts veröffentlichen
        raise Abbruch(f"Zeitzone {ZEITZONE} nicht verfügbar ({fehler})")


def e(wert):
    return html.escape(str(wert), quote=True)


def slug_aus(text):
    t = unicodedata.normalize("NFC", text).lower()
    for alt, neu in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss")):
        t = t.replace(alt, neu)
    t = unicodedata.normalize("NFKD", t).encode("ascii", "ignore").decode()
    t = re.sub(r"[^a-z0-9]+", "-", t).strip("-")
    if len(t) > 80:
        t = t[:80].rsplit("-", 1)[0]
    return t


def de_datum(zeit):
    return f"{zeit.day}. {MONATE[zeit.month - 1]} {zeit.year}"


# ---------------------------------------------------------------------------------------------------------
# Front-Matter: eine Teilmenge von YAML (schluessel: wert, Kommentare mit #, Werte optional in Anführungszeichen)

def skalar(roh, datei, nr):
    if roh[:1] in "\"'":
        q = roh[0]
        ende = roh.rfind(q)
        if ende == 0:
            raise Fehler(datei, nr, f"Anführungszeichen {q} nicht geschlossen")
        rest = roh[ende + 1:].strip()
        if rest and not rest.startswith("#"):
            raise Fehler(datei, nr, f"Text nach dem schließenden Anführungszeichen: {rest!r}")
        innen = roh[1:ende]
        return innen.replace("''", "'") if q == "'" else innen.replace('\\"', '"').replace("\\\\", "\\")
    return re.sub(r"\s+#.*$", "", roh).strip()


def lesen(pfad, anzeige):
    text = pfad.read_text(encoding="utf-8-sig").replace("\r\n", "\n")
    zeilen = text.split("\n")
    if not zeilen or zeilen[0].strip() != "---":
        raise Fehler(anzeige, 1, "Front-Matter fehlt: Die Datei muss mit einer Zeile --- beginnen")
    try:
        ende = next(i for i in range(1, len(zeilen)) if zeilen[i].strip() == "---")
    except StopIteration:
        raise Fehler(anzeige, 1, "Front-Matter nicht geschlossen: zweite Zeile --- fehlt")
    daten = {}
    for nr in range(2, ende + 1):
        zeile = zeilen[nr - 1]
        if not zeile.strip() or zeile.lstrip().startswith("#"):
            continue
        if zeile[0] in " \t":
            raise Fehler(anzeige, nr, "Eingerückte Zeile; erlaubt ist nur „schluessel: wert“ in einer Zeile")
        m = re.match(r"^([A-Za-z_][\w-]*)\s*:(?:\s+(.*))?$", zeile.rstrip())
        if not m:
            raise Fehler(anzeige, nr, f"Erwartet „schluessel: wert“, gefunden: {zeile.strip()!r}")
        schluessel = m[1]
        if schluessel not in FELDER:
            aehnlich = difflib.get_close_matches(schluessel, FELDER, n=1)
            tipp = f" Gemeint: {aehnlich[0]}?" if aehnlich else f" Erlaubt: {', '.join(FELDER)}."
            raise Fehler(anzeige, nr, f"Unbekanntes Feld „{schluessel}“.{tipp}")
        if schluessel in daten:
            raise Fehler(anzeige, nr, f"Feld „{schluessel}“ steht doppelt im Front-Matter")
        daten[schluessel] = (skalar((m[2] or "").strip(), anzeige, nr), nr)
    return daten, "\n".join(zeilen[ende + 1:])


def zeitpunkt(wert, tz, datei, nr, feld):
    m = re.fullmatch(r"(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?", wert)
    if not m:
        raise Fehler(datei, nr, f"{feld} muss JJJJ-MM-TT oder JJJJ-MM-TT HH:MM sein, gefunden: {wert!r}")
    try:
        return datetime(int(m[1]), int(m[2]), int(m[3]), int(m[4] or 0), int(m[5] or 0), tzinfo=tz)
    except ValueError as fehler:
        raise Fehler(datei, nr, f"{feld} ist kein gültiges Datum ({wert}): {fehler}")


class Beitrag:
    def __init__(self, pfad, inhalte, tz):
        self.pfad = pfad
        self.anzeige = str(pfad.relative_to(WURZEL)) if pfad.is_relative_to(WURZEL) else str(pfad)
        daten, self.text = lesen(pfad, self.anzeige)
        self.kopfzeilen = pfad.read_text(encoding="utf-8-sig").replace("\r\n", "\n").count("\n") - self.text.count("\n")
        self.zeile = {k: nr for k, (_, nr) in daten.items()}
        wert = {k: w for k, (w, _) in daten.items()}

        entwurf = wert.get("draft", "false").lower()
        if entwurf not in ("true", "false", "yes", "no"):
            raise Fehler(self.anzeige, self.zeile["draft"], f"draft muss true oder false sein, gefunden: {entwurf!r}")
        self.entwurf = entwurf in ("true", "yes")
        if self.entwurf:
            return

        for feld in ("title", "publish_date"):
            if not wert.get(feld):
                raise Fehler(self.anzeige, self.zeile.get(feld, 1), f"Pflichtfeld {feld} fehlt oder ist leer")
        self.titel = wert["title"]
        self.datum = zeitpunkt(wert["publish_date"], tz, self.anzeige, self.zeile["publish_date"], "publish_date")
        self.geaendert = None
        if wert.get("updated"):
            self.geaendert = zeitpunkt(wert["updated"], tz, self.anzeige, self.zeile["updated"], "updated")
            if self.geaendert < self.datum:
                raise Fehler(self.anzeige, self.zeile["updated"], "updated liegt vor publish_date")
        self.beschreibung = wert.get("description", "")

        self.autor = wert.get("author") or None
        if self.autor and self.autor not in AUTOREN:
            raise Fehler(self.anzeige, self.zeile["author"],
                         f"Unbekannter author „{self.autor}“; erlaubt: {', '.join(AUTOREN)}")

        self.slug = wert.get("slug") or slug_aus(self.titel)
        if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", self.slug):
            raise Fehler(self.anzeige, self.zeile.get("slug", self.zeile["title"]),
                         f"Slug „{self.slug}“ ungültig: nur a–z, 0–9 und einzelne Bindestriche")
        self.pfad_web = f"/blog/{self.slug}/"
        self.url = DOMAIN + self.pfad_web

        self.bilder = {}  # Quelle -> Dateiname im Ordner des Beitrags
        self.bild = None
        if wert.get("image"):
            self.bild = self.bild_aufnehmen(wert["image"], inhalte, self.zeile["image"])
        self.bild_alt = wert.get("image_alt") or self.titel

        self.html = self.rendern(inhalte)
        woerter = len(re.findall(r"\w+", re.sub(r"<[^>]+>", " ", self.html)))
        self.minuten = max(1, math.ceil(woerter / 200))
        if not self.beschreibung:
            erster = re.search(r"<p>(.*?)</p>", self.html, re.S)
            text = html.unescape(re.sub(r"<[^>]+>", "", erster[1] if erster else "")).strip()
            text = re.sub(r"\s+", " ", text)
            self.beschreibung = text if len(text) <= 155 else text[:154].rsplit(" ", 1)[0].rstrip(",;:–-") + " …"
            warnung(self.anzeige, "description fehlt; verwendet wird der Anfang des ersten Absatzes")

    def bild_aufnehmen(self, angabe, inhalte, nr):
        if re.match(r"^[a-z]+:|^/", angabe, re.I):
            raise Fehler(self.anzeige, nr, f"Bildpfad {angabe!r} muss relativ zur Datei sein (z. B. bilder/foto.jpg)")
        quelle = (self.pfad.parent / angabe).resolve()
        if not quelle.is_relative_to(inhalte.resolve()):
            raise Fehler(self.anzeige, nr, f"Bild {angabe!r} liegt außerhalb von {inhalte.name}/")
        if not quelle.is_file():
            raise Fehler(self.anzeige, nr, f"Bild {angabe!r} nicht gefunden")
        if quelle.suffix.lower() not in BILDTYPEN:
            raise Fehler(self.anzeige, nr, f"Bildformat {quelle.suffix} nicht erlaubt ({', '.join(BILDTYPEN)})")
        name = slug_aus(quelle.stem) + quelle.suffix.lower()
        if name in self.bilder.values() and self.bilder.get(quelle) != name:
            raise Fehler(self.anzeige, nr, f"Zwei verschiedene Bilder heißen {name}; bitte eines umbenennen")
        self.bilder[quelle] = name
        try:
            masse = bildmasse(quelle)
        except Exception as fehler:
            raise Fehler(self.anzeige, nr, f"Bild {angabe!r} nicht lesbar: {fehler}")
        if quelle.stat().st_size > 600_000:
            warnung(self.anzeige, f"Bild {angabe} ist {quelle.stat().st_size // 1000} KB groß; "
                                  "besser auf etwa 1600 px Breite verkleinern")
        return {"quelle": quelle, "pfad": self.pfad_web + name, "url": self.url + name, "breite": masse[0],
                "hoehe": masse[1], "typ": BILDTYPEN[quelle.suffix.lower()]}

    def rendern(self, inhalte):
        # HTML-Kommentare sind oft Notizen an sich selbst: nie mit ausliefern
        text = re.sub(r"<!--.*?-->", "", self.text, flags=re.S)
        md = markdown.Markdown(extensions=["extra", "sane_lists", "toc"], output_format="html",
                               extension_configs={"toc": {"slugify": lambda wert, _: slug_aus(wert)}})
        roh = md.convert(text)
        # Die Seite hat schon eine h1 (Titel): # im Text wird zu h2
        roh = re.sub(r"<(/?)h1\b", r"<\1h2", roh)

        def bild(m):
            attribute = m[1]
            src = re.search(r'\bsrc="([^"]*)"', attribute)
            if src and not re.match(r"^[a-z]+:|^/|^#", html.unescape(src[1]), re.I):
                angabe = html.unescape(src[1])
                vorher = self.text.split(angabe)[0] if angabe in self.text else self.text
                nr = self.kopfzeilen + vorher.count("\n") + 1
                b = self.bild_aufnehmen(angabe, inhalte, nr)
                attribute = attribute.replace(src[0], f'src="{e(b["pfad"])}"')
                if "width=" not in attribute:
                    attribute += f' width="{b["breite"]}" height="{b["hoehe"]}"'
            return f'<img {attribute.strip()} loading="lazy" decoding="async">'

        roh = re.sub(r"<img\s([^>]*?)\s*/?>", bild, roh)

        # Ein Bild allein im Absatz wird zur Abbildung; der Titel ![alt](bild.jpg "Titel") zur Bildunterschrift
        def abbildung(m):
            titel = re.search(r' title="([^"]*)"', m[1])
            img = m[1].replace(titel[0], "") if titel else m[1]
            return f"<figure>{img}" + (f"<figcaption>{titel[1]}</figcaption>" if titel else "") + "</figure>"

        roh = re.sub(r"<p>(<img [^>]*>)</p>", abbildung, roh)
        roh = re.sub(r"<table>", '<div class="beitrag-tabelle"><table>', roh).replace("</table>", "</table></div>")
        return roh


def verlinken(beitrag, veroeffentlicht, alle):
    """Links auf andere Beiträge ([Text](anderer-beitrag.md)) zeigen auf deren Seite. Ist der Beitrag noch nicht
    veröffentlicht, bleibt nur der Linktext: seine Adresse darf vorher nirgends stehen. Das gilt auch für einen
    von Hand geschriebenen Link auf /blog/<slug>/ eines künftigen Beitrags."""
    nach_datei = {b.pfad.resolve(): b for b in veroeffentlicht}
    offen = {b.slug for b in alle if not b.entwurf} - {b.slug for b in veroeffentlicht}

    def link(m):
        ziel = html.unescape(m[2])
        verweis = re.match(r"^(?:https?://(?:www\.)?heydensattler\.com)?/blog/([a-z0-9-]+)", ziel)
        if verweis and verweis[1] in offen:
            warnung(beitrag.anzeige, "Link auf einen noch nicht veröffentlichten Beitrag, nur der Text erscheint")
            return m[4]
        if re.match(r"^[a-z]+:|^/|^#", ziel, re.I) or not re.search(r"\.md(#|$)", ziel):
            return m[0]
        datei, _, anker = ziel.partition("#")
        andere = nach_datei.get((beitrag.pfad.parent / datei).resolve())
        if andere:
            return f'<a{m[1]}href="{e(andere.pfad_web + ("#" + anker if anker else ""))}"{m[3]}>{m[4]}</a>'
        warnung(beitrag.anzeige, f"Link auf {datei}: kein veröffentlichter Beitrag, nur der Text erscheint")
        return m[4]

    beitrag.html = re.sub(r'<a([^>]*?)href="([^"]*)"([^>]*)>(.*?)</a>', link, beitrag.html, flags=re.S)


# ---------------------------------------------------------------------------------------------------------
# Bildgröße ohne Zusatzpakete (für width/height gegen Layoutsprünge)

def bildmasse(pfad):
    d = pfad.read_bytes()
    if d[:8] == b"\x89PNG\r\n\x1a\n":
        return struct.unpack(">II", d[16:24])
    if d[:4] == b"GIF8":
        return struct.unpack("<HH", d[6:10])
    if d[:4] == b"RIFF" and d[8:12] == b"WEBP":
        art = d[12:16]
        if art == b"VP8 ":
            w, h = struct.unpack("<HH", d[26:30])
            return w & 0x3FFF, h & 0x3FFF
        if art == b"VP8L":
            b = d[21:25]
            return 1 + (b[0] | (b[1] & 0x3F) << 8), 1 + (b[1] >> 6 | b[2] << 2 | (b[3] & 0x0F) << 10)
        if art == b"VP8X":
            return 1 + int.from_bytes(d[24:27], "little"), 1 + int.from_bytes(d[27:30], "little")
    if d[:2] == b"\xff\xd8":
        i, gedreht, masse = 2, False, None
        while i + 9 < len(d):
            if d[i] != 0xFF:
                i += 1
                continue
            marke = d[i + 1]
            if marke in (0xD8, 0x01) or 0xD0 <= marke <= 0xD7:
                i += 2
                continue
            laenge = struct.unpack(">H", d[i + 2:i + 4])[0]
            if marke == 0xE1 and d[i + 4:i + 10] == b"Exif\x00\x00":
                gedreht = exif_gedreht(d[i + 10:i + 2 + laenge])
            elif 0xC0 <= marke <= 0xCF and marke not in (0xC4, 0xC8, 0xCC):
                h, w = struct.unpack(">HH", d[i + 5:i + 9])
                masse = (w, h)
                break
            i += 2 + laenge
        if masse:
            # Handyfotos: Browser drehen nach EXIF, also stimmen Breite und Höhe dann vertauscht
            return (masse[1], masse[0]) if gedreht else masse
    raise ValueError("unbekanntes oder beschädigtes Bildformat")


def exif_gedreht(t):
    if t[:2] not in (b"II", b"MM"):
        return False
    o = "<" if t[:2] == b"II" else ">"
    start = struct.unpack(o + "I", t[4:8])[0]
    anzahl = struct.unpack(o + "H", t[start:start + 2])[0]
    for k in range(anzahl):
        eintrag = t[start + 2 + 12 * k:start + 14 + 12 * k]
        if len(eintrag) == 12 and struct.unpack(o + "H", eintrag[:2])[0] == 0x0112:
            return struct.unpack(o + "H", eintrag[8:10])[0] in (5, 6, 7, 8)
    return False


# ---------------------------------------------------------------------------------------------------------
# HTML-Bausteine

PFEIL = ('<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
         '<path d="M3 9h12M10 4l5 5-5 5"/></svg>')
PFEIL_ZURUECK = ('<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
                 '<path d="M15 9H3M8 4 3 9l5 5"/></svg>')


def autor_name(b):
    return AUTOREN[b.autor]["name"] if b.autor else "HeydenSattler"


def autor_ld(b):
    return {"@id": f"{DOMAIN}/#{b.autor}"} if b.autor else {"@id": FIRMA}


def geaendert(b):
    return b.geaendert or b.datum


def karte(b, ebene):
    zeilen = [f'        <li><a class="beitrag-karte" href="{e(b.pfad_web)}">']
    if b.bild:
        zeilen.append(f'          <div class="bild"><img src="{e(b.bild["pfad"])}" alt="{e(b.bild_alt)}" '
                      f'width="{b.bild["breite"]}" height="{b.bild["hoehe"]}" loading="lazy" decoding="async"></div>')
    else:
        # Ohne eigenes Bild das Hero-Bild der Startseite, damit alle Karten gleich aufgebaut sind
        zeilen.append('          <div class="bild"><img src="/wp-content/uploads/2022/05/cover3-800.webp" alt="" '
                      'width="800" height="442" loading="lazy" decoding="async"></div>')
    zeilen.append(f'          <div class="info"><time datetime="{b.datum.date().isoformat()}">{de_datum(b.datum)}</time>'
                  f'<h{ebene}>{e(b.titel)}</h{ebene}><p>{e(b.beschreibung)}</p>'
                  f'<span class="mehr">Weiterlesen{PFEIL}</span></div>')
    zeilen.append('        </a></li>')
    return "\n".join(zeilen)


def kontakt(betreff, querlinks):
    link = f"mailto:{MAIL}?subject=" + urllib.parse.quote(betreff)
    return f"""  <section class="abschnitt" id="kontakt">
    <div class="wrap">
      <div class="kontakt-box">
        <div>
          <h2>Kontakt aufnehmen</h2>
          <p class="text">Sie möchten Ihre Immobilie in Augsburg verkaufen oder vermieten? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.</p>
          <a class="btn" href="{e(link)}">E-Mail schreiben</a>
        </div>
        <div>
          <h3>Telefon und E-Mail</h3>
          <p><a href="tel:{TELEFON[0]}">{TELEFON[1]}</a><br><a href="mailto:{MAIL}">{MAIL}</a></p>
          <h3 style="margin-top:18px">Erreichbar</h3>
          <p>Montag bis Freitag<br>9:00 bis 17:00 Uhr</p>
        </div>
        <div>
          <h3>Unsere Region</h3>
          <p>Augsburg und Umgebung,<br>zum Beispiel Neusäß</p>
        </div>
      </div>
{querlinks}    </div>
  </section>
"""


def json_ld(daten):
    return json.dumps(daten, ensure_ascii=False, indent=2)


def firma_ld():
    return {"@type": "RealEstateAgent", "@id": FIRMA, "name": "HeydenSattler GmbH", "url": DOMAIN + "/",
            "telephone": "+49 821 99982940", "email": MAIL, "address": {
                "@type": "PostalAddress", "postalCode": "86159",
                "addressLocality": "Augsburg", "addressRegion": "Bayern", "addressCountry": "DE"}}


def person_ld(schluessel):
    a = AUTOREN[schluessel]
    return {"@type": "Person", "@id": f"{DOMAIN}/#{schluessel}", "name": a["name"], "honorificSuffix": a["titel"],
            "url": DOMAIN + "/#team", "image": a["bild"], "worksFor": {"@id": FIRMA}}


def posting_ld(b, voll):
    bild = b.bild["url"] if b.bild else HERO[0]
    ld = {"@type": "BlogPosting", "@id": b.url + "#beitrag", "url": b.url, "headline": b.titel,
          "description": b.beschreibung, "datePublished": b.datum.isoformat(),
          "dateModified": geaendert(b).isoformat(), "author": autor_ld(b), "publisher": {"@id": FIRMA},
          "image": bild, "inLanguage": "de-DE"}
    if voll:
        ld.update({"mainEntityOfPage": {"@id": b.url}, "isPartOf": {"@id": DOMAIN + "/blog/#blog"},
                   "breadcrumb": {"@id": b.url + "#brotkrumen"},
                   "wordCount": len(re.findall(r"\w+", re.sub(r"<[^>]+>", " ", b.html))),
                   "timeRequired": f"PT{b.minuten}M"})
    return ld


def kopfdaten(titel, beschreibung, url, og_typ, og_bild, og_alt, ld, extra=""):
    bild_url, breite, hoehe = og_bild
    endung = bild_url.rsplit(".", 1)[-1].lower().replace("jpg", "jpeg")
    return f"""<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(titel)}</title>
<meta name="description" content="{e(beschreibung)}">
<link rel="canonical" href="{e(url)}">
<link rel="alternate" hreflang="de-DE" href="{e(url)}">
<link rel="alternate" type="application/atom+xml" title="HeydenSattler Blog" href="/blog/feed.xml">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
<meta name="theme-color" content="#163C37">
<meta property="og:type" content="{og_typ}">
<meta property="og:locale" content="de_DE">
<meta property="og:site_name" content="HeydenSattler">
<meta property="og:title" content="{e(titel.removesuffix(' | HeydenSattler'))}">
<meta property="og:description" content="{e(beschreibung)}">
<meta property="og:url" content="{e(url)}">
<meta property="og:image" content="{e(bild_url)}">
<meta property="og:image:type" content="image/{endung}">
<meta property="og:image:width" content="{breite}">
<meta property="og:image:height" content="{hoehe}">
<meta property="og:image:alt" content="{e(og_alt)}">
{extra}<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="https://heydensattler.com/favicon.ico">

<script type="application/ld+json">
{json_ld(ld)}
</script>

<link rel="preload" href="/wp-content/uploads/2022/05/soehne-buch.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/wp-content/uploads/2022/05/soehne-breit-kraftig.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
"""


def brotkrumen(*eintraege):
    teile = [f'<li><a href="{e(pfad)}">{e(name)}</a></li>' if pfad else f'<li aria-current="page">{e(name)}</li>'
             for name, pfad in eintraege]
    return ('      <nav aria-label="Brotkrümelpfad">\n        <ol class="brotkrumen">\n          '
            + "\n          ".join(teile) + "\n        </ol>\n      </nav>\n")


def brotkrumen_ld(url, *eintraege):
    return {"@type": "BreadcrumbList", "@id": url + "#brotkrumen", "itemListElement": [
        {"@type": "ListItem", "position": i, "name": name, "item": ziel} for i, (name, ziel) in enumerate(eintraege, 1)]}


def beitragsseite(b, neuer, aelter, kopf, fuss):
    titel = f"{b.titel} | HeydenSattler"
    og_bild = (b.bild["url"], b.bild["breite"], b.bild["hoehe"]) if b.bild else HERO
    extra = (f'<meta property="article:published_time" content="{b.datum.isoformat()}">\n'
             f'<meta property="article:modified_time" content="{geaendert(b).isoformat()}">\n')
    graph = [posting_ld(b, True),
             brotkrumen_ld(b.url, ("Startseite", DOMAIN + "/"), ("Blog", DOMAIN + "/blog/"), (b.titel, b.url)),
             firma_ld()]
    if b.autor:
        graph.append(person_ld(b.autor))

    meta = [e(autor_name(b)), f'<time datetime="{b.datum.isoformat()}">{de_datum(b.datum)}</time>',
            f"{b.minuten} Min. Lesezeit"]
    if b.geaendert and b.geaendert.date() != b.datum.date():
        meta.append(f'aktualisiert am <time datetime="{b.geaendert.isoformat()}">{de_datum(b.geaendert)}</time>')
    bild = ""
    if b.bild:
        bild = (f'      <figure class="beitrag-bild"><img src="{e(b.bild["pfad"])}" alt="{e(b.bild_alt)}" '
                f'width="{b.bild["breite"]}" height="{b.bild["hoehe"]}" fetchpriority="high"></figure>\n')
    autor = ""
    if b.autor:
        a = AUTOREN[b.autor]
        autor = (f'      <aside class="beitrag-autor" aria-label="{a["ueber"]}">\n'
                 f'        <img src="{e(a["bild"])}" alt="" width="251" height="300" loading="lazy" decoding="async">\n'
                 f'        <p><strong>{e(a["name"])}, {e(a["titel"])}</strong>{e(a["rolle"])} bei HeydenSattler</p>\n'
                 '      </aside>\n')
    nachbarn = []
    if aelter:
        nachbarn.append(f'        <a class="querlink aelter" href="{e(aelter.pfad_web)}">{PFEIL_ZURUECK}'
                        f'<span>Vorheriger Beitrag<strong>{e(aelter.titel)}</strong></span></a>\n')
    if neuer:
        nachbarn.append(f'        <a class="querlink neuer" href="{e(neuer.pfad_web)}">'
                        f'<span>Nächster Beitrag<strong>{e(neuer.titel)}</strong></span>{PFEIL}</a>\n')
    querlinks = (('      <nav class="weiter beitrag-nachbarn" aria-label="Weitere Beiträge">\n'
                  + "".join(nachbarn) + "      </nav>\n") if nachbarn else "")

    return (kopfdaten(titel, b.beschreibung, b.url, "article", og_bild, b.bild_alt if b.bild else "HeydenSattler",
                      {"@context": "https://schema.org", "@graph": graph}, extra)
            + kopf.replace('<a href="/blog/">', '<a href="/blog/" aria-current="page">')
            + '<main id="inhalt">\n\n  <header class="seitentitel">\n    <div class="wrap">\n'
            + brotkrumen(("Startseite", "/"), ("Blog", "/blog/"), (b.titel, None))
            + f'      <h1>{e(b.titel)}</h1>\n      <p class="beitrag-meta">{" · ".join(meta)}</p>\n'
            + "    </div>\n  </header>\n\n"
            + '  <section class="abschnitt">\n    <div class="wrap">\n    <article class="beitrag">\n'
            + bild + f'      <div class="beitrag-text">\n{b.html}\n      </div>\n' + autor
            + "    </article>\n    </div>\n  </section>\n\n"
            + kontakt("Blog: Anfrage über heydensattler.com", querlinks)
            + "</main>" + fuss)


def uebersicht(beitraege, kopf, fuss):
    url = DOMAIN + "/blog/"
    beschreibung = ("Tipps und Wissenswertes von HeydenSattler zum Verkauf und zur Vermietung von Immobilien in "
                    "Augsburg und Umgebung.")
    ld = {"@context": "https://schema.org", "@graph": [
        {"@type": "Blog", "@id": url + "#blog", "url": url, "name": "HeydenSattler Blog", "description": beschreibung,
         "inLanguage": "de-DE", "publisher": {"@id": FIRMA}, "isPartOf": {"@id": DOMAIN + "/#website"},
         "breadcrumb": {"@id": url + "#brotkrumen"}, "blogPost": [posting_ld(b, False) for b in beitraege]},
        brotkrumen_ld(url, ("Startseite", DOMAIN + "/"), ("Blog", url)),
        firma_ld(),
    ]}
    karten = "\n".join(karte(b, 2) for b in beitraege)
    querlinks = (f'      <div class="weiter">\n'
                 f'        <a class="querlink" href="/leistungen/verkaufen/"><span><strong>Verkaufen</strong>So verkaufen wir Ihre Immobilie</span>{PFEIL}</a>\n'
                 f'        <a class="querlink" href="/leistungen/vermieten/"><span><strong>Vermieten</strong>So finden wir den passenden Mieter</span>{PFEIL}</a>\n'
                 "      </div>\n")
    return (kopfdaten("Blog: Ratgeber Immobilien in Augsburg | HeydenSattler", beschreibung, url, "website", HERO,
                      "HeydenSattler", ld)
            + kopf.replace('<a href="/blog/">', '<a href="/blog/" aria-current="page">')
            + '<main id="inhalt">\n\n  <header class="seitentitel">\n    <div class="wrap">\n'
            + brotkrumen(("Startseite", "/"), ("Blog", None))
            + f'      <h1>Blog</h1>\n      <p class="beitrag-meta">{e(beschreibung)}</p>\n'
            + "    </div>\n  </header>\n\n"
            + f'  <section class="abschnitt">\n    <div class="wrap">\n      <ul class="beitraege">\n{karten}\n'
            + '      </ul>\n      <p class="beitraege-feed"><a href="/blog/feed.xml">Neue Beiträge als Feed abonnieren (Atom)</a></p>\n'
            + "    </div>\n  </section>\n\n"
            + kontakt("Blog: Anfrage über heydensattler.com", querlinks)
            + "</main>" + fuss)


def feed(beitraege):
    atom = "http://www.w3.org/2005/Atom"
    ET.register_namespace("", atom)

    def kind(eltern, name, text=None, **attribute):
        el = ET.SubElement(eltern, f"{{{atom}}}{name}", attribute)
        if text is not None:
            el.text = text
        return el

    wurzel = ET.Element(f"{{{atom}}}feed", {"{http://www.w3.org/XML/1998/namespace}lang": "de-DE"})
    kind(wurzel, "title", "HeydenSattler Blog")
    kind(wurzel, "subtitle", "Tipps und Wissenswertes zum Verkauf und zur Vermietung von Immobilien in Augsburg")
    kind(wurzel, "link", rel="alternate", type="text/html", href=DOMAIN + "/blog/")
    kind(wurzel, "link", rel="self", type="application/atom+xml", href=DOMAIN + "/blog/feed.xml")
    kind(wurzel, "id", DOMAIN + "/blog/")
    kind(wurzel, "updated", max(geaendert(b) for b in beitraege).isoformat())
    autor = kind(wurzel, "author")
    kind(autor, "name", "HeydenSattler GmbH")
    kind(autor, "uri", DOMAIN + "/")
    kind(wurzel, "icon", DOMAIN + "/favicon.ico")
    for b in beitraege:
        eintrag = kind(wurzel, "entry")
        kind(eintrag, "title", b.titel)
        kind(eintrag, "link", rel="alternate", type="text/html", href=b.url)
        kind(eintrag, "id", b.url)
        kind(eintrag, "published", b.datum.isoformat())
        kind(eintrag, "updated", geaendert(b).isoformat())
        kind(kind(eintrag, "author"), "name", autor_name(b))
        kind(eintrag, "summary", b.beschreibung)
        inhalt = re.sub(r'\b(href|src)="/', rf'\1="{DOMAIN}/', b.html)
        if b.bild:
            inhalt = f'<p><img src="{e(b.bild["url"])}" alt="{e(b.bild_alt)}"></p>\n' + inhalt
        kind(eintrag, "content", inhalt, type="html")
    ET.indent(wurzel)
    return '<?xml version="1.0" encoding="utf-8"?>\n' + ET.tostring(wurzel, encoding="unicode") + "\n"


# ---------------------------------------------------------------------------------------------------------
# Bestehende Dateien zwischen Markierungen ersetzen

def ersetzen(inhalt, name, neu, datei):
    start, ende = f"<!-- blog:{name}:start -->", f"<!-- blog:{name}:ende -->"
    muster = re.compile(re.escape(start) + r".*?" + re.escape(ende), re.S)
    if not muster.search(inhalt):
        raise Abbruch(f"Markierung {start} … {ende} fehlt in {datei}")
    return muster.sub(lambda m: start + neu + ende, inhalt, count=1)


def navigation(inhalt, beitraege, datei):
    return ersetzen(inhalt, "nav", '<li><a href="/blog/">Blog</a></li>' if beitraege else "", datei)


def startseite(inhalt, beitraege):
    neu = "\n"
    if beitraege:
        neu = ('\n  <section class="abschnitt" id="aktuelles">\n    <div class="wrap">\n'
               "      <h2>Aktuelles aus unserem Blog:</h2>\n      <ul class=\"beitraege\">\n"
               + "\n".join(karte(b, 3) for b in beitraege[:TEASER])
               + '\n      </ul>\n      <div class="beitraege-aktion"><a class="btn" href="/blog/">Alle Beiträge</a></div>\n'
               "    </div>\n  </section>\n")
    return ersetzen(inhalt, "aktuelles", neu, "index.html")


def sitemap(inhalt, beitraege):
    neu = "\n  "
    if beitraege:
        zeilen = [f"  <url><loc>{DOMAIN}/blog/</loc><lastmod>{max(geaendert(b) for b in beitraege).date()}</lastmod></url>"]
        zeilen += [f"  <url><loc>{e(b.url)}</loc><lastmod>{geaendert(b).date()}</lastmod></url>" for b in beitraege]
        neu = "\n" + "\n".join(zeilen) + "\n  "
    return ersetzen(inhalt, "liste", neu, "sitemap.xml")


def llms(inhalt, beitraege):
    neu = "\n"
    if beitraege:
        zeilen = ["## Blog", "", f"Alle Beiträge: {DOMAIN}/blog/ (Feed: {DOMAIN}/blog/feed.xml)", ""]
        zeilen += [f"- [{b.titel}]({b.url}) ({de_datum(b.datum)}, {autor_name(b)}): {b.beschreibung}"
                   for b in beitraege]
        neu = "\n" + "\n".join(zeilen) + "\n"
    return ersetzen(inhalt, "liste", neu, "llms.txt")


# ---------------------------------------------------------------------------------------------------------

def einlesen(inhalte, tz):
    beitraege, fehler = [], []
    for pfad in sorted(inhalte.glob("*.md")):
        if pfad.name.startswith("_"):
            continue
        try:
            beitraege.append(Beitrag(pfad, inhalte, tz))
        except Fehler as f:
            fehler.append(f)
    aktiv = [b for b in beitraege if not b.entwurf]
    for slug in {b.slug for b in aktiv if [c.slug for c in aktiv].count(b.slug) > 1}:
        dateien = [b.anzeige for b in aktiv if b.slug == slug]
        fehler.append(Fehler(dateien[0], 1, f"Slug „{slug}“ doppelt: {', '.join(dateien)}; bitte slug setzen"))
    if fehler:
        for f in fehler:
            print(f"::error file={f.datei},line={f.zeile}::{f.datei}, Zeile {f.zeile}: {f.text}", file=sys.stderr)
        raise Abbruch(f"{len(fehler)} Fehler in den Beiträgen, nichts geschrieben")
    return beitraege


def main():
    teiler = argparse.ArgumentParser(description="Blog aus Markdown-Dateien in die Website schreiben")
    teiler.add_argument("--beispiel", action="store_true",
                        help="Beispiele aus scripts/beispiel-blog/, schreibt in _beispiel-blog/ (nie in site/)")
    teiler.add_argument("--ordner", help="vorhandener Ordner, in den geschrieben wird (Standard site/)")
    teiler.add_argument("--inhalte", help="Ordner mit den Beiträgen (Standard inhalte/blog/)")
    teiler.add_argument("--jetzt", help="so tun, als wäre es dieser Zeitpunkt (JJJJ-MM-TT HH:MM); nie für site/")
    args = teiler.parse_args()

    tz = zeitzone()
    inhalte = Path(args.inhalte).resolve() if args.inhalte else (BEISPIEL if args.beispiel else INHALTE)
    if args.ordner:
        ziel = Path(args.ordner).resolve()
    else:
        ziel = WURZEL / "_beispiel-blog" if args.beispiel else QUELLE
    if ziel == QUELLE and (args.beispiel or args.inhalte or args.jetzt):
        raise Abbruch("--beispiel, --inhalte und --jetzt schreiben nie in site/, sonst gingen sie live")
    jetzt = zeitpunkt(args.jetzt, tz, "--jetzt", 0, "--jetzt") if args.jetzt else datetime.now(tz)

    beitraege = einlesen(inhalte, tz) if inhalte.is_dir() else []
    # Die einzige Stelle, die entscheidet, was erscheint: alles danach sieht nur noch diese Liste
    veroeffentlicht = sorted((b for b in beitraege if not b.entwurf and b.datum <= jetzt),
                             key=lambda b: b.datum, reverse=True)
    geplant = sorted(b.datum for b in beitraege if not b.entwurf and b.datum > jetzt)
    for b in veroeffentlicht:
        verlinken(b, veroeffentlicht, beitraege)

    if args.beispiel and not args.ordner:
        if ziel.exists():
            shutil.rmtree(ziel)
        shutil.copytree(QUELLE, ziel, ignore=shutil.ignore_patterns("blog"))
    if not (ziel / "index.html").is_file():
        raise Abbruch(f"{ziel} enthält keine index.html")

    # Erst alles erzeugen, dann schreiben: bei einem Fehler bleibt der alte Stand
    dateien = {}
    for seite in sorted(ziel.rglob("*.html")):
        relativ = seite.relative_to(ziel).as_posix()
        if relativ.startswith("blog/"):
            continue
        inhalt = seite.read_text(encoding="utf-8")
        if "<!-- blog:nav:start -->" in inhalt or relativ in ("index.html", "404.html") \
                or relativ.startswith("leistungen/"):
            dateien[relativ] = navigation(inhalt, veroeffentlicht, relativ)
    dateien["index.html"] = startseite(dateien["index.html"], veroeffentlicht)
    dateien["sitemap.xml"] = sitemap((ziel / "sitemap.xml").read_text(encoding="utf-8"), veroeffentlicht)
    dateien["llms.txt"] = llms((ziel / "llms.txt").read_text(encoding="utf-8"), veroeffentlicht)

    kopien = {}
    if veroeffentlicht:
        index = dateien["index.html"]
        kopf = index[index.index('<a class="skip"'):index.index('<main id="inhalt">')]
        fuss = index[index.index("</main>") + len("</main>"):index.index("<script src=\"/assets/site.js\"")]
        fuss += '<script src="/assets/site.js" defer></script>\n</body>\n</html>\n'
        dateien["blog/index.html"] = uebersicht(veroeffentlicht, kopf, fuss)
        dateien["blog/feed.xml"] = feed(veroeffentlicht)
        for i, b in enumerate(veroeffentlicht):
            neuer = veroeffentlicht[i - 1] if i > 0 else None
            aelter = veroeffentlicht[i + 1] if i + 1 < len(veroeffentlicht) else None
            dateien[f"blog/{b.slug}/index.html"] = beitragsseite(b, neuer, aelter, kopf, fuss)
            for quelle, name in b.bilder.items():
                kopien[f"blog/{b.slug}/{name}"] = quelle

    ordner = ziel / "blog"
    if ordner.exists():
        shutil.rmtree(ordner)
    for pfad, inhalt in dateien.items():
        (ziel / pfad).parent.mkdir(parents=True, exist_ok=True)
        (ziel / pfad).write_text(inhalt, encoding="utf-8")
    for pfad, quelle in kopien.items():
        shutil.copyfile(quelle, ziel / pfad)

    for b in veroeffentlicht:
        log(f"  {b.pfad_web}  ({b.datum:%d.%m.%Y %H:%M}, {len(b.bilder)} Bilder)")
    entwuerfe = sum(b.entwurf for b in beitraege)
    hinweis = f", nächster am {geplant[0]:%d.%m.%Y %H:%M}" if geplant else ""
    log(f"{len(veroeffentlicht)} Beiträge veröffentlicht, {len(geplant)} geplant{hinweis}, {entwuerfe} Entwürfe; "
        f"Stand {jetzt:%d.%m.%Y %H:%M} ({ZEITZONE}), geschrieben nach {ziel}")


if __name__ == "__main__":
    try:
        main()
    except Abbruch as fehler:
        print(f"::error::{fehler}", file=sys.stderr)
        sys.exit(1)
    except Fehler as fehler:
        print(f"::error::{fehler.datei}: {fehler.text}", file=sys.stderr)
        sys.exit(1)
