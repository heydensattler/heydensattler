<?php
// Einrichtung: Das Theme stellt sich nach jedem Hochladen selbst ein (fehlende Adressregeln neu einlesen), und im
// Admin zeigt ein Hinweis, welche Einstellung der Website noch fehlt, mit einem Knopf, der sie setzt.

defined('ABSPATH') || exit;

const HS_PERMALINKS = '/immobilienwissen/%postname%/';
// Bis 07.11.2026 hieß der Bereich „Blog“ unter /blog/. Steht die Website noch so, stellt das Theme sie einmal um.
const HS_PERMALINKS_ALT = '/blog/%postname%/';

/** Eine Adressregel des Themes (oben in der Liste). Sie wird gemerkt, damit die Prüfung unten sie findet. */
function hs_regel(string $muster, string $ziel): void {
    $GLOBALS['hs_regeln'][$muster] = $ziel;
    add_rewrite_rule($muster, $ziel, 'top');
}

// Fehlt eine Regel des Themes in den gespeicherten Regeln oder lautet sie anders (Theme neu hochgeladen, eine
// Regel geändert, Theme gerade aktiviert), liest WordPress alle Regeln einmal neu ein. Nach allen init-Hooks, damit
// auch die Regeln von WordPress und den Plugins dabei sind. Nicht in der Vorschau des Customizers: Dort ist das Theme
// noch gar nicht aktiv.
add_action('wp_loaded', function () {
    if (!get_option('permalink_structure') || is_customize_preview() || wp_installing()) {
        return;
    }
    if (get_option('permalink_structure') === HS_PERMALINKS_ALT) {
        hs_einrichten();
        // Dieser Aufruf hat die Regeln schon mit der alten Struktur angemeldet und baut sie womöglich gleich damit
        // neu auf; am Ende darum noch einmal leeren, damit der nächste Aufruf sie mit /immobilienwissen/ aufbaut
        add_action('shutdown', function () {
            update_option('rewrite_rules', '');
        });
        return;
    }
    $gespeichert = get_option('rewrite_rules');
    if (!is_array($gespeichert)) {
        // Leer: WordPress baut die Regeln bei diesem Aufruf selbst neu auf
        return;
    }
    foreach ($GLOBALS['hs_regeln'] ?? [] as $muster => $ziel) {
        if (($gespeichert[$muster] ?? null) !== $ziel) {
            flush_rewrite_rules(false);
            return;
        }
    }
});

/** Was an den Einstellungen noch nicht passt, als lesbare Sätze. */
function hs_einrichtung_offen(): array {
    $offen = [];
    if (!extension_loaded('mbstring')) {
        $offen[] = 'Dem PHP des Servers fehlt die Erweiterung mbstring; ohne sie gehen Angebote und Immobilienwissen nicht.';
    }
    $blog = get_page_by_path(HS_BLOG_PFAD);
    $start = (int) get_option('page_on_front');
    if (get_option('show_on_front') !== 'page' || !$start) {
        $offen[] = 'Die Startseite ist keine feste Seite (Einstellungen → Lesen).';
    } elseif (get_post_status($start) !== 'publish') {
        $offen[] = 'Die Startseite (Seite „' . get_the_title($start) . '“) ist nicht veröffentlicht (Seiten).';
    }
    if (!$blog || (int) get_option('page_for_posts') !== $blog->ID) {
        $offen[] = 'Die Übersicht „' . HS_BLOG_NAME . '“ ist nicht die Seite „' . HS_BLOG_PFAD . '“ (Einstellungen → Lesen → Beitragsseite).';
    }
    if (get_option('permalink_structure') !== HS_PERMALINKS) {
        $offen[] = 'Beiträge haben nicht die Adresse /' . HS_BLOG_PFAD . '/beitragsname/ (Einstellungen → Permalinks).';
    }
    foreach ([HS_BLOG_PFAD => HS_BLOG_NAME, 'leistungen' => 'Leistungen', 'leistungen/vermieten' => 'Vermieten', 'leistungen/verkaufen' => 'Verkaufen'] as $pfad => $name) {
        $seite = get_page_by_path($pfad);
        if ($seite && $seite->post_status !== 'publish') {
            $offen[] = "Die Seite „{$name}“ unter /{$pfad}/ ist nicht veröffentlicht (Seiten → {$name}).";
        } elseif (!$seite && $pfad !== HS_BLOG_PFAD) {
            $offen[] = "Die Seite „{$name}“ unter /{$pfad}/ fehlt.";
        }
    }
    return $offen;
}

