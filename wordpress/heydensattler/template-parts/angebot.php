<?php
// Detailseite einer Immobilie unter /angebote/<slug>/ (inc/angebote.php). Inhalt aus dem gespeicherten Stand der
// Angebote; eine unbekannte Adresse bekommt schon vorher die 404-Seite.

defined('ABSPATH') || exit;

$a = hs_angebot_aktuell();
if ($a === null) {
    status_header(404);
    include get_404_template();
    return;
}

hs_seite(hs_ang_detail_kopf($a));
get_header();
echo hs_ang_detail_html($a);
get_footer();
