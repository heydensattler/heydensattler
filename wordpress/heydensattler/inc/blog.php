<?php
// Blog: Beiträge sind WordPress-Beiträge. Titel, Auszug (= description), Beitragsbild (= image) und das Feld
// „Autor“ ergeben dieselben Seiten wie bisher scripts/blog.py. Geplante Beiträge und Entwürfe erscheinen nirgends,
// das regelt WordPress selbst.

defined('ABSPATH') || exit;

const HS_TEASER = 3;

const HS_AUTOREN = [
    'cedric-heyden' => [
        'name' => 'Cedric Heyden', 'titel' => 'M. Eng.', 'rolle' => 'Fachmann für Immobilienbewertung und Marketing',
        'ueber' => 'Über den Autor', 'bild' => HS_UPLOADS . '/2022/08/DSC09558b-e1694595155471-251x300.jpg',
    ],
    'anna-heyden' => [
        'name' => 'Anna Heyden', 'titel' => 'M. Eng.', 'rolle' => 'Spezialistin für Vermietung und Verkauf',
        'ueber' => 'Über die Autorin', 'bild' => HS_UPLOADS . '/2023/08/DSC00735-scaled-e1694546678891-251x300.jpg',
    ],
];

const HS_BLOG_BESCHREIBUNG = 'Tipps und Wissenswertes von HeydenSattler zum Verkauf und zur Vermietung von Immobilien in Augsburg und Umgebung.';

// ---------------------------------------------------------------------------------------------------------
// Feld „Autor“ im Editor (Person der Startseite oder leer = HeydenSattler)

add_action('init', function () {
    register_post_meta('post', 'hs_autor', [
        'type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '',
        'sanitize_callback' => fn($wert) => array_key_exists($wert, HS_AUTOREN) ? $wert : '',
        'auth_callback' => fn() => current_user_can('edit_posts'),
    ]);
});

add_action('add_meta_boxes_post', function () {
    add_meta_box('hs-autor', 'Autor', function (WP_Post $beitrag) {
        $wert = get_post_meta($beitrag->ID, 'hs_autor', true);
        wp_nonce_field('hs_autor', 'hs_autor_nonce');
        echo '<select name="hs_autor" style="width:100%"><option value="">HeydenSattler</option>';
        foreach (HS_AUTOREN as $schluessel => $a) {
            printf('<option value="%s"%s>%s</option>', esc_attr($schluessel), selected($wert, $schluessel, false), esc_html($a['name']));
        }
        echo '</select><p class="description">Erscheint über dem Beitrag, mit Bild und Kurzvorstellung darunter.</p>';
    }, null, 'side');
});

add_action('save_post_post', function (int $id) {
    if (!isset($_POST['hs_autor_nonce']) || !wp_verify_nonce(sanitize_key($_POST['hs_autor_nonce']), 'hs_autor')
        || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id)) {
        return;
    }
    $wert = sanitize_key(wp_unslash($_POST['hs_autor'] ?? ''));
    update_post_meta($id, 'hs_autor', array_key_exists($wert, HS_AUTOREN) ? $wert : '');
});

// Auf der Blogübersicht alle Beiträge, wie bisher ohne Seitenzahlen und nach Datum (auch „oben halten“ ändert nichts).
// Beiträge mit Passwort stehen nur unter ihrer eigenen Adresse, in keiner Liste und bei keinem Nachbarn.
add_action('pre_get_posts', function (WP_Query $q) {
    if (is_admin() || !$q->is_main_query()) {
        return;
    }
    if ($q->is_home()) {
        $q->set('posts_per_page', -1);
        $q->set('ignore_sticky_posts', true);
    }
    if ($q->is_home() || $q->is_archive() || $q->is_search()) {
        $q->set('has_password', false);
    }
});

$hs_ohne_passwort = function (string $where): string {
    return $where . " AND p.post_password = ''";
};
add_filter('get_previous_post_where', $hs_ohne_passwort);
add_filter('get_next_post_where', $hs_ohne_passwort);

