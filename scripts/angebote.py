"""Erzeugt die Immobilienangebote der Website aus Propstack.

Liest PROPSTACK_API_KEY aus der Umgebung, lädt die öffentlichen Objekte und schreibt
- in index.html die Liste im Abschnitt #angebote und das JSON-LD ItemList (nur zwischen den Markierungen
  <!-- angebote:…:start --> und <!-- angebote:…:ende -->),
- pro Objekt eine Detailseite angebote/<slug>/index.html,
- die Angebote in sitemap.xml und llms.txt (ebenfalls zwischen Markierungen).

Welche Objekte erscheinen:
- Ist PROPSTACK_PORTAL_ID gesetzt (API V2), nur Objekte mit einer Veröffentlichung auf diesem Portal und
  davon nur Bilder, die für dieses Portal freigegeben sind.
- Sonst alle nicht archivierten Objekte in einem öffentlichen Status (property_statuses, nonpublic = false).
Nie ausgegeben werden private Bilder, Straße und Hausnummer, Koordinaten, Eigentümer-, Makler- und
Kontaktdaten sowie interne Felder: die Seiten nutzen nur die Felder, die unten ausdrücklich genannt sind.

Standard ist die Propstack-API V1 (dafür ist der hinterlegte Schlüssel). Mit PROPSTACK_API_VERSION=2 und einem
Schlüssel für V2 nutzt das Skript V2; nur dort gibt es den Filter nach Portal und die Abfrage der Schlüssel-Rechte.

Aufruf:
  python3 scripts/angebote.py                 schreibt in site/ (im Build auf GitHub)
  python3 scripts/angebote.py --beispiel      Beispieldaten, schreibt in _beispiel/ (Kopie von site/)
  python3 scripts/angebote.py --ziel ORDNER   kopiert site/ nach ORDNER und schreibt dort
"""
import argparse
import html
import json
import os
import re
import shutil
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import date, datetime, timezone
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent
QUELLE = WURZEL / "site"
BEISPIEL = WURZEL / "scripts" / "beispiel-angebote.json"
DOMAIN = "https://heydensattler.com"
API = "https://api.propstack.de"
FIRMA = DOMAIN + "/#firma"
TELEFON = ("+4982199982940", "0821 999 8294 0")
MAIL = "info@heydensattler.com"


def log(text):
    print(text, flush=True)


def warnung(text):
    # Im Log von GitHub Actions als gelbe Warnung sichtbar
    print(f"::warning::{text}", flush=True)


class Abbruch(Exception):
    pass


# ---------------------------------------------------------------------------------------------------------
# Werte robust lesen: V2 typisiert vieles als string, V1 (new=1) liefert {label, value, pretty_value}

def roh(wert):
    while isinstance(wert, dict) and "value" in wert:
        wert = wert["value"]
    return wert


def wahr(wert):
    wert = roh(wert)
    if isinstance(wert, bool):
        return wert
    if isinstance(wert, (int, float)):
        return wert == 1
    return str(wert).strip().lower() in {"true", "1", "yes", "ja", "t"}


def falsch(wert):
    # Nur ein ausdrückliches Nein, leer zählt nicht
    wert = roh(wert)
    if isinstance(wert, bool):
        return not wert
    if isinstance(wert, (int, float)):
        return wert == 0
    return str(wert).strip().lower() in {"false", "0", "no", "nein", "f"}


def zahl(wert):
    wert = roh(wert)
    if wert is None or isinstance(wert, bool) or wert == "":
        return None
    try:
        return float(str(wert).replace(",", ".")) if isinstance(wert, str) else float(wert)
    except ValueError:
        return None


def text(wert):
    wert = roh(wert)
    if wert is None or isinstance(wert, (dict, list)):
        return ""
    return str(wert).strip()


# ---------------------------------------------------------------------------------------------------------
# Deutsche Formate

def de_zahl(wert, stellen=2):
    """1234.5 -> "1.234,5"; höchstens `stellen` Nachkommastellen, ohne Nullen am Ende."""
    s = f"{wert:,.{stellen}f}".replace(",", "X").replace(".", ",").replace("X", ".")
    if "," in s:
        s = s.rstrip("0").rstrip(",")
    return s


def euro(wert):
    return de_zahl(wert, 2 if wert % 1 else 0) + " €"


def flaeche(wert, stellen=2):
    return de_zahl(wert, stellen) + " m²"


def de_datum(wert):
    wert = text(wert)
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})", wert)
    return f"{m[3]}.{m[2]}.{m[1]}" if m else wert


def etage(wert):
    n = zahl(wert)
    if n is None:
        return text(wert)
    n = int(n)
    return "EG" if n == 0 else ("UG" if n < 0 else f"{n}. OG")


ARTEN = {
    "APARTMENT": "Wohnung", "HOUSE": "Haus", "TRADE_SITE": "Grundstück", "GARAGE": "Stellplatz",
    "SHORT_TERM_ACCOMODATION": "Wohnen auf Zeit", "OFFICE": "Büro/Praxis", "GASTRONOMY": "Gastronomie",
    "INDUSTRY": "Halle/Lager", "STORE": "Einzelhandel", "SPECIAL_PURPOSE": "Spezialimmobilie",
    "INVESTMENT": "Anlageobjekt",
}
WOHNEN = {"APARTMENT", "HOUSE", "SHORT_TERM_ACCOMODATION"}

