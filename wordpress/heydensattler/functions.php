<?php
/**
 * HeydenSattler – WordPress-Theme der Website.
 *
 * Die festen Seiten (Startseite, Vermieten, Verkaufen) stehen als Vorlagen im Theme, wie bisher im statischen
 * HTML unter site/. Blogbeiträge sind WordPress-Beiträge, Impressum und Datenschutz normale Seiten. Die Angebote
 * lädt das Theme selbst aus Propstack (inc/angebote.php).
 */

defined('ABSPATH') || exit;

const HS_TELEFON = '+4982199982940';
const HS_TELEFON_ANZEIGE = '0821 999 829 40';
const HS_MAIL = 'info@heydensattler.com';
// Datum der letzten inhaltlichen Änderung der festen Seiten (dateModified, Sitemap)
const HS_STAND = '2026-10-27';

require __DIR__ . '/inc/grundlagen.php';
require __DIR__ . '/inc/kopf.php';
require __DIR__ . '/inc/blog.php';
require __DIR__ . '/inc/angebote.php';
require __DIR__ . '/inc/dateien.php';
require __DIR__ . '/inc/einrichtung.php';
require __DIR__ . '/inc/mcp.php';
