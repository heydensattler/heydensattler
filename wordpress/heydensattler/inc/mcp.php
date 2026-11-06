<?php
// Fähigkeiten (Abilities API, seit WordPress 6.9) für Claude über das Plugin „MCP Adapter“: Beiträge auflisten,
// lesen, anlegen, bearbeiten und veröffentlichen, Bilder hochladen. Nichts löschen, keine Seiten, keine
// Einstellungen. Was erlaubt ist, entscheidet die Rolle des angemeldeten Benutzers (für Claude: „Autor“);
// jede Fähigkeit prüft die passenden Rechte selbst. Ohne Abilities API oder ohne MCP Adapter passiert nichts.

defined('ABSPATH') || exit;

const HS_MCP_STATUS = ['draft', 'pending', 'publish', 'future'];
const HS_MCP_BILDTYPEN = ['image/jpeg', 'image/png', 'image/webp'];
// Größte Bilddatei in Byte (vor base64)
const HS_MCP_BILD_MAX = 10 * 1024 * 1024;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('heydensattler', [
        'label' => 'HeydenSattler',
        'description' => 'Blogbeiträge und Bilder der Website heydensattler.com.',
    ]);
});

add_action('wp_abilities_api_init', function () {
    $sichtbar = ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']];
    $autoren = array_merge([''], array_keys(HS_AUTOREN));
    $autoren_text = 'Autor über dem Beitrag: ' . implode(', ', array_map(
        fn($k) => "„{$k}“ (" . HS_AUTOREN[$k]['name'] . ')', array_keys(HS_AUTOREN))) . ' oder leer für HeydenSattler.';
    $beitrag_schema = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'], 'titel' => ['type' => 'string'], 'status' => ['type' => 'string'],
            'datum' => ['type' => 'string'], 'link' => ['type' => 'string'], 'slug' => ['type' => 'string'],
            'autor' => ['type' => 'string'], 'auszug' => ['type' => 'string'], 'inhalt' => ['type' => 'string'],
            'bild_id' => ['type' => 'integer'], 'bild_url' => ['type' => 'string'],
        ],
    ];

    wp_register_ability('heydensattler/beitraege-auflisten', [
        'label' => 'Blogbeiträge auflisten',
        'description' => 'Listet Blogbeiträge mit ID, Titel, Status, Datum und Link, die neuesten zuerst. Ohne Inhalt; '
            . 'den liefert heydensattler/beitrag-lesen.',
        'category' => 'heydensattler',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => array_merge(['any'], HS_MCP_STATUS), 'default' => 'any',
                    'description' => 'publish = veröffentlicht, draft = Entwurf, pending = zur Prüfung, future = geplant'],
                'suche' => ['type' => 'string', 'description' => 'Suchbegriff in Titel und Text'],
                'anzahl' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'array', 'items' => $beitrag_schema],
        'execute_callback' => function ($eingabe = []) {
            $eingabe = (array) $eingabe;
            $status = $eingabe['status'] ?? 'any';
            $beitraege = get_posts([
                'post_type' => 'post',
                'post_status' => $status === 'any' ? HS_MCP_STATUS : [$status],
                's' => (string) ($eingabe['suche'] ?? ''),
                'numberposts' => (int) ($eingabe['anzahl'] ?? 20),
                'orderby' => 'date', 'order' => 'DESC',
                'perm' => 'editable',
            ]);
            return array_map(fn($b) => hs_mcp_beitrag($b, false), $beitraege);
        },
        'permission_callback' => fn() => current_user_can('edit_posts'),
        'meta' => $sichtbar + ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('heydensattler/beitrag-lesen', [
        'label' => 'Blogbeitrag lesen',
        'description' => 'Liefert einen Blogbeitrag mit Inhalt (HTML wie im Editor gespeichert), Auszug, Autor, '
            . 'Beitragsbild und Status.',
        'category' => 'heydensattler',
        'input_schema' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
        'output_schema' => $beitrag_schema,
        'execute_callback' => function ($eingabe) {
            $beitrag = get_post((int) ((array) $eingabe)['id']);
            if (!$beitrag || $beitrag->post_type !== 'post') {
                return new WP_Error('hs_kein_beitrag', 'Diesen Beitrag gibt es nicht.');
            }
            return hs_mcp_beitrag($beitrag, true);
        },
        'permission_callback' => fn($eingabe = []) => current_user_can('edit_post', (int) (((array) $eingabe)['id'] ?? 0)),
        'meta' => $sichtbar + ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    wp_register_ability('heydensattler/beitrag-speichern', [
        'label' => 'Blogbeitrag anlegen oder ändern',
        'description' => 'Legt einen Blogbeitrag an (ohne id) oder ändert einen bestehenden (mit id). Nur übergebene '
            . 'Felder ändern sich. Inhalt als HTML: Absätze <p>, Zwischenüberschriften ab <h2>, Listen, Links, '
            . 'Tabellen; keine <h1>, kein Inline-CSS. Der Auszug ist die Beschreibung für Suchmaschinen und die '
            . 'Blogkarte (etwa 150 Zeichen). status: draft (Entwurf, Standard), pending, publish (sofort online) oder '
            . 'future (geplant, mit datum). ' . $autoren_text,
        'category' => 'heydensattler',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Nur zum Ändern eines Beitrags'],
                'titel' => ['type' => 'string'],
                'inhalt' => ['type' => 'string', 'description' => 'HTML des Beitrags'],
                'auszug' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'description' => 'Adresse unter /blog/…/, sonst aus dem Titel'],
                'status' => ['type' => 'string', 'enum' => HS_MCP_STATUS],
                'datum' => ['type' => 'string', 'description' => 'Veröffentlichungsdatum, z. B. 2026-11-20T09:00:00 (Ortszeit)'],
                'autor' => ['type' => 'string', 'enum' => $autoren],
                'bild_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Beitragsbild (ID aus heydensattler/bild-hochladen), 0 entfernt es'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => $beitrag_schema,
        'execute_callback' => function ($eingabe) {
            $eingabe = (array) $eingabe;
            $id = (int) ($eingabe['id'] ?? 0);
            if ($id && (!($alt = get_post($id)) || $alt->post_type !== 'post')) {
                return new WP_Error('hs_kein_beitrag', 'Diesen Beitrag gibt es nicht.');
            }
            if (!$id && trim((string) ($eingabe['titel'] ?? '')) === '') {
                return new WP_Error('hs_titel_fehlt', 'Ein neuer Beitrag braucht einen Titel.');
            }
            $daten = ['post_type' => 'post'];
            $felder = ['titel' => 'post_title', 'inhalt' => 'post_content', 'auszug' => 'post_excerpt',
                'slug' => 'post_name', 'status' => 'post_status'];
            foreach ($felder as $feld => $spalte) {
                if (array_key_exists($feld, $eingabe)) {
                    $daten[$spalte] = (string) $eingabe[$feld];
                }
            }
            if (!$id && !isset($daten['post_status'])) {
                $daten['post_status'] = 'draft';
            }
            if (isset($eingabe['datum'])) {
                $datum = date_create_immutable((string) $eingabe['datum'], wp_timezone());
                if (!$datum) {
                    return new WP_Error('hs_datum', 'Das Datum lässt sich nicht lesen.');
                }
                $daten['post_date'] = $datum->format('Y-m-d H:i:s');
                $daten['post_date_gmt'] = get_gmt_from_date($daten['post_date']);
                $daten['edit_date'] = true;
            }
            if (($daten['post_status'] ?? '') === 'future' && empty($daten['post_date'])) {
                return new WP_Error('hs_datum_fehlt', 'Ein geplanter Beitrag braucht ein Datum.');
            }
            if ($id) {
                $daten['ID'] = $id;
            } else {
                $daten['post_author'] = get_current_user_id();
            }
            $ergebnis = $id ? wp_update_post(wp_slash($daten), true) : wp_insert_post(wp_slash($daten), true);
            if (is_wp_error($ergebnis)) {
                return $ergebnis;
            }
            if (array_key_exists('autor', $eingabe)) {
                update_post_meta($ergebnis, 'hs_autor', array_key_exists($eingabe['autor'], HS_AUTOREN) ? $eingabe['autor'] : '');
            }
            if (array_key_exists('bild_id', $eingabe)) {
                $bild = (int) $eingabe['bild_id'];
                if ($bild === 0) {
                    delete_post_thumbnail($ergebnis);
                } elseif (wp_attachment_is_image($bild)) {
                    set_post_thumbnail($ergebnis, $bild);
                } else {
                    return new WP_Error('hs_kein_bild', 'bild_id ist kein Bild in der Mediathek.');
                }
            }
            return hs_mcp_beitrag(get_post($ergebnis), true);
        },
        'permission_callback' => function ($eingabe = []) {
            $eingabe = (array) $eingabe;
            $id = (int) ($eingabe['id'] ?? 0);
            if ($id ? !current_user_can('edit_post', $id) : !current_user_can('edit_posts')) {
                return false;
            }
            // Veröffentlichen und Planen nur mit dem Recht dazu
            $status = $eingabe['status'] ?? '';
            return !in_array($status, ['publish', 'future'], true) || current_user_can('publish_posts');
        },
        'meta' => $sichtbar + ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);

    wp_register_ability('heydensattler/bild-hochladen', [
        'label' => 'Bild hochladen',
        'description' => 'Lädt ein Bild (JPEG, PNG oder WebP, höchstens 10 MB) base64-kodiert in die Mediathek und '
            . 'liefert ID und Adresse. Die ID taugt als bild_id für heydensattler/beitrag-speichern; die Adresse für '
            . '<img> im Inhalt. alt beschreibt das Bild für Screenreader und Suchmaschinen.',
        'category' => 'heydensattler',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'dateiname' => ['type' => 'string', 'description' => 'z. B. wohnungsuebergabe.jpg'],
                'daten' => ['type' => 'string', 'description' => 'Inhalt der Datei, base64'],
                'alt' => ['type' => 'string'],
                'beitrag_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Beitrag, zu dem das Bild gehört'],
            ],
            'required' => ['dateiname', 'daten', 'alt'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer'], 'url' => ['type' => 'string']],
        ],
        'execute_callback' => function ($eingabe) {
            $eingabe = (array) $eingabe;
            $inhalt = base64_decode(preg_replace('/\s+/', '', (string) $eingabe['daten']), true);
            if ($inhalt === false || $inhalt === '') {
                return new WP_Error('hs_base64', 'daten ist kein gültiges base64.');
            }
            if (strlen($inhalt) > HS_MCP_BILD_MAX) {
                return new WP_Error('hs_zu_gross', 'Das Bild ist größer als 10 MB.');
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $name = sanitize_file_name(wp_basename((string) $eingabe['dateiname']));
            $datei = wp_tempnam($name);
            file_put_contents($datei, $inhalt);
            $typ = wp_check_filetype_and_ext($datei, $name);
            if (!in_array($typ['type'] ?: '', HS_MCP_BILDTYPEN, true)) {
                wp_delete_file($datei);
                return new WP_Error('hs_bildtyp', 'Nur JPEG, PNG oder WebP, und die Endung muss zum Inhalt passen.');
            }
            $beitrag = (int) ($eingabe['beitrag_id'] ?? 0);
            $id = media_handle_sideload(['name' => $typ['proper_filename'] ?: $name, 'tmp_name' => $datei], $beitrag);
            if (is_wp_error($id)) {
                wp_delete_file($datei);
                return $id;
            }
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $eingabe['alt']));
            return ['id' => $id, 'url' => (string) wp_get_attachment_url($id)];
        },
        'permission_callback' => function ($eingabe = []) {
            $beitrag = (int) (((array) $eingabe)['beitrag_id'] ?? 0);
            return current_user_can('upload_files') && (!$beitrag || current_user_can('edit_post', $beitrag));
        },
        'meta' => $sichtbar + ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
    ]);
});

/** Ein Beitrag für die Fähigkeiten oben; mit $voll auch Inhalt und Auszug. */
function hs_mcp_beitrag(WP_Post $beitrag, bool $voll): array {
    $bild_id = (int) get_post_thumbnail_id($beitrag);
    $daten = [
        'id' => $beitrag->ID,
        'titel' => $beitrag->post_title,
        'status' => $beitrag->post_status,
        'datum' => ($datum = get_post_datetime($beitrag)) ? $datum->format('c') : '',
        'link' => $beitrag->post_status === 'publish' ? (string) get_permalink($beitrag) : (string) get_preview_post_link($beitrag),
        'slug' => $beitrag->post_name,
        'autor' => (string) get_post_meta($beitrag->ID, 'hs_autor', true),
        'bild_id' => $bild_id,
        'bild_url' => $bild_id ? (string) wp_get_attachment_url($bild_id) : '',
    ];
    if ($voll) {
        $daten['auszug'] = $beitrag->post_excerpt;
        $daten['inhalt'] = $beitrag->post_content;
    }
    return $daten;
}