# Objektarten (rs_category) laut Propstack-Doku
KATEGORIEN = {
    "ROOF_STOREY": "Dachgeschoss", "LOFT": "Loft", "MAISONETTE": "Maisonette", "PENTHOUSE": "Penthouse",
    "TERRACED_FLAT": "Terrassenwohnung", "GROUND_FLOOR": "Erdgeschosswohnung", "APARTMENT": "Etagenwohnung",
    "RAISED_GROUND_FLOOR": "Hochparterre", "HALF_BASEMENT": "Souterrain", "ATTIKA": "Attikawohnung",
    "OTHER": "Sonstige", "SINGLE_FAMILY_HOUSE": "Einfamilienhaus", "TWO_FAMILY_HOUSE": "Zweifamilienhaus",
    "TERRACE_HOUSE": "Reihenhaus", "MID_TERRACE_HOUSE": "Reihenmittelhaus", "TERRACE_END_HOUSE": "Reihenendhaus",
    "END_TERRACE_HOUSE": "Reiheneckhaus", "MULTI_FAMILY_HOUSE": "Mehrfamilienhaus", "TOWNHOUSE": "Stadthaus",
    "FINCA": "Finca", "BUNGALOW": "Bungalow", "FARMHOUSE": "Bauernhaus", "SEMIDETACHED_HOUSE": "Doppelhaushälfte",
    "VILLA": "Villa", "CASTLE_MANOR_HOUSE": "Burg/Schloss", "SPECIAL_REAL_ESTATE": "Besondere Immobilie",
    "TWIN_SINGLE_FAMILY_HOUSE": "Doppeleinfamilienhaus", "SUMMER_RESIDENCE": "Ferienhaus", "GARAGE": "Garage",
    "STREET_PARKING": "Außenstellplatz", "CARPORT": "Carport", "DUPLEX": "Duplex", "CAR_PARK": "Parkhaus",
    "UNDERGROUND_GARAGE": "Tiefgarage", "DOUBLE_GARAGE": "Doppelgarage", "NO_INFORMATION": "Keine Angabe",
    "OFFICE_LOFT": "Loft", "STUDIO": "Atelier", "OFFICE": "Büro", "OFFICE_FLOOR": "Büroetage",
    "OFFICE_BUILDING": "Bürohaus", "OFFICE_CENTRE": "Bürozentrum", "OFFICE_STORAGE_BUILDING": "Büro-/ Lagergebäude",
    "SURGERY": "Praxis", "SURGERY_FLOOR": "Praxisetage", "SURGERY_BUILDING": "Praxishaus",
    "COMMERCIAL_CENTRE": "Gewerbezentrum", "LIVING_AND_COMMERCIAL_BUILDING": "Wohn- und Geschäftsgebäude",
    "OFFICE_AND_COMMERCIAL_BUILDING": "Büro- und Geschäftsgebäude", "BAR_LOUNGE": "Barbetrieb/Lounge",
    "CAFE": "Café", "CLUB_DISCO": "Club/Diskothek", "GUESTS_HOUSE": "Gästehaus", "TAVERN": "Gaststätte",
    "HOTEL": "Hotel", "HOTEL_RESIDENCE": "Hotelanwesen", "HOTEL_GARNI": "Hotel garni", "PENSION": "Pension",
    "RESTAURANT": "Restaurant", "SHOWROOM_SPACE": "Ausstellungsfläche", "HALL": "Halle",
    "HIGH_LACK_STORAGE": "Hochregallager", "INDUSTRY_HALL": "Industriehalle",
    "INDUSTRY_HALL_WITH_OPEN_AREA": "Industriehalle mit Freifläche", "COLD_STORAGE": "Kühlhaus",
    "MULTIDECK_CABINET_STORAGE": "Kühlregallager", "STORAGE_WITH_OPEN_AREA": "Lager mit Freifläche",
    "STORAGE_AREA": "Lagerfläche", "STORAGE_HALL": "Lagerhalle", "SERVICE_AREA": "Servicefläche",
    "SHIPPING_STORAGE": "Speditionslager", "REPAIR_SHOP": "Werkstatt", "SHOPPING_CENTRE": "Einkaufszentrum",
    "FACTORY_OUTLET": "Factory Outlet", "DEPARTMENT_STORE": "Kaufhaus", "KIOSK": "Kiosk", "STORE": "Laden",
    "SELF_SERVICE_MARKET": "SB-Markt", "SALES_AREA": "Verkaufsfläche", "SALES_HALL": "Verkaufshalle",
    "RESIDENCE": "Anwesen", "FARM": "Bauernhof", "LEISURE_FACILITY": "Freizeitanlage",
    "COMMERCIAL_UNIT": "Gewerbeeinheit", "INDUSTRIAL_AREA": "Gewerbefläche", "NURSING_HOME": "Pflegeheim",
    "ASSISTED_LIVING": "Betreutes Wohnen", "HORSE_FARM": "Reiterhof", "VINEYARD": "Weingut",
    "SPECIAL_ESTATE": "Spezialobjekt", "INVEST_LIVING_BUSINESS_HOUSE": "Wohn-/Geschäftshaus",
    "INVEST_HOUSING_ESTATE": "Wohnanlage", "INVEST_MICRO_APARTMENTS": "Micro-Apartments",
    "INVEST_OFFICE_BUILDING": "Bürohaus", "INVEST_COMMERCIAL_BUILDING": "Geschäftshaus",
    "INVEST_OFFICE_AND_COMMERCIAL_BUILDING": "Büro- und Geschäftshaus",
    "INVEST_SHOP_SALES_FLOOR": "Laden/Verkaufsfläche", "INVEST_SUPERMARKET": "Supermarkt",
    "INVEST_SHOPPING_CENTRE": "Einkaufszentrum", "INVEST_RETAIL_PARK": "Fachmarktzentrum", "INVEST_HOTEL": "Hotel",
    "INVEST_BOARDING_HOUSE": "Boarding House", "INVEST_SURGERY_BUILDING": "Ärztehaus", "INVEST_CLINIC": "Klinik",
    "INVEST_REHAB_CLINIC": "Rehaklinik", "INVEST_MEDICAL_SERVICE_CENTER": "MVZ",
    "INVEST_INTEGRATION_ASSISTANCE": "Eingliederungshilfe", "INVEST_DAY_NURSERY": "Kita",
    "INVEST_DAY_CARE": "Tagespflege", "INVEST_NURSING_HOME": "Pflegeheim",
    "INVEST_ASSISTED_LIVING": "Betreutes Wohnen", "INVEST_COMMERCIAL_CENTRE": "Gewerbepark",
    "INVEST_HALL_STORAGE": "Halle/Logistik", "INVEST_INDUSTRIAL_PROPERTY": "Produktion/Fertigung",
    "INVEST_CAR_PARK": "Parkhaus", "INVEST_PLOT": "Grundstück", "INVEST_COMMERCIAL_UNIT": "Gewerbeeinheit",
    "INVEST_OTHER": "Sonstiges",
}

ZUSTAND = {
    "FIRST_TIME_USE": "Erstbezug", "FIRST_TIME_USE_AFTER_REFURBISHMENT": "Erstbezug nach Sanierung",
    "MINT_CONDITION": "neuwertig", "REFURBISHED": "saniert", "MODERNIZED": "modernisiert",
    "FULLY_RENOVATED": "vollständig renoviert", "WELL_KEPT": "gepflegt", "NEED_OF_RENOVATION": "renovierungsbedürftig",
    "NEGOTIABLE": "nach Vereinbarung", "RIPE_FOR_DEMOLITION": "abbruchreif",
}
QUALITAET = {"LUXURY": "Luxus", "SOPHISTICATED": "gehoben", "NORMAL": "normal", "SIMPLE": "einfach"}
AUSWEIS = {
    "AVAILABLE": "liegt vor", "NOT_AVAILABLE_YET": "liegt noch nicht vor", "NOT_REQUIRED": "nicht nötig",
    "FOR_VIEWING": "liegt zur Besichtigung vor", "NOT_AVAILABLE": "liegt nicht vor",
}
AUSWEISTYP = {"ENERGY_REQUIRED": "Bedarfsausweis", "ENERGY_CONSUMPTION": "Verbrauchsausweis"}
HEIZUNG = {
    "CENTRAL_HEATING": "Zentralheizung", "SELF_CONTAINED_CENTRAL_HEATING": "Etagenheizung",
    "FLOOR_HEATING": "Fußbodenheizung", "DISTRICT_HEATING": "Fernwärme", "STOVE_HEATING": "Ofenheizung",
    "OIL_HEATING": "Öl-Heizung", "GAS_HEATING": "Gas-Heizung", "ELECTRIC_HEATING": "Elektro-Heizung",
    "HEAT_PUMP": "Wärmepumpe", "COMBINED_HEAT_AND_POWER_PLANT": "Blockheizkraftwerk",
    "NIGHT_STORAGE_HEATER": "Nachtspeicheröfen", "SOLAR_HEATING": "Solarheizung",
    "WOOD_PELLET_HEATING": "Pelletheizung",
}
ENERGIETRAEGER = {
    "OIL": "Öl", "GAS": "Gas", "ELECTRICITY": "Strom", "DISTRICT_HEATING": "Fernwärme", "LOCAL_HEATING": "Nahwärme",
    "SOLAR_HEATING": "Solar", "PELLET_HEATING": "Pellets", "GEOTHERMAL": "Erdwärme", "LIQUID_GAS": "Flüssiggas",
    "WOOD": "Holz", "WOOD_CHIPS": "Holzhackschnitzel", "COAL": "Kohle", "COAL_COKE": "Koks",
    "HEAT_PUMP": "Wärmepumpe", "AIR_WATER_HEAT_PUMP": "Luft-Wasser-Wärmepumpe", "BIO_ENERGY": "Bioenergie",
    "ENVIRONMENTAL_THERMAL_ENERGY": "Umweltwärme", "HYDRO_ENERGY": "Wasserenergie", "WIND_ENERGY": "Windenergie",
    "STEAM_DISTRICT_HEATING": "Fernwärme (Dampf)", "COMBINED_HEAT_AND_POWER_FOSSIL_FUELS": "KWK fossil",
    "COMBINED_HEAT_AND_POWER_RENEWABLE_ENERGY": "KWK erneuerbar",
    "COMBINED_HEAT_AND_POWER_REGENERATIVE_ENERGY": "KWK regenerativ",
    "COMBINED_HEAT_AND_POWER_BIO_ENERGY": "KWK Bioenergie",
}
EFFIZIENZKLASSEN = ["A+", "A", "B", "C", "D", "E", "F", "G", "H"]

unbekannt = set()


def uebersetzt(tabelle, wert, feld):
    """Propstack-Kürzel ins Deutsche; unbekannte Kürzel nicht anzeigen, nur im Log nennen."""
    wert = text(wert)
    if not wert:
        return ""
    if wert in tabelle:
        return tabelle[wert]
    if re.fullmatch(r"[A-Z0-9_]+", wert):
        unbekannt.add(f"{feld}={wert}")
        return ""
    return wert


def effizienzklasse(wert):
    wert = text(wert).upper().replace("_PLUS", "+").replace("PLUS", "+").replace(" ", "")
    return wert if wert in EFFIZIENZKLASSEN else ""


