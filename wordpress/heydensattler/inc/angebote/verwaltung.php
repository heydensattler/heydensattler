<?php
// Angebote: Einstellungen → Angebote. Schlüssel und Filter für Propstack, „Jetzt aktualisieren“, das Protokoll des
// letzten Laufs und die Diagnose. Jede Einstellung lässt sich in wp-config.php festlegen (HS_ANG_KONSTANTEN);
// dann steht sie hier nur zur Ansicht. Der Schlüssel wird nie angezeigt.

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_options_page('Angebote aus Propstack', 'Angebote', 'manage_options', 'hs-angebote', 'hs_angebote_seite');
});

add_action('admin_init', function () {
    register_setting('hs_propstack_einstellungen', 'hs_propstack', [
        'type' => 'array', 'sanitize_callback' => 'hs_propstack_bereinigen', 'show_in_rest' => false,
    ]);
    // Der Schlüssel soll nicht bei jedem Seitenaufruf mitgeladen werden
    if (get_option('hs_propstack', false) === false) {
        add_option('hs_propstack', [], '', 'no');
    }
});

/** Prüft die Eingaben; ein leeres Schlüsselfeld behält den hinterlegten Schlüssel. */
function hs_propstack_bereinigen($eingabe): array {
    $neu = get_option('hs_propstack', []);
    $neu = is_array($neu) ? $neu : [];
    $eingabe = is_array($eingabe) ? $eingabe : [];
    if (!empty($eingabe['schluessel_entfernen'])) {
        $neu['schluessel'] = '';
    } elseif (isset($eingabe['schluessel']) && trim((string) $eingabe['schluessel']) !== '') {
        $neu['schluessel'] = preg_replace('/\s+/', '', sanitize_text_field((string) $eingabe['schluessel']));
    }
    if (isset($eingabe['version'])) {
        $neu['version'] = (string) $eingabe['version'] === '2' ? '2' : '1';
    }
    if (isset($eingabe['portal'])) {
        $portal = trim(sanitize_text_field((string) $eingabe['portal']));
        if ($portal === '' || ctype_digit($portal)) {
            $neu['portal'] = $portal;
        } else {
            add_settings_error('hs_propstack', 'portal', 'Die Portal-ID besteht nur aus Ziffern; sie wurde nicht geändert.');
        }
    }
    if (isset($eingabe['status'])) {
        $neu['status'] = trim(sanitize_text_field((string) $eingabe['status']));
    }
    if (isset($eingabe['max'])) {
        $max = trim(sanitize_text_field((string) $eingabe['max']));
        if ($max === '' || (ctype_digit($max) && (int) $max > 0)) {
            $neu['max'] = $max;
        } else {
            add_settings_error('hs_propstack', 'max', 'Die Höchstzahl muss eine ganze Zahl größer als 0 sein; sie wurde nicht geändert.');
        }
    }
    return $neu;
}

add_action('admin_post_hs_angebote_aktualisieren', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Dafür fehlt die Berechtigung.', '', ['response' => 403]);
    }
    check_admin_referer('hs_angebote_aktualisieren');
    $ergebnis = hs_angebote_aktualisieren();
    wp_safe_redirect(add_query_arg(['page' => 'hs-angebote', 'hs_meldung' => $ergebnis === null ? 'gesperrt' : 'lauf'],
        admin_url('options-general.php')));
    exit;
});

add_action('admin_post_hs_angebote_diagnose', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Dafür fehlt die Berechtigung.', '', ['response' => 403]);
    }
    check_admin_referer('hs_angebote_diagnose');
    hs_angebote_diagnose();
    wp_safe_redirect(add_query_arg(['page' => 'hs-angebote', 'hs_meldung' => 'diagnose'], admin_url('options-general.php')) . '#diagnose');
    exit;
});

function hs_ang_zeitpunkt(int $zeit): string {
    return wp_date('d.m.Y, H:i', $zeit) . ' Uhr';
}

