<?php
// Angebote aus Propstack, wie bisher scripts/angebote.py: die Liste auf der Startseite (#angebote), eine Detailseite
// pro Immobilie unter /angebote/<slug>/ und die Einträge in sitemap.xml und llms.txt.
//
// - inc/angebote/werte.php: Werte aus Propstack lesen, deutsche Formate, Übersetzungen
// - inc/angebote/laden.php: Laden aus Propstack (WP-Cron alle 30 Minuten), Filter, Bildgrößen, Diagnose
// - inc/angebote/ausgabe.php: Startseite, Detailseite, JSON-LD, sitemap.xml und llms.txt aus dem gespeicherten Stand
// - inc/angebote/verwaltung.php: Einstellungen → Angebote
// - template-parts/angebot.php: Vorlage der Detailseite
//
// Gespeichert wird in den Optionen hs_angebote (der Stand, den die Seiten zeigen), hs_angebote_lauf (Ergebnis und
// Protokoll des letzten Laufs), hs_angebote_diagnose, hs_angebote_bildmasse (bekannte Bildgrößen) und hs_propstack
// (Einstellungen).

defined('ABSPATH') || exit;

require __DIR__ . '/angebote/werte.php';
require __DIR__ . '/angebote/laden.php';
require __DIR__ . '/angebote/ausgabe.php';
if (is_admin()) {
    require __DIR__ . '/angebote/verwaltung.php';
}

// Fehlt die Regel in WordPress oder lautet sie anders, liest inc/einrichtung.php die Regeln neu ein
add_action('init', function () {
    hs_regel('^angebote/([a-z0-9-]+)/?$', 'index.php?hs_angebot=$matches[1]');
});

add_filter('query_vars', function ($variablen) {
    $variablen[] = 'hs_angebot';
    return $variablen;
});

/** Der Slug aus /angebote/<slug>/ in dieser Abfrage (sonst der Hauptabfrage), oder ''. */
function hs_angebot_slug(?WP_Query $abfrage = null): string {
    $slug = $abfrage ? $abfrage->get('hs_angebot') : get_query_var('hs_angebot');
    return is_string($slug) ? $slug : '';
}

// Die Hauptabfrage einer Angebotsseite ist weder Startseite noch Blog und braucht keine Beiträge
add_action('parse_query', function (WP_Query $abfrage) {
    if ($abfrage->is_main_query() && hs_angebot_slug($abfrage) !== '') {
        $abfrage->is_home = false;
    }
});

add_filter('posts_pre_query', function ($beitraege, WP_Query $abfrage) {
    if ($abfrage->is_main_query() && hs_angebot_slug($abfrage) !== '') {
        return [];
    }
    return $beitraege;
}, 10, 2);

// Bekanntes Angebot: Status 200; unbekanntes (z. B. verkauft): 404 mit 404.php („nicht mehr verfügbar“)
add_filter('pre_handle_404', function ($erledigt, WP_Query $abfrage) {
    if (hs_angebot_slug($abfrage) === '') {
        return $erledigt;
    }
    if (hs_angebot_aktuell() === null) {
        $abfrage->set_404();
        status_header(404);
        nocache_headers();
    } else {
        status_header(200);
    }
    return true;
}, 10, 2);

// WordPress soll für /angebote/… keine ähnlich heißende Seite raten
add_filter('redirect_canonical', function ($ziel) {
    return hs_angebot_slug() !== '' ? false : $ziel;
});

// Ohne Schrägstrich am Ende auf die Adresse mit Schrägstrich weiterleiten, wie bei allen anderen Seiten
add_action('template_redirect', function () {
    $a = hs_angebot_aktuell();
    $pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($a !== null && substr($pfad, -1) !== '/') {
        wp_safe_redirect($a['url'], 301);
        exit;
    }
});

add_filter('template_include', function ($vorlage) {
    if (hs_angebot_aktuell() !== null) {
        $eigene = locate_template('template-parts/angebot.php');
        if ($eigene !== '') {
            return $eigene;
        }
    }
    return $vorlage;
});

add_action('wp_enqueue_scripts', function () {
    if (hs_angebot_aktuell() !== null) {
        hs_js('hs-galerie', 'assets/galerie.js');
    }
}, 20);

// Das erste Bild der Galerie vorladen, wie im statischen <head>
add_action('wp_head', function () {
    $a = hs_angebot_aktuell();
    if ($a !== null && $a['bilder']) {
        echo '<link rel="preload" as="image" fetchpriority="high" imagesrcset="' . hs_ang_srcset($a['bilder'][0])
            . '" imagesizes="(min-width: 992px) 740px, 100vw">' . "\n";
    }
}, 6);

// ---------------------------------------------------------------------------------------------------------
// Alle 30 Minuten laden (WP-Cron läuft bei Seitenaufrufen; auf ruhigen Websites besser per echtem Cronjob)

add_filter('cron_schedules', function ($plaene) {
    $plaene['hs_halbstuendlich'] = ['interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Alle 30 Minuten'];
    return $plaene;
});

add_action('hs_angebote_laden', 'hs_angebote_aktualisieren');

add_action('init', function () {
    // In der Vorschau des Customizers ist das Theme noch nicht aktiv
    if (!is_customize_preview() && !wp_installing() && !wp_next_scheduled('hs_angebote_laden')) {
        wp_schedule_event(time(), 'hs_halbstuendlich', 'hs_angebote_laden');
    }
});

// Wird ein anderes Theme aktiviert, nicht weiter laden
add_action('switch_theme', function () {
    wp_clear_scheduled_hook('hs_angebote_laden');
});