# ---------------------------------------------------------------------------------------------------------
# Propstack laden

class Propstack:
    def __init__(self, schluessel):
        self.schluessel = schluessel

    def holen(self, pfad, **parameter):
        url = API + pfad + ("?" + urllib.parse.urlencode(parameter) if parameter else "")
        anfrage = urllib.request.Request(url, headers={
            "X-Api-Key": self.schluessel, "Accept": "application/json", "User-Agent": "heydensattler.com-Build",
        })
        for versuch in range(4):
            try:
                with urllib.request.urlopen(anfrage, timeout=60) as antwort:
                    return json.load(antwort)
            except urllib.error.HTTPError as fehler:
                if fehler.code in (429, 500, 502, 503, 504) and versuch < 3:
                    time.sleep(2 ** versuch * 3)
                    continue
                raise
            except urllib.error.URLError:
                if versuch < 3:
                    time.sleep(2 ** versuch * 3)
                    continue
                raise

    def seiten(self, pfad, per=100, **parameter):
        alle, seite = [], 1
        while True:
            antwort = self.holen(pfad, page=seite, per=per, **parameter)
            daten = antwort.get("data", []) if isinstance(antwort, dict) else antwort
            alle += daten
            if len(daten) < per:
                return alle
            seite += 1


def liste(antwort):
    if isinstance(antwort, dict):
        antwort = antwort.get("data", antwort)
    return antwort if isinstance(antwort, list) else []


def rechte_pruefen(rechte):
    daten = rechte.get("data", rechte) if isinstance(rechte, dict) else {}
    name = text(daten.get("name")) or "(ohne Namen)"
    erlaubt = [str(r) for r in daten.get("permissions") or []]
    log(f"Propstack-Schlüssel: {name}; Rechte: {', '.join(erlaubt) or '(keine angegeben)'}")
    schreiben = [r for r in erlaubt if re.search(r"write|create|update|delete|destroy|edit|manage|schreib", r, re.I)]
    kontakte = [r for r in erlaubt if re.search(r"client|contact|kontakt|deal|mail|message", r, re.I)]
    if schreiben:
        warnung(f"Der Propstack-Schlüssel darf schreiben ({', '.join(schreiben)}). Für die Website reicht ein nur "
                "lesender Schlüssel; bitte in Propstack einen solchen anlegen und das Secret PROPSTACK_API_KEY ersetzen.")
    if kontakte:
        warnung(f"Der Propstack-Schlüssel hat Zugriff auf Kontakte ({', '.join(kontakte)}). Die Website braucht nur "
                "Objekte, Status, Portale und Veröffentlichungen; bitte die Rechte des Schlüssels einschränken.")


def aus_api(schluessel, portal_id, version):
    """Lädt Status und Objekte; gibt (status, objekte, veröffentlichte Objekt-IDs oder None) zurück."""
    ps = Propstack(schluessel)
    if version != "2":
        log("Propstack-API V1")
        return aus_api_v1(ps, portal_id)

    log("Propstack-API V2")
    rechte_pruefen(ps.holen("/v2/rights"))
    status = liste(ps.holen("/v2/property_statuses"))
    veroeffentlicht = None
    if portal_id:
        portale = {str(p.get("id")): text(p.get("name")) for p in ps.seiten("/v2/portals")}
        if portal_id not in portale:
            raise Abbruch(f"Das Portal {portal_id} (Variable PROPSTACK_PORTAL_ID) gibt es in Propstack nicht. "
                          f"Vorhanden: {', '.join(f'{i} {n}' for i, n in portale.items()) or 'keine'}.")
        veroeffentlicht = {str(v.get("property_id")) for v in ps.seiten("/v2/publishings", portal_id=portal_id)}
        log(f"Filter: Veröffentlichungen auf Portal {portal_id} „{portale[portal_id]}“ ({len(veroeffentlicht)} Objekte)")
    uebersicht = ps.seiten("/v2/properties", per=500)
    kandidaten = vorauswahl(uebersicht, status, veroeffentlicht)
    objekte = [ps.holen(f"/v2/properties/{o['id']}") for o in kandidaten]
    return status, [o.get("data", o) if isinstance(o.get("data"), dict) else o for o in objekte], veroeffentlicht


def aus_api_v1(ps, portal_id):
    status = liste(ps.holen("/v1/property_statuses"))
    if portal_id:
        warnung("PROPSTACK_PORTAL_ID ist gesetzt, aber der Filter nach Portal gibt es nur in API V2 "
                "(PROPSTACK_API_VERSION=2). Gefiltert wird stattdessen nach öffentlichem Status.")
    # expand=1 liefert das ausführliche JSON schon in der Liste
    uebersicht = [v1_normal(o) for o in ps.seiten("/v1/units", per=100, with_meta=1, expand=1)]
    kandidaten = vorauswahl(uebersicht, status, None)
    if kandidaten:
        # Nur Feldnamen, keine Werte: zeigt im Log, was Propstack liefert
        felder = sorted({k for o in kandidaten for k, v in o.items() if v not in (None, "", [], {})})
        log("Felder in GET /v1/units: " + ", ".join(felder))
    objekte, einzeln = [], True
    for o in kandidaten:
        if einzeln:
            try:
                objekte.append(v1_normal(ps.holen(f"/v1/units/{o['id']}", new=1)))
                continue
            except urllib.error.HTTPError as fehler:
                if fehler.code not in (401, 403):
                    raise
                einzeln = False
                log(f"GET /v1/units/:id ist für diesen Schlüssel gesperrt (HTTP {fehler.code}); "
                    "die Seiten nutzen die Daten aus der Liste.")
        objekte.append(o)
    return status, objekte, None


def v1_normal(o):
    """Objekt aus API V1 in die Form von V2 bringen."""
    o = dict(o)
    st = o.get("property_status") or o.get("status")
    if isinstance(st, dict):
        o["property_status_id"] = st.get("id")
    elif st is not None and "property_status_id" not in o:
        o["property_status_id"] = st
    bilder = []
    for b in o.get("images") or []:
        b = dict(b)
        b["urls"] = {k: b.get(f"{k}_url") or b.get(k) for k in ("big", "medium", "thumb")
                     if isinstance(b.get(f"{k}_url") or b.get(k), str)}
        b["url"] = b.get("url") or b.get("original")
        bilder.append(b)
    o["images"] = bilder
    return o


def vorauswahl(objekte, status, veroeffentlicht):
    oeffentlich = {str(s.get("id")) for s in status if not wahr(s.get("nonpublic"))}
    namen = {str(s.get("id")): text(s.get("name")) for s in status}
    if veroeffentlicht is None:
        log(f"Filter: öffentliche Status ({', '.join(namen[i] for i in sorted(oeffentlich)) or 'keine'}), "
            "nicht archiviert")
    auswahl, ohne_status = [], 0
    for o in objekte:
        if wahr(o.get("archived")):
            continue
        sid = text(o.get("property_status_id"))
        if not sid:
            ohne_status += 1
            continue
        if sid not in oeffentlich:
            continue
        if veroeffentlicht is not None and text(o.get("id")) not in veroeffentlicht:
            continue
        auswahl.append(o)
    if ohne_status:
        log(f"{ohne_status} Objekte ohne Status übersprungen")
    log(f"{len(auswahl)} von {len(objekte)} Objekten sind öffentlich")
    return auswahl


def aus_beispiel(portal_id):
    daten = json.loads(BEISPIEL.read_text(encoding="utf-8"))
    rechte_pruefen(daten["rights"])
    status = liste(daten["property_statuses"])
    veroeffentlicht = None
    if portal_id:
        veroeffentlicht = {str(v["property_id"]) for v in liste(daten["publishings"])
                           if str(v["portal_id"]) == portal_id}
        log(f"Filter: Veröffentlichungen auf Portal {portal_id} ({len(veroeffentlicht)} Objekte)")
    return status, vorauswahl(daten["properties"], status, veroeffentlicht), veroeffentlicht


# ---------------------------------------------------------------------------------------------------------
# Bilder: nur öffentliche, Größe aus dem Dateikopf

