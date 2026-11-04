<?php
// Angebote: aus Propstack laden (alle 30 Minuten per WP-Cron oder von Hand unter Einstellungen → Angebote).
//
// Welche Objekte erscheinen, wie in scripts/angebote.py:
// - Ist eine Portal-ID eingestellt (API V2), nur Objekte mit einer Veröffentlichung auf diesem Portal und davon nur
//   Bilder, die für dieses Portal freigegeben sind.
// - Sonst alle nicht archivierten Objekte in einem der eingestellten Status (Standard „Vermarktung, Reserviert“).
//   Ein Status mit nonpublic = true zählt nie. Mehr Treffer als die Höchstzahl (Standard 40) brechen den Lauf ab,
//   damit nie versehentlich Akquise- oder Altobjekte erscheinen.
// Gespeichert werden nur die Felder, die die Seiten zeigen (HS_ANG_FELDER): nie private Bilder, Straße,
// Hausnummer, Koordinaten, Eigentümer-, Makler- oder Kontaktdaten. Schlägt ein Lauf fehl, bleibt der letzte Stand
// online; die Seiten lesen nur den gespeicherten Stand und fragen Propstack nie selbst.

defined('ABSPATH') || exit;

const HS_ANG_API = 'https://api.propstack.de';
const HS_ANG_AGENT = 'heydensattler.com-WordPress';
const HS_ANG_STATUS_STANDARD = 'Vermarktung, Reserviert';

// Einstellung => Konstante in wp-config.php, die sie ersetzt
const HS_ANG_KONSTANTEN = [
    'schluessel' => 'HS_PROPSTACK_API_KEY',
    'version' => 'HS_PROPSTACK_API_VERSION',
    'portal' => 'HS_PROPSTACK_PORTAL_ID',
    'status' => 'HS_PROPSTACK_STATUS',
    'max' => 'HS_PROPSTACK_MAX_ANGEBOTE',
];

// Die Felder eines Objekts, die die Seiten lesen; alles andere wird nicht gespeichert
const HS_ANG_FELDER = [
    'id', 'unit_id', 'exposee_id', 'title', 'name', 'marketing_type', 'rs_type', 'rs_category', 'zip_code', 'city',
    'property_status_id', 'price', 'base_rent', 'price_on_inquiry', 'show_price_in_expose', 'number_of_rooms',
    'living_space', 'property_space_value', 'total_floor_space', 'usable_floor_space', 'net_floor_space',
    'construction_year', 'condition', 'interior_quality', 'last_refurbishment', 'rented', 'free_from',
    'service_charge', 'heating_costs', 'total_rent', 'parking_space_price', 'rent_subsidy', 'house_money',
    'maintenance_reserve', 'deposit', 'courtage', 'floor', 'number_of_floors', 'number_of_bed_rooms',
    'number_of_bath_rooms', 'plot_area', 'balcony_space', 'garden_space', 'number_of_parking_spaces', 'cellar',
    'built_in_kitchen', 'balcony', 'terrace', 'garden', 'lift', 'guest_toilet', 'barrier_free', 'storeroom',
    'bicycle_storage', 'laundry_room', 'monument', 'distance_to_pt', 'distance_to_mrs', 'distance_to_fm',
    'distance_to_airport', 'energy_certificate_availability', 'building_energy_rating_type',
    'energy_certificate_start_date', 'energy_certificate_creation_date', 'energy_certificate_end_date',
    'thermal_characteristic', 'energy_efficiency_value', 'energy_consumption_contains_warm_water', 'heating_type',
    'firing_types', 'energy_efficiency_class', 'energy_certificate_construction_year',
    'equipment_technology_construction_year', 'description_note', 'long_description_note', 'furnishing_note',
    'long_furnishing_note', 'location_note', 'long_location_note', 'other_note', 'long_other_note', 'courtage_note',
    'created_at', 'updated_at',
];

class HS_Angebote_Abbruch extends Exception {
}

class HS_Angebote_Http extends Exception {
    public $status;
    public $adresse;

    public function __construct(int $status, string $adresse) {
        parent::__construct("HTTP {$status}");
        $this->status = $status;
        $this->adresse = $adresse;
    }
}

class HS_Angebote_Netz extends Exception {
}

// ---------------------------------------------------------------------------------------------------------
// Einstellungen: Einstellungen → Angebote, jede ersetzbar durch eine Konstante in wp-config.php

function hs_propstack_konstante(string $name): ?string {
    $konstante = HS_ANG_KONSTANTEN[$name];
    return defined($konstante) ? trim((string) constant($konstante)) : null;
}

function hs_propstack_einstellung(string $name): string {
    $wert = hs_propstack_konstante($name);
    if ($wert !== null) {
        return $wert;
    }
    $optionen = get_option('hs_propstack', []);
    return is_array($optionen) ? trim((string) ($optionen[$name] ?? '')) : '';
}

function hs_propstack_version(): string {
    $version = ltrim(strtolower(hs_propstack_einstellung('version')), 'v');
    return $version !== '' ? $version : '1';
}

function hs_propstack_status(): string {
    $status = hs_propstack_einstellung('status');
    return $status !== '' ? $status : HS_ANG_STATUS_STANDARD;
}

function hs_propstack_grenze(): int {
    $wert = hs_propstack_einstellung('max');
    if ($wert === '') {
        return 40;
    }
    if (!ctype_digit($wert) || (int) $wert < 1) {
        hs_ang_log("Die Höchstzahl „{$wert}“ ist keine positive ganze Zahl; es gilt 40.", 'warnung');
        return 40;
    }
    return (int) $wert;
}

