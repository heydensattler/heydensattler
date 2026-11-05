<?php
// Grundlagen: Theme-Unterstützung, schlanker <head>, CSS/JS und kleine Bausteine, die mehrere Seiten teilen.

defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    // Die Gestaltung kommt aus assets/style.css; der Editor zeigt die Blöcke ohne eigene Vorgaben des Themes
    remove_theme_support('core-block-patterns');
});

// WordPress schreibt von sich aus vieles in den <head>, das die Seite nicht braucht oder das das Theme selbst
// ausgibt (Canonical). Was bleibt, ist der Kopf der statischen Seite.
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_shortlink_wp_head', 10);
    remove_action('wp_head', 'rest_output_link_wp_head', 10);
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'feed_links', 2);
    remove_action('wp_head', 'feed_links_extra', 3);
    remove_action('wp_head', 'rel_canonical');
    remove_action('wp_head', 'wp_site_icon', 99);
    remove_action('template_redirect', 'wp_shortlink_header', 11);
    remove_action('template_redirect', 'rest_output_link_header', 11);
});

// Favicon: Ohne eigenes Website-Icon zeigt WordPress sein Logo, unter /favicon.ico (do_favicon, das Browser und
// Suchmaschinen von selbst abfragen), im Admin und auf der Anmeldeseite. Überall gilt das HS-Zeichen des Themes.
add_filter('get_site_icon_url', function ($url, $groesse) {
    return get_theme_file_uri($groesse > 48 ? 'assets/icons/icon-192.png' : 'assets/icons/favicon.ico');
}, 10, 2);
add_action('do_favicon', function () {
    $datei = get_theme_file_path('assets/icons/favicon.ico');
    header('Content-Type: image/vnd.microsoft.icon');
    header('Content-Length: ' . filesize($datei));
    header('Cache-Control: public, max-age=86400');
    readfile($datei);
    exit;
}, 1);

// robots: Die Angabe der Seite (hs_seite, sonst „index, follow, …“) geht in den Filter von WordPress. So kommt noindex
// von WordPress selbst („Suchmaschinen davon abhalten“, Suche) und von Plugins (WooCommerce im Warenkorb) dazu.
add_filter('wp_robots', function (array $robots) {
    $angabe = $GLOBALS['hs_seite']['robots'] ?? 'index, follow, max-image-preview:large, max-snippet:-1';
    foreach (array_map('trim', explode(',', $angabe)) as $teil) {
        [$name, $wert] = array_pad(explode(':', $teil, 2), 2, true);
        $robots[$name] = $wert;
    }
    return $robots;
}, 1);

add_filter('wp_robots', function (array $robots) {
    // noindex und nofollow gehen vor; ohne Index braucht es auch keine Vorgaben für Vorschauen
    if (!empty($robots['noindex'])) {
        unset($robots['index'], $robots['max-image-preview'], $robots['max-snippet']);
    }
    if (!empty($robots['nofollow'])) {
        unset($robots['follow']);
    }
    return $robots;
}, PHP_INT_MAX);

// Eigene Abfragewerte nur als Text: ?hs_datei[]=… oder ?hs_angebot[]=… gäben sonst PHP-Fehler
add_filter('request', function (array $werte) {
    foreach (['hs_datei', 'hs_angebot'] as $name) {
        if (isset($werte[$name]) && !is_string($werte[$name])) {
            unset($werte[$name]);
        }
    }
    return $werte;
});

// Eine Adresse für alles: Wer über einen anderen Namen (www.) oder über http kommt, landet auf der Adresse der
// Website. Die Weiterleitung von WordPress (redirect_canonical) lässt Angebote, Suche, sitemap.xml und llms.txt
// aus und bei Seiten auch http.
add_action('template_redirect', function () {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $anfrage = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $anderer_name = $host && $anfrage !== '' && $anfrage !== $host;
    $ohne_https = !is_ssl() && wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https';
    if (($anderer_name || $ohne_https) && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        wp_safe_redirect(home_url($_SERVER['REQUEST_URI'] ?? '/'), 301);
        exit;
    }
}, 0);

// /leistungen/ gibt es nur als Elternseite von Vermieten und Verkaufen (für die Adressen); sie führt zu den
// Leistungen auf der Startseite, wie im Brotkrümelpfad
add_action('template_redirect', function () {
    $leistungen = get_page_by_path('leistungen');
    if ($leistungen && is_page($leistungen->ID)) {
        wp_safe_redirect(hs_url('/#leistungen'), 301);
        exit;
    }
});