def bildadresse(bild, groesse):
    urls = bild.get("urls") or {}
    for schluessel in (groesse, f"{groesse}_url"):
        wert = urls.get(schluessel) if isinstance(urls, dict) else None
        if isinstance(wert, dict):
            wert = wert.get("url")
        if wert:
            return wert
    if bild.get(f"{groesse}_url"):
        return bild[f"{groesse}_url"]
    # Propstack legt die Größen neben das Original: …/photo/<id>/big_<name>
    original = text(bild.get("url"))
    m = re.match(r"(https://images\.propstack\.de/.+/)([^/]+)$", original)
    return m[1] + f"{groesse}_" + m[2] if m else original


def bildmasse(url):
    """Breite und Höhe aus dem Kopf einer JPEG-, PNG- oder WebP-Datei."""
    try:
        anfrage = urllib.request.Request(url, headers={"Range": "bytes=0-65535", "User-Agent": "heydensattler.com-Build"})
        with urllib.request.urlopen(anfrage, timeout=30) as antwort:
            daten = antwort.read()
    except (urllib.error.URLError, TimeoutError):
        return None
    if daten[:8] == b"\x89PNG\r\n\x1a\n":
        return struct.unpack(">II", daten[16:24])
    if daten[:4] == b"RIFF" and daten[8:12] == b"WEBP":
        if daten[12:16] == b"VP8X":
            return 1 + int.from_bytes(daten[24:27], "little"), 1 + int.from_bytes(daten[27:30], "little")
        if daten[12:16] == b"VP8 ":
            w, h = struct.unpack("<HH", daten[26:30])
            return w & 0x3FFF, h & 0x3FFF
        return None
    i = 2
    while daten[:2] == b"\xff\xd8" and i + 9 < len(daten):
        if daten[i] != 0xFF:
            i += 1
            continue
        marke, laenge = daten[i + 1], struct.unpack(">H", daten[i + 2:i + 4])[0]
        if 0xC0 <= marke <= 0xCF and marke not in (0xC4, 0xC8, 0xCC):
            h, w = struct.unpack(">HH", daten[i + 5:i + 9])
            return w, h
        i += 2 + laenge
    return None


def bilder_vorbereiten(objekte, portal_id):
    """Öffentliche Bilder je Objekt (Fotos, dann Grundrisse) mit Adressen und Maßen."""
    alle = {}
    for o in objekte:
        fotos, plaene = [], []
        for b in sorted(o.get("images") or [], key=lambda b: zahl(b.get("position")) or 0):
            if wahr(b.get("is_private")):
                continue
            freigaben = b.get("connection_ids")
            if portal_id and isinstance(freigaben, list) and freigaben and portal_id not in {str(f) for f in freigaben}:
                continue
            eintrag = {
                "titel": text(b.get("title")), "plan": wahr(b.get("is_floorplan")),
                "gross": bildadresse(b, "big"), "mittel": bildadresse(b, "medium"), "klein": bildadresse(b, "thumb"),
            }
            if eintrag["gross"]:
                (plaene if eintrag["plan"] else fotos).append(eintrag)
        alle[text(o.get("id"))] = fotos + plaene

    adressen = {u for liste_ in alle.values() for b in liste_ for u in (b["gross"], b["mittel"])}
    with ThreadPoolExecutor(max_workers=8) as pool:
        masse = dict(zip(adressen, pool.map(bildmasse, adressen)))
    fehlend = 0
    for liste_ in alle.values():
        for b in liste_:
            b["gross_masse"] = masse.get(b["gross"])
            b["mittel_masse"] = masse.get(b["mittel"])
            if not b["gross_masse"] or not b["mittel_masse"]:
                fehlend += 1
                b["gross_masse"] = b["gross_masse"] or (1920, 1440)
                b["mittel_masse"] = b["mittel_masse"] or (600, round(600 * b["gross_masse"][1] / b["gross_masse"][0]))
    if fehlend:
        log(f"Bei {fehlend} Bildern ließ sich die Größe nicht lesen, angenommen 4:3")
    return alle


# ---------------------------------------------------------------------------------------------------------
# Ein Angebot für die Seiten aufbereiten

def slug_teil(wert):
    wert = wert.lower()
    for a, b in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss")):
        wert = wert.replace(a, b)
    return re.sub(r"[^a-z0-9]+", "-", wert).strip("-")


def zimmer_text(n):
    return de_zahl(n, 1)


class Angebot:
    def __init__(self, o, bilder, status_namen):
        self.o = o
        self.id = text(o.get("id"))
        self.nr = text(o.get("unit_id")) or text(o.get("exposee_id")) or self.id
        self.titel = text(o.get("title")) or text(o.get("name"))
        self.kauf = text(o.get("marketing_type")).upper() != "RENT"
        self.typ = text(o.get("rs_type")).upper()
        self.art = ARTEN.get(self.typ, "Immobilie")
        self.kategorie = KATEGORIEN.get(text(o.get("rs_category")).upper(), "")
        self.zimmer = zahl(o.get("number_of_rooms"))
        self.wohnen = self.typ in WOHNEN
        self.flaeche = zahl(o.get("living_space")) if self.wohnen else None
        if not self.flaeche:
            for feld in ("property_space_value", "total_floor_space", "usable_floor_space", "net_floor_space"):
                self.flaeche = zahl(o.get(feld))
                if self.flaeche:
                    break
        self.plz, self.ort = text(o.get("zip_code")), text(o.get("city"))
        self.status = status_namen.get(text(o.get("property_status_id")), "")
        self.bilder = bilder

        # Preis: „Preis auf Anfrage“, wenn Propstack ihn verbirgt
        self.preis_label = "Kaufpreis" if self.kauf else "Kaltmiete"
        betrag = zahl(o.get("price") if self.kauf else o.get("base_rent"))
        verborgen = wahr(o.get("price_on_inquiry")) or falsch(o.get("show_price_in_expose"))
        self.preis = None if verborgen or not betrag else betrag
        self.preis_text = euro(self.preis) if self.preis else "Preis auf Anfrage"

        if self.typ == "APARTMENT" or self.typ == "HOUSE":
            grundwort = "Wohnung" if self.typ == "APARTMENT" else (
                self.kategorie if self.kategorie and "haus" in self.kategorie.lower() else "Haus")
            self.kurzname = f"{zimmer_text(self.zimmer)}-Zimmer-{grundwort}" if self.zimmer else grundwort
        else:
            self.kurzname = self.kategorie if self.kategorie not in ("", "Sonstige", "Keine Angabe") else self.art
        self.name = f"{self.kurzname} in {self.ort}" if self.ort else self.kurzname

        teile = [self.kurzname, self.ort, self.nr]
        self.slug = "-".join(slug_teil(t) for t in teile if t)
        self.pfad = f"/angebote/{self.slug}/"
        self.url = DOMAIN + self.pfad

    def wert(self, feld):
        return self.o.get(feld)

    def notiz(self, feld):
        return text(self.o.get(feld)) or text(self.o.get(f"long_{feld}"))


# ---------------------------------------------------------------------------------------------------------
# HTML-Bausteine

def e(wert):
    return html.escape(str(wert), quote=True)


PIN = ('<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">'
       '<path d="M7 13s4.5-4.2 4.5-7.5a4.5 4.5 0 0 0-9 0C2.5 8.8 7 13 7 13z"/><circle cx="7" cy="5.5" r="1.6"/></svg>')
PFEIL = ('<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
         '<path d="M3 9h12M10 4l5 5-5 5"/></svg>')


def srcset(b):
    return f'{e(b["mittel"])} {b["mittel_masse"][0]}w, {e(b["gross"])} {b["gross_masse"][0]}w'


def karte(a):
    zeilen = [(a.preis_label, a.preis_text)]
    if a.wohnen:
        if a.flaeche:
            zeilen.append(("Wohnfläche ca.", flaeche(a.flaeche, 1)))
        if a.zimmer:
            zeilen.append(("Zimmer", zimmer_text(a.zimmer)))
    else:
        zeilen.append(("Art", a.art))
        if a.flaeche:
            zeilen.append(("Fläche ca.", flaeche(a.flaeche, 1)))
    dl = "".join(f"<dt>{e(k)}</dt><dd>{e(v)}</dd>" for k, v in zeilen)
    bild = ""
    if a.bilder:
        b = a.bilder[0]
        w, h = b["mittel_masse"]
        bild = (f'<img src="{e(b["mittel"])}" srcset="{srcset(b)}" '
                'sizes="(min-width: 992px) 370px, (min-width: 641px) 50vw, 100vw" '
                f'alt="{e(a.name)}" width="{w}" height="{h}" loading="lazy" decoding="async">')
    return (f'        <li><a class="objekt" href="{e(a.pfad)}">\n'
            f'          <div class="bild">{bild}</div>\n'
            f'          <div class="info"><h3>{PIN}{e(a.plz)} {e(a.ort)}</h3><dl>{dl}</dl></div>\n'
            '        </a></li>')


