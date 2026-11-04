<?php
// Seite mit Inhalt aus dem Editor (Impressum, Datenschutzerklärung, Inhalte von Plugins): Titelleiste und Text in
// Lesebreite. Für page.php und für Inhaltstypen außer Beiträgen in single.php.

defined('ABSPATH') || exit;

the_post();
$seite = get_post();
hs_seite(hs_seite_aus_wordpress());
get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
<?php hs_brotkrumen([['Startseite', '/'], [$seite->post_title, null]]); ?>
      <h1><?= hs_e($seite->post_title) ?></h1>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap">
    <article class="beitrag">
      <div class="beitrag-text">
<?= hs_beitrag_html($seite) ?>
      </div>
    </article>
    </div>
  </section>
</main>
<?php
get_footer();