// /blog/ gibt es nur mit veröffentlichten Beiträgen, wie bisher, und nur als eine Seite: /blog/page/2/ ist 404,
// ebenso /page/2/ der Startseite
add_action('template_redirect', function () {
    $blog_ohne = is_home() && !is_front_page() && (!hs_blog_hat_beitraege() || (int) get_query_var('paged') > 1);
    $start_seite = is_front_page() && max((int) get_query_var('paged'), (int) get_query_var('page')) > 1;
    if ($blog_ohne || $start_seite) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}, 9);

// Die Feeds von WordPress (/feed/, /blog/feed/, Kommentare) führen zum Feed der Website: Nur der zeigt keine
// Notizen in Kommentaren, keine Links auf geplante Beiträge und keine Beiträge mit Passwort
add_action('template_redirect', function () {
    if (is_feed() && !get_query_var('hs_datei')) {
        wp_redirect(hs_url('/blog/feed.xml'), 301);
        exit;
    }
}, 1);

// ---------------------------------------------------------------------------------------------------------
// Ein Beitrag mit allem, was Seiten, Karten, Feed, Sitemap und llms.txt brauchen

function hs_beitrag($beitrag): array {
    $beitrag = get_post($beitrag);
    $html = hs_beitrag_html($beitrag);
    $woerter = post_password_required($beitrag) ? 0 : hs_woerter($html);
    $autor = (string) get_post_meta($beitrag->ID, 'hs_autor', true);
    $datum = get_post_datetime($beitrag, 'date') ?: new DateTimeImmutable('now', wp_timezone());
    $geaendert = get_post_datetime($beitrag, 'modified') ?: $datum;
    if ($geaendert < $datum) {
        $geaendert = $datum;
    }

    $bild = null;
    $bild_id = get_post_thumbnail_id($beitrag);
    if ($bild_id && ($quelle = wp_get_attachment_image_src($bild_id, 'full'))) {
        $alt = trim((string) get_post_meta($bild_id, '_wp_attachment_image_alt', true));
        $bild = ['id' => $bild_id, 'url' => $quelle[0], 'breite' => $quelle[1], 'hoehe' => $quelle[2],
            'alt' => $alt !== '' ? $alt : $beitrag->post_title];
    }

    return [
        'id' => $beitrag->ID,
        'titel' => $beitrag->post_title,
        'url' => get_permalink($beitrag),
        'datum' => $datum,
        'geaendert' => $geaendert,
        'beschreibung' => hs_beitrag_beschreibung($beitrag, $html),
        'autor' => array_key_exists($autor, HS_AUTOREN) ? $autor : '',
        'bild' => $bild,
        'html' => $html,
        'woerter' => $woerter,
        'minuten' => max(1, (int) ceil($woerter / 200)),
    ];
}

/**
 * Der Text eines Beitrags oder einer Seite, wie er auf der Seite steht: durch die Filter von WordPress, ohne
 * HTML-Kommentare, # als ## (die Seite hat schon eine h1), Tabellen in einer Hülle, die auf dem Handy waagerecht
 * scrollt, und ohne Links auf Beiträge, die noch nicht erschienen sind. Mit Passwort das Formular von WordPress.
 * Einmal pro Aufruf berechnet, auch wenn Kopf und Seite ihn brauchen.
 */