def text_html(roh_text):
    """Propstack-Text (Absätze, Aufzählungen mit -, * oder •) als HTML; vorhandenes HTML wird zu Text."""
    t = roh_text.replace("\r\n", "\n")
    if re.search(r"<(p|br|ul|ol|li|div|strong|b|em|span)\b", t, re.I):
        t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
        t = re.sub(r"<li[^>]*>", "\n- ", t, flags=re.I)
        t = re.sub(r"</(p|div|li|ul|ol|h\d)>", "\n", t, flags=re.I)
        t = html.unescape(re.sub(r"<[^>]+>", "", t))
    teile, punkte = [], []
    for zeile in t.split("\n"):
        zeile = zeile.strip()
        m = re.match(r"^[-*•–]\s+(.*)", zeile)
        if m:
            punkte.append(f"<li>{e(m[1])}</li>")
            continue
        if punkte:
            teile.append('<ul class="haken">' + "".join(punkte) + "</ul>")
            punkte = []
        if zeile:
            teile.append(f"<p>{e(zeile)}</p>")
    if punkte:
        teile.append('<ul class="haken">' + "".join(punkte) + "</ul>")
    return "\n".join(teile)


def tabelle(zeilen):
    return ('<table class="datentabelle"><tbody>'
            + "".join(f'<tr><th scope="row">{e(k)}</th><td>{e(v)}</td></tr>' for k, v in zeilen if v)
            + "</tbody></table>")


def eckdaten(a):
    w = a.wert
    z = []

    def betrag(label, feld):
        n = zahl(w(feld))
        if n:
            z.append((label, euro(n)))

    def qm(label, feld):
        n = zahl(w(feld))
        if n:
            z.append((label, flaeche(n)))

    def anzahl(label, feld):
        n = zahl(w(feld))
        if n:
            z.append((label, de_zahl(n, 1)))

    z.append(("Objekt-Nr.", a.nr))
    z.append(("Kategorie", f"{'Kauf' if a.kauf else 'Miete'} – {a.art}"))
    if a.kategorie and a.kategorie not in (a.art, "Sonstige", "Keine Angabe"):
        z.append(("Objektart", a.kategorie))
    if a.status and re.search(r"reserv", a.status, re.I):
        z.append(("Status", a.status))
    def jahr(label, feld):
        if zahl(w(feld)):
            z.append((label, str(int(zahl(w(feld))))))

    jahr("Baujahr ca.", "construction_year")
    z.append(("Objektzustand", uebersetzt(ZUSTAND, w("condition"), "condition")))
    z.append(("Qualität der Ausstattung", uebersetzt(QUALITAET, w("interior_quality"), "interior_quality")))
    jahr("Letzte Modernisierung", "last_refurbishment")
    if wahr(w("rented")):
        z.append(("Vermietet", "Ja"))
    z.append(("Verfügbar ab", de_datum(w("free_from"))))

    z.append((a.preis_label, a.preis_text))
    if a.preis or not a.kauf:
        if a.kauf:
            betrag("Kaltmiete", "base_rent")
        betrag("Nebenkosten", "service_charge")
        betrag("Heizkosten", "heating_costs")
        betrag("Warmmiete", "total_rent")
        betrag("Stellplatz-Preis", "parking_space_price")
        betrag("Hausgeld/Monat", "rent_subsidy")
        betrag("Hausgeld/Monat", "house_money")
        betrag("Instandhaltungsrücklage", "maintenance_reserve")
        if text(w("deposit")):
            n = zahl(w("deposit"))
            z.append(("Kaution", euro(n) if n else text(w("deposit"))))
    z.append(("Käuferprovision" if a.kauf else "Provision", text(w("courtage"))))

    if text(w("floor")):
        z.append(("Etage", etage(w("floor"))))
    anzahl("Etagenzahl", "number_of_floors")
    anzahl("Zimmer", "number_of_rooms")
    anzahl("Schlafzimmer", "number_of_bed_rooms")
    anzahl("Badezimmer", "number_of_bath_rooms")
    qm("Wohnfläche ca.", "living_space")
    qm("Nutzfläche ca.", "usable_floor_space")
    qm("Gesamtfläche ca.", "total_floor_space")
    qm("Grundstück ca.", "plot_area")
    qm("Balkon/Terrasse ca.", "balcony_space")
    qm("Garten ca.", "garden_space")
    anzahl("Stellplätze", "number_of_parking_spaces")
    for feld, label in (
        ("cellar", "Keller"), ("built_in_kitchen", "Einbauküche"), ("balcony", "Balkon"), ("terrace", "Terrasse"),
        ("garden", "Garten"), ("lift", "Aufzug"), ("guest_toilet", "Gäste-WC"), ("barrier_free", "Barrierefrei"),
        ("storeroom", "Abstellraum"), ("bicycle_storage", "Fahrradraum"), ("laundry_room", "Waschküche"),
        ("monument", "Denkmalschutz"),
    ):
        if wahr(w(feld)):
            z.append((label, "Ja"))
    for feld, label in (
        ("distance_to_pt", "Fußweg zu öffentl. Verkehrsmitteln"), ("distance_to_mrs", "Fahrzeit zum Hauptbahnhof"),
        ("distance_to_fm", "Fahrzeit zur Autobahn"), ("distance_to_airport", "Fahrzeit zum Flughafen"),
    ):
        n = zahl(w(feld))
        if n:
            z.append((label, f"{int(n)} Min."))
    return z


def energieausweis(a):
    w = a.wert
    typ = uebersetzt(AUSWEISTYP, w("building_energy_rating_type"), "building_energy_rating_type")
    kennwert = zahl(w("thermal_characteristic")) or zahl(w("energy_efficiency_value"))
    erstellt = text(w("energy_certificate_creation_date")).upper()
    if erstellt:
        erstellt = "vor dem 1. Mai 2014" if "BEFORE" in erstellt else (
            "ab 1. Mai 2014" if re.search(r"FROM|AFTER|SINCE", erstellt) else uebersetzt({}, erstellt, "energy_certificate_creation_date"))
    traeger = text(w("firing_types"))
    traeger = ", ".join(filter(None, (uebersetzt(ENERGIETRAEGER, t.strip(), "firing_types")
                                      for t in re.split(r"[,;]", traeger.strip("[]").replace('"', "")) if t.strip())))
    if isinstance(roh(w("firing_types")), list):
        traeger = ", ".join(filter(None, (uebersetzt(ENERGIETRAEGER, t, "firing_types") for t in roh(w("firing_types")))))
    warmwasser = w("energy_consumption_contains_warm_water")
    z = [
        ("Energieausweis", uebersetzt(AUSWEIS, w("energy_certificate_availability"), "energy_certificate_availability")),
        ("Energieausweistyp", typ),
        ("Ausstellungsdatum", de_datum(w("energy_certificate_start_date"))),
        ("Erstellt", erstellt),
        ("Gültig bis", de_datum(w("energy_certificate_end_date"))),
        ("Endenergiebedarf" if typ == "Bedarfsausweis" else "Energieverbrauchskennwert",
         f"{de_zahl(kennwert)} kWh/(m²·a)" if kennwert else ""),
        ("Warmwasser enthalten", "Ja" if wahr(warmwasser) else ("Nein" if falsch(warmwasser) else "")),
        ("Heizungsart", uebersetzt(HEIZUNG, w("heating_type"), "heating_type")),
        ("Wesentlicher Energieträger", traeger),
        ("Energieeffizienzklasse", effizienzklasse(w("energy_efficiency_class"))),
        ("Baujahr laut Energieausweis", str(int(zahl(w("energy_certificate_construction_year")))) if zahl(w("energy_certificate_construction_year")) else ""),
        ("Baujahr Anlagentechnik", str(int(zahl(w("equipment_technology_construction_year")))) if zahl(w("equipment_technology_construction_year")) else ""),
    ]
    return [(k, v) for k, v in z if v]