/** Pfad der Beispieldaten (Konstante HS_ANGEBOTE_BEISPIEL) für die lokale Ansicht ohne Schlüssel, sonst leer. */
function hs_angebote_beispieldatei(): string {
    return defined('HS_ANGEBOTE_BEISPIEL') ? trim((string) HS_ANGEBOTE_BEISPIEL) : '';
}

// ---------------------------------------------------------------------------------------------------------
// Protokoll eines Laufs: steht unter Einstellungen → Angebote

/** $art: info, hinweis, warnung oder fehler. Außerhalb eines Laufs (beim Anzeigen der Seiten) geschieht nichts. */
function hs_ang_log(string $text, string $art = 'info'): void {
    if (isset($GLOBALS['hs_angebote_protokoll'])) {
        $GLOBALS['hs_angebote_protokoll'][] = ['art' => $art, 'text' => $text];
    }
}

function hs_ang_fehlertext(Throwable $fehler): string {
    if ($fehler instanceof HS_Angebote_Abbruch) {
        return $fehler->getMessage();
    }
    if ($fehler instanceof HS_Angebote_Http) {
        $hinweis = [
            401 => 'Der Schlüssel wird nicht angenommen; bitte den Propstack-Schlüssel unter Einstellungen → Angebote prüfen.',
            402 => 'Propstack verlangt für diesen Zugriff ein anderes Paket oder einen anderen Schlüssel.',
            403 => 'Der Schlüssel darf das nicht lesen (bei V2: Rechte des Schlüssels; passt die API-Version?).',
        ][$fehler->status] ?? '';
        $adresse = explode('?', $fehler->adresse)[0];
        return trim("Propstack antwortet mit HTTP {$fehler->status} ({$adresse}). {$hinweis}");
    }
    if ($fehler instanceof HS_Angebote_Netz) {
        return 'Propstack nicht erreichbar: ' . $fehler->getMessage();
    }
    return 'Unerwarteter Fehler: ' . $fehler->getMessage();
}

// ---------------------------------------------------------------------------------------------------------
// Propstack laden

class HS_Propstack {
    private $schluessel;

    public function __construct(string $schluessel) {
        $this->schluessel = $schluessel;
    }