function hs_ang_protokoll_ausgeben(array $protokoll): void {
    $vorsatz = ['hinweis' => 'Hinweis: ', 'warnung' => 'Warnung: ', 'fehler' => 'Fehler: '];
    echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:12px 14px;max-height:420px;overflow:auto">';
    foreach ($protokoll as $zeile) {
        if (is_array($zeile)) {
            echo esc_html(($vorsatz[$zeile['art'] ?? ''] ?? '') . ($zeile['text'] ?? '')) . "\n";
        }
    }
    echo '</pre>';
}

function hs_ang_meldung(string $art, string $text): void {
    echo '<div class="notice notice-' . esc_attr($art) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
}

/** Eine Zeile der Einstellungen; ist die Konstante gesetzt, nur zur Ansicht. */
function hs_ang_feld(string $name, string $beschriftung, string $beschreibung, callable $eingabe): void {
    $konstante = HS_ANG_KONSTANTEN[$name];
    $fest = defined($konstante);
    echo '<tr><th scope="row"><label for="hs-propstack-' . esc_attr($name) . '">' . esc_html($beschriftung) . '</label></th><td>';
    $eingabe($fest);
    if ($fest) {
        echo '<p class="description">Festgelegt in wp-config.php (<code>' . esc_html($konstante) . '</code>).</p>';
    }
    echo '<p class="description">' . esc_html($beschreibung) . '</p></td></tr>';
}