def skala(klasse):
    if not klasse:
        return ""
    aktuell = ' aria-current="true"'
    stufen = "".join(f"<li{aktuell if k == klasse else ''}>{e(k)}</li>" for k in EFFIZIENZKLASSEN)
    return (f'<ol class="effizienz" aria-label="Energieeffizienzklasse {e(klasse)} auf der Skala von A+ bis H">'
            f"{stufen}</ol>")


def galerie(a):
    if not a.bilder:
        return ""
    n = len(a.bilder)
    folien, vorschau = [], []
    for i, b in enumerate(a.bilder, 1):
        w, h = b["gross_masse"]
        titel = b["titel"] or ("Grundriss" if b["plan"] else f"Bild {i}")
        alt = f"{titel} – {a.name}" if not b["plan"] else f"Grundriss – {a.name}"
        laden = 'fetchpriority="high"' if i == 1 else 'loading="lazy"'
        klasse = ' class="plan"' if b["plan"] else ""
        aktuell = ' aria-current="true"' if i == 1 else ""
        folien.append(
            f'<li id="bild-{i}" role="group" aria-roledescription="Bild" aria-label="{i} von {n}: {e(titel)}"{klasse}>'
            f'<figure><img src="{e(b["gross"])}" srcset="{srcset(b)}" sizes="(min-width: 992px) 740px, 100vw" '
            f'alt="{e(alt)}" width="{w}" height="{h}" {laden} decoding="async">'
            f"<figcaption>{e(titel)}</figcaption></figure></li>")
        vorschau.append(
            f'<li><a href="#bild-{i}"{aktuell}>'
            f'<img src="{e(b["klein"])}" alt="{e(titel)}, Bild {i} zeigen" width="72" height="72" loading="lazy" decoding="async"></a></li>')
    return f"""<section class="galerie" aria-roledescription="Karussell" aria-label="Bilder und Grundrisse">
          <div class="galerie-buehne">
            <ul class="galerie-liste" tabindex="0" aria-label="Bilder, mit den Pfeiltasten blättern">
              {(chr(10) + "              ").join(folien)}
            </ul>
            <button class="galerie-knopf galerie-zurueck" type="button" aria-label="Vorheriges Bild" hidden><svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M11 3 5 9l6 6"/></svg></button>
            <button class="galerie-knopf galerie-weiter" type="button" aria-label="Nächstes Bild" hidden><svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m7 3 6 6-6 6"/></svg></button>
            <p class="galerie-zaehler" aria-live="polite">1 / {n}</p>
          </div>
          <ul class="galerie-vorschau">
            {(chr(10) + "            ").join(vorschau)}
          </ul>
        </section>"""


def titel_html(titel):
    # „3-Zimmer-Wohnung“ nicht am Bindestrich umbrechen
    return re.sub(r"\d+(?:,\d+)?-Zimmer-[\wäöüÄÖÜß]+", lambda m: f'<span class="kein-umbruch">{m[0]}</span>', e(titel))


def mailto(a):
    betreff = f"Anfrage zu Objekt {a.nr}: {a.titel}"
    inhalt = (f"Guten Tag,\n\nich interessiere mich für das Angebot „{a.titel}“ (Objekt-Nr. {a.nr}):\n{a.url}\n\n"
              "Bitte melden Sie sich bei mir.\n\n")
    q = urllib.parse.quote
    return f"mailto:{MAIL}?subject={q(betreff, safe='')}&body={q(inhalt, safe='')}"


def kurzbeschreibung(a, laenge=155):
    teile = [a.kurzname + (f" in {a.plz} {a.ort}" if a.ort else "")]
    fakten = [f"{a.preis_label} {a.preis_text}" if a.preis else a.preis_text]
    if a.flaeche:
        fakten.append(f"{flaeche(a.flaeche, 1)} {'Wohnfläche' if a.wohnen else 'Fläche'}")
    if zahl(a.wert("construction_year")):
        fakten.append(f"Baujahr {int(zahl(a.wert('construction_year')))}")
    satz = f"{teile[0]}: {', '.join(fakten)}."
    rest = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", a.notiz("description_note"))).strip()
    if rest and len(satz) + 1 < laenge:
        rest = rest[: laenge - len(satz) - 1]
        if len(rest) < len(re.sub(r"\s+", " ", a.notiz("description_note"))):
            rest = rest.rsplit(" ", 1)[0].rstrip(",;:–-") + " …"
        satz += " " + rest
    return satz


def json_ld(daten):
    return json.dumps(daten, ensure_ascii=False, indent=2)


def detail_ld(a):
    fotos = [b["gross"] for b in a.bilder if not b["plan"]][:10]
    adresse = {"@type": "PostalAddress", "postalCode": a.plz, "addressLocality": a.ort, "addressCountry": "DE"}
    immobilie = {"@id": a.url + "#immobilie", "name": a.name, "address": adresse}
    if a.typ == "APARTMENT":
        immobilie["@type"] = "Apartment"
    elif a.typ == "HOUSE":
        immobilie["@type"] = "SingleFamilyResidence" if text(a.wert("rs_category")) == "SINGLE_FAMILY_HOUSE" else "House"
    else:
        immobilie["@type"] = "Accommodation" if a.wohnen else "Place"
    if immobilie["@type"] != "Place":
        if a.zimmer:
            immobilie["numberOfRooms"] = a.zimmer
        if zahl(a.wert("number_of_bed_rooms")):
            immobilie["numberOfBedrooms"] = zahl(a.wert("number_of_bed_rooms"))
        if zahl(a.wert("number_of_bath_rooms")):
            immobilie["numberOfBathroomsTotal"] = int(zahl(a.wert("number_of_bath_rooms")))
        if a.flaeche:
            immobilie["floorSize"] = {"@type": "QuantitativeValue", "value": a.flaeche, "unitCode": "MTK"}
        if zahl(a.wert("construction_year")):
            immobilie["yearBuilt"] = int(zahl(a.wert("construction_year")))
    elif a.flaeche:
        immobilie["additionalProperty"] = {"@type": "PropertyValue", "name": "Fläche", "value": a.flaeche,
                                           "unitCode": "MTK"}
    if fotos:
        immobilie["image"] = fotos[0]
    angebot = {
        "@type": "Offer", "url": a.url, "seller": {"@id": FIRMA}, "itemOffered": {"@id": a.url + "#immobilie"},
        "availability": "https://schema.org/InStock",
        "businessFunction": "http://purl.org/goodrelations/v1#" + ("Sell" if a.kauf else "LeaseOut"),
    }
    if a.preis:
        angebot.update(price=a.preis, priceCurrency="EUR")
        if not a.kauf:
            angebot["priceSpecification"] = {"@type": "UnitPriceSpecification", "price": a.preis,
                                             "priceCurrency": "EUR", "unitCode": "MON"}
    seite = {
        "@type": "RealEstateListing", "@id": a.url, "url": a.url, "name": a.titel,
        "description": kurzbeschreibung(a, 300), "inLanguage": "de-DE",
        "isPartOf": {"@id": DOMAIN + "/#website"}, "provider": {"@id": FIRMA},
        "breadcrumb": {"@id": a.url + "#brotkrumen"}, "about": immobilie, "offers": angebot,
    }
    if fotos:
        seite["image"] = fotos
        seite["primaryImageOfPage"] = fotos[0]
    for feld, ziel in (("created_at", "datePosted"), ("updated_at", "dateModified")):
        if re.match(r"\d{4}-\d{2}-\d{2}", text(a.wert(feld))):
            seite[ziel] = text(a.wert(feld))[:10]
    return {"@context": "https://schema.org", "@graph": [
        seite,
        {"@type": "BreadcrumbList", "@id": a.url + "#brotkrumen", "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Startseite", "item": DOMAIN + "/"},
            {"@type": "ListItem", "position": 2, "name": "Angebote", "item": DOMAIN + "/#angebote"},
            {"@type": "ListItem", "position": 3, "name": a.name, "item": a.url},
        ]},
        {"@type": "RealEstateAgent", "@id": FIRMA, "name": "HeydenSattler GmbH", "url": DOMAIN + "/",
         "telephone": "+49 821 99982940", "email": MAIL, "address": {
             "@type": "PostalAddress", "streetAddress": "Alpenstraße 9", "postalCode": "86159",
             "addressLocality": "Augsburg", "addressRegion": "Bayern", "addressCountry": "DE"}},
    ]}


