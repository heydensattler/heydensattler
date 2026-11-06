<?php defined('ABSPATH') || exit; ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php hs_kopf(); ?>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip" href="#inhalt">Zum Inhalt springen</a>

<header class="kopf">
  <div class="topbar">
    <div class="wrap">
      <a href="tel:<?= HS_TELEFON ?>" aria-label="Anrufen: <?= HS_TELEFON_ANZEIGE ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
        <?= HS_TELEFON_ANZEIGE ?>

      </a>
      <a href="mailto:<?= HS_MAIL ?>" aria-label="E-Mail an <?= HS_MAIL ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/></svg>
        <span class="mail-text" style="padding:0;border:0"><?= HS_MAIL ?></span>
      </a>
      <span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        Mo - Fr: 9:00 - 17:00
      </span>
    </div>
  </div>
  <div class="wrap leiste">
    <a class="logo" href="<?= esc_url(hs_url('/')) ?>" aria-label="HeydenSattler, zur Startseite">
      <img src="<?= HS_UPLOADS ?>/2023/03/HeydenSattler_CombiLogo_DarkGreen-Cropped.png" alt="HeydenSattler" width="906" height="88">
    </a>
    <button class="menu-knopf" aria-expanded="false" aria-controls="hauptnav" aria-label="Menü öffnen">
      <svg viewBox="0 0 26 26" aria-hidden="true"><path d="M3 8h20M3 13h20M3 18h20" stroke="currentColor" stroke-width="2"/></svg>
    </button>
    <nav class="haupt" id="hauptnav" aria-label="Hauptnavigation">
      <ul>
        <li class="hat-unter">
          <button aria-expanded="false" aria-controls="untermenue-leistungen">Leistungen
            <svg viewBox="0 0 10 10" aria-hidden="true"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
          </button>
          <ul class="untermenue" id="untermenue-leistungen">
            <li><a href="<?= esc_url(hs_url('/leistungen/vermieten/')) ?>">Vermieten</a></li>
            <li><a href="<?= esc_url(hs_url('/leistungen/verkaufen/')) ?>">Verkaufen</a></li>
          </ul>
        </li>
        <li><a href="<?= esc_url(hs_url('/#team')) ?>">Über uns</a></li>
        <li><a href="<?= esc_url(hs_url('/#angebote')) ?>">Angebote</a></li>
<?php if (hs_blog_hat_beitraege()) : ?>
        <li><a href="<?= esc_url(hs_blog_url()) ?>"<?= hs_ist_blog() ? ' aria-current="page"' : '' ?>><?= HS_BLOG_NAME ?></a></li>
<?php endif; ?>
        <li><a href="<?= esc_url(hs_url('/#fragen')) ?>">Fragen</a></li>
      </ul>
      <a class="btn" href="<?= esc_url(hs_url('/#kontakt')) ?>">Kontakt aufnehmen</a>
    </nav>
  </div>
</header>