function hs_beitrag_html(WP_Post $beitrag): string {
    static $fertig = [];
    if (isset($fertig[$beitrag->ID])) {
        return $fertig[$beitrag->ID];
    }
    if (post_password_required($beitrag)) {
        return $fertig[$beitrag->ID] = get_the_password_form($beitrag);
    }
    // Notizen in HTML-Kommentaren schon vor den Filtern entfernen (sonst bleibt ein leerer Absatz); die
    // Block-Kommentare <!-- wp:… --> braucht WordPress noch
    $inhalt = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', hs_ohne_wpbakery_huellen($beitrag->post_content));
    // Filter und Shortcodes erwarten den Beitrag als globalen $post. Bilder im Text laden erst beim Scrollen,
    // wie bisher.
    $vorher = $GLOBALS['post'] ?? null;
    $GLOBALS['post'] = $beitrag;
    setup_postdata($beitrag);
    add_filter('wp_omit_loading_attr_threshold', '__return_zero');
    $html = apply_filters('the_content', $inhalt);
    remove_filter('wp_omit_loading_attr_threshold', '__return_zero');
    $GLOBALS['post'] = $vorher;
    if ($vorher instanceof WP_Post) {
        setup_postdata($vorher);
    }
    $html = str_replace(']]>', ']]&gt;', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('#<(/?)h1\b#i', '<$1h2', $html);
    $html = preg_replace('#<table\b.*?</table>#is', '<div class="beitrag-tabelle">$0</div>', $html);
    return $fertig[$beitrag->ID] = hs_ohne_links_auf_kuenftige(hs_anker($html));
}

/** Zwischenüberschriften bekommen eine id wie bisher in blog.py, damit Links wie #wer-stellt-was-aus springen. */
function hs_anker(string $html): string {
    $vergeben = [];
    return preg_replace_callback('#<(h[2-6])((?:(?!\bid=)[^>])*)>(.*?)</\1>#is', function ($m) use (&$vergeben) {
        $text = strtr(mb_strtolower(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES, 'UTF-8')),
            ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $id = trim(preg_replace('/[^a-z0-9]+/', '-', remove_accents($text)), '-');
        if ($id === '') {
            return $m[0];
        }
        $basis = $id;
        for ($n = 1; isset($vergeben[$id]); $n++) {
            $id = $basis . '_' . $n;
        }
        $vergeben[$id] = true;
        return '<' . $m[1] . $m[2] . ' id="' . esc_attr($id) . '">' . $m[3] . '</' . $m[1] . '>';
    }, $html);
}

/**
 * Impressum und Datenschutz stammen aus dem bisherigen Theme und stecken in Layout-Shortcodes des Page Builders
 * WPBakery ([vc_row][vc_column][vc_column_text] …). Die Hüllen fallen weg, der Text bleibt. Ist WPBakery nicht mehr
 * aktiv, fallen alle [vc_…] weg, die sonst als Text auf der Seite stünden.
 */
function hs_ohne_wpbakery_huellen(string $inhalt): string {
    $muster = shortcode_exists('vc_row')
        ? '#\[/?(?:vc_row|vc_row_inner|vc_column|vc_column_inner|vc_column_text|vc_section)(?=[\s\]/])[^\]]*\]#'
        : '#\[/?vc_[a-z0-9_]+(?=[\s\]/])[^\]]*\]#';
    return preg_replace($muster, '', $inhalt);
}

/**
 * Links auf Beiträge, die noch nicht erschienen sind (geplant, Entwurf, privat), bleiben nur Text, wie bisher in
 * blog.py: Die Adresse eines geplanten Beitrags steht vorher nirgends, und es gibt keinen toten Link.
 */
function hs_ohne_links_auf_kuenftige(string $html): string {
    static $kuenftige = null;
    if ($kuenftige === null) {
        $kuenftige = [];
        $ids = get_posts(['post_type' => 'post', 'post_status' => ['future', 'draft', 'pending', 'private'],
            'numberposts' => -1, 'suppress_filters' => true, 'fields' => 'ids']);
        foreach ($ids as $id) {
            $kuenftige[$id] = get_post_field('post_name', $id);
        }
        // Ein Entwurf kann den Slug eines erschienenen Beitrags tragen (neue Fassung): dessen Links bleiben
        $erschienen = $kuenftige ? get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1,
            'post_name__in' => array_values(array_filter($kuenftige)), 'suppress_filters' => true]) : [];
        $kuenftige = array_diff($kuenftige, wp_list_pluck($erschienen, 'post_name'));
    }
    if (!$kuenftige || stripos($html, '<a') === false) {
        return $html;
    }

    // Pfad eines Beitrags nach den Permalink-Einstellungen, z. B. /blog/%postname%/ → ^blog/([^/]+)$
    $struktur = trim((string) get_option('permalink_structure'), '/');
    $muster = null;
    if (strpos($struktur, '%postname%') !== false) {
        [$vor, $nach] = array_map(function ($teil) {
            return preg_replace('/%[a-z_]+%/', '[^/]+', preg_quote($teil, '#'));
        }, explode('%postname%', $struktur, 2));
        $muster = '#^' . $vor . '([^/]+)' . $nach . '$#';
    }
    $start = wp_parse_url(hs_url('/'));
    $basis = trim($start['path'] ?? '', '/');

    return preg_replace_callback('#<a\b[^>]*?\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) use ($kuenftige, $muster, $start, $basis) {
        $ziel = wp_parse_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if (!is_array($ziel)) {
            return $m[0];
        }
        if (isset($ziel['host']) && strcasecmp($ziel['host'], $start['host'] ?? '') !== 0
            && !preg_match('/(^|\.)heydensattler\.com$/i', $ziel['host'])) {
            return $m[0];
        }
        parse_str($ziel['query'] ?? '', $abfrage);
        if (isset($abfrage['p']) && isset($kuenftige[(int) $abfrage['p']])) {
            return $m[3];
        }
        $pfad = trim($ziel['path'] ?? '', '/');
        if ($basis !== '' && strpos($pfad . '/', $basis . '/') === 0) {
            $pfad = trim(substr($pfad, strlen($basis)), '/');
        }
        if ($muster && preg_match($muster, $pfad, $treffer) && in_array($treffer[1], $kuenftige, true)) {
            return $m[3];
        }
        return $m[0];
    }, $html);
}

