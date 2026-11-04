<?php
// Seite für nicht mehr vorhandene Adressen, meist verkaufte oder vermietete Angebote

defined('ABSPATH') || exit;

hs_seite([
    'titel' => 'Angebot nicht mehr verfügbar | HeydenSattler',
    'beschreibung' => 'Dieses Angebot ist nicht mehr verfügbar. Unsere aktuellen Immobilienangebote in Augsburg und Umgebung finden Sie auf der Startseite.',
    'robots' => 'noindex, follow',
]);
get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
      <h1>Dieses Angebot ist nicht mehr verfügbar</h1>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap fehlerseite">
      <p>Die Immobilie wurde vermutlich schon verkauft oder vermietet, oder die Adresse hat sich geändert. Unsere aktuellen Angebote finden Sie auf der Startseite. Gerne informieren wir Sie auch, sobald ein passendes Objekt frei wird.</p>
      <div class="aktionen">
        <a class="btn" href="<?= esc_url(hs_url('/#angebote')) ?>">Aktuelle Angebote ansehen</a>
        <a class="btn zweit" href="<?= esc_url(hs_url('/#kontakt')) ?>">Kontakt aufnehmen</a>
      </div>
    </div>
  </section>

<?php hs_kontakt('Sie suchen eine Immobilie in Augsburg oder Umgebung? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.',
    'Anfrage über heydensattler.com'); ?>
</main>
<?php
get_footer();
