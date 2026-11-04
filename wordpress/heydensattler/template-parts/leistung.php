<?php
// Leistungsseite (Vermieten, Verkaufen): Titel, Einleitung, Ablauf in Spalten, Fragen, Kontakt.
// $args kommt aus page-vermieten.php bzw. page-verkaufen.php. Ein Punkt im Ablauf ist ein Text oder
// [Text, true] für „(optional)“.

defined('ABSPATH') || exit;

$l = $args;
$url = hs_url('/leistungen/' . $l['slug'] . '/');
$start = hs_url('/');

$katalog = [];
foreach ($l['ablauf'] as [$schritt, $punkte]) {
    $katalog[] = ['@type' => 'OfferCatalog', 'name' => $schritt, 'itemListElement' => array_map(fn($p) => [
        '@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => is_array($p) ? $p[0] : $p],
    ], $punkte)];
}

hs_seite([
    'titel' => $l['titel'],
    'beschreibung' => $l['beschreibung'],
    'url' => $url,
    'og_titel' => $l['name'] . ' – HeydenSattler, Immobilienmakler in Augsburg',
    'geo' => true,
    'ld' => [[
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Service',
                '@id' => $url . '#leistung',
                'name' => $l['leistung'],
                'serviceType' => $l['art'],
                'url' => $url,
                'description' => $l['beschreibung'],
                'provider' => hs_firma_ld(),
                'areaServed' => [
                    ['@type' => 'City', 'name' => 'Augsburg'],
                    ['@type' => 'AdministrativeArea', 'name' => 'Landkreis Augsburg'],
                ],
                'hasOfferCatalog' => ['@type' => 'OfferCatalog', 'name' => 'Ablauf: ' . $l['name'], 'itemListElement' => $katalog],
            ],
            [
                '@type' => 'WebPage',
                '@id' => $url,
                'url' => $url,
                'name' => $l['titel'],
                'inLanguage' => 'de-DE',
                'isPartOf' => ['@id' => $start . '#website'],
                'about' => ['@id' => $url . '#leistung'],
                'dateModified' => HS_STAND,
                'breadcrumb' => ['@type' => 'BreadcrumbList', 'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Startseite', 'item' => $start],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Leistungen', 'item' => $start . '#leistungen'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $l['name'], 'item' => $url],
                ]],
            ],
            hs_faq_ld($l['fragen']),
        ],
    ]],
]);
get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
<?php hs_brotkrumen([['Startseite', '/'], ['Leistungen', null], [$l['name'], null]]); ?>
      <h1><?= hs_e($l['name']) ?></h1>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap">
      <div class="einleitung">
        <h2><?= hs_e($l['einleitung'][0]) ?></h2>
        <p><?= hs_e($l['einleitung'][1]) ?></p>
      </div>

      <ol class="ablauf" style="--spalten:<?= count($l['ablauf']) ?>">
<?php foreach ($l['ablauf'] as [$schritt, $punkte]) : ?>
        <li>
          <h3><?= hs_e($schritt) ?></h3>
          <ul class="haken">
<?php foreach ($punkte as $p) : ?>
            <li><?= is_array($p) ? hs_e($p[0]) . ' <span class="optional">(optional)</span>' : hs_e($p) ?></li>
<?php endforeach; ?>
          </ul>
        </li>
<?php endforeach; ?>
      </ol>
    </div>
  </section>

<?php hs_faq($l['fragen_titel'], $l['fragen']); ?>

<?php hs_kontakt('Erzählen Sie uns von Ihrer Immobilie. Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.',
    $l['name'] . ': Anfrage über heydensattler.com',
    "      <div class=\"weiter\">\n" . $l['querlinks'] . "      </div>\n"); ?>
</main>
<?php
get_footer();