/**
 * Fehlende Seiten anlegen und die Einstellungen setzen. Bestehende Seiten bleiben, wie sie sind, auch wenn sie
 * Entwurf oder privat sind (der Hinweis nennt sie dann).
 */
function hs_einrichten(): void {
    $seite = function (string $pfad, string $titel, int $eltern = 0): int {
        $vorhanden = get_page_by_path($pfad);
        if ($vorhanden) {
            return $vorhanden->ID;
        }
        return (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $titel,
            'post_name' => basename($pfad), 'post_parent' => $eltern]);
    };

    $start = (int) get_option('page_on_front') ?: $seite('startseite', 'Startseite');
    // Die bisherige Beitragsseite „blog“ bekommt den neuen Namen und die neue Adresse, statt eine zweite anzulegen
    $alt = get_page_by_path('blog');
    if ($alt && !get_page_by_path(HS_BLOG_PFAD) && (int) get_option('page_for_posts') === $alt->ID) {
        wp_update_post(['ID' => $alt->ID, 'post_name' => HS_BLOG_PFAD, 'post_title' => HS_BLOG_NAME]);
        clean_post_cache($alt->ID);
    }
    $blog = $seite(HS_BLOG_PFAD, HS_BLOG_NAME);
    $leistungen = $seite('leistungen', 'Leistungen');
    $seite('leistungen/vermieten', 'Vermieten', $leistungen);
    $seite('leistungen/verkaufen', 'Verkaufen', $leistungen);

    update_option('show_on_front', 'page');
    update_option('page_on_front', $start);
    update_option('page_for_posts', $blog);
    if (get_option('permalink_structure') !== HS_PERMALINKS) {
        update_option('permalink_structure', HS_PERMALINKS);
        // Nicht in diesem Aufruf neu einlesen: Taxonomien, Plugins (z. B. WooCommerce) und das Theme haben ihre
        // Regeln schon mit der alten Struktur angemeldet. Leer gespeichert, baut der nächste Aufruf alle neu auf.
        update_option('rewrite_rules', '');
    }
}

add_action('admin_post_hs_einrichten', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.', 403);
    }
    check_admin_referer('hs_einrichten');
    hs_einrichten();
    wp_safe_redirect(add_query_arg('hs_eingerichtet', '1', wp_get_referer() ?: admin_url()));
    exit;
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $offen = hs_einrichtung_offen();
    if (isset($_GET['hs_eingerichtet']) && !$offen) {
        echo '<div class="notice notice-success is-dismissible"><p>HeydenSattler-Theme: Die Website ist eingerichtet.</p></div>';
        return;
    }
    if (!$offen) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>HeydenSattler-Theme: Die Website ist noch nicht ganz eingerichtet.</strong></p><ul style="list-style:disc;padding-left:20px">';
    foreach ($offen as $satz) {
        echo '<li>' . esc_html($satz) . '</li>';
    }
    echo '</ul><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('hs_einrichten');
    echo '<input type="hidden" name="action" value="hs_einrichten"><p>';
    submit_button('Jetzt einrichten', 'primary', 'submit', false);
    echo ' Legt fehlende Seiten an (Startseite, Immobilienwissen, Leistungen mit Vermieten und Verkaufen), stellt Start- und Beitragsseite ein und setzt die Beitragsadressen auf /immobilienwissen/beitragsname/. Bestehende Seiten und Beiträge bleiben unverändert.</p></form></div>';
});
