<?php
// Angebote: aus dem gespeicherten Stand die Liste der Startseite, die Detailseiten, das JSON-LD und die Einträge
// für sitemap.xml und llms.txt erzeugen (Markup und Texte wie scripts/angebote.py).

defined('ABSPATH') || exit;

const HS_ANG_PIN = '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true">'
    . '<path d="M7 13s4.5-4.2 4.5-7.5a4.5 4.5 0 0 0-9 0C2.5 8.8 7 13 7 13z"/><circle cx="7" cy="5.5" r="1.6"/></svg>';

// ---------------------------------------------------------------------------------------------------------
// Gespeicherter Stand

/** Der zuletzt erfolgreich geladene Stand (Option hs_angebote) oder null, solange noch keiner geladen ist. */
function hs_angebote_daten(): ?array {
    $daten = get_option('hs_angebote');
    return is_array($daten) && is_array($daten['angebote'] ?? null) ? $daten : null;
}

/** Alle Angebote, aufbereitet für die Seiten, neueste zuerst. */
function hs_angebote_alle(): array {
    static $zwischenspeicher = [null, []];
    $daten = hs_angebote_daten();
    $geladen = $daten['geladen'] ?? 0;
    if ($zwischenspeicher[0] !== $geladen) {
        $angebote = [];
        foreach ($daten['angebote'] ?? [] as $eintrag) {
            if (is_array($eintrag) && is_array($eintrag['o'] ?? null) && is_array($eintrag['bilder'] ?? null)) {
                $angebote[] = hs_ang_angebot($eintrag);
            }
        }
        $zwischenspeicher = [$geladen, $angebote];
    }
    return $zwischenspeicher[1];
}

/** Datum des Stands: Tag des letzten erfolgreichen Laufs, ohne geladenen Stand heute (Zeit in Augsburg). */
function hs_angebote_stand(): DateTimeImmutable {
    $stand = hs_angebote_daten()['stand'] ?? '';
    if (is_string($stand) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stand)) {
        return new DateTimeImmutable($stand, new DateTimeZone('Europe/Berlin'));
    }
    return hs_ang_heute();
}