    public function holen(string $pfad, array $parameter = []) {
        $url = HS_ANG_API . $pfad . ($parameter ? '?' . http_build_query($parameter) : '');
        for ($versuch = 0; $versuch < 4; $versuch++) {
            $antwort = wp_remote_get($url, [
                'timeout' => 60,
                'user-agent' => HS_ANG_AGENT,
                'headers' => ['X-Api-Key' => $this->schluessel, 'Accept' => 'application/json'],
            ]);
            if (is_wp_error($antwort)) {
                if ($versuch < 3) {
                    sleep(3 * 2 ** $versuch);
                    continue;
                }
                throw new HS_Angebote_Netz($antwort->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($antwort);
            if ($code >= 400) {
                if (in_array($code, [429, 500, 502, 503, 504], true) && $versuch < 3) {
                    sleep(3 * 2 ** $versuch);
                    continue;
                }
                throw new HS_Angebote_Http($code, $url);
            }
            $daten = json_decode(wp_remote_retrieve_body($antwort), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new HS_Angebote_Abbruch("Propstack lieferte für {$pfad} keine lesbaren Daten (" . json_last_error_msg() . ').');
            }
            return $daten;
        }
        throw new HS_Angebote_Netz('keine Antwort');
    }

    public function seiten(string $pfad, int $per = 100, array $parameter = []): array {
        $alle = [];
        for ($seite = 1; $seite <= 200; $seite++) {
            $antwort = $this->holen($pfad, ['page' => $seite, 'per' => $per] + $parameter);
            $daten = is_array($antwort) && !hs_ang_ist_liste($antwort) ? ($antwort['data'] ?? []) : $antwort;
            $daten = hs_ang_ist_liste($daten) ? $daten : [];
            $alle = array_merge($alle, $daten);
            if (count($daten) < $per) {
                return $alle;
            }
        }
        throw new HS_Angebote_Abbruch("Propstack liefert für {$pfad} mehr als 200 Seiten; abgebrochen.");
    }
}

function hs_ang_liste($antwort): array {
    if (is_array($antwort) && !hs_ang_ist_liste($antwort)) {
        $antwort = $antwort['data'] ?? $antwort;
    }
    return hs_ang_ist_liste($antwort) ? $antwort : [];
}

function hs_ang_rechte_pruefen($rechte): void {
    $daten = is_array($rechte) && !hs_ang_ist_liste($rechte) ? ($rechte['data'] ?? $rechte) : [];
    $daten = is_array($daten) && !hs_ang_ist_liste($daten) ? $daten : [];
    $name = hs_ang_text($daten['name'] ?? null);
    $name = $name !== '' ? $name : '(ohne Namen)';
    $erlaubt = hs_ang_ist_liste($daten['permissions'] ?? null) ? array_map('hs_ang_py_str', $daten['permissions']) : [];
    $liste = implode(', ', $erlaubt);
    hs_ang_log("Propstack-Schlüssel: {$name}; Rechte: " . ($liste !== '' ? $liste : '(keine angegeben)'));
    $schreiben = array_filter($erlaubt, function ($recht) {
        return preg_match('/write|create|update|delete|destroy|edit|manage|schreib/i', $recht) === 1;
    });
    $kontakte = array_filter($erlaubt, function ($recht) {
        return preg_match('/client|contact|kontakt|deal|mail|message/i', $recht) === 1;
    });
    if ($schreiben) {
        hs_ang_log('Der Propstack-Schlüssel darf schreiben (' . implode(', ', $schreiben) . '). Für die Website reicht '
            . 'ein nur lesender Schlüssel; bitte in Propstack einen solchen anlegen und hier eintragen.', 'warnung');
    }
    if ($kontakte) {
        hs_ang_log('Der Propstack-Schlüssel hat Zugriff auf Kontakte (' . implode(', ', $kontakte) . '). Die Website '
            . 'braucht nur Objekte, Status, Portale und Veröffentlichungen; bitte die Rechte des Schlüssels einschränken.', 'warnung');
    }
}

/** Lädt Status und Objekte; gibt [Status, Objekte] zurück. */
function hs_ang_aus_api(string $schluessel, string $portal_id, string $version): array {
    $ps = new HS_Propstack($schluessel);
    if ($version !== '2') {
        hs_ang_log('Propstack-API V1');
        return hs_ang_aus_api_v1($ps, $portal_id);
    }

    hs_ang_log('Propstack-API V2');
    hs_ang_rechte_pruefen($ps->holen('/v2/rights'));
    $status = hs_ang_liste($ps->holen('/v2/property_statuses'));
    $veroeffentlicht = null;
    if ($portal_id !== '') {
        $portale = [];
        foreach ($ps->seiten('/v2/portals') as $p) {
            if (is_array($p)) {
                $portale[hs_ang_py_str($p['id'] ?? null)] = hs_ang_text($p['name'] ?? null);
            }
        }
        if (!array_key_exists($portal_id, $portale)) {
            $vorhanden = [];
            foreach ($portale as $id => $name) {
                $vorhanden[] = "{$id} {$name}";
            }
            throw new HS_Angebote_Abbruch("Das Portal {$portal_id} (Einstellung „Portal-ID“) gibt es in Propstack nicht. "
                . 'Vorhanden: ' . ($vorhanden ? implode(', ', $vorhanden) : 'keine') . '.');
        }
        $veroeffentlicht = [];
        foreach ($ps->seiten('/v2/publishings', 100, ['portal_id' => $portal_id]) as $v) {
            if (is_array($v)) {
                $veroeffentlicht[hs_ang_py_str($v['property_id'] ?? null)] = true;
            }
        }
        hs_ang_log("Filter: Veröffentlichungen auf Portal {$portal_id} „{$portale[$portal_id]}“ (" . count($veroeffentlicht) . ' Objekte)');
    }
    $uebersicht = $ps->seiten('/v2/properties', 500);
    $kandidaten = hs_ang_vorauswahl($uebersicht, $status, $veroeffentlicht);
    $objekte = [];
    foreach ($kandidaten as $o) {
        $antwort = $ps->holen('/v2/properties/' . rawurlencode(hs_ang_py_str($o['id'] ?? null)));
        if (is_array($antwort) && is_array($antwort['data'] ?? null) && !hs_ang_ist_liste($antwort['data'])) {
            $antwort = $antwort['data'];
        }
        if (is_array($antwort)) {
            $objekte[] = $antwort;
        }
    }
    return [$status, $objekte];
}

function hs_ang_aus_api_v1(HS_Propstack $ps, string $portal_id): array {
    $status = hs_ang_liste($ps->holen('/v1/property_statuses'));
    if ($portal_id !== '') {
        hs_ang_log('Die Portal-ID ist eingestellt, aber den Filter nach Portal gibt es nur in API V2 (Einstellung '
            . '„API-Version“ = 2). Gefiltert wird stattdessen nach öffentlichem Status.', 'warnung');
    }
    // expand=1 liefert das ausführliche JSON schon in der Liste
    $uebersicht = array_map('hs_ang_v1_normal', array_values(array_filter(
        $ps->seiten('/v1/units', 100, ['with_meta' => 1, 'expand' => 1]), 'is_array')));
    $kandidaten = hs_ang_vorauswahl($uebersicht, $status, null);
    if ($kandidaten) {
        // Nur Feldnamen, keine Werte: zeigt im Protokoll, was Propstack liefert
        $felder = [];
        foreach ($kandidaten as $o) {
            foreach ($o as $feld => $wert) {
                if (!in_array($wert, [null, '', []], true)) {
                    $felder[(string) $feld] = true;
                }
            }
        }
        $felder = array_map('strval', array_keys($felder));
        sort($felder, SORT_STRING);
        hs_ang_log('Felder in GET /v1/units: ' . implode(', ', $felder));
    }
    $objekte = [];
    $einzeln = true;
    foreach ($kandidaten as $o) {
        if ($einzeln) {
            try {
                $antwort = $ps->holen('/v1/units/' . rawurlencode(hs_ang_py_str($o['id'] ?? null)), ['new' => 1]);
                if (is_array($antwort)) {
                    $objekte[] = hs_ang_v1_normal($antwort);
                    continue;
                }
            } catch (HS_Angebote_Http $fehler) {
                if (!in_array($fehler->status, [401, 403], true)) {
                    throw $fehler;
                }
                $einzeln = false;
                hs_ang_log("GET /v1/units/:id ist für diesen Schlüssel gesperrt (HTTP {$fehler->status}); "
                    . 'die Seiten nutzen die Daten aus der Liste.');
            }
        }
        $objekte[] = $o;
    }
    return [$status, $objekte];
}

/** Objekt aus API V1 in die Form von V2 bringen. */
function hs_ang_v1_normal(array $o): array {
    $st = $o['property_status'] ?? null;
    if (!hs_ang_py_wahr($st)) {
        $st = $o['status'] ?? null;
    }
    if (is_array($st) && !hs_ang_ist_liste($st)) {
        $o['property_status_id'] = $st['id'] ?? null;
    } elseif ($st !== null && !array_key_exists('property_status_id', $o)) {
        $o['property_status_id'] = $st;
    }
    $bilder = [];
    foreach ((is_array($o['images'] ?? null) ? $o['images'] : []) as $b) {
        if (!is_array($b)) {
            continue;
        }
        $urls = [];
        foreach (['big', 'medium', 'thumb'] as $groesse) {
            $wert = hs_ang_py_wahr($b["{$groesse}_url"] ?? null) ? $b["{$groesse}_url"] : ($b[$groesse] ?? null);
            if (is_string($wert)) {
                $urls[$groesse] = $wert;
            }
        }
        $b['urls'] = $urls;
        $b['url'] = hs_ang_py_wahr($b['url'] ?? null) ? $b['url'] : ($b['original'] ?? null);
        $bilder[] = $b;
    }
    $o['images'] = $bilder;
    return $o;
}

function hs_ang_vorauswahl(array $objekte, array $status, ?array $veroeffentlicht): array {
    $gewuenscht = [];
    foreach (explode(',', hs_propstack_status()) as $name) {
        $name = hs_ang_strip($name);
        if ($name !== '') {
            $gewuenscht[mb_convert_case($name, MB_CASE_FOLD, 'UTF-8')] = true;
        }
    }
    $namen = [];
    $oeffentlich = [];
    foreach ($status as $s) {
        if (!is_array($s)) {
            continue;
        }
        $id = hs_ang_py_str($s['id'] ?? null);
        $name = hs_ang_text($s['name'] ?? null);
        $namen[$id] = $name;
        if (isset($gewuenscht[mb_convert_case($name, MB_CASE_FOLD, 'UTF-8')]) && !hs_ang_wahr($s['nonpublic'] ?? null)) {
            $oeffentlich[$id] = true;
        }
    }
    if (!$oeffentlich) {
        throw new HS_Angebote_Abbruch('Keiner der Status aus der Einstellung „Status“ gibt es in Propstack. Vorhanden: '
            . implode(', ', $namen) . '.');
    }
    $ids = array_map('strval', array_keys($oeffentlich));
    sort($ids, SORT_STRING);
    hs_ang_log('Filter: Status ' . implode(', ', array_map(function ($id) use ($namen) {
        return $namen[$id];
    }, $ids)) . ', nicht archiviert' . ($veroeffentlicht !== null ? ', auf dem Portal veröffentlicht' : ''));

    $auswahl = [];
    $ohne_status = 0;
    foreach ($objekte as $o) {
        if (!is_array($o) || hs_ang_wahr($o['archived'] ?? null)) {
            continue;
        }
        $sid = hs_ang_text($o['property_status_id'] ?? null);
        if ($sid === '') {
            $ohne_status++;
            continue;
        }
        if (!isset($oeffentlich[$sid])) {
            continue;
        }
        if ($veroeffentlicht !== null && !isset($veroeffentlicht[hs_ang_text($o['id'] ?? null)])) {
            continue;
        }
        $auswahl[] = $o;
    }
    if ($ohne_status) {
        hs_ang_log("{$ohne_status} Objekte ohne Status übersprungen");
    }
    hs_ang_log(count($auswahl) . ' von ' . count($objekte) . ' Objekten sind öffentlich');
    $grenze = hs_propstack_grenze();
    if (count($auswahl) > $grenze) {
        throw new HS_Angebote_Abbruch(count($auswahl) . " Objekte würden veröffentlicht, mehr als die Grenze von {$grenze}. "
            . 'Stimmt der Filter (Einstellung „Status“)? Wenn ja, die Einstellung „Höchstzahl“ erhöhen.');
    }
    return $auswahl;
}

/** Wie python3 scripts/angebote.py --beispiel: Daten im Format von scripts/beispiel-angebote.json. */
function hs_ang_aus_beispiel(string $datei, string $portal_id): array {
    $inhalt = is_readable($datei) ? file_get_contents($datei) : false;
    $daten = $inhalt !== false ? json_decode($inhalt, true) : null;
    if (!is_array($daten)) {
        throw new HS_Angebote_Abbruch("Die Beispieldaten {$datei} (Konstante HS_ANGEBOTE_BEISPIEL) sind nicht lesbar.");
    }
    hs_ang_rechte_pruefen($daten['rights'] ?? []);
    $status = hs_ang_liste($daten['property_statuses'] ?? []);
    $veroeffentlicht = null;
    if ($portal_id !== '') {
        $veroeffentlicht = [];
        foreach (hs_ang_liste($daten['publishings'] ?? []) as $v) {
            if (is_array($v) && hs_ang_py_str($v['portal_id'] ?? null) === $portal_id) {
                $veroeffentlicht[hs_ang_py_str($v['property_id'] ?? null)] = true;
            }
        }
        hs_ang_log("Filter: Veröffentlichungen auf Portal {$portal_id} (" . count($veroeffentlicht) . ' Objekte)');
    }
    return [$status, hs_ang_vorauswahl(hs_ang_liste($daten['properties'] ?? []), $status, $veroeffentlicht)];
}

// ---------------------------------------------------------------------------------------------------------
// Bilder: nur öffentliche, Größe aus dem Dateikopf

function hs_ang_bildadresse(array $bild, string $groesse): string {
    $urls = $bild['urls'] ?? [];
    foreach ([$groesse, "{$groesse}_url"] as $schluessel) {
        $wert = is_array($urls) ? ($urls[$schluessel] ?? null) : null;
        if (is_array($wert)) {
            $wert = $wert['url'] ?? null;
        }
        if (hs_ang_py_wahr($wert) && !is_array($wert)) {
            return (string) $wert;
        }
    }
    if (hs_ang_py_wahr($bild["{$groesse}_url"] ?? null) && !is_array($bild["{$groesse}_url"])) {
        return (string) $bild["{$groesse}_url"];
    }
    // Propstack legt die Größen neben das Original: …/photo/<id>/big_<name>
    $original = hs_ang_text($bild['url'] ?? null);
    return preg_match('#^(https://images\.propstack\.de/.+/)([^/]+)$#', $original, $m) ? $m[1] . "{$groesse}_" . $m[2] : $original;
}

/** Breite und Höhe aus dem Kopf einer JPEG-, PNG- oder WebP-Datei (wie scripts/angebote.py), sonst null. */
function hs_ang_bildmasse_aus_daten(string $daten): ?array {
    $laenge = strlen($daten);
    if (substr($daten, 0, 8) === "\x89PNG\r\n\x1a\n") {
        if ($laenge < 24) {
            return null;
        }
        $masse = unpack('N2', substr($daten, 16, 8));
        return hs_ang_masse_pruefen($masse[1], $masse[2]);
    }
    if (substr($daten, 0, 4) === 'RIFF' && substr($daten, 8, 4) === 'WEBP') {
        $art = substr($daten, 12, 4);
        if ($art === 'VP8X' && $laenge >= 30) {
            $b = unpack('C6', substr($daten, 24, 6));
            return hs_ang_masse_pruefen(1 + ($b[1] | $b[2] << 8 | $b[3] << 16), 1 + ($b[4] | $b[5] << 8 | $b[6] << 16));
        }
        if ($art === 'VP8 ' && $laenge >= 30) {
            $masse = unpack('v2', substr($daten, 26, 4));
            return hs_ang_masse_pruefen($masse[1] & 0x3FFF, $masse[2] & 0x3FFF);
        }
        return null;
    }
    $i = 2;
    while (substr($daten, 0, 2) === "\xFF\xD8" && $i + 9 < $laenge) {
        if (ord($daten[$i]) !== 0xFF) {
            $i++;
            continue;
        }
        $marke = ord($daten[$i + 1]);
        $abschnitt = unpack('n', substr($daten, $i + 2, 2))[1];
        if ($marke >= 0xC0 && $marke <= 0xCF && !in_array($marke, [0xC4, 0xC8, 0xCC], true)) {
            $masse = unpack('n2', substr($daten, $i + 5, 4));
            return hs_ang_masse_pruefen($masse[2], $masse[1]);
        }
        $i += 2 + $abschnitt;
    }
    return null;
}

function hs_ang_masse_pruefen(int $breite, int $hoehe): ?array {
    return $breite > 0 && $hoehe > 0 ? [$breite, $hoehe] : null;
}

/** Maße eines Bildes aus den ersten 64 KB; zuerst wie scripts/angebote.py, sonst mit PHP (z. B. verlustfreies WebP). */
function hs_ang_bildmasse_lesen(string $daten): ?array {
    $masse = hs_ang_bildmasse_aus_daten($daten);
    if ($masse === null && $daten !== '') {
        $info = @getimagesizefromstring($daten);
        if (is_array($info)) {
            $masse = hs_ang_masse_pruefen((int) $info[0], (int) $info[1]);
        }
    }
    return $masse;
}

/**
 * Maße für viele Bilder. Bekannte stehen in der Option hs_angebote_bildmasse (die Adressen von Propstack ändern sich
 * mit jedem neuen Bild), neue werden zu acht gleichzeitig gelesen, höchstens 60 Sekunden lang; was dann noch fehlt,
 * versucht der nächste Lauf.
 */
function hs_ang_bildmasse(array $adressen): array {
    $speicher = get_option('hs_angebote_bildmasse', []);
    $speicher = is_array($speicher) ? $speicher : [];
    $masse = [];
    $offen = [];
    foreach ($adressen as $url) {
        if (isset($speicher[$url]) && is_array($speicher[$url])) {
            $masse[$url] = $speicher[$url];
        } else {
            $offen[] = $url;
        }
    }
    $ende = microtime(true) + 60;
    $neu = 0;
    foreach (array_chunk($offen, 8) as $gruppe) {
        if (microtime(true) > $ende) {
            break;
        }
        $gelesen = hs_ang_bildmasse_holen($gruppe);
        $neu += count($gelesen);
        $masse = $gelesen + $masse;
        // Zwischenstand sichern: bricht der Lauf ab, muss der nächste diese Bilder nicht noch einmal lesen
        update_option('hs_angebote_bildmasse', $gelesen + $speicher, false);
        $speicher = $gelesen + $speicher;
    }
    // Nur die Maße der Bilder behalten, die gerade gebraucht werden
    update_option('hs_angebote_bildmasse', $masse, false);
    if ($offen) {
        hs_ang_log('Bildgrößen: ' . (count($adressen) - count($offen)) . " bekannt, {$neu} von " . count($offen) . ' neuen gelesen');
    }
    return $masse;
}

/** Liest die ersten 64 KB einer Gruppe von Bildern; gibt [Adresse => [Breite, Höhe]] für die lesbaren zurück. */
function hs_ang_bildmasse_holen(array $gruppe): array {
    $ergebnis = [];
    $kopf = ['Range' => 'bytes=0-65535'];
    // Nur öffentliche http(s)-Adressen, keine im lokalen Netz (wie wp_safe_remote_get)
    $gruppe = array_values(array_filter($gruppe, function ($url) {
        return (bool) wp_http_validate_url($url);
    }));
    $einzeln = $gruppe;
    if (class_exists('WpOrg\Requests\Requests') && !(new WP_HTTP_Proxy())->is_enabled() && count($gruppe) > 1) {
        $anfragen = [];
        foreach ($gruppe as $i => $url) {
            $anfragen[$i] = ['url' => $url, 'headers' => $kopf];
        }
        try {
            $antworten = \WpOrg\Requests\Requests::request_multiple($anfragen, [
                'timeout' => 30,
                'connect_timeout' => 5,
                'useragent' => HS_ANG_AGENT,
                'max_bytes' => 65536,
                'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
                // Weiterleitungen nur über wp_safe_remote_get, das jedes Ziel prüft
                'follow_redirects' => false,
            ]);
        } catch (Throwable $fehler) {
            // z. B. kein Transport für mehrere Anfragen: dann einzeln
            $antworten = null;
        }
        if ($antworten !== null) {
            $einzeln = [];
            foreach ($gruppe as $i => $url) {
                $antwort = $antworten[$i] ?? null;
                $code = $antwort instanceof \WpOrg\Requests\Response ? (int) $antwort->status_code : 0;
                if ($code >= 200 && $code < 300) {
                    $masse = hs_ang_bildmasse_lesen((string) $antwort->body);
                    if ($masse) {
                        $ergebnis[$url] = $masse;
                    }
                } elseif ($code >= 300 && $code < 400) {
                    $einzeln[] = $url;
                }
            }
        }
    }
    foreach ($einzeln as $url) {
        $antwort = wp_safe_remote_get($url, [
            'timeout' => 30,
            'user-agent' => HS_ANG_AGENT,
            'headers' => $kopf,
            'limit_response_size' => 65536,
        ]);
        $code = is_wp_error($antwort) ? 0 : (int) wp_remote_retrieve_response_code($antwort);
        if ($code >= 200 && $code < 300) {
            $masse = hs_ang_bildmasse_lesen((string) wp_remote_retrieve_body($antwort));
            if ($masse) {
                $ergebnis[$url] = $masse;
            }
        }
    }
    return $ergebnis;
}

/** Öffentliche Bilder je Objekt (Fotos, dann Grundrisse) mit Adressen und Maßen: [Objekt-ID => [Bild, …]]. */
function hs_ang_bilder_vorbereiten(array $objekte, string $portal_id): array {
    $alle = [];
    foreach ($objekte as $o) {
        $bilder = array_values(array_filter(is_array($o['images'] ?? null) ? $o['images'] : [], 'is_array'));
        usort($bilder, function ($a, $b) {
            return (hs_ang_zahl($a['position'] ?? null) ?: 0) <=> (hs_ang_zahl($b['position'] ?? null) ?: 0);
        });
        $fotos = [];
        $plaene = [];
        foreach ($bilder as $b) {
            if (hs_ang_wahr($b['is_private'] ?? null)) {
                continue;
            }
            $freigaben = $b['connection_ids'] ?? null;
            if ($portal_id !== '' && hs_ang_ist_liste($freigaben) && $freigaben
                && !in_array($portal_id, array_map('hs_ang_py_str', $freigaben), true)) {
                continue;
            }
            $eintrag = [
                'titel' => hs_ang_text($b['title'] ?? null), 'plan' => hs_ang_wahr($b['is_floorplan'] ?? null),
                'gross' => hs_ang_bildadresse($b, 'big'), 'mittel' => hs_ang_bildadresse($b, 'medium'),
                'klein' => hs_ang_bildadresse($b, 'thumb'),
            ];
            if ($eintrag['gross'] !== '') {
                if ($eintrag['plan']) {
                    $plaene[] = $eintrag;
                } else {
                    $fotos[] = $eintrag;
                }
            }
        }
        $alle[hs_ang_text($o['id'] ?? null)] = array_merge($fotos, $plaene);
    }

    $adressen = [];
    foreach ($alle as $liste) {
        foreach ($liste as $b) {
            $adressen[$b['gross']] = true;
            $adressen[$b['mittel']] = true;
        }
    }
    $masse = hs_ang_bildmasse(array_map('strval', array_keys($adressen)));
    $fehlend = 0;
    foreach ($alle as $id => $liste) {
        foreach ($liste as $i => $b) {
            $gross = $masse[$b['gross']] ?? null;
            $mittel = $masse[$b['mittel']] ?? null;
            if (!$gross || !$mittel) {
                $fehlend++;
                $gross = $gross ?: [1920, 1440];
                $mittel = $mittel ?: [600, (int) round(600 * $gross[1] / $gross[0], 0, PHP_ROUND_HALF_EVEN)];
            }
            $alle[$id][$i]['gross_masse'] = [(int) $gross[0], (int) $gross[1]];
            $alle[$id][$i]['mittel_masse'] = [(int) $mittel[0], (int) $mittel[1]];
        }
    }
    if ($fehlend) {
        hs_ang_log("Bei {$fehlend} Bildern ließ sich die Größe nicht lesen, angenommen 4:3");
    }
    return $alle;
}

// ---------------------------------------------------------------------------------------------------------
// Ein Lauf

/**
 * Lädt die Angebote und speichert sie in der Option hs_angebote. Gibt true zurück, wenn der Lauf gelungen ist,
 * false bei einem Fehler (dann bleibt der letzte Stand online) und null, wenn gerade schon ein Lauf läuft.
 */
function hs_angebote_aktualisieren(): ?bool {
    if (get_transient('hs_angebote_laeuft')) {
        return null;
    }
    set_transient('hs_angebote_laeuft', time(), 15 * MINUTE_IN_SECONDS);
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    $GLOBALS['hs_angebote_protokoll'] = [];
    $GLOBALS['hs_angebote_unbekannt'] = [];
    $erfolg = false;
    $meldung = '';
    try {
        $anzahl = hs_ang_laden_und_speichern();
        $erfolg = true;
        $meldung = $anzahl === 1 ? '1 Angebot geladen.' : "{$anzahl} Angebote geladen.";
    } catch (Throwable $fehler) {
        $meldung = hs_ang_fehlertext($fehler);
        hs_ang_log($meldung, 'fehler');
    } finally {
        delete_transient('hs_angebote_laeuft');
    }
    update_option('hs_angebote_lauf', [
        'zeit' => time(), 'erfolg' => $erfolg, 'meldung' => $meldung, 'protokoll' => $GLOBALS['hs_angebote_protokoll'],
    ], false);
    unset($GLOBALS['hs_angebote_protokoll'], $GLOBALS['hs_angebote_unbekannt']);
    return $erfolg;
}

function hs_ang_laden_und_speichern(): int {
    $portal_id = hs_propstack_einstellung('portal');
    $beispiel = hs_angebote_beispieldatei();
    if ($beispiel !== '') {
        hs_ang_log("Beispieldaten aus {$beispiel}");
        [$status, $objekte] = hs_ang_aus_beispiel($beispiel, $portal_id);
    } else {
        $schluessel = hs_propstack_einstellung('schluessel');
        if ($schluessel === '') {
            throw new HS_Angebote_Abbruch('Kein Propstack-Schlüssel hinterlegt; bitte unter Einstellungen → Angebote '
                . 'eintragen. Für die lokale Ansicht ohne Schlüssel: Konstante HS_ANGEBOTE_BEISPIEL.');
        }
        [$status, $objekte] = hs_ang_aus_api($schluessel, $portal_id, hs_propstack_version());
    }

    $status_namen = [];
    foreach ($status as $s) {
        if (is_array($s)) {
            $status_namen[hs_ang_text($s['id'] ?? null)] = hs_ang_text($s['name'] ?? null);
        }
    }
    $bilder = hs_ang_bilder_vorbereiten($objekte, $portal_id);

    // Erst alles aufbereiten, dann speichern: bei einem Fehler bleibt der alte Stand
    $eintraege = [];
    $angebote = [];
    foreach ($objekte as $o) {
        $eintrag = [
            'o' => array_intersect_key($o, array_flip(HS_ANG_FELDER)),
            'status' => $status_namen[hs_ang_text($o['property_status_id'] ?? null)] ?? '',
            'bilder' => $bilder[hs_ang_text($o['id'] ?? null)] ?? [],
        ];
        $eintraege[] = $eintrag;
        $angebote[] = hs_ang_angebot($eintrag);
    }
    // Neueste zuerst (usort ist ab PHP 8 stabil, wie sort() in Python)
    $reihenfolge = array_keys($angebote);
    usort($reihenfolge, function ($x, $y) use ($angebote) {
        return strcmp(hs_ang_text($angebote[$y]['o']['created_at'] ?? null), hs_ang_text($angebote[$x]['o']['created_at'] ?? null));
    });
    $slugs = array_count_values(array_map('strval', array_column($angebote, 'slug')));
    $gespeichert = [];
    foreach ($reihenfolge as $i) {
        $a = $angebote[$i];
        if ($slugs[$a['slug']] > 1) {
            $a['slug'] .= '-' . hs_ang_slug_teil($a['id']);
            $a['pfad'] = "/angebote/{$a['slug']}/";
            $a['url'] = hs_url($a['pfad']);
        }
        // Auch Eckdaten und Energieausweis einmal aufbereiten: meldet unbekannte Kürzel im Protokoll
        hs_ang_eckdaten($a);
        hs_ang_energieausweis($a);
        $gespeichert[] = $eintraege[$i] + ['slug' => $a['slug']];
        $angebote[$i] = $a;
    }

    update_option('hs_angebote', [
        'stand' => hs_ang_heute()->format('Y-m-d'), 'geladen' => time(), 'angebote' => $gespeichert,
    ], false);

    $unbekannt = array_map('strval', array_keys($GLOBALS['hs_angebote_unbekannt'] ?? []));
    if ($unbekannt) {
        sort($unbekannt, SORT_STRING);
        hs_ang_log('Kürzel ohne Übersetzung (nicht angezeigt): ' . implode(', ', $unbekannt));
    }
    foreach ($reihenfolge as $i) {
        hs_ang_log("  {$angebote[$i]['pfad']}  (" . count($angebote[$i]['bilder']) . ' Bilder)');
    }
    hs_ang_log(count($gespeichert) . ' Angebote gespeichert');
    return count($gespeichert);
}

function hs_ang_heute(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
}

// ---------------------------------------------------------------------------------------------------------
// Diagnose (Einstellungen → Angebote): nur Status und Anzahlen, schreibt nichts

/**
 * Zeigt je Status, wie viele Objekte es gibt und wie viele davon öffentliche Bilder haben.
 * Nur Namen, IDs und Anzahlen; keine Adressen, Preise oder Kontakte. Nutzt wie scripts/angebote.py --diagnose
 * die API V1.
 */
function hs_angebote_diagnose(): bool {
    $GLOBALS['hs_angebote_protokoll'] = [];
    $erfolg = false;
    try {
        $schluessel = hs_propstack_einstellung('schluessel');
        if ($schluessel === '') {
            throw new HS_Angebote_Abbruch('Für die Diagnose fehlt der Propstack-Schlüssel.');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        hs_ang_diagnose(new HS_Propstack($schluessel));
        $erfolg = true;
    } catch (Throwable $fehler) {
        hs_ang_log(hs_ang_fehlertext($fehler), 'fehler');
    }
    update_option('hs_angebote_diagnose', [
        'zeit' => time(), 'erfolg' => $erfolg, 'protokoll' => $GLOBALS['hs_angebote_protokoll'],
    ], false);
    unset($GLOBALS['hs_angebote_protokoll']);
    return $erfolg;
}

function hs_ang_diagnose(HS_Propstack $ps): void {
    $status = array_values(array_filter(hs_ang_liste($ps->holen('/v1/property_statuses')), 'is_array'));
    $objekte = array_map('hs_ang_v1_normal', array_values(array_filter(
        $ps->seiten('/v1/units', 100, ['with_meta' => 1, 'expand' => 1]), 'is_array')));
    $zaehler = [];
    foreach ($objekte as $o) {
        $sid = hs_ang_text($o['property_status_id'] ?? null);
        $sid = $sid !== '' ? $sid : 'ohne';
        $z = $zaehler[$sid] ?? [0, 0, 0, 0];
        $z[0]++;
        $z[1] += hs_ang_wahr($o['archived'] ?? null) ? 1 : 0;
        $oeffentliches_bild = false;
        foreach ($o['images'] as $b) {
            if (!hs_ang_wahr($b['is_private'] ?? null)) {
                $oeffentliches_bild = true;
                break;
            }
        }
        $z[2] += $oeffentliches_bild ? 1 : 0;
        $z[3] += hs_ang_text($o['unit_id'] ?? null) !== '' ? 1 : 0;
        $zaehler[$sid] = $z;
    }
    $zeilen = [count($objekte) . ' Objekte in GET /v1/units. Je Status: Anzahl | archiviert | mit öffentlichem Bild | mit Objekt-Nr.'];
    usort($status, function ($a, $b) {
        return (hs_ang_zahl($a['position'] ?? null) ?: 0) <=> (hs_ang_zahl($b['position'] ?? null) ?: 0);
    });
    foreach ($status as $s) {
        $id = hs_ang_text($s['id'] ?? null);
        $z = $zaehler[$id] ?? [0, 0, 0, 0];
        unset($zaehler[$id]);
        $zeilen[] = "{$id} „" . hs_ang_text($s['name'] ?? null) . '“ nonpublic=' . (hs_ang_wahr($s['nonpublic'] ?? null) ? 'True' : 'False')
            . ": {$z[0]} | {$z[1]} | {$z[2]} | {$z[3]}";
    }
    foreach ($zaehler as $sid => $z) {
        $zeilen[] = "{$sid} (kein bekannter Status): {$z[0]} | {$z[1]} | {$z[2]} | {$z[3]}";
    }
    $namen = [];
    foreach ($status as $s) {
        $namen[hs_ang_text($s['id'] ?? null)] = hs_ang_text($s['name'] ?? null);
    }
    $bekannt = ['20260703', '20260708', '20251227'];
    $gefunden = [];
    foreach ($objekte as $o) {
        if (in_array(hs_ang_text($o['unit_id'] ?? null), $bekannt, true)) {
            $sid = hs_ang_text($o['property_status_id'] ?? null);
            $gefunden[] = hs_ang_text($o['unit_id'] ?? null) . ' → ' . ($namen[$sid] ?? $sid);
        }
    }
    $zeilen[] = 'Bekannt öffentliche Objekte: ' . implode(', ', $gefunden);
    $markierungen = [];
    foreach ($objekte as $o) {
        foreach (array_keys($o) as $feld) {
            if (preg_match('/publish|portal|homepage|website|public|iframe|visib|connection|expose|group/i', (string) $feld)) {
                $markierungen[(string) $feld] = true;
            }
        }
    }
    $markierungen = array_map('strval', array_keys($markierungen));
    sort($markierungen, SORT_STRING);
    $zeilen[] = 'Felder zu Freigabe/Gruppen: ' . ($markierungen ? implode(', ', $markierungen) : 'keine');
    foreach ($zeilen as $zeile) {
        hs_ang_log($zeile);
    }
}
