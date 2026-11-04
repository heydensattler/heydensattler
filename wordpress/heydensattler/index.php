<?php
// Rückfall für alles, wofür es keine eigene Vorlage gibt (Kategorien, Schlagwörter, Suche, Archive):
// eine Liste der Beiträge wie in der Blogübersicht.

defined('ABSPATH') || exit;

$beitraege = [];
while (have_posts()) {
    the_post();
    $beitraege[] = hs_beitrag(get_post());
}
$titel = wp_strip_all_tags(get_the_archive_title()) ?: 'Blog';

hs_seite(['titel' => $titel . ' | HeydenSattler', 'beschreibung' => HS_BLOG_BESCHREIBUNG, 'robots' => 'noindex, follow']);
get_header();
?>
<main id="inhalt">

  <header class="seitentitel">
    <div class="wrap">
<?php hs_brotkrumen([['Startseite', '/'], ['Blog', hs_blog_url()], [$titel, null]]); ?>
      <h1><?= hs_e($titel) ?></h1>
    </div>
  </header>

  <section class="abschnitt">
    <div class="wrap">
<?php if ($beitraege) : ?>
      <ul class="beitraege">
<?php foreach ($beitraege as $b) {
    echo hs_beitrag_karte($b, 2);
} ?>
      </ul>
<?php else : ?>
      <p>Hier gibt es keine Beiträge.</p>
<?php endif; ?>
<?php the_posts_pagination(); ?>
    </div>
  </section>
</main>
<?php
get_footer();