function hs_angebote_seite(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $lauf = get_option('hs_angebote_lauf');
    $diagnose = get_option('hs_angebote_diagnose');
    $daten = hs_angebote_daten();
    $angebote = hs_angebote_alle();
    $beispiel = hs_angebote_beispieldatei();
    $meldung = sanitize_key($_GET['hs_meldung'] ?? '');
    ?>
<div class="wrap">
  <h1>Angebote aus Propstack</h1>
<?php
    if ($meldung === 'gesperrt') {
        hs_ang_meldung('warning', 'Gerade läuft schon ein Lauf. Bitte in ein paar Minuten noch einmal versuchen.');
    } elseif ($meldung === 'lauf' && is_array($lauf)) {
        if (!empty($lauf['erfolg'])) {
            hs_ang_meldung('success', 'Angebote aktualisiert: ' . ($lauf['meldung'] ?? ''));
        } else {
            hs_ang_meldung('error', 'Die Angebote konnten nicht geladen werden: ' . ($lauf['meldung'] ?? '')
                . ' Die zuletzt geladenen Angebote bleiben online.');
        }
    } elseif ($meldung === 'diagnose' && is_array($diagnose)) {
        hs_ang_meldung(!empty($diagnose['erfolg']) ? 'success' : 'error',
            !empty($diagnose['erfolg']) ? 'Diagnose abgeschlossen; das Ergebnis steht unten.' : 'Die Diagnose ist fehlgeschlagen; Einzelheiten stehen unten.');
    }
    ?>
  <p>Die Website lädt die Angebote alle 30 Minuten aus Propstack und zeigt sie auf der Startseite (Abschnitt
    „Angebote“) und je auf einer eigenen Seite unter <code>/angebote/…/</code>. Die Adresse einer Immobilie ändert sich
    nicht, wenn sich der Preis ändert; ein verkauftes Objekt verschwindet beim nächsten Lauf, alte Links zeigen dann
    „Dieses Angebot ist nicht mehr verfügbar“. Ist Propstack nicht erreichbar oder schlägt ein Lauf fehl, bleiben die
    zuletzt geladenen Angebote online.</p>
  <p>Nie auf der Website: als privat markierte Bilder, Straße, Hausnummer und Koordinaten (nur PLZ und Ort),
    Eigentümer-, Makler- und Kontaktdaten. „Preis auf Anfrage“ steht da, wenn in Propstack „Preis auf Anfrage“ gesetzt
    oder „Preis im Exposé anzeigen“ ausgeschaltet ist.</p>
<?php if ($beispiel !== '') : ?>
  <div class="notice notice-info inline"><p>Beispielmodus: Die Angebote kommen aus <code><?= esc_html($beispiel) ?></code>
    (Konstante <code>HS_ANGEBOTE_BEISPIEL</code>), nicht aus Propstack.</p></div>
<?php endif; ?>

  <h2>Einstellungen</h2>
  <form method="post" action="options.php">
<?php settings_fields('hs_propstack_einstellungen'); ?>
    <table class="form-table" role="presentation">
<?php
    $hinterlegt = hs_propstack_einstellung('schluessel') !== '';
    hs_ang_feld('schluessel', 'Propstack-Schlüssel',
        'API-Schlüssel aus Propstack, am besten nur lesend. Er wird hier nie angezeigt.',
        function (bool $fest) use ($hinterlegt) {
            if ($fest) {
                echo '<p>' . ($hinterlegt ? 'Ein Schlüssel ist hinterlegt.' : 'Die Konstante ist leer.') . '</p>';
                return;
            }
            echo '<input type="password" id="hs-propstack-schluessel" name="hs_propstack[schluessel]" value="" class="regular-text" autocomplete="new-password" placeholder="'
                . esc_attr($hinterlegt ? 'hinterlegt; leer lassen, um ihn zu behalten' : 'nicht hinterlegt') . '">';
            if ($hinterlegt) {
                echo '<p><label><input type="checkbox" name="hs_propstack[schluessel_entfernen]" value="1"> Schlüssel entfernen</label></p>';
            }
        });
    hs_ang_feld('version', 'API-Version',
        'Standard ist V1. Mit V2 und einem V2-Schlüssel stehen Name und Rechte des Schlüssels im Protokoll, mit einer '
        . 'Warnung, wenn er schreiben oder Kontakte lesen darf; nur V2 kann nach Portal filtern.',
        function (bool $fest) {
            $version = hs_propstack_version() === '2' ? '2' : '1';
            echo '<select id="hs-propstack-version" name="hs_propstack[version]"' . ($fest ? ' disabled' : '') . '>';
            foreach (['1' => 'V1', '2' => 'V2'] as $wert => $name) {
                echo '<option value="' . esc_attr($wert) . '"' . selected($version, $wert, false) . '>' . esc_html($name) . '</option>';
            }
            echo '</select>';
        });
    hs_ang_feld('portal', 'Portal-ID',
        'Nur Objekte, die in Propstack auf diesem Portal veröffentlicht sind, und davon nur die Bilder, die für dieses '
        . 'Portal freigegeben sind. Dafür die Website in Propstack als eigenes Portal anlegen und Objekte dort wie bei '
        . 'ImmoScout freigeben. Geht nur mit API V2; ist die ID falsch, nennt das Protokoll die vorhandenen Portale. '
        . 'Leer: Filter nach Status.',
        function (bool $fest) {
            echo '<input type="text" id="hs-propstack-portal" name="hs_propstack[portal]" value="'
                . esc_attr(hs_propstack_einstellung('portal')) . '" class="small-text" inputmode="numeric"' . ($fest ? ' disabled' : '') . '>';
        });
    hs_ang_feld('status', 'Status',
        'Ohne Portal-ID erscheinen alle nicht archivierten Objekte in diesen Status (Namen wie in Propstack, '
        . 'kommagetrennt). Leer: „' . HS_ANG_STATUS_STANDARD . '“.',
        function (bool $fest) {
            echo '<input type="text" id="hs-propstack-status" name="hs_propstack[status]" value="'
                . esc_attr(hs_propstack_einstellung('status')) . '" class="regular-text" placeholder="' . esc_attr(HS_ANG_STATUS_STANDARD) . '"'
                . ($fest ? ' disabled' : '') . '>';
        });
    hs_ang_feld('max', 'Höchstzahl',
        'Sicherheitsgrenze: Würden mehr Objekte erscheinen, bricht der Lauf ab und die bisherigen Angebote bleiben online. Leer: 40.',
        function (bool $fest) {
            echo '<input type="text" id="hs-propstack-max" name="hs_propstack[max]" value="'
                . esc_attr(hs_propstack_einstellung('max')) . '" class="small-text" inputmode="numeric" placeholder="40"' . ($fest ? ' disabled' : '') . '>';
        });
    ?>
    </table>
<?php submit_button('Einstellungen speichern'); ?>
  </form>
  <p>Nach dem Speichern lädt der nächste Lauf mit den neuen Einstellungen; sofort geht es mit „Jetzt aktualisieren“.</p>

  <h2>Laden</h2>
<?php
    $naechster = wp_next_scheduled('hs_angebote_laden');
    echo '<p>';
    if (is_array($lauf)) {
        echo 'Letzter Lauf: ' . esc_html(hs_ang_zeitpunkt((int) $lauf['zeit'])) . ', '
            . (!empty($lauf['erfolg']) ? 'erfolgreich' : '<strong>fehlgeschlagen</strong>') . '. ';
    } else {
        echo 'Noch kein Lauf. ';
    }
    if ($naechster) {
        echo 'Nächster Lauf: ' . esc_html(hs_ang_zeitpunkt((int) $naechster)) . '.';
    }
    echo '</p>';
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        echo '<p>WP-Cron ist abgeschaltet (<code>DISABLE_WP_CRON</code>): Die Angebote laden nur, wenn ein Cronjob des '
            . 'Servers <code>wp-cron.php</code> aufruft.</p>';
    } else {
        echo '<p>WP-Cron läuft bei Besuchen der Website. Kommen selten Besucher, lädt ein Lauf entsprechend später; '
            . 'zuverlässiger ist ein Cronjob des Servers, der alle paar Minuten <code>wp-cron.php</code> aufruft.</p>';
    }
    ?>
  <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
    <input type="hidden" name="action" value="hs_angebote_aktualisieren">