// Andere Inhaltstypen haben keine eigene Seite: Anhänge führen zur Datei, wie seit WordPress 6.4 üblich
add_action('template_redirect', function () {
    if (is_attachment()) {
        // wp_redirect wie WordPress selbst: Die Datei darf auf einem anderen Server liegen (CDN)
        $datei = wp_get_attachment_url(get_queried_object_id());
        if ($datei) {
            wp_redirect($datei, 301);
            exit;
        }
    }
});

add_action('wp_enqueue_scripts', function () {
    hs_css('hs-style', 'assets/style.css');
    hs_js('hs-site', 'assets/site.js');
});

// Die Stile von WordPress für Blöcke nur, wo Inhalte aus dem Editor stehen (Beiträge, Impressum, Datenschutz)
add_action('template_redirect', function () {
    if (is_singular() && !hs_feste_seite()) {
        return;
    }
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    add_action('wp_enqueue_scripts', function () {
        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('classic-theme-styles');
    }, 20);
});

function hs_css(string $name, string $datei): void {
    wp_enqueue_style($name, get_theme_file_uri($datei), [], (string) filemtime(get_theme_file_path($datei)));
}

function hs_js(string $name, string $datei): void {
    wp_enqueue_script($name, get_theme_file_uri($datei), [], (string) filemtime(get_theme_file_path($datei)),
        ['strategy' => 'defer', 'in_footer' => false]);
}

// Schriften und (auf der Startseite) das Hero-Bild vorladen, wie im statischen <head>
add_action('wp_head', function () {
    echo '<link rel="preload" href="/wp-content/uploads/2022/05/soehne-buch.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="/wp-content/uploads/2022/05/soehne-breit-kraftig.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    if (is_front_page()) {
        echo '<link rel="preload" as="image" type="image/webp" fetchpriority="high" imagesrcset="' . esc_attr(hs_hero_srcset())
            . '" imagesizes="100vw">' . "\n";
    }
}, 5);

/** Ist die aktuelle Seite eine der Seiten, deren Inhalt im Theme steht? */
function hs_feste_seite(): bool {
    return is_front_page() || is_page(['vermieten', 'verkaufen']);
}

function hs_bild(string $datei): string {
    return get_theme_file_uri('assets/bilder/' . $datei);
}

function hs_hero_srcset(): string {
    return hs_bild('cover3-800.webp') . ' 800w, ' . hs_bild('cover3-1400.webp') . ' 1400w, '
        . hs_bild('cover3-2048.webp') . ' 2048w';
}

/** Ersatzbild für Open Graph und Karten ohne eigenes Bild: das Hero-Bild der Startseite. */
function hs_hero_og(): array {
    return ['url' => hs_bild('cover3-1400.jpg'), 'breite' => 1400, 'hoehe' => 772, 'alt' => 'HeydenSattler'];
}

// ---------------------------------------------------------------------------------------------------------
// Bausteine

function hs_e($wert): string {
    return esc_html((string) $wert);
}

/** Interne Adresse wie „/leistungen/vermieten/“ als absolute URL der Installation. */
function hs_url(string $pfad = '/'): string {
    return home_url($pfad);
}

const HS_PFEIL = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 9h12M10 4l5 5-5 5"/></svg>';
const HS_PFEIL_ZURUECK = '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M15 9H3M8 4 3 9l5 5"/></svg>';

function hs_mailto(string $betreff): string {
    return 'mailto:' . HS_MAIL . '?subject=' . rawurlencode($betreff);
}

function hs_querlink(string $pfad, string $titel, string $text): string {
    return '        <a class="querlink" href="' . esc_url(hs_url($pfad)) . '"><span><strong>' . hs_e($titel) . '</strong>'
        . hs_e($text) . '</span>' . HS_PFEIL . "</a>\n";
}

/** Der Kontaktabschnitt am Ende jeder Seite; $danach steht im .wrap unter der Box (z. B. Querlinks). */
function hs_kontakt(string $text, string $betreff, string $danach = ''): void {
    echo hs_kontakt_html($text, hs_mailto($betreff), $danach);
}

