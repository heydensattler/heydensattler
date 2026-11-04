<?php
// Der <head> jeder Seite: Titel, Beschreibung, Canonical, Open Graph und JSON-LD. Jede Vorlage beschreibt ihre
// Seite mit hs_seite([...]) vor get_header(); header.php gibt das mit hs_kopf() aus.

defined('ABSPATH') || exit;

// Bilder, die in der Mediathek der bestehenden Website liegen (Logo, Team, Bild für Open Graph)
const HS_UPLOADS = 'https://heydensattler.com/wp-content/uploads';

/**
 * Felder:
 * - titel, beschreibung, url (Canonical): Pflicht
 * - og_titel: Standard ist der Titel ohne „ | HeydenSattler“
 * - og_typ: Standard „website“
 * - og_bild: URL oder ['url', 'breite', 'hoehe', 'alt']; Standard ist das Titelbild der bestehenden Website
 * - ld: Liste von JSON-LD-Objekten, je eines als <script>
 * - robots: Standard „index, follow, …“ (ausgegeben über den Filter wp_robots, siehe inc/grundlagen.php)
 * - geo: true für die Ortsangaben „Augsburg“ (Startseite, Leistungen) oder ein anderer Ort (Angebote)
 * - feed: true für den Link auf den Blog-Feed
 * - extra: weiteres HTML im <head> (z. B. article:published_time)
 */
function hs_seite(array $seite): void {
    $GLOBALS['hs_seite'] = $seite;
}

function hs_kopf(): void {
    $s = $GLOBALS['hs_seite'] ?? hs_seite_aus_wordpress();
    $titel = $s['titel'];
    $og_titel = $s['og_titel'] ?? preg_replace('/ \| HeydenSattler$/', '', $titel);
    $bild = $s['og_bild'] ?? HS_UPLOADS . '/2022/05/cover3.png';
    $bild = is_array($bild) ? $bild : ['url' => $bild];

    echo '<title>' . hs_e($titel) . "</title>\n";
    echo '<meta name="description" content="' . esc_attr($s['beschreibung']) . "\">\n";
    if (!empty($s['url'])) {
        echo '<link rel="canonical" href="' . esc_url($s['url']) . "\">\n";
        echo '<link rel="alternate" hreflang="de-DE" href="' . esc_url($s['url']) . "\">\n";
    }
    if (!empty($s['feed'])) {
        echo '<link rel="alternate" type="application/atom+xml" title="HeydenSattler Blog" href="' . esc_url(hs_url('/blog/feed.xml')) . "\">\n";
    }
    echo "<meta name=\"theme-color\" content=\"#163C37\">\n";
    if (!empty($s['geo'])) {
        $ort = is_string($s['geo']) ? $s['geo'] : 'Augsburg';
        echo "<meta name=\"geo.region\" content=\"DE-BY\">\n<meta name=\"geo.placename\" content=\"" . esc_attr($ort) . "\">\n";
    }
    if (!empty($s['url'])) {
        $og = [
            'og:type' => $s['og_typ'] ?? 'website',
            'og:locale' => 'de_DE',
            'og:site_name' => 'HeydenSattler',
            'og:title' => $og_titel,
            'og:description' => $s['og_beschreibung'] ?? $s['beschreibung'],
            'og:url' => $s['url'],
            'og:image' => $bild['url'],
        ];
        if (isset($bild['breite'])) {
            $og['og:image:type'] = 'image/' . str_replace('jpg', 'jpeg', strtolower(pathinfo(parse_url($bild['url'], PHP_URL_PATH), PATHINFO_EXTENSION)));
            $og['og:image:width'] = $bild['breite'];
            $og['og:image:height'] = $bild['hoehe'];
            $og['og:image:alt'] = $bild['alt'];
        }
        foreach ($og as $name => $wert) {
            echo '<meta property="' . $name . '" content="' . esc_attr((string) $wert) . "\">\n";
        }
        echo $s['extra'] ?? '';
        echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    }
    echo "<link rel=\"icon\" href=\"https://heydensattler.com/favicon.ico\">\n";
    foreach ($s['ld'] ?? [] as $ld) {
        echo "\n<script type=\"application/ld+json\">\n" . hs_json($ld) . "\n</script>\n";
    }
    echo "\n";
}

function hs_json($daten): string {
    // < und > als < und >: Kein Text (etwa aus Propstack) kann so das <script> beenden oder mit „<!--“ verlängern
    return (string) wp_json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
}

/** Für Seiten aus dem Editor (z. B. Impressum, Datenschutz): Titel und Auszug, sonst der Anfang des Texts. */
function hs_seite_aus_wordpress(): array {
    $beitrag = get_queried_object();
    if (!($beitrag instanceof WP_Post)) {
        return ['titel' => wp_get_document_title(), 'beschreibung' => get_bloginfo('description'), 'url' => ''];
    }
    return [
        'titel' => $beitrag->post_title . ' | HeydenSattler',
        'beschreibung' => hs_beitrag_beschreibung($beitrag, hs_beitrag_html($beitrag)),
        'url' => get_permalink($beitrag),
    ];
}