/** Wörter im Text wie bisher in blog.py: ein Tag trennt Wörter, eine Entity zählt als ihr Zeichen. */
function hs_woerter(string $html): int {
    $text = html_entity_decode(wp_strip_all_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return (int) preg_match_all('/\w+/u', $text);
}

/** Der Auszug; fehlt er, der Anfang des ersten Absatzes (höchstens 155 Zeichen), wie bisher. */
function hs_beitrag_beschreibung(WP_Post $beitrag, string $html): string {
    if (post_password_required($beitrag)) {
        return 'Dieser Inhalt ist durch ein Passwort geschützt.';
    }
    $auszug = trim($beitrag->post_excerpt);
    if ($auszug !== '') {
        return $auszug;
    }
    preg_match('#<p\b[^>]*>(.*?)</p>#s', $html, $erster);
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($erster[1] ?? ''), ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($text) <= 155) {
        return $text;
    }
    // Wie blog.py: auf 154 Zeichen, dann bis zum letzten Leerzeichen, ohne Satzzeichen am Ende
    $text = preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, 154));
    return preg_replace('/[,;:–-]+$/u', '', $text) . ' …';
}

function hs_blog_url(): string {
    $seite = (int) get_option('page_for_posts');
    return $seite ? get_permalink($seite) : hs_url('/blog/');
}

function hs_blog_hat_beitraege(): bool {
    static $hat = null;
    return $hat ??= (bool) get_posts(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false,
        'numberposts' => 1, 'fields' => 'ids']);
}

function hs_ist_blog(): bool {
    return is_home() || is_singular('post');
}

/** Die neuesten veröffentlichten Beiträge ohne Passwort als hs_beitrag()-Daten. */
function hs_beitraege(int $anzahl = -1): array {
    $ids = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'numberposts' => $anzahl,
        'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'suppress_filters' => false]);
    return array_map('hs_beitrag', $ids);
}

function hs_autor_name(array $b): string {
    return $b['autor'] ? HS_AUTOREN[$b['autor']]['name'] : 'HeydenSattler';
}

