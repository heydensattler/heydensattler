<?php
// Dateien neben den Seiten: /sitemap.xml, /llms.txt, /blog/feed.xml und robots.txt, mit denselben Inhalten wie
// bisher im statischen site/. Die eigene Sitemap von WordPress (wp-sitemap.xml) ist aus, damit es nur eine gibt.

defined('ABSPATH') || exit;

add_filter('wp_sitemaps_enabled', '__return_false');

add_action('init', function () {
    hs_regel('^sitemap\.xml$', 'index.php?hs_datei=sitemap');
    hs_regel('^llms\.txt$', 'index.php?hs_datei=llms');
    hs_regel('^blog/feed\.xml$', 'index.php?hs_datei=feed');
});

add_filter('query_vars', function (array $vars) {
    $vars[] = 'hs_datei';
    return $vars;
});

// Vor redirect_canonical, sonst hängt WordPress einen Schrägstrich an
add_action('template_redirect', function () {
    $datei = get_query_var('hs_datei');
    $typen = ['sitemap' => 'application/xml', 'llms' => 'text/plain', 'feed' => 'application/atom+xml'];
    if (!is_string($datei) || !isset($typen[$datei])) {
        return;
    }
    // Den Feed gibt es nur mit veröffentlichten Beiträgen, wie bisher
    if ($datei === 'feed' && !hs_blog_hat_beitraege()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        return;
    }
    status_header(200);
    header('Content-Type: ' . $typen[$datei] . '; charset=utf-8');
    header('X-Robots-Tag: noindex', true);
    echo match ($datei) {
        'sitemap' => hs_sitemap(),
        'llms' => hs_llms(),
        'feed' => hs_feed(),
    };
    exit;
}, 1);

