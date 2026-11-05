<?php
// Seite für nicht mehr vorhandene Adressen: unter /angebote/… meist verkaufte oder vermietete Angebote, sonst
// alte Blogadressen, Seiten der alten Website oder Tippfehler

defined('ABSPATH') || exit;

$angebot = hs_angebot_slug() !== '';
hs_seite($angebot ? [
    'titel' => 'Angebot nicht mehr verfügbar | HeydenSattler',
    'beschreibung' => 'Dieses Angebot ist nicht mehr verfügbar. Unsere aktuellen Immobilienangebote in Augsburg und Umgebung finden Sie auf der Startseite.',
    'robots' => 'noindex, follow',
] : [
    'titel' => 'Seite nicht gefunden | HeydenSattler',
    'beschreibung' => 'Diese Seite gibt es nicht mehr. Unsere Leistungen und aktuellen Immobilienangebote in Augsburg und Umgebung finden Sie auf der Startseite.',
    'robots' => 'noindex, follow',
]);
get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
      <h1><?= $angebot ? 'Dieses Angebot ist nicht mehr verfügbar' : 'Diese Seite gibt es nicht mehr' ?></h1>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap fehlerseite">
<?php if ($angebot) : ?>
      <p>Die Immobilie wurde vermutlich schon verkauft oder vermietet, oder die Adresse hat sich geändert. Unsere aktuellen Angebote finden Sie auf der Startseite. Gerne informieren wir Sie auch, sobald ein passendes Objekt frei wird.</p>
      <div class="aktionen">
        <a class="btn" href="<?= esc_url(hs_url('/#angebote')) ?>">Aktuelle Angebote ansehen</a>
        <a class="btn zweit" href="<?= esc_url(hs_url('/#kontakt')) ?>">Kontakt aufnehmen</a>
      </div>
<?php else : ?>
      <p>Die Adresse hat sich vermutlich geändert, oder die Seite stammt von unserer früheren Website. Auf der Startseite finden Sie unsere Leistungen rund um Vermietung und Verkauf und unsere aktuellen Angebote.</p>
      <div class="aktionen">
        <a class="btn" href="<?= esc_url(hs_url('/')) ?>">Zur Startseite</a>
        <a class="btn zweit" href="<?= esc_url(hs_url('/#angebote')) ?>">Aktuelle Angebote ansehen</a>
      </div>
<?php endif; ?>
    </div>
  </section>

<?php hs_kontakt($angebot
    ? 'Sie suchen eine Immobilie in Augsburg oder Umgebung? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.'
    : 'Sie haben eine Frage zur Vermietung, zum Verkauf oder zu einem Angebot? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.',
    'Anfrage über heydensattler.com'); ?>
</main>
<?php
get_footer();