/** Das Angebot der aufgerufenen Adresse /angebote/<slug>/ oder null. */
function hs_angebot_aktuell(): ?array {
    $slug = hs_angebot_slug();
    if ($slug === '') {
        return null;
    }
    foreach (hs_angebote_alle() as $a) {
        if ($a['slug'] === $slug) {
            return $a;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------------------
// Ein Angebot für die Seiten aufbereiten

/**
 * $eintrag: ['o' => Felder aus Propstack, 'status' => Name des Status, 'bilder' => […], 'slug' => …]. Ohne slug
 * (beim Laden) wird er aus Objektart, Ort und Objekt-Nr. gebildet.
 */
function hs_ang_angebot(array $eintrag): array {
    $o = $eintrag['o'];
    $a = ['o' => $o];
    $a['id'] = hs_ang_text($o['id'] ?? null);
    $nr = hs_ang_text($o['unit_id'] ?? null);
    $nr = $nr !== '' ? $nr : hs_ang_text($o['exposee_id'] ?? null);
    $a['nr'] = $nr !== '' ? $nr : $a['id'];
    $titel = hs_ang_text($o['title'] ?? null);
    $a['titel'] = $titel !== '' ? $titel : hs_ang_text($o['name'] ?? null);
    $a['kauf'] = mb_strtoupper(hs_ang_text($o['marketing_type'] ?? null)) !== 'RENT';
    $a['typ'] = mb_strtoupper(hs_ang_text($o['rs_type'] ?? null));
    $a['art'] = HS_ANG_ARTEN[$a['typ']] ?? 'Immobilie';
    $a['kategorie'] = HS_ANG_KATEGORIEN[mb_strtoupper(hs_ang_text($o['rs_category'] ?? null))] ?? '';
    $a['zimmer'] = hs_ang_zahl($o['number_of_rooms'] ?? null);
    $a['wohnen'] = in_array($a['typ'], HS_ANG_WOHNEN, true);
    $flaeche = $a['wohnen'] ? hs_ang_zahl($o['living_space'] ?? null) : null;
    if (!$flaeche) {
        foreach (['property_space_value', 'total_floor_space', 'usable_floor_space', 'net_floor_space'] as $feld) {
            $flaeche = hs_ang_zahl($o[$feld] ?? null);
            if ($flaeche) {
                break;
            }
        }
    }
    $a['flaeche'] = $flaeche;
    $a['plz'] = hs_ang_text($o['zip_code'] ?? null);
    $a['ort'] = hs_ang_text($o['city'] ?? null);
    $a['status'] = (string) ($eintrag['status'] ?? '');
    $a['bilder'] = array_values($eintrag['bilder']);

    // Preis: „Preis auf Anfrage“, wenn Propstack ihn verbirgt
    $a['preis_label'] = $a['kauf'] ? 'Kaufpreis' : 'Kaltmiete';
    $betrag = hs_ang_zahl($a['kauf'] ? ($o['price'] ?? null) : ($o['base_rent'] ?? null));
    $verborgen = hs_ang_wahr($o['price_on_inquiry'] ?? null) || hs_ang_falsch($o['show_price_in_expose'] ?? null);
    $a['preis'] = $verborgen || !$betrag ? null : $betrag;
    if ($verborgen && $betrag) {
        hs_ang_log("Preis auf Anfrage: Objekt {$a['nr']}: price_on_inquiry=" . hs_ang_py_repr(hs_ang_roh($o['price_on_inquiry'] ?? null))
            . ', show_price_in_expose=' . hs_ang_py_repr(hs_ang_roh($o['show_price_in_expose'] ?? null)), 'hinweis');
    }
    $a['preis_text'] = $a['preis'] ? hs_ang_euro($a['preis']) : 'Preis auf Anfrage';

    if ($a['typ'] === 'APARTMENT' || $a['typ'] === 'HOUSE') {
        if ($a['typ'] === 'APARTMENT') {
            $grundwort = 'Wohnung';
        } else {
            $haus = $a['kategorie'] !== '' && mb_strpos(mb_strtolower($a['kategorie']), 'haus') !== false;
            $grundwort = $haus ? $a['kategorie'] : 'Haus';
        }
        $a['kurzname'] = $a['zimmer'] ? hs_ang_zimmer_text($a['zimmer']) . "-Zimmer-{$grundwort}" : $grundwort;
    } else {
        $a['kurzname'] = !in_array($a['kategorie'], ['', 'Sonstige', 'Keine Angabe'], true) ? $a['kategorie'] : $a['art'];
    }
    $a['name'] = $a['ort'] !== '' ? "{$a['kurzname']} in {$a['ort']}" : $a['kurzname'];

    $slug = (string) ($eintrag['slug'] ?? '');
    if ($slug === '') {
        $teile = array_filter([$a['kurzname'], $a['ort'], $a['nr']], function ($teil) {
            return $teil !== '';
        });
        $slug = implode('-', array_map('hs_ang_slug_teil', $teile));
    }
    $a['slug'] = $slug;
    $a['pfad'] = "/angebote/{$slug}/";
    $a['url'] = hs_url($a['pfad']);
    return $a;
}

function hs_ang_wert(array $a, string $feld) {
    return $a['o'][$feld] ?? null;
}

function hs_ang_notiz(array $a, string $feld): string {
    $text = hs_ang_text(hs_ang_wert($a, $feld));
    return $text !== '' ? $text : hs_ang_text(hs_ang_wert($a, "long_{$feld}"));
}

// ---------------------------------------------------------------------------------------------------------
// HTML-Bausteine

function hs_ang_srcset(array $b): string {
    return esc_url($b['mittel']) . ' ' . (int) $b['mittel_masse'][0] . 'w, ' . esc_url($b['gross']) . ' ' . (int) $b['gross_masse'][0] . 'w';
}

function hs_ang_karte(array $a): string {
    $zeilen = [[$a['preis_label'], $a['preis_text']]];
    if ($a['wohnen']) {
        if ($a['flaeche']) {
            $zeilen[] = ['Wohnfläche ca.', hs_ang_flaeche($a['flaeche'], 1)];
        }
        if ($a['zimmer']) {
            $zeilen[] = ['Zimmer', hs_ang_zimmer_text($a['zimmer'])];
        }
    } else {
        $zeilen[] = ['Art', $a['art']];
        if ($a['flaeche']) {
            $zeilen[] = ['Fläche ca.', hs_ang_flaeche($a['flaeche'], 1)];
        }
    }
    $dl = '';
    foreach ($zeilen as [$name, $wert]) {
        $dl .= '<dt>' . hs_e($name) . '</dt><dd>' . hs_e($wert) . '</dd>';
    }
    $bild = '';
    if ($a['bilder']) {
        $b = $a['bilder'][0];
        [$breite, $hoehe] = $b['mittel_masse'];
        $bild = '<img src="' . esc_url($b['mittel']) . '" srcset="' . hs_ang_srcset($b) . '" '
            . 'sizes="(min-width: 992px) 370px, (min-width: 641px) 50vw, 100vw" '
            . 'alt="' . esc_attr($a['name']) . '" width="' . (int) $breite . '" height="' . (int) $hoehe . '" loading="lazy" decoding="async">';
    }
    return '        <li><a class="objekt" href="' . esc_url($a['url']) . "\">\n"
        . '          <div class="bild">' . $bild . "</div>\n"
        . '          <div class="info"><h3>' . HS_ANG_PIN . hs_e($a['plz']) . ' ' . hs_e($a['ort']) . '</h3><dl>' . $dl . "</dl></div>\n"
        . '        </a></li>';
}

/** Propstack-Text (Absätze, Aufzählungen mit -, * oder •) als HTML; vorhandenes HTML wird zu Text. */
function hs_ang_text_html(string $roh_text): string {
    $t = str_replace("\r\n", "\n", $roh_text);
    if (preg_match('/<(p|br|ul|ol|li|div|strong|b|em|span)\b/i', $t)) {
        $t = preg_replace('/<br\s*\/?>/i', "\n", $t);
        $t = preg_replace('/<li[^>]*>/i', "\n- ", $t);
        $t = preg_replace('/<\/(p|div|li|ul|ol|h\d)>/i', "\n", $t);
        $t = html_entity_decode(preg_replace('/<[^>]+>/', '', $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $teile = [];
    $punkte = [];
    foreach (explode("\n", $t) as $zeile) {
        $zeile = hs_ang_strip($zeile);
        if (preg_match('/^[-*•–]\s+(.*)/u', $zeile, $m)) {
            $punkte[] = '<li>' . hs_e($m[1]) . '</li>';
            continue;
        }
        if ($punkte) {
            $teile[] = '<ul class="haken">' . implode('', $punkte) . '</ul>';
            $punkte = [];
        }
        if ($zeile !== '') {
            $teile[] = '<p>' . hs_e($zeile) . '</p>';
        }
    }
    if ($punkte) {
        $teile[] = '<ul class="haken">' . implode('', $punkte) . '</ul>';
    }
    return implode("\n", $teile);
}

function hs_ang_tabelle(array $zeilen): string {
    $html = '<table class="datentabelle"><tbody>';
    foreach ($zeilen as [$name, $wert]) {
        if ((string) $wert !== '') {
            $html .= '<tr><th scope="row">' . hs_e($name) . '</th><td>' . hs_e($wert) . '</td></tr>';
        }
    }
    return $html . '</tbody></table>';
}

function hs_ang_eckdaten(array $a): array {
    $z = [];
    $betrag = function (string $name, string $feld) use ($a, &$z) {
        $n = hs_ang_zahl(hs_ang_wert($a, $feld));
        if ($n) {
            $z[] = [$name, hs_ang_euro($n)];
        }
    };
    $qm = function (string $name, string $feld) use ($a, &$z) {
        $n = hs_ang_zahl(hs_ang_wert($a, $feld));
        if ($n) {
            $z[] = [$name, hs_ang_flaeche($n)];
        }
    };
    $anzahl = function (string $name, string $feld) use ($a, &$z) {
        $n = hs_ang_zahl(hs_ang_wert($a, $feld));
        if ($n) {
            $z[] = [$name, hs_ang_de_zahl($n, 1)];
        }
    };
    $jahr = function (string $name, string $feld) use ($a, &$z) {
        $n = hs_ang_zahl(hs_ang_wert($a, $feld));
        if ($n) {
            $z[] = [$name, (string) (int) $n];
        }
    };

    $z[] = ['Objekt-Nr.', $a['nr']];
    $z[] = ['Kategorie', ($a['kauf'] ? 'Kauf' : 'Miete') . ' – ' . $a['art']];
    if ($a['kategorie'] !== '' && !in_array($a['kategorie'], [$a['art'], 'Sonstige', 'Keine Angabe'], true)) {
        $z[] = ['Objektart', $a['kategorie']];
    }
    if ($a['status'] !== '' && preg_match('/reserv/i', $a['status'])) {
        $z[] = ['Status', $a['status']];
    }
    $jahr('Baujahr ca.', 'construction_year');
    $z[] = ['Objektzustand', hs_ang_uebersetzt(HS_ANG_ZUSTAND, hs_ang_wert($a, 'condition'), 'condition')];
    $z[] = ['Qualität der Ausstattung', hs_ang_uebersetzt(HS_ANG_QUALITAET, hs_ang_wert($a, 'interior_quality'), 'interior_quality')];
    $jahr('Letzte Modernisierung', 'last_refurbishment');
    if (hs_ang_wahr(hs_ang_wert($a, 'rented'))) {
        $z[] = ['Vermietet', 'Ja'];
    }
    $z[] = ['Verfügbar ab', hs_ang_de_datum(hs_ang_wert($a, 'free_from'))];

    $z[] = [$a['preis_label'], $a['preis_text']];
    if ($a['preis'] || !$a['kauf']) {
        if ($a['kauf']) {
            $betrag('Kaltmiete', 'base_rent');
        }
        $betrag('Nebenkosten', 'service_charge');
        $betrag('Heizkosten', 'heating_costs');
        $betrag('Warmmiete', 'total_rent');
        $betrag('Stellplatz-Preis', 'parking_space_price');
        $betrag('Hausgeld/Monat', 'rent_subsidy');
        $betrag('Hausgeld/Monat', 'house_money');
        $betrag('Instandhaltungsrücklage', 'maintenance_reserve');
        $kaution = hs_ang_text(hs_ang_wert($a, 'deposit'));
        if ($kaution !== '') {
            $n = hs_ang_zahl(hs_ang_wert($a, 'deposit'));
            $z[] = ['Kaution', $n ? hs_ang_euro($n) : $kaution];
        }
    }
    $z[] = [$a['kauf'] ? 'Käuferprovision' : 'Provision', hs_ang_text(hs_ang_wert($a, 'courtage'))];

    if (hs_ang_text(hs_ang_wert($a, 'floor')) !== '') {
        $z[] = ['Etage', hs_ang_etage(hs_ang_wert($a, 'floor'))];
    }
    $anzahl('Etagenzahl', 'number_of_floors');
    $anzahl('Zimmer', 'number_of_rooms');
    $anzahl('Schlafzimmer', 'number_of_bed_rooms');
    $anzahl('Badezimmer', 'number_of_bath_rooms');
    $qm('Wohnfläche ca.', 'living_space');
    $qm('Nutzfläche ca.', 'usable_floor_space');
    $qm('Gesamtfläche ca.', 'total_floor_space');
    $qm('Grundstück ca.', 'plot_area');
    $qm('Balkon/Terrasse ca.', 'balcony_space');
    $qm('Garten ca.', 'garden_space');
    $anzahl('Stellplätze', 'number_of_parking_spaces');
    foreach ([
        'cellar' => 'Keller', 'built_in_kitchen' => 'Einbauküche', 'balcony' => 'Balkon', 'terrace' => 'Terrasse',
        'garden' => 'Garten', 'lift' => 'Aufzug', 'guest_toilet' => 'Gäste-WC', 'barrier_free' => 'Barrierefrei',
        'storeroom' => 'Abstellraum', 'bicycle_storage' => 'Fahrradraum', 'laundry_room' => 'Waschküche',
        'monument' => 'Denkmalschutz',
    ] as $feld => $name) {
        if (hs_ang_wahr(hs_ang_wert($a, $feld))) {
            $z[] = [$name, 'Ja'];
        }
    }
    foreach ([
        'distance_to_pt' => 'Fußweg zu öffentl. Verkehrsmitteln', 'distance_to_mrs' => 'Fahrzeit zum Hauptbahnhof',
        'distance_to_fm' => 'Fahrzeit zur Autobahn', 'distance_to_airport' => 'Fahrzeit zum Flughafen',
    ] as $feld => $name) {
        $n = hs_ang_zahl(hs_ang_wert($a, $feld));
        if ($n) {
            $z[] = [$name, (int) $n . ' Min.'];
        }
    }
    return $z;
}

function hs_ang_kennwert(array $a): ?float {
    return hs_ang_zahl(hs_ang_wert($a, 'thermal_characteristic')) ?: hs_ang_zahl(hs_ang_wert($a, 'energy_efficiency_value'));
}

function hs_ang_energieausweis(array $a): array {
    $typ = hs_ang_uebersetzt(HS_ANG_AUSWEISTYP, hs_ang_wert($a, 'building_energy_rating_type'), 'building_energy_rating_type');
    $kennwert = hs_ang_kennwert($a);
    $erstellt = mb_strtoupper(hs_ang_text(hs_ang_wert($a, 'energy_certificate_creation_date')));
    if ($erstellt !== '') {
        if (preg_match('/BEFORE|VOR/', $erstellt)) {
            $erstellt = 'vor dem 1. Mai 2014';
        } elseif (preg_match('/FROM|AFTER|SINCE|AB /', $erstellt)) {
            $erstellt = 'ab 1. Mai 2014';
        } else {
            $erstellt = hs_ang_uebersetzt([], hs_ang_text(hs_ang_wert($a, 'energy_certificate_creation_date')), 'energy_certificate_creation_date');
        }
    }
    $traeger = [];
    $roh = hs_ang_roh(hs_ang_wert($a, 'firing_types'));
    if (hs_ang_ist_liste($roh)) {
        $teile = $roh;
    } else {
        $teile = preg_split('/[,;]/', str_replace('"', '', trim(hs_ang_text($roh), '[]')));
        $teile = array_filter(array_map('hs_ang_strip', $teile), function ($teil) {
            return $teil !== '';
        });
    }
    foreach ($teile as $teil) {
        $name = hs_ang_uebersetzt(HS_ANG_ENERGIETRAEGER, $teil, 'firing_types');
        if ($name !== '') {
            $traeger[] = $name;
        }
    }
    $warmwasser = hs_ang_wert($a, 'energy_consumption_contains_warm_water');
    $baujahr = hs_ang_zahl(hs_ang_wert($a, 'energy_certificate_construction_year'));
    $anlage = hs_ang_zahl(hs_ang_wert($a, 'equipment_technology_construction_year'));
    $z = [
        ['Energieausweis', hs_ang_uebersetzt(HS_ANG_AUSWEIS, hs_ang_wert($a, 'energy_certificate_availability'), 'energy_certificate_availability')],
        ['Energieausweistyp', $typ],
        ['Ausstellungsdatum', hs_ang_de_datum(hs_ang_wert($a, 'energy_certificate_start_date'))],
        ['Erstellt', $erstellt],
        ['Gültig bis', hs_ang_de_datum(hs_ang_wert($a, 'energy_certificate_end_date'))],
        [$typ === 'Bedarfsausweis' ? 'Endenergiebedarf' : 'Energieverbrauchskennwert',
            $kennwert ? hs_ang_de_zahl($kennwert) . ' kWh/(m²·a)' : ''],
        ['Warmwasser enthalten', hs_ang_wahr($warmwasser) ? 'Ja' : (hs_ang_falsch($warmwasser) ? 'Nein' : '')],
        ['Heizungsart', hs_ang_uebersetzt(HS_ANG_HEIZUNG, hs_ang_wert($a, 'heating_type'), 'heating_type')],
        ['Wesentlicher Energieträger', implode(', ', $traeger)],
        ['Energieeffizienzklasse', hs_ang_effizienzklasse(hs_ang_wert($a, 'energy_efficiency_class'))],
        ['Baujahr laut Energieausweis', $baujahr ? (string) (int) $baujahr : ''],
        ['Baujahr Anlagentechnik', $anlage ? (string) (int) $anlage : ''],
    ];
    return array_values(array_filter($z, function ($zeile) {
        return $zeile[1] !== '';
    }));
}

// Skala wie im Energieausweis für Wohngebäude (GEG): Klassengrenzen in kWh/(m²·a), Balken bis 275 (H = über 250)
const HS_ANG_KLASSENGRENZEN = [['A+', 0, 30], ['A', 30, 50], ['B', 50, 75], ['C', 75, 100], ['D', 100, 130],
    ['E', 130, 160], ['F', 160, 200], ['G', 200, 250], ['H', 250, 275]];
const HS_ANG_SKALA_ENDE = 275;

function hs_ang_prozent(float $wert): string {
    return sprintf('%.2F', $wert / HS_ANG_SKALA_ENDE * 100) . '%';
}

function hs_ang_skala(array $a): string {
    $klasse = hs_ang_effizienzklasse(hs_ang_wert($a, 'energy_efficiency_class'));
    $kennwert = hs_ang_kennwert($a);
    if ($klasse === '' && !$kennwert) {
        return '';
    }
    $typ = hs_ang_uebersetzt(HS_ANG_AUSWEISTYP, hs_ang_wert($a, 'building_energy_rating_type'), 'building_energy_rating_type');
    $art = ['Bedarfsausweis' => 'Endenergiebedarf', 'Verbrauchsausweis' => 'Endenergieverbrauch'][$typ] ?? 'Energiekennwert';

    $stufen = '';
    foreach (HS_ANG_KLASSENGRENZEN as [$k, $von, $bis]) {
        $stufen .= '<li style="--breite: ' . hs_ang_prozent($bis - $von) . '"' . ($k === $klasse ? ' aria-current="true"' : '')
            . '>' . hs_e($k) . '</li>';
    }
    $zahlen = '';
    for ($w = 0; $w <= 250; $w += 25) {
        $zahlen .= '<li' . ($w % 50 ? ' class="neben"' : '') . ' style="--pos: ' . hs_ang_prozent($w) . '">'
            . ($w === 250 ? '&gt;250' : $w) . '</li>';
    }

    $beschreibung = [];
    if ($kennwert) {
        $position = $kennwert;
        $wert_text = hs_ang_de_zahl($kennwert) . ' kWh/(m²·a)';
        $beschreibung[] = "{$art} {$wert_text}";
    } else {
        $position = 0;
        foreach (HS_ANG_KLASSENGRENZEN as [$k, $von, $bis]) {
            if ($k === $klasse) {
                $position = ($von + $bis) / 2;
                break;
            }
        }
        $wert_text = "Klasse {$klasse}";
    }
    if ($klasse !== '') {
        array_unshift($beschreibung, "Energieeffizienzklasse {$klasse}");
    }
    $seite = $position > HS_ANG_SKALA_ENDE / 2 ? ' rechts' : '';
    return '<figure class="energieskala" role="img" aria-label="' . esc_attr(implode(', ', $beschreibung)) . ' auf der Skala '
        . 'von A+ bis H">'
        . '<p class="energieskala-wert' . $seite . '" style="--pos: ' . hs_ang_prozent(min(max($position, 0), HS_ANG_SKALA_ENDE - 4)) . '">'
        . '<span>' . hs_e($art) . ' dieses Gebäudes</span><strong>' . hs_e($wert_text) . '</strong></p>'
        . '<ol class="energieskala-balken">' . $stufen . '</ol>'
        . '<ol class="energieskala-zahlen">' . $zahlen . '</ol></figure>';
}

function hs_ang_galerie(array $a): string {
    if (!$a['bilder']) {
        return '';
    }
    $n = count($a['bilder']);
    $folien = [];
    $vorschau = [];
    foreach ($a['bilder'] as $index => $b) {
        $i = $index + 1;
        [$breite, $hoehe] = $b['gross_masse'];
        $titel = $b['titel'] !== '' ? $b['titel'] : ($b['plan'] ? 'Grundriss' : "Bild {$i}");
        $alt = !$b['plan'] ? "{$titel} – {$a['name']}" : "Grundriss – {$a['name']}";
        $laden = $i === 1 ? 'fetchpriority="high"' : 'loading="lazy"';
        $klasse = $b['plan'] ? ' class="plan"' : '';
        $aktuell = $i === 1 ? ' aria-current="true"' : '';
        $folien[] = '<li id="bild-' . $i . '" role="group" aria-roledescription="Bild" aria-label="' . $i . ' von ' . $n . ': '
            . esc_attr($titel) . '"' . $klasse . '>'
            . '<figure><img src="' . esc_url($b['gross']) . '" srcset="' . hs_ang_srcset($b) . '" sizes="(min-width: 992px) 740px, 100vw" '
            . 'alt="' . esc_attr($alt) . '" width="' . (int) $breite . '" height="' . (int) $hoehe . '" ' . $laden . ' decoding="async">'
            . '<figcaption>' . hs_e($titel) . '</figcaption></figure></li>';
        $vorschau[] = '<li><a href="#bild-' . $i . '"' . $aktuell . '>'
            . '<img src="' . esc_url($b['klein']) . '" alt="' . esc_attr("{$titel}, Bild {$i} zeigen") . '" width="72" height="72" loading="lazy" decoding="async"></a></li>';
    }
    $folien = implode("\n              ", $folien);
    $vorschau = implode("\n            ", $vorschau);
    return <<<HTML
<section class="galerie" aria-roledescription="Karussell" aria-label="Bilder und Grundrisse">
          <div class="galerie-buehne">
            <ul class="galerie-liste" tabindex="0" aria-label="Bilder, mit den Pfeiltasten blättern">
              {$folien}
            </ul>
            <button class="galerie-knopf galerie-zurueck" type="button" aria-label="Vorheriges Bild" hidden><svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M11 3 5 9l6 6"/></svg></button>
            <button class="galerie-knopf galerie-weiter" type="button" aria-label="Nächstes Bild" hidden><svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m7 3 6 6-6 6"/></svg></button>
            <p class="galerie-zaehler" aria-live="polite">1 / {$n}</p>
          </div>
          <ul class="galerie-vorschau">
            {$vorschau}
          </ul>
        </section>
HTML;
}

/** „3-Zimmer-Wohnung“ im Titel nicht am Bindestrich umbrechen. */
function hs_ang_titel_html(string $titel): string {
    $html = hs_e($titel);
    return preg_replace_callback('/\d+(?:,\d+)?-Zimmer-[\p{L}\p{N}_]+/u', function ($m) {
        return '<span class="kein-umbruch">' . $m[0] . '</span>';
    }, $html) ?? $html;
}

function hs_ang_mailto(array $a): string {
    $betreff = "Anfrage zu Objekt {$a['nr']}: {$a['titel']}";
    $inhalt = "Guten Tag,\n\nich interessiere mich für das Angebot „{$a['titel']}“ (Objekt-Nr. {$a['nr']}):\n{$a['url']}\n\n"
        . "Bitte melden Sie sich bei mir.\n\n";
    return 'mailto:' . HS_MAIL . '?subject=' . rawurlencode($betreff) . '&body=' . rawurlencode($inhalt);
}

function hs_ang_kurzbeschreibung(array $a, int $laenge = 155): string {
    $anfang = $a['kurzname'] . ($a['ort'] !== '' ? " in {$a['plz']} {$a['ort']}" : '');
    $fakten = [$a['preis'] ? "{$a['preis_label']} {$a['preis_text']}" : $a['preis_text']];
    if ($a['flaeche']) {
        $fakten[] = hs_ang_flaeche($a['flaeche'], 1) . ' ' . ($a['wohnen'] ? 'Wohnfläche' : 'Fläche');
    }
    $baujahr = hs_ang_zahl(hs_ang_wert($a, 'construction_year'));
    if ($baujahr) {
        $fakten[] = 'Baujahr ' . (int) $baujahr;
    }
    $satz = "{$anfang}: " . implode(', ', $fakten) . '.';
    $notiz = hs_ang_notiz($a, 'description_note');
    $rest = hs_ang_strip((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]+>/', ' ', $notiz)));
    if ($rest !== '' && mb_strlen($satz) + 1 < $laenge) {
        $rest = mb_substr($rest, 0, $laenge - mb_strlen($satz) - 1);
        if (mb_strlen($rest) < mb_strlen((string) preg_replace('/\s+/u', ' ', $notiz))) {
            $leer = mb_strrpos($rest, ' ');
            $rest = $leer === false ? $rest : mb_substr($rest, 0, $leer);
            $rest = preg_replace('/[,;:–-]+\z/u', '', $rest) . ' …';
        }
        $satz .= ' ' . $rest;
    }
    return $satz;
}

function hs_ang_detail_ld(array $a): array {
    $fotos = [];
    foreach ($a['bilder'] as $b) {
        if (!$b['plan'] && count($fotos) < 10) {
            $fotos[] = $b['gross'];
        }
    }
    $adresse = ['@type' => 'PostalAddress', 'postalCode' => $a['plz'], 'addressLocality' => $a['ort'], 'addressCountry' => 'DE'];
    $immobilie = ['@id' => $a['url'] . '#immobilie', 'name' => $a['name'], 'address' => $adresse];
    if ($a['typ'] === 'APARTMENT') {
        $immobilie['@type'] = 'Apartment';
    } elseif ($a['typ'] === 'HOUSE') {
        $immobilie['@type'] = hs_ang_text(hs_ang_wert($a, 'rs_category')) === 'SINGLE_FAMILY_HOUSE' ? 'SingleFamilyResidence' : 'House';
    } else {
        $immobilie['@type'] = $a['wohnen'] ? 'Accommodation' : 'Place';
    }
    if ($immobilie['@type'] !== 'Place') {
        if ($a['zimmer']) {
            $immobilie['numberOfRooms'] = $a['zimmer'];
        }
        $schlafzimmer = hs_ang_zahl(hs_ang_wert($a, 'number_of_bed_rooms'));
        if ($schlafzimmer) {
            $immobilie['numberOfBedrooms'] = $schlafzimmer;
        }
        $baeder = hs_ang_zahl(hs_ang_wert($a, 'number_of_bath_rooms'));
        if ($baeder) {
            $immobilie['numberOfBathroomsTotal'] = (int) $baeder;
        }
        if ($a['flaeche']) {
            $immobilie['floorSize'] = ['@type' => 'QuantitativeValue', 'value' => $a['flaeche'], 'unitCode' => 'MTK'];
        }
        $baujahr = hs_ang_zahl(hs_ang_wert($a, 'construction_year'));
        if ($baujahr) {
            $immobilie['yearBuilt'] = (int) $baujahr;
        }
    } elseif ($a['flaeche']) {
        $immobilie['additionalProperty'] = ['@type' => 'PropertyValue', 'name' => 'Fläche', 'value' => $a['flaeche'],
            'unitCode' => 'MTK'];
    }
    if ($fotos) {
        $immobilie['image'] = $fotos[0];
    }
    $firma = hs_url('/#firma');
    $angebot = [
        '@type' => 'Offer', 'url' => $a['url'], 'seller' => ['@id' => $firma], 'itemOffered' => ['@id' => $a['url'] . '#immobilie'],
        'availability' => 'https://schema.org/InStock',
        'businessFunction' => 'http://purl.org/goodrelations/v1#' . ($a['kauf'] ? 'Sell' : 'LeaseOut'),
    ];
    if ($a['preis']) {
        $angebot['price'] = $a['preis'];
        $angebot['priceCurrency'] = 'EUR';
        if (!$a['kauf']) {
            $angebot['priceSpecification'] = ['@type' => 'UnitPriceSpecification', 'price' => $a['preis'],
                'priceCurrency' => 'EUR', 'unitCode' => 'MON'];
        }
    }
    $seite = [
        '@type' => 'RealEstateListing', '@id' => $a['url'], 'url' => $a['url'], 'name' => $a['titel'],
        'description' => hs_ang_kurzbeschreibung($a, 300), 'inLanguage' => 'de-DE',
        'isPartOf' => ['@id' => hs_url('/#website')], 'provider' => ['@id' => $firma],
        'breadcrumb' => ['@id' => $a['url'] . '#brotkrumen'], 'about' => $immobilie, 'offers' => $angebot,
    ];
    if ($fotos) {
        $seite['image'] = $fotos;
        $seite['primaryImageOfPage'] = $fotos[0];
    }
    foreach (['created_at' => 'datePosted', 'updated_at' => 'dateModified'] as $feld => $ziel) {
        $wert = hs_ang_text(hs_ang_wert($a, $feld));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $wert)) {
            $seite[$ziel] = substr($wert, 0, 10);
        }
    }
    return ['@context' => 'https://schema.org', '@graph' => [
        $seite,
        hs_brotkrumen_ld($a['url'], [['Startseite', hs_url('/')], ['Angebote', hs_url('/#angebote')], [$a['name'], $a['url']]]),
        hs_firma_ld(),
    ]];
}

// ---------------------------------------------------------------------------------------------------------
// Detailseite /angebote/<slug>/ (template-parts/angebot.php)

/** Was hs_seite() für den <head> der Detailseite braucht. */
function hs_ang_detail_kopf(array $a): array {
    $titel = $a['name'] . ' ' . ($a['kauf'] ? 'kaufen' : 'mieten') . ($a['flaeche'] ? ', ' . hs_ang_flaeche($a['flaeche'], 1) : '')
        . ' | HeydenSattler';
    $seite = [
        'titel' => $titel,
        'beschreibung' => hs_ang_kurzbeschreibung($a),
        'url' => $a['url'],
        'og_titel' => $a['titel'],
        'ld' => [hs_ang_detail_ld($a)],
        // Ort des Objekts statt „Augsburg“
        'geo' => $a['ort'],
    ];
    if ($a['bilder']) {
        $b = $a['bilder'][0];
        $seite['og_bild'] = ['url' => $b['gross'], 'breite' => $b['gross_masse'][0], 'hoehe' => $b['gross_masse'][1], 'alt' => $a['name']];
    }
    return $seite;
}

/** Der Inhalt der Detailseite von <main> bis </main>. */
function hs_ang_detail_html(array $a): string {
    $merkmale = array_filter([$a['kauf'] ? 'Kauf' : 'Miete', $a['art'], hs_ang_strip("{$a['plz']} {$a['ort']}"), "Objekt-Nr. {$a['nr']}"],
        function ($teil) {
            return $teil !== '';
        });
    $merkmale = implode(' · ', array_map(function ($teil) {
        return '<span class="kein-umbruch">' . hs_e($teil) . '</span>';
    }, $merkmale));

    $fakten = [];
    if ($a['flaeche']) {
        $fakten[] = [$a['wohnen'] ? 'Wohnfläche ca.' : 'Fläche ca.', hs_ang_flaeche($a['flaeche'])];
    }
    if ($a['zimmer']) {
        $fakten[] = ['Zimmer', hs_ang_zimmer_text($a['zimmer'])];
    }
    $baujahr = hs_ang_zahl(hs_ang_wert($a, 'construction_year'));
    if ($baujahr) {
        $fakten[] = ['Baujahr ca.', (string) (int) $baujahr];
    }
    $fakten[] = ['Ort', hs_ang_strip("{$a['plz']} {$a['ort']}")];
    $fakten_dl = '';
    foreach ($fakten as [$name, $wert]) {
        $fakten_dl .= '<dt>' . hs_e($name) . '</dt><dd>' . hs_e($wert) . '</dd>';
    }
    $status = $a['status'] !== '' && preg_match('/reserv/i', $a['status'])
        ? '<p class="expose-status">' . hs_e($a['status']) . '</p>' : '';

    $energie = hs_ang_energieausweis($a);
    $energie_html = $energie ? hs_ang_skala($a) . hs_ang_tabelle($energie)
        : '<p>Angaben zum Energieausweis erhalten Sie auf Anfrage.</p>';

    $texte = [];
    foreach ([
        'description_note' => 'Beschreibung', 'furnishing_note' => 'Ausstattung', 'location_note' => 'Lage',
        'other_note' => 'Sonstiges', 'courtage_note' => 'Provision',
    ] as $feld => $titel) {
        $inhalt = $feld !== 'courtage_note' ? hs_ang_notiz($a, $feld) : hs_ang_text(hs_ang_wert($a, $feld));
        if ($inhalt !== '') {
            $anker = hs_ang_slug_teil($titel);
            $texte[] = '<section class="expose-text" aria-labelledby="' . $anker . '"><h2 id="' . $anker . '">' . hs_e($titel)
                . "</h2>\n" . hs_ang_text_html($inhalt) . '</section>';
        }
    }
    $texte = implode("\n        ", $texte);

    ob_start();
    hs_brotkrumen([['Startseite', '/'], ['Angebote', '/#angebote'], [$a['name'], null]]);
    $brotkrumen = ob_get_clean();

    $titel = hs_ang_titel_html($a['titel']);
    $galerie = hs_ang_galerie($a);
    $preis_label = hs_e($a['preis_label']);
    $preis_text = hs_e($a['preis_text']);
    $link = esc_attr(hs_ang_mailto($a));
    $eckdaten = hs_ang_tabelle(hs_ang_eckdaten($a));
    $telefon = HS_TELEFON;
    $telefon_anzeige = HS_TELEFON_ANZEIGE;
    $kontakt = hs_kontakt_html('Schreiben Sie uns oder rufen Sie uns an. Wir beantworten Ihre Fragen und vereinbaren gerne einen Besichtigungstermin.',
        hs_ang_mailto($a),
        "      <div class=\"weiter\">\n"
        . hs_querlink('/#angebote', 'Alle Angebote', 'Weitere Immobilien in Augsburg und Umgebung')
        . hs_querlink('/leistungen/verkaufen/', 'Verkaufen', 'Sie möchten selbst eine Immobilie verkaufen?')
        . "      </div>\n",
        'Interesse an dieser Immobilie?');

    return <<<HTML
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
{$brotkrumen}      <h1>{$titel}</h1>
      <p class="expose-merkmale">{$merkmale}</p>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap expose">
      <div class="expose-oben">
        {$galerie}
        <aside class="expose-karte" aria-label="Das Wichtigste">
          {$status}<p class="expose-preis-label">{$preis_label}</p>
          <p class="expose-preis">{$preis_text}</p>
          <dl>{$fakten_dl}</dl>
          <a class="btn" href="{$link}">Anfrage per E-Mail</a>
          <p class="expose-anruf">oder anrufen: <a href="tel:{$telefon}">{$telefon_anzeige}</a></p>
        </aside>
      </div>

      <div class="expose-daten">
        <section aria-labelledby="eckdaten"><h2 id="eckdaten">Eckdaten</h2>
          {$eckdaten}
        </section>
        <section aria-labelledby="energieausweis"><h2 id="energieausweis">Energieausweis</h2>
          {$energie_html}
        </section>
      </div>

      <div class="expose-texte">
        {$texte}
        <p class="expose-hinweis">Alle Angaben ohne Gewähr; sie beruhen auf Informationen der Eigentümer. Die genaue Adresse nennen wir Ihnen gerne auf Anfrage.</p>
      </div>
    </div>
  </section>

{$kontakt}</main>

HTML;
}

// ---------------------------------------------------------------------------------------------------------
// Für die Startseite, sitemap.xml und llms.txt

/** Die Liste im Abschnitt #angebote der Startseite (ohne Angebote ein Hinweis), darunter der Stand. */
function hs_angebote_startseite(): void {
    $angebote = hs_angebote_alle();
    if ($angebote) {
        echo "      <ul class=\"angebote\">\n";
        foreach ($angebote as $a) {
            echo hs_ang_karte($a) . "\n";
        }
        echo "      </ul>\n";
    } else {
        echo "      <p>Zurzeit sind keine Angebote veröffentlicht. Sprechen Sie uns gerne an: Neue Immobilien "
            . "stellen wir oft zuerst Interessenten aus unserer Kartei vor.</p>\n";
    }
    $stand = hs_angebote_stand();
    echo '      <p class="stand">Stand: <time datetime="' . $stand->format('Y-m-d') . '">' . $stand->format('d.m.Y')
        . "</time>. Ein Klick öffnet das vollständige Exposé.</p>\n";
}

/** JSON-LD ItemList der Angebote für den <head> der Startseite; null ohne Angebote. */
function hs_angebote_ld(): ?array {
    $angebote = hs_angebote_alle();
    if (!$angebote) {
        return null;
    }
    $elemente = [];
    foreach ($angebote as $i => $a) {
        $angebot = [
            '@type' => 'Offer',
            'name' => $a['name'] . ($a['flaeche'] ? ', ' . hs_ang_flaeche($a['flaeche'], 1) : ''),
            'url' => $a['url'],
        ];
        if ($a['preis']) {
            $angebot['price'] = $a['preis'];
            $angebot['priceCurrency'] = 'EUR';
        }
        if ($a['bilder']) {
            $angebot['image'] = $a['bilder'][0]['gross'];
        }
        $angebot['availableAtOrFrom'] = ['@type' => 'Place', 'address' => [
            '@type' => 'PostalAddress', 'postalCode' => $a['plz'], 'addressLocality' => $a['ort'], 'addressCountry' => 'DE',
        ]];
        $elemente[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $angebot];
    }
    return [
        '@context' => 'https://schema.org', '@type' => 'ItemList', '@id' => hs_url('/#angebote'),
        'name' => 'Aktuelle Immobilienangebote von HeydenSattler', 'numberOfItems' => count($angebote),
        'itemListElement' => $elemente,
    ];
}

/** Einträge für sitemap.xml: [['url' => …, 'lastmod' => 'Y-m-d'], …]. */
function hs_angebote_sitemap(): array {
    $stand = hs_angebote_stand()->format('Y-m-d');
    $eintraege = [];
    foreach (hs_angebote_alle() as $a) {
        $geaendert = substr(hs_ang_text(hs_ang_wert($a, 'updated_at')), 0, 10);
        $eintraege[] = ['url' => $a['url'], 'lastmod' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $geaendert) ? $geaendert : $stand];
    }
    return $eintraege;
}

/** Der Abschnitt „Aktuelle Angebote“ in llms.txt (ohne die Überschrift). */
function hs_angebote_llms(): string {
    $stand = hs_angebote_stand()->format('d.m.Y');
    $angebote = hs_angebote_alle();
    if (!$angebote) {
        return "Stand {$stand}: Zurzeit sind keine Angebote veröffentlicht.";
    }
    $zeilen = ["Stand {$stand}, alle Angaben ohne Gewähr. Jede Seite nennt Eckdaten, Energieausweis, Beschreibung, "
        . 'Ausstattung und Lage; die genaue Adresse nennt HeydenSattler auf Anfrage.', ''];
    foreach ($angebote as $a) {
        $fakten = array_filter([
            hs_ang_strip("{$a['plz']} {$a['ort']}"),
            $a['flaeche'] ? hs_ang_flaeche($a['flaeche'], 1) : '',
            $a['zimmer'] && $a['wohnen'] ? hs_ang_zimmer_text($a['zimmer']) . ' Zimmer' : '',
            $a['preis'] ? "{$a['preis_label']} {$a['preis_text']}" : $a['preis_text'],
        ], function ($teil) {
            return $teil !== '';
        });
        $zeilen[] = "- [{$a['name']}]({$a['url']}): {$a['titel']} (" . implode(', ', $fakten) . "; Objekt-Nr. {$a['nr']})";
    }
    return implode("\n", $zeilen);
}
