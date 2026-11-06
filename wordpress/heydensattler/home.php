<?php
// Übersicht „Immobilienwissen“ (/immobilienwissen/): alle Beiträge, neueste zuerst

defined('ABSPATH') || exit;

$url = hs_blog_url();
$beitraege = [];
while (have_posts()) {
    the_post();
    $beitraege[] = hs_beitrag(get_post());
}

hs_seite([
    'titel' => HS_BLOG_NAME . ': Ratgeber Immobilien in Augsburg | HeydenSattler',
    'beschreibung' => HS_BLOG_BESCHREIBUNG,
    'url' => $url,
    'og_bild' => hs_hero_og(),
    'feed' => true,
    'ld' => [[
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Blog', '@id' => $url . '#blog', 'url' => $url, 'name' => 'HeydenSattler ' . HS_BLOG_NAME,
                'description' => HS_BLOG_BESCHREIBUNG, 'inLanguage' => 'de-DE', 'publisher' => ['@id' => hs_url('/#firma')],
                'isPartOf' => ['@id' => hs_url('/#website')], 'breadcrumb' => ['@id' => $url . '#brotkrumen'],
                'blogPost' => array_map(fn($b) => hs_posting_ld($b, false), $beitraege)],
            hs_brotkrumen_ld($url, [['Startseite', hs_url('/')], [HS_BLOG_NAME, $url]]),
            hs_firma_ld(),
        ],
    ]],
]);
get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
<?php hs_brotkrumen([['Startseite', '/'], [HS_BLOG_NAME, null]]); ?>
      <h1><?= HS_BLOG_NAME ?></h1>
      <p class="beitrag-meta"><?= hs_e(HS_BLOG_BESCHREIBUNG) ?></p>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap">
      <ul class="beitraege">
<?php foreach ($beitraege as $b) {
    echo hs_beitrag_karte($b, 2);
} ?>
      </ul>
      <p class="beitraege-feed"><a href="<?= esc_url(hs_feed_url()) ?>">Neue Beiträge als Feed abonnieren (Atom)</a></p>
    </div>
  </section>

<?php hs_kontakt('Sie möchten Ihre Immobilie in Augsburg verkaufen oder vermieten? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.',
    HS_BLOG_NAME . ': Anfrage über heydensattler.com',
    "      <div class=\"weiter\">\n"
    . hs_querlink('/leistungen/verkaufen/', 'Verkaufen', 'So verkaufen wir Ihre Immobilie')
    . hs_querlink('/leistungen/vermieten/', 'Vermieten', 'So finden wir den passenden Mieter')
    . "      </div>\n"); ?>
</main>
<?php
get_footer();
