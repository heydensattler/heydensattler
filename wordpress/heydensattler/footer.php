<?php defined('ABSPATH') || exit; ?>

<footer>
  <div class="wrap">
    <span>Copyright <?= esc_html(wp_date('Y')) ?> © HeydenSattler GmbH</span>
    <span><a href="<?= esc_url(hs_url('/impressum/')) ?>">Impressum</a></span>
    <span><a href="<?= esc_url(hs_url('/datenschutzerklaerung/')) ?>">Datenschutzerklärung</a></span>
  </div>
</footer>

<a class="nach-oben" href="#inhalt" aria-label="Nach oben">
  <svg viewBox="0 0 14 14" aria-hidden="true"><path d="M2 9l5-5 5 5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
</a>

<?php wp_footer(); ?>
</body>
</html>