function hs_x($wert): string {
    return htmlspecialchars((string) $wert, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Adresse einer Seite aus WordPress per Pfad, wenn es sie gibt. */
function hs_seiten_url(string $pfad): ?string {
    $seite = get_page_by_path($pfad);
    return $seite && $seite->post_status === 'publish' ? get_permalink($seite) : null;
}

function hs_sitemap(): string {
    $zeilen = [];
    $url = fn($loc, $lastmod = null) => '  <url><loc>' . hs_x($loc) . '</loc>' . ($lastmod ? "<lastmod>$lastmod</lastmod>" : '') . '</url>';
    $zeilen[] = $url(hs_url('/'), HS_STAND);
    $zeilen[] = $url(hs_url('/leistungen/verkaufen/'), HS_STAND);
    $zeilen[] = $url(hs_url('/leistungen/vermieten/'), HS_STAND);
    foreach (hs_angebote_sitemap() as $a) {
        $zeilen[] = $url($a['url'], $a['lastmod']);
    }
    $beitraege = hs_beitraege();
    if ($beitraege) {
        $zeilen[] = $url(hs_blog_url(), max(array_map(fn($b) => $b['geaendert'], $beitraege))->format('Y-m-d'));
        foreach ($beitraege as $b) {
            $zeilen[] = $url($b['url'], $b['geaendert']->format('Y-m-d'));
        }
    }
    foreach (['impressum', 'datenschutzerklaerung'] as $pfad) {
        $zeilen[] = $url(hs_seiten_url($pfad) ?? hs_url("/$pfad/"));
    }
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
        . implode("\n", $zeilen) . "\n</urlset>\n";
}

function hs_llms(): string {
    $start = hs_url('/');
    $angebote = trim(hs_angebote_llms());
    $blog = '';
    $beitraege = hs_beitraege();
    if ($beitraege) {
        $blog = "## Blog\n\nAlle Beiträge: " . hs_blog_url() . ' (Feed: ' . hs_url('/blog/feed.xml') . ")\n\n";
        foreach ($beitraege as $b) {
            $blog .= '- [' . $b['titel'] . '](' . $b['url'] . ') (' . hs_de_datum($b['datum']) . ', ' . hs_autor_name($b) . '): '
                . $b['beschreibung'] . "\n";
        }
        $blog .= "\n";
    }
    $impressum = hs_seiten_url('impressum') ?? hs_url('/impressum/');
    return <<<TEXT
# HeydenSattler

> HeydenSattler GmbH ist ein Immobilienmakler in Augsburg. Das Unternehmen verkauft und vermietet Wohnungen, Häuser und Gewerbeflächen in Augsburg und Umgebung. Geschäftsführer sind Cedric Heyden und Anna Heyden, beide Master of Engineering, zertifizierte Immobilienmakler (IHK) und zertifizierte Immobilienverwalter (DFI).

## Kontakt

- Telefon: 0821 999 829 40 (Montag bis Freitag, 9:00 bis 17:00 Uhr)
- E-Mail: info@heydensattler.com
- Website: {$start}

## Leistungen

- [Immobilie verkaufen]({$start}leistungen/verkaufen/): Ermittlung des Verkaufswerts, Unterlagen (Energieausweis, Grundriss), Bilder und 360-Grad-Rundgang, Exposé, Vorauswahl der Interessenten, Besichtigungen, regelmäßiges Reporting, Vertragsverhandlung, Vertrag mit dem Notar, Begleitung zum Notartermin, Übergabe.
- [Immobilie vermieten]({$start}leistungen/vermieten/): Objektaufnahme mit Bildern, Aufbereitung des Grundrisses, Mietpreisfindung nach den gesetzlichen Vorgaben, Exposé auf den gängigen Plattformen, Vorauswahl, Besichtigungen, Selbstauskunft und Bonitätscheck, Mietvertrag, Wohnungsübergabe mit Protokoll (optional).

## Aktuelle Angebote

{$angebote}

{$blog}## Team

- Cedric Heyden, M. Eng.: Fachmann für Immobilienbewertung und Marketing. Mehrere Jahre Berufserfahrung in Architektur und Stadtplanung. Master „Energie Effizienz Design“, Bachelor „Energieeffizientes Planen und Bauen“.
- Anna Heyden, M. Eng.: Spezialistin für Vermietung und Verkauf. Mehrere Jahre Berufserfahrung in der Projektsteuerung. Master „Projektmanagement Bau und Immobilie“, Bachelor „Energieeffizientes Planen und Bauen“, ausgebildete Bankkauffrau.

## Weitere Seiten

- [Aktuelle Angebote]({$start}#angebote)
- [Häufige Fragen]({$start}#fragen)
- [Impressum]({$impressum}): HeydenSattler GmbH, Amtsgericht Augsburg HRB 37232, Erlaubnis nach § 34c GewO, Aufsichtsbehörde IHK München und Oberbayern.

TEXT;
}

function hs_feed(): string {
    $beitraege = hs_beitraege();
    $blog = hs_blog_url();
    $start = hs_url('/');
    $neueste = $beitraege ? max(array_map(fn($b) => $b['geaendert'], $beitraege)) : new DateTimeImmutable('now', wp_timezone());
    $x = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="de-DE">' . "\n"
        . "  <title>HeydenSattler Blog</title>\n"
        . "  <subtitle>Tipps und Wissenswertes zum Verkauf und zur Vermietung von Immobilien in Augsburg</subtitle>\n"
        . '  <link rel="alternate" type="text/html" href="' . hs_x($blog) . "\" />\n"
        . '  <link rel="self" type="application/atom+xml" href="' . hs_x(hs_url('/blog/feed.xml')) . "\" />\n"
        . '  <id>' . hs_x($blog) . "</id>\n"
        . '  <updated>' . $neueste->format('c') . "</updated>\n"
        . "  <author>\n    <name>HeydenSattler GmbH</name>\n    <uri>" . hs_x($start) . "</uri>\n  </author>\n"
        . "  <icon>https://heydensattler.com/favicon.ico</icon>\n";
    foreach ($beitraege as $b) {
        // Relative Adressen im Text absolut, damit Bilder und Links auch im Feedreader funktionieren
        $inhalt = preg_replace('#\b(href|src)="/(?!/)#', '$1="' . $start, $b['html']);
        if ($b['bild']) {
            $inhalt = '<p><img src="' . esc_url($b['bild']['url']) . '" alt="' . esc_attr($b['bild']['alt']) . "\"></p>\n" . $inhalt;
        }
        $x .= "  <entry>\n"
            . '    <title>' . hs_x($b['titel']) . "</title>\n"
            . '    <link rel="alternate" type="text/html" href="' . hs_x($b['url']) . "\" />\n"
            . '    <id>' . hs_x($b['url']) . "</id>\n"
            . '    <published>' . $b['datum']->format('c') . "</published>\n"
            . '    <updated>' . $b['geaendert']->format('c') . "</updated>\n"
            . "    <author>\n      <name>" . hs_x(hs_autor_name($b)) . "</name>\n    </author>\n"
            . '    <summary>' . hs_x($b['beschreibung']) . "</summary>\n"
            . '    <content type="html">' . hs_x($inhalt) . "</content>\n"
            . "  </entry>\n";
    }
    return $x . "</feed>\n";
}

// robots.txt: WordPress erzeugt sie selbst, solange keine echte Datei im Hauptverzeichnis liegt
add_filter('robots_txt', function (string $text, $oeffentlich) {
    if (!$oeffentlich) {
        return $text;
    }
    $bots = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended'];
    $text = "# Suchmaschinen und KI-Suchdienste dürfen die Seite lesen,\n# damit HeydenSattler in Suchergebnissen und KI-Antworten erscheint.\n"
        . "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\n";
    foreach ($bots as $bot) {
        $text .= "User-agent: $bot\nAllow: /\n\n";
    }
    return $text . 'Sitemap: ' . hs_url('/sitemap.xml') . "\n";
}, 10, 2);