def detailseite(a, kopf, fuss):
    seitentitel = f"{a.name} {'kaufen' if a.kauf else 'mieten'}" + (f", {flaeche(a.flaeche, 1)}" if a.flaeche else "")
    seitentitel += " | HeydenSattler"
    beschreibung = kurzbeschreibung(a)
    og_bild = ""
    vorladen = ""
    if a.bilder:
        b = a.bilder[0]
        endung = "png" if b["gross"].lower().endswith(".png") else "jpeg"
        og_bild = (f'<meta property="og:image" content="{e(b["gross"])}">\n'
                   f'<meta property="og:image:type" content="image/{endung}">\n'
                   f'<meta property="og:image:width" content="{b["gross_masse"][0]}">\n'
                   f'<meta property="og:image:height" content="{b["gross_masse"][1]}">\n'
                   f'<meta property="og:image:alt" content="{e(a.name)}">\n')
        vorladen = (f'<link rel="preload" as="image" fetchpriority="high" imagesrcset="{srcset(b)}" '
                    'imagesizes="(min-width: 992px) 740px, 100vw">\n')

    merkmale = " · ".join(f'<span class="kein-umbruch">{e(t)}</span>' for t in filter(None, [
        "Kauf" if a.kauf else "Miete", a.art, f"{a.plz} {a.ort}".strip(), f"Objekt-Nr. {a.nr}"]))
    fakten = []
    if a.flaeche:
        fakten.append(("Wohnfläche ca." if a.wohnen else "Fläche ca.", flaeche(a.flaeche)))
    if a.zimmer:
        fakten.append(("Zimmer", zimmer_text(a.zimmer)))
    if zahl(a.wert("construction_year")):
        fakten.append(("Baujahr ca.", str(int(zahl(a.wert("construction_year"))))))
    fakten.append(("Ort", f"{a.plz} {a.ort}".strip()))
    fakten_dl = "".join(f"<dt>{e(k)}</dt><dd>{e(v)}</dd>" for k, v in fakten)
    status = (f'<p class="expose-status">{e(a.status)}</p>'
              if a.status and re.search(r"reserv", a.status, re.I) else "")

    energie = energieausweis(a)
    klasse = effizienzklasse(a.wert("energy_efficiency_class"))
    energie_html = (skala(klasse) + tabelle(energie)) if energie else \
        "<p>Angaben zum Energieausweis erhalten Sie auf Anfrage.</p>"

    texte = []
    for feld, titel in (("description_note", "Beschreibung"), ("furnishing_note", "Ausstattung"),
                        ("location_note", "Lage"), ("other_note", "Sonstiges"), ("courtage_note", "Provision")):
        inhalt = a.notiz(feld) if feld != "courtage_note" else text(a.wert(feld))
        if inhalt:
            anker = slug_teil(titel)
            texte.append(f'<section class="expose-text" aria-labelledby="{anker}"><h2 id="{anker}">{e(titel)}</h2>\n'
                         f"{text_html(inhalt)}</section>")

    link = mailto(a)
    return f"""<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(seitentitel)}</title>
<meta name="description" content="{e(beschreibung)}">
<link rel="canonical" href="{e(a.url)}">
<link rel="alternate" hreflang="de-DE" href="{e(a.url)}">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
<meta name="theme-color" content="#163C37">
<meta name="geo.region" content="DE-BY">
<meta name="geo.placename" content="{e(a.ort)}">
<meta property="og:type" content="website">
<meta property="og:locale" content="de_DE">
<meta property="og:site_name" content="HeydenSattler">
<meta property="og:title" content="{e(a.titel)}">
<meta property="og:description" content="{e(beschreibung)}">
<meta property="og:url" content="{e(a.url)}">
{og_bild}<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="https://heydensattler.com/favicon.ico">

<script type="application/ld+json">
{json_ld(detail_ld(a))}
</script>

<link rel="preload" href="/wp-content/uploads/2022/05/soehne-buch.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/wp-content/uploads/2022/05/soehne-breit-kraftig.woff2" as="font" type="font/woff2" crossorigin>
{vorladen}<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
{kopf}<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
      <nav aria-label="Brotkrümelpfad">
        <ol class="brotkrumen">
          <li><a href="/">Startseite</a></li>
          <li><a href="/#angebote">Angebote</a></li>
          <li aria-current="page">{e(a.name)}</li>
        </ol>
      </nav>
      <h1>{titel_html(a.titel)}</h1>
      <p class="expose-merkmale">{merkmale}</p>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap expose">
      <div class="expose-oben">
        {galerie(a)}
        <aside class="expose-karte" aria-label="Das Wichtigste">
          {status}<p class="expose-preis-label">{e(a.preis_label)}</p>
          <p class="expose-preis">{e(a.preis_text)}</p>
          <dl>{fakten_dl}</dl>
          <a class="btn" href="{e(link)}">Anfrage per E-Mail</a>
          <p class="expose-anruf">oder anrufen: <a href="tel:{TELEFON[0]}">{TELEFON[1]}</a></p>
        </aside>
      </div>

      <div class="expose-daten">
        <section aria-labelledby="eckdaten"><h2 id="eckdaten">Eckdaten</h2>
          {tabelle(eckdaten(a))}
        </section>
        <section aria-labelledby="energieausweis"><h2 id="energieausweis">Energieausweis</h2>
          {energie_html}
        </section>
      </div>

      <div class="expose-texte">
        {(chr(10) + "        ").join(texte)}
        <p class="expose-hinweis">Alle Angaben ohne Gewähr; sie beruhen auf Informationen der Eigentümer. Die genaue Adresse nennen wir Ihnen gerne auf Anfrage.</p>
      </div>
    </div>
  </section>

  <section class="abschnitt" id="kontakt">
    <div class="wrap">
      <div class="kontakt-box">
        <div>
          <h2>Interesse an dieser Immobilie?</h2>
          <p class="text">Schreiben Sie uns oder rufen Sie uns an. Wir beantworten Ihre Fragen und vereinbaren gerne einen Besichtigungstermin.</p>
          <a class="btn" href="{e(link)}">E-Mail schreiben</a>
        </div>
        <div>
          <h3>Telefon und E-Mail</h3>
          <p><a href="tel:{TELEFON[0]}">{TELEFON[1]}</a><br><a href="mailto:{MAIL}">{MAIL}</a></p>
          <h3 style="margin-top:18px">Erreichbar</h3>
          <p>Montag bis Freitag<br>9:00 bis 17:00 Uhr</p>
        </div>
        <div>
          <h3>Büro</h3>
          <address>HeydenSattler GmbH<br>Alpenstraße 9<br>86159 Augsburg</address>
          <p style="margin-top:8px"><a href="https://www.openstreetmap.org/?mlat=48.35865&amp;mlon=10.89500#map=18/48.35865/10.89500" target="_blank" rel="noopener">Auf der Karte ansehen</a></p>
        </div>
      </div>
      <div class="weiter">
        <a class="querlink" href="/#angebote"><span><strong>Alle Angebote</strong>Weitere Immobilien in Augsburg und Umgebung</span>{PFEIL}</a>
        <a class="querlink" href="/leistungen/verkaufen/"><span><strong>Verkaufen</strong>Sie möchten selbst eine Immobilie verkaufen?</span>{PFEIL}</a>
      </div>
    </div>
  </section>
</main>
{fuss.rstrip()}
<script src="/assets/galerie.js" defer></script>
</body>
</html>
"""


# ---------------------------------------------------------------------------------------------------------
# Bestehende Dateien zwischen Markierungen ersetzen

def ersetzen(inhalt, name, neu, kommentar=("<!-- ", " -->")):
    start = f"{kommentar[0]}angebote:{name}:start{kommentar[1]}"
    ende = f"{kommentar[0]}angebote:{name}:ende{kommentar[1]}"
    muster = re.compile(re.escape(start) + r".*?" + re.escape(ende), re.S)
    if not muster.search(inhalt):
        raise Abbruch(f"Markierung {start} … {ende} fehlt")
    return muster.sub(lambda _: f"{start}\n{neu}\n{ende}", inhalt, count=1)


