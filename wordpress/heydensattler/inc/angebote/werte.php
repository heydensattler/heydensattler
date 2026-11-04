<?php
// Angebote: Werte aus Propstack robust lesen, deutsche Formate und die Übersetzungen der Propstack-Kürzel
// (wie scripts/angebote.py).

defined('ABSPATH') || exit;

// ---------------------------------------------------------------------------------------------------------
// Werte robust lesen: V2 typisiert vieles als string, V1 (new=1) liefert {label, value, pretty_value}

function hs_ang_roh($wert) {
    while (is_array($wert) && array_key_exists('value', $wert)) {
        $wert = $wert['value'];
    }
    return $wert;
}

/** str() wie in Python für einfache Werte (IDs, Kürzel): None, True, 4711, 70.62. */
function hs_ang_py_str($wert): string {
    if ($wert === null) {
        return 'None';
    }
    if (is_bool($wert)) {
        return $wert ? 'True' : 'False';
    }
    if (is_float($wert)) {
        return var_export($wert, true);
    }
    if (is_array($wert)) {
        return '';
    }
    return (string) $wert;
}

/** Wahrheitswert wie in Python: leer, 0, None und leere Listen sind falsch, „0“ als Text nicht. */
function hs_ang_py_wahr($wert): bool {
    if (is_string($wert)) {
        return $wert !== '';
    }
    if (is_array($wert)) {
        return count($wert) > 0;
    }
    return (bool) $wert;
}

/** str.strip() aus Python: auch geschützte Leerzeichen an den Rändern entfernen. */
function hs_ang_strip(string $text): string {
    $ergebnis = preg_replace('/^\s+|\s+$/u', '', $text);
    return $ergebnis ?? trim($text);
}

/** Ist das Array eine Liste (JSON-Array) und kein Objekt? */
function hs_ang_ist_liste($wert): bool {
    if (!is_array($wert)) {
        return false;
    }
    $i = 0;
    foreach ($wert as $schluessel => $_) {
        if ($schluessel !== $i++) {
            return false;
        }
    }
    return true;
}

function hs_ang_wahr($wert): bool {
    $wert = hs_ang_roh($wert);
    if (is_bool($wert)) {
        return $wert;
    }
    if (is_int($wert) || is_float($wert)) {
        return $wert == 1;
    }
    return in_array(mb_strtolower(hs_ang_strip(hs_ang_py_str($wert))), ['true', '1', 'yes', 'ja', 't'], true);
}

function hs_ang_falsch($wert): bool {
    // Nur ein ausdrückliches Nein, leer zählt nicht
    $wert = hs_ang_roh($wert);
    if (is_bool($wert)) {
        return !$wert;
    }
    if (is_int($wert) || is_float($wert)) {
        return $wert == 0;
    }
    return in_array(mb_strtolower(hs_ang_strip(hs_ang_py_str($wert))), ['false', '0', 'no', 'nein', 'f'], true);
}

function hs_ang_zahl($wert): ?float {
    $wert = hs_ang_roh($wert);
    if ($wert === null || is_bool($wert) || $wert === '') {
        return null;
    }
    if (is_int($wert) || is_float($wert)) {
        return (float) $wert;
    }
    if (is_string($wert)) {
        $wert = hs_ang_strip(str_replace(',', '.', $wert));
        return is_numeric($wert) ? (float) $wert : null;
    }
    return null;
}

function hs_ang_text($wert): string {
    $wert = hs_ang_roh($wert);
    if ($wert === null || is_array($wert)) {
        return '';
    }
    return hs_ang_strip(hs_ang_py_str($wert));
}

/** repr() aus Python für die Hinweise im Protokoll. */
function hs_ang_py_repr($wert): string {
    return is_string($wert) ? "'" . str_replace("'", "\\'", $wert) . "'" : hs_ang_py_str($wert);
}

// ---------------------------------------------------------------------------------------------------------
// Deutsche Formate