<?php wp_nonce_field('hs_angebote_aktualisieren'); ?>
<?php submit_button('Jetzt aktualisieren', 'secondary', 'submit', false); ?>
  </form>
<?php
    if (is_array($lauf) && !empty($lauf['protokoll']) && is_array($lauf['protokoll'])) {
        echo '<h3>Protokoll des letzten Laufs</h3>';
        hs_ang_protokoll_ausgeben($lauf['protokoll']);
    }
    ?>

  <h2>Auf der Website</h2>
<?php
    if ($daten === null) {
        echo '<p>Noch keine Angebote geladen. Die Startseite zeigt „Zurzeit sind keine Angebote veröffentlicht“.</p>';
    } else {
        echo '<p>Stand ' . esc_html(hs_angebote_stand()->format('d.m.Y')) . ' (geladen ' . esc_html(hs_ang_zeitpunkt((int) ($daten['geladen'] ?? 0)))
            . '): ' . esc_html(count($angebote) === 1 ? '1 Angebot' : count($angebote) . ' Angebote') . '.</p>';
        if ($angebote) {
            echo '<ul>';
            foreach ($angebote as $a) {
                echo '<li><a href="' . esc_url($a['url']) . '">' . esc_html($a['name']) . '</a> (Objekt-Nr. ' . esc_html($a['nr']) . ', '
                    . esc_html($a['preis_text']) . ', ' . esc_html((string) count($a['bilder'])) . ' Bilder)</li>';
            }
            echo '</ul>';
        }
    }
    ?>

  <h2 id="diagnose">Diagnose</h2>
  <p>Zeigt je Propstack-Status, wie viele Objekte es gibt, wie viele davon archiviert sind, ein öffentliches Bild und
    eine Objekt-Nr. haben; dazu die Felder, die nach Freigabe oder Gruppen klingen. Nur Namen, IDs und Anzahlen, keine
    Adressen, Preise oder Kontakte. Hilft bei der Wahl des Filters, schreibt nichts und nutzt die API V1.</p>
  <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
    <input type="hidden" name="action" value="hs_angebote_diagnose">
<?php wp_nonce_field('hs_angebote_diagnose'); ?>
<?php submit_button('Diagnose starten', 'secondary', 'submit', false); ?>
  </form>
<?php
    if (is_array($diagnose) && !empty($diagnose['protokoll']) && is_array($diagnose['protokoll'])) {
        echo '<h3>Ergebnis vom ' . esc_html(hs_ang_zeitpunkt((int) $diagnose['zeit'])) . '</h3>';
        hs_ang_protokoll_ausgeben($diagnose['protokoll']);
    }
    ?>
</div>
<?php
}