def startseite(inhalt, angebote, heute):
    if angebote:
        liste_html = '      <ul class="angebote">\n' + "\n".join(karte(a) for a in angebote) + "\n      </ul>"
    else:
        liste_html = ('      <p>Zurzeit sind keine Angebote veröffentlicht. Sprechen Sie uns gerne an: Neue Immobilien '
                      'stellen wir oft zuerst Interessenten aus unserer Kartei vor.</p>')
    liste_html += (f'\n      <p class="stand">Stand: <time datetime="{heute.isoformat()}">{heute:%d.%m.%Y}</time>. '
                   "Ein Klick öffnet das vollständige Exposé.</p>")
    inhalt = ersetzen(inhalt, "liste", liste_html)

    elemente = []
    for i, a in enumerate(angebote, 1):
        name = a.name + (f", {flaeche(a.flaeche, 1)}" if a.flaeche else "")
        angebot = {"@type": "Offer", "name": name, "url": a.url}
        if a.preis:
            angebot.update(price=a.preis, priceCurrency="EUR")
        if a.bilder:
            angebot["image"] = a.bilder[0]["gross"]
        angebot["availableAtOrFrom"] = {"@type": "Place", "address": {
            "@type": "PostalAddress", "postalCode": a.plz, "addressLocality": a.ort, "addressCountry": "DE"}}
        elemente.append({"@type": "ListItem", "position": i, "item": angebot})
    ld = {"@context": "https://schema.org", "@type": "ItemList", "@id": DOMAIN + "/#angebote",
          "name": "Aktuelle Immobilienangebote von HeydenSattler", "numberOfItems": len(angebote),
          "itemListElement": elemente}
    return ersetzen(inhalt, "jsonld", f'<script type="application/ld+json">\n{json_ld(ld)}\n</script>')


def sitemap(inhalt, angebote, heute):
    zeilen = []
    for a in angebote:
        geaendert = text(a.wert("updated_at"))[:10]
        geaendert = geaendert if re.match(r"\d{4}-\d{2}-\d{2}$", geaendert) else heute.isoformat()
        zeilen.append(f"  <url><loc>{e(a.url)}</loc><lastmod>{geaendert}</lastmod></url>")
    return ersetzen(inhalt, "liste", "\n".join(zeilen))


def llms(inhalt, angebote, heute):
    if angebote:
        zeilen = [f"Stand {heute:%d.%m.%Y}, alle Angaben ohne Gewähr. Jede Seite nennt Eckdaten, Energieausweis, "
                  "Beschreibung, Ausstattung und Lage; die genaue Adresse nennt HeydenSattler auf Anfrage.", ""]
        for a in angebote:
            fakten = ", ".join(filter(None, [f"{a.plz} {a.ort}".strip(),
                                             flaeche(a.flaeche, 1) if a.flaeche else "",
                                             f"{zimmer_text(a.zimmer)} Zimmer" if a.zimmer and a.wohnen else "",
                                             f"{a.preis_label} {a.preis_text}" if a.preis else a.preis_text]))
            zeilen.append(f"- [{a.name}]({a.url}): {a.titel} ({fakten}; Objekt-Nr. {a.nr})")
    else:
        zeilen = [f"Stand {heute:%d.%m.%Y}: Zurzeit sind keine Angebote veröffentlicht."]
    return ersetzen(inhalt, "liste", "\n".join(zeilen))


# ---------------------------------------------------------------------------------------------------------

def heute_in_augsburg():
    try:
        from zoneinfo import ZoneInfo
        return datetime.now(ZoneInfo("Europe/Berlin")).date()
    except Exception:
        return datetime.now(timezone.utc).date()


def main():
    teiler = argparse.ArgumentParser(description="Angebote aus Propstack in die Website schreiben")
    teiler.add_argument("--beispiel", action="store_true", help="Beispieldaten statt API (schreibt nicht in site/)")
    teiler.add_argument("--ziel", help="Zielordner; wird als Kopie von site/ angelegt")
    args = teiler.parse_args()

    ziel = Path(args.ziel).resolve() if args.ziel else (WURZEL / "_beispiel" if args.beispiel else QUELLE)
    if args.beispiel and ziel == QUELLE:
        raise Abbruch("--beispiel schreibt nie in site/, sonst ersetzen Beispielobjekte die echten Angebote")
    portal_id = os.environ.get("PROPSTACK_PORTAL_ID", "").strip()
    version = os.environ.get("PROPSTACK_API_VERSION", "").strip().lower().lstrip("v") or "1"

    if args.beispiel:
        log(f"Beispieldaten aus {BEISPIEL.relative_to(WURZEL)}")
        status, objekte, _ = aus_beispiel(portal_id)
    else:
        schluessel = os.environ.get("PROPSTACK_API_KEY", "").strip()
        if not schluessel:
            raise Abbruch("PROPSTACK_API_KEY fehlt. Für die lokale Ansicht: --beispiel")
        status, objekte, _ = aus_api(schluessel, portal_id, version)

    status_namen = {text(s.get("id")): text(s.get("name")) for s in status}
    bilder = bilder_vorbereiten(objekte, portal_id)
    angebote = [Angebot(o, bilder[text(o.get("id"))], status_namen) for o in objekte]
    angebote.sort(key=lambda a: text(a.wert("created_at")), reverse=True)
    doppelt = {a.slug for a in angebote if [b.slug for b in angebote].count(a.slug) > 1}
    for a in angebote:
        if a.slug in doppelt:
            a.slug += f"-{slug_teil(a.id)}"
            a.pfad, a.url = f"/angebote/{a.slug}/", f"{DOMAIN}/angebote/{a.slug}/"

    if ziel != QUELLE:
        if ziel.exists():
            shutil.rmtree(ziel)
        shutil.copytree(QUELLE, ziel)

    # Erst alles erzeugen, dann schreiben: bei einem Fehler bleibt der alte Stand
    heute = heute_in_augsburg()
    index = (ziel / "index.html").read_text(encoding="utf-8")
    kopf = index[index.index('<a class="skip"'):index.index('<main id="inhalt">')]
    fuss = index[index.index("</main>") + len("</main>"):index.index("<script src=\"/assets/site.js\"")]
    fuss += '<script src="/assets/site.js" defer></script>\n'
    dateien = {
        "index.html": startseite(index, angebote, heute),
        "sitemap.xml": sitemap((ziel / "sitemap.xml").read_text(encoding="utf-8"), angebote, heute),
        "llms.txt": llms((ziel / "llms.txt").read_text(encoding="utf-8"), angebote, heute),
    }
    for a in angebote:
        dateien[f"angebote/{a.slug}/index.html"] = detailseite(a, kopf, fuss)

    ordner = ziel / "angebote"
    if ordner.exists():
        shutil.rmtree(ordner)
    for pfad, inhalt in dateien.items():
        (ziel / pfad).parent.mkdir(parents=True, exist_ok=True)
        (ziel / pfad).write_text(inhalt, encoding="utf-8")

    if unbekannt:
        log("Kürzel ohne Übersetzung (nicht angezeigt): " + ", ".join(sorted(unbekannt)))
    for a in angebote:
        log(f"  {a.pfad}  ({len(a.bilder)} Bilder)")
    log(f"{len(angebote)} Angebote nach {ziel} geschrieben")


if __name__ == "__main__":
    try:
        main()
    except Abbruch as fehler:
        print(f"::error::{fehler}", file=sys.stderr)
        sys.exit(1)
    except urllib.error.HTTPError as fehler:
        hinweis = {
            401: "Der Schlüssel wird nicht angenommen; bitte das Secret PROPSTACK_API_KEY prüfen.",
            402: "Propstack verlangt für diesen Zugriff ein anderes Paket oder einen anderen Schlüssel.",
            403: "Der Schlüssel darf das nicht lesen (bei V2: Rechte des Schlüssels; passt die API-Version?).",
        }.get(fehler.code, "")
        print(f"::error::Propstack antwortet mit HTTP {fehler.code} ({fehler.url.split('?')[0]}). {hinweis}",
              file=sys.stderr)
        sys.exit(1)
    except urllib.error.URLError as fehler:
        print(f"::error::Propstack nicht erreichbar: {fehler.reason}", file=sys.stderr)
        sys.exit(1)