/** Wie hs_kontakt(), mit eigenem Link für „E-Mail schreiben“ und eigener Überschrift (Angebotsseiten). */
function hs_kontakt_html(string $text, string $link, string $danach = '', string $ueberschrift = 'Kontakt aufnehmen'): string {
    $text = hs_e($text);
    $link = esc_attr($link);
    $ueberschrift = hs_e($ueberschrift);
    $telefon = HS_TELEFON;
    $telefon_anzeige = HS_TELEFON_ANZEIGE;
    $mail = HS_MAIL;
    return <<<HTML
  <section class="abschnitt" id="kontakt">
    <div class="wrap">
      <div class="kontakt-box">
        <div>
          <h2>{$ueberschrift}</h2>
          <p class="text">{$text}</p>
          <a class="btn" href="{$link}">E-Mail schreiben</a>
        </div>
        <div>
          <h3>Telefon und E-Mail</h3>
          <p><a href="tel:{$telefon}">{$telefon_anzeige}</a><br><a href="mailto:{$mail}">{$mail}</a></p>
          <h3 style="margin-top:18px">Erreichbar</h3>
          <p>Montag bis Freitag<br>9:00 bis 17:00 Uhr</p>
        </div>
        <div>
          <h3>Unsere Region</h3>
          <p>Augsburg und Umgebung</p>
        </div>
      </div>
{$danach}    </div>
  </section>

HTML;
}

/**
 * Häufige Fragen: [[Frage, Antwort als HTML], …]. Dieselbe Liste ergibt das sichtbare FAQ und das JSON-LD
 * FAQPage, damit beide immer übereinstimmen.
 */
function hs_faq(string $ueberschrift, array $fragen): void {
    ?>
  <section class="abschnitt" id="fragen">
    <div class="wrap">
      <h2><?= hs_e($ueberschrift) ?></h2>
      <div class="faq">
<?php foreach ($fragen as [$frage, $antwort]) : ?>
        <details>
          <summary><h3><?= hs_e($frage) ?></h3></summary>
          <p><?= $antwort ?></p>
        </details>
<?php endforeach; ?>
      </div>
    </div>
  </section>
<?php
}

function hs_faq_ld(array $fragen): array {
    return ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => [
        '@type' => 'Question', 'name' => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => html_entity_decode(wp_strip_all_tags($f[1]), ENT_QUOTES, 'UTF-8')],
    ], $fragen)];
}

/** Brotkrümelpfad: [[Name, Pfad, URL oder null], …]; ohne Pfad und als letzter Eintrag die aktuelle Seite. */
function hs_brotkrumen(array $eintraege): void {
    echo "      <nav aria-label=\"Brotkrümelpfad\">\n        <ol class=\"brotkrumen\">\n";
    $letzter = count($eintraege) - 1;
    foreach ($eintraege as $i => [$name, $pfad]) {
        if ($pfad !== null) {
            $ziel = preg_match('#^https?://#', $pfad) ? $pfad : hs_url($pfad);
            echo '          <li><a href="' . esc_url($ziel) . '">' . hs_e($name) . "</a></li>\n";
        } elseif ($i === $letzter) {
            echo '          <li aria-current="page">' . hs_e($name) . "</li>\n";
        } else {
            echo '          <li>' . hs_e($name) . "</li>\n";
        }
    }
    echo "        </ol>\n      </nav>\n";
}

function hs_brotkrumen_ld(string $url, array $eintraege): array {
    $liste = [];
    foreach ($eintraege as $i => [$name, $ziel]) {
        $liste[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $ziel];
    }
    return ['@type' => 'BreadcrumbList', '@id' => $url . '#brotkrumen', 'itemListElement' => $liste];
}

/** Die Firma als Verweisziel für JSON-LD auf Unterseiten (vollständig steht sie auf der Startseite). */
function hs_firma_ld(): array {
    return [
        '@type' => 'RealEstateAgent', '@id' => hs_url('/#firma'), 'name' => 'HeydenSattler GmbH', 'url' => hs_url('/'),
        'telephone' => '+49 821 99982940', 'email' => HS_MAIL,
        'address' => ['@type' => 'PostalAddress', 'postalCode' => '86159', 'addressLocality' => 'Augsburg',
            'addressRegion' => 'Bayern', 'addressCountry' => 'DE'],
    ];
}

/** Datum auf Deutsch: „3. November 2026“. */
function hs_de_datum(DateTimeInterface $zeit): string {
    static $monate = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September',
        'Oktober', 'November', 'Dezember'];
    return $zeit->format('j') . '. ' . $monate[(int) $zeit->format('n') - 1] . ' ' . $zeit->format('Y');
}
