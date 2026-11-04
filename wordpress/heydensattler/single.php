<?php
// Ein Blogbeitrag: Titel mit Autor, Datum und Lesedauer, Bild, Text, Autor, Kontakt und Nachbarbeiträge

defined('ABSPATH') || exit;

// Andere Inhaltstypen (z. B. von Plugins) sind keine Blogbeiträge
if (get_post_type() !== 'post') {
    get_template_part('template-parts/inhalt');
    return;
}

the_post();
$b = hs_beitrag(get_post());
$blog = hs_blog_url();

$graph = [
    hs_posting_ld($b, true),
    hs_brotkrumen_ld($b['url'], [['Startseite', hs_url('/')], ['Blog', $blog], [$b['titel'], $b['url']]]),
    hs_firma_ld(),
];
if ($b['autor']) {
    $graph[] = hs_person_ld($b['autor']);
}
hs_seite([
    'titel' => $b['titel'] . ' | HeydenSattler',
    'beschreibung' => $b['beschreibung'],
    'url' => $b['url'],
    'og_typ' => 'article',
    'og_bild' => $b['bild'] ?: hs_hero_og(),
    'feed' => true,
    'extra' => '<meta property="article:published_time" content="' . $b['datum']->format('c') . "\">\n"
        . '<meta property="article:modified_time" content="' . $b['geaendert']->format('c') . "\">\n",
    'ld' => [['@context' => 'https://schema.org', '@graph' => $graph]],
]);

$meta = [hs_e(hs_autor_name($b)), '<time datetime="' . $b['datum']->format('c') . '">' . hs_de_datum($b['datum']) . '</time>',
    $b['minuten'] . ' Min. Lesezeit'];
if ($b['geaendert']->format('Y-m-d') !== $b['datum']->format('Y-m-d')) {
    $meta[] = 'aktualisiert am <time datetime="' . $b['geaendert']->format('c') . '">' . hs_de_datum($b['geaendert']) . '</time>';
}

// Nachbarn in der Zeit: der ältere links, der neuere rechts
$aelter = get_previous_post();
$neuer = get_next_post();
$nachbarn = '';
if ($aelter) {
    $nachbarn .= '        <a class="querlink aelter" href="' . esc_url(get_permalink($aelter)) . '">' . HS_PFEIL_ZURUECK
        . '<span>Vorheriger Beitrag<strong>' . hs_e($aelter->post_title) . "</strong></span></a>\n";
}
if ($neuer) {
    $nachbarn .= '        <a class="querlink neuer" href="' . esc_url(get_permalink($neuer)) . '">'
        . '<span>Nächster Beitrag<strong>' . hs_e($neuer->post_title) . '</strong></span>' . HS_PFEIL . "</a>\n";
}

get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
<?php hs_brotkrumen([['Startseite', '/'], ['Blog', $blog], [$b['titel'], null]]); ?>
      <h1><?= hs_e($b['titel']) ?></h1>
      <p class="beitrag-meta"><?= implode(' · ', $meta) ?></p>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap">
    <article class="beitrag">
<?php if ($b['bild']) : ?>
      <figure class="beitrag-bild"><?= wp_get_attachment_image($b['bild']['id'], 'full', false,
          ['alt' => $b['bild']['alt'], 'fetchpriority' => 'high', 'loading' => false, 'decoding' => false,
              'sizes' => '(min-width: 800px) 760px, 100vw']) ?></figure>
<?php endif; ?>
      <div class="beitrag-text">
<?= $b['html'] ?>
      </div>
<?php if ($b['autor']) : $a = HS_AUTOREN[$b['autor']]; ?>
      <aside class="beitrag-autor" aria-label="<?= esc_attr($a['ueber']) ?>">
        <img src="<?= esc_url($a['bild']) ?>" alt="" width="251" height="300" loading="lazy" decoding="async">
        <p><strong><?= hs_e($a['name'] . ', ' . $a['titel']) ?></strong><?= hs_e($a['rolle']) ?> bei HeydenSattler</p>
      </aside>
<?php endif; ?>
    </article>
    </div>
  </section>

<?php hs_kontakt('Sie möchten Ihre Immobilie in Augsburg verkaufen oder vermieten? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.',
    'Blog: Anfrage über heydensattler.com',
    $nachbarn ? "      <nav class=\"weiter beitrag-nachbarn\" aria-label=\"Weitere Beiträge\">\n$nachbarn      </nav>\n" : ''); ?>
</main>
<?php
get_footer();