function hs_autor_ld(array $b): array {
    return ['@id' => hs_url('/#' . ($b['autor'] ?: 'firma'))];
}

function hs_person_ld(string $schluessel): array {
    $a = HS_AUTOREN[$schluessel];
    return ['@type' => 'Person', '@id' => hs_url('/#' . $schluessel), 'name' => $a['name'], 'honorificSuffix' => $a['titel'],
        'url' => hs_url('/#team'), 'image' => $a['bild'], 'worksFor' => ['@id' => hs_url('/#firma')]];
}

function hs_posting_ld(array $b, bool $voll): array {
    $ld = [
        '@type' => 'BlogPosting', '@id' => $b['url'] . '#beitrag', 'url' => $b['url'], 'headline' => $b['titel'],
        'description' => $b['beschreibung'], 'datePublished' => $b['datum']->format('c'),
        'dateModified' => $b['geaendert']->format('c'), 'author' => hs_autor_ld($b),
        'publisher' => ['@id' => hs_url('/#firma')], 'image' => $b['bild']['url'] ?? hs_hero_og()['url'],
        'inLanguage' => 'de-DE',
    ];
    if ($voll) {
        $ld += [
            'mainEntityOfPage' => ['@id' => $b['url']], 'isPartOf' => ['@id' => hs_blog_url() . '#blog'],
            'breadcrumb' => ['@id' => $b['url'] . '#brotkrumen'], 'wordCount' => $b['woerter'],
            'timeRequired' => 'PT' . $b['minuten'] . 'M',
        ];
    }
    return $ld;
}

/** Karte eines Beitrags in der Übersicht ($ebene 2) und unter „Aktuelles“ auf der Startseite ($ebene 3). */
function hs_beitrag_karte(array $b, int $ebene): string {
    if ($b['bild']) {
        // Drei Spalten bis 992 px, darunter zwei, ab 640 px eine (wie .beitraege in style.css)
        $bild = wp_get_attachment_image($b['bild']['id'], 'large', false, ['alt' => $b['bild']['alt'], 'loading' => 'lazy',
            'decoding' => 'async', 'sizes' => '(min-width: 992px) 370px, (min-width: 641px) 50vw, 100vw']);
    } else {
        // Ohne eigenes Bild das Hero-Bild der Startseite, damit alle Karten gleich aufgebaut sind
        $bild = '<img src="' . esc_url(hs_bild('cover3-800.webp')) . '" alt="" width="800" height="442" loading="lazy" decoding="async">';
    }
    return '        <li><a class="beitrag-karte" href="' . esc_url($b['url']) . "\">\n"
        . '          <div class="bild">' . $bild . "</div>\n"
        . '          <div class="info"><time datetime="' . $b['datum']->format('Y-m-d') . '">' . hs_de_datum($b['datum']) . '</time>'
        . "<h$ebene>" . hs_e($b['titel']) . "</h$ebene><p>" . hs_e($b['beschreibung']) . '</p>'
        . '<span class="mehr">Weiterlesen' . HS_PFEIL . "</span></div>\n"
        . "        </a></li>\n";
}

/** Abschnitt „Aktuelles“ der Startseite; ohne veröffentlichten Beitrag nichts. */
function hs_blog_aktuelles(): void {
    $beitraege = hs_beitraege(HS_TEASER);
    if (!$beitraege) {
        return;
    }
    echo "\n  <section class=\"abschnitt\" id=\"aktuelles\">\n    <div class=\"wrap\">\n"
        . "      <h2>Aktuelles aus unserem Blog:</h2>\n      <ul class=\"beitraege\">\n";
    foreach ($beitraege as $b) {
        echo hs_beitrag_karte($b, 3);
    }
    echo "      </ul>\n      <div class=\"beitraege-aktion\"><a class=\"btn\" href=\"" . esc_url(hs_blog_url()) . "\">Alle Beiträge</a></div>\n"
        . "    </div>\n  </section>\n";
}