/** 1234.5 -> „1.234,5“; höchstens $stellen Nachkommastellen, ohne Nullen am Ende. */
function hs_ang_de_zahl(float $wert, int $stellen = 2): string {
    $text = number_format($wert, $stellen, ',', '.');
    if (strpos($text, ',') !== false) {
        $text = rtrim(rtrim($text, '0'), ',');
    }
    return $text;
}

function hs_ang_euro(float $wert): string {
    return hs_ang_de_zahl($wert, fmod($wert, 1.0) != 0.0 ? 2 : 0) . ' €';
}

function hs_ang_flaeche(float $wert, int $stellen = 2): string {
    return hs_ang_de_zahl($wert, $stellen) . ' m²';
}

function hs_ang_de_datum($wert): string {
    $wert = hs_ang_text($wert);
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $wert, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $wert;
}

function hs_ang_etage($wert): string {
    $n = hs_ang_zahl($wert);
    if ($n === null) {
        return hs_ang_text($wert);
    }
    $n = (int) $n;
    return $n === 0 ? 'EG' : ($n < 0 ? 'UG' : "{$n}. OG");
}

function hs_ang_zimmer_text(float $n): string {
    return hs_ang_de_zahl($n, 1);
}

function hs_ang_slug_teil(string $wert): string {
    $wert = strtr(mb_strtolower($wert, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return trim(preg_replace('/[^a-z0-9]+/u', '-', $wert) ?? '', '-');
}

// ---------------------------------------------------------------------------------------------------------
// Propstack-Kürzel

const HS_ANG_ARTEN = [
    'APARTMENT' => 'Wohnung', 'HOUSE' => 'Haus', 'TRADE_SITE' => 'Grundstück', 'GARAGE' => 'Stellplatz',
    'SHORT_TERM_ACCOMODATION' => 'Wohnen auf Zeit', 'OFFICE' => 'Büro/Praxis', 'GASTRONOMY' => 'Gastronomie',
    'INDUSTRY' => 'Halle/Lager', 'STORE' => 'Einzelhandel', 'SPECIAL_PURPOSE' => 'Spezialimmobilie',
    'INVESTMENT' => 'Anlageobjekt',
];
const HS_ANG_WOHNEN = ['APARTMENT', 'HOUSE', 'SHORT_TERM_ACCOMODATION'];

// Objektarten (rs_category) laut Propstack-Doku
const HS_ANG_KATEGORIEN = [
    'ROOF_STOREY' => 'Dachgeschoss', 'LOFT' => 'Loft', 'MAISONETTE' => 'Maisonette', 'PENTHOUSE' => 'Penthouse',
    'TERRACED_FLAT' => 'Terrassenwohnung', 'GROUND_FLOOR' => 'Erdgeschosswohnung', 'APARTMENT' => 'Etagenwohnung',
    'RAISED_GROUND_FLOOR' => 'Hochparterre', 'HALF_BASEMENT' => 'Souterrain', 'ATTIKA' => 'Attikawohnung',
    'OTHER' => 'Sonstige', 'SINGLE_FAMILY_HOUSE' => 'Einfamilienhaus', 'TWO_FAMILY_HOUSE' => 'Zweifamilienhaus',
    'TERRACE_HOUSE' => 'Reihenhaus', 'MID_TERRACE_HOUSE' => 'Reihenmittelhaus', 'TERRACE_END_HOUSE' => 'Reihenendhaus',
    'END_TERRACE_HOUSE' => 'Reiheneckhaus', 'MULTI_FAMILY_HOUSE' => 'Mehrfamilienhaus', 'TOWNHOUSE' => 'Stadthaus',
    'FINCA' => 'Finca', 'BUNGALOW' => 'Bungalow', 'FARMHOUSE' => 'Bauernhaus', 'SEMIDETACHED_HOUSE' => 'Doppelhaushälfte',
    'VILLA' => 'Villa', 'CASTLE_MANOR_HOUSE' => 'Burg/Schloss', 'SPECIAL_REAL_ESTATE' => 'Besondere Immobilie',
    'TWIN_SINGLE_FAMILY_HOUSE' => 'Doppeleinfamilienhaus', 'SUMMER_RESIDENCE' => 'Ferienhaus', 'GARAGE' => 'Garage',
    'STREET_PARKING' => 'Außenstellplatz', 'CARPORT' => 'Carport', 'DUPLEX' => 'Duplex', 'CAR_PARK' => 'Parkhaus',
    'UNDERGROUND_GARAGE' => 'Tiefgarage', 'DOUBLE_GARAGE' => 'Doppelgarage', 'NO_INFORMATION' => 'Keine Angabe',
    'OFFICE_LOFT' => 'Loft', 'STUDIO' => 'Atelier', 'OFFICE' => 'Büro', 'OFFICE_FLOOR' => 'Büroetage',
    'OFFICE_BUILDING' => 'Bürohaus', 'OFFICE_CENTRE' => 'Bürozentrum', 'OFFICE_STORAGE_BUILDING' => 'Büro-/ Lagergebäude',
    'SURGERY' => 'Praxis', 'SURGERY_FLOOR' => 'Praxisetage', 'SURGERY_BUILDING' => 'Praxishaus',
    'COMMERCIAL_CENTRE' => 'Gewerbezentrum', 'LIVING_AND_COMMERCIAL_BUILDING' => 'Wohn- und Geschäftsgebäude',
    'OFFICE_AND_COMMERCIAL_BUILDING' => 'Büro- und Geschäftsgebäude', 'BAR_LOUNGE' => 'Barbetrieb/Lounge',
    'CAFE' => 'Café', 'CLUB_DISCO' => 'Club/Diskothek', 'GUESTS_HOUSE' => 'Gästehaus', 'TAVERN' => 'Gaststätte',
    'HOTEL' => 'Hotel', 'HOTEL_RESIDENCE' => 'Hotelanwesen', 'HOTEL_GARNI' => 'Hotel garni', 'PENSION' => 'Pension',
    'RESTAURANT' => 'Restaurant', 'SHOWROOM_SPACE' => 'Ausstellungsfläche', 'HALL' => 'Halle',
    'HIGH_LACK_STORAGE' => 'Hochregallager', 'INDUSTRY_HALL' => 'Industriehalle',
    'INDUSTRY_HALL_WITH_OPEN_AREA' => 'Industriehalle mit Freifläche', 'COLD_STORAGE' => 'Kühlhaus',
    'MULTIDECK_CABINET_STORAGE' => 'Kühlregallager', 'STORAGE_WITH_OPEN_AREA' => 'Lager mit Freifläche',
    'STORAGE_AREA' => 'Lagerfläche', 'STORAGE_HALL' => 'Lagerhalle', 'SERVICE_AREA' => 'Servicefläche',
    'SHIPPING_STORAGE' => 'Speditionslager', 'REPAIR_SHOP' => 'Werkstatt', 'SHOPPING_CENTRE' => 'Einkaufszentrum',
    'FACTORY_OUTLET' => 'Factory Outlet', 'DEPARTMENT_STORE' => 'Kaufhaus', 'KIOSK' => 'Kiosk', 'STORE' => 'Laden',
    'SELF_SERVICE_MARKET' => 'SB-Markt', 'SALES_AREA' => 'Verkaufsfläche', 'SALES_HALL' => 'Verkaufshalle',
    'RESIDENCE' => 'Anwesen', 'FARM' => 'Bauernhof', 'LEISURE_FACILITY' => 'Freizeitanlage',
    'COMMERCIAL_UNIT' => 'Gewerbeeinheit', 'INDUSTRIAL_AREA' => 'Gewerbefläche', 'NURSING_HOME' => 'Pflegeheim',
    'ASSISTED_LIVING' => 'Betreutes Wohnen', 'HORSE_FARM' => 'Reiterhof', 'VINEYARD' => 'Weingut',
    'SPECIAL_ESTATE' => 'Spezialobjekt', 'INVEST_LIVING_BUSINESS_HOUSE' => 'Wohn-/Geschäftshaus',
    'INVEST_HOUSING_ESTATE' => 'Wohnanlage', 'INVEST_MICRO_APARTMENTS' => 'Micro-Apartments',
    'INVEST_OFFICE_BUILDING' => 'Bürohaus', 'INVEST_COMMERCIAL_BUILDING' => 'Geschäftshaus',
    'INVEST_OFFICE_AND_COMMERCIAL_BUILDING' => 'Büro- und Geschäftshaus',
    'INVEST_SHOP_SALES_FLOOR' => 'Laden/Verkaufsfläche', 'INVEST_SUPERMARKET' => 'Supermarkt',
    'INVEST_SHOPPING_CENTRE' => 'Einkaufszentrum', 'INVEST_RETAIL_PARK' => 'Fachmarktzentrum', 'INVEST_HOTEL' => 'Hotel',
    'INVEST_BOARDING_HOUSE' => 'Boarding House', 'INVEST_SURGERY_BUILDING' => 'Ärztehaus', 'INVEST_CLINIC' => 'Klinik',
    'INVEST_REHAB_CLINIC' => 'Rehaklinik', 'INVEST_MEDICAL_SERVICE_CENTER' => 'MVZ',
    'INVEST_INTEGRATION_ASSISTANCE' => 'Eingliederungshilfe', 'INVEST_DAY_NURSERY' => 'Kita',
    'INVEST_DAY_CARE' => 'Tagespflege', 'INVEST_NURSING_HOME' => 'Pflegeheim',
    'INVEST_ASSISTED_LIVING' => 'Betreutes Wohnen', 'INVEST_COMMERCIAL_CENTRE' => 'Gewerbepark',
    'INVEST_HALL_STORAGE' => 'Halle/Logistik', 'INVEST_INDUSTRIAL_PROPERTY' => 'Produktion/Fertigung',
    'INVEST_CAR_PARK' => 'Parkhaus', 'INVEST_PLOT' => 'Grundstück', 'INVEST_COMMERCIAL_UNIT' => 'Gewerbeeinheit',
    'INVEST_OTHER' => 'Sonstiges',
];

const HS_ANG_ZUSTAND = [
    'FIRST_TIME_USE' => 'Erstbezug', 'FIRST_TIME_USE_AFTER_REFURBISHMENT' => 'Erstbezug nach Sanierung',
    'MINT_CONDITION' => 'neuwertig', 'REFURBISHED' => 'saniert', 'MODERNIZED' => 'modernisiert',
    'FULLY_RENOVATED' => 'vollständig renoviert', 'WELL_KEPT' => 'gepflegt', 'NEED_OF_RENOVATION' => 'renovierungsbedürftig',
    'NEGOTIABLE' => 'nach Vereinbarung', 'RIPE_FOR_DEMOLITION' => 'abbruchreif',
];
const HS_ANG_QUALITAET = ['LUXURY' => 'Luxus', 'SOPHISTICATED' => 'gehoben', 'NORMAL' => 'normal', 'SIMPLE' => 'einfach'];
const HS_ANG_AUSWEIS = [
    'AVAILABLE' => 'liegt vor', 'NOT_AVAILABLE_YET' => 'liegt noch nicht vor', 'NOT_REQUIRED' => 'nicht nötig',
    'FOR_VIEWING' => 'liegt zur Besichtigung vor', 'NOT_AVAILABLE' => 'liegt nicht vor',
];
const HS_ANG_AUSWEISTYP = ['ENERGY_REQUIRED' => 'Bedarfsausweis', 'ENERGY_CONSUMPTION' => 'Verbrauchsausweis'];
const HS_ANG_HEIZUNG = [
    'CENTRAL_HEATING' => 'Zentralheizung', 'SELF_CONTAINED_CENTRAL_HEATING' => 'Etagenheizung',
    'FLOOR_HEATING' => 'Fußbodenheizung', 'DISTRICT_HEATING' => 'Fernwärme', 'STOVE_HEATING' => 'Ofenheizung',
    'OIL_HEATING' => 'Öl-Heizung', 'GAS_HEATING' => 'Gas-Heizung', 'ELECTRIC_HEATING' => 'Elektro-Heizung',
    'HEAT_PUMP' => 'Wärmepumpe', 'COMBINED_HEAT_AND_POWER_PLANT' => 'Blockheizkraftwerk',
    'NIGHT_STORAGE_HEATER' => 'Nachtspeicheröfen', 'SOLAR_HEATING' => 'Solarheizung',
    'WOOD_PELLET_HEATING' => 'Pelletheizung',
];
const HS_ANG_ENERGIETRAEGER = [
    'OIL' => 'Öl', 'GAS' => 'Gas', 'ELECTRICITY' => 'Strom', 'DISTRICT_HEATING' => 'Fernwärme', 'LOCAL_HEATING' => 'Nahwärme',
    'SOLAR_HEATING' => 'Solar', 'PELLET_HEATING' => 'Pellets', 'GEOTHERMAL' => 'Erdwärme', 'LIQUID_GAS' => 'Flüssiggas',
    'WOOD' => 'Holz', 'WOOD_CHIPS' => 'Holzhackschnitzel', 'COAL' => 'Kohle', 'COAL_COKE' => 'Koks',
    'HEAT_PUMP' => 'Wärmepumpe', 'AIR_WATER_HEAT_PUMP' => 'Luft-Wasser-Wärmepumpe', 'BIO_ENERGY' => 'Bioenergie',
    'ENVIRONMENTAL_THERMAL_ENERGY' => 'Umweltwärme', 'HYDRO_ENERGY' => 'Wasserenergie', 'WIND_ENERGY' => 'Windenergie',
    'STEAM_DISTRICT_HEATING' => 'Fernwärme (Dampf)', 'COMBINED_HEAT_AND_POWER_FOSSIL_FUELS' => 'KWK fossil',
    'COMBINED_HEAT_AND_POWER_RENEWABLE_ENERGY' => 'KWK erneuerbar',
    'COMBINED_HEAT_AND_POWER_REGENERATIVE_ENERGY' => 'KWK regenerativ',
    'COMBINED_HEAT_AND_POWER_BIO_ENERGY' => 'KWK Bioenergie',
];
const HS_ANG_EFFIZIENZKLASSEN = ['A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

/** Propstack-Kürzel ins Deutsche; unbekannte Kürzel nicht anzeigen, nur im Protokoll des Laufs nennen. */
function hs_ang_uebersetzt(array $tabelle, $wert, string $feld): string {
    $wert = hs_ang_text($wert);
    if ($wert === '') {
        return '';
    }
    if (isset($tabelle[$wert])) {
        return $tabelle[$wert];
    }
    if (preg_match('/^[A-Z0-9_]+\z/', $wert)) {
        if (isset($GLOBALS['hs_angebote_unbekannt'])) {
            $GLOBALS['hs_angebote_unbekannt'][$feld . '=' . $wert] = true;
        }
        return '';
    }
    return $wert;
}

function hs_ang_effizienzklasse($wert): string {
    $wert = mb_strtoupper(hs_ang_text($wert), 'UTF-8');
    $wert = str_replace(' ', '', str_replace('PLUS', '+', str_replace('_PLUS', '+', $wert)));
    return in_array($wert, HS_ANG_EFFIZIENZKLASSEN, true) ? $wert : '';
}
