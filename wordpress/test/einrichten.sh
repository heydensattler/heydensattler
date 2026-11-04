#!/bin/sh
# Startet das Test-WordPress (compose.yaml daneben) und richtet es ein wie heydensattler.com: Theme aktiv, Seiten
# und Einstellungen über die Einrichtung des Themes, die Beispielbeiträge aus scripts/beispiel-blog/ (einer davon
# geplant, einer Entwurf), Impressum und Datenschutzerklärung als Testseiten und die Angebote aus
# scripts/beispiel-angebote.json. Danach http://localhost:8733, Admin unter /wp-admin/ (admin / admin).
#
# Braucht Docker und die Python-Umgebung mit Markdown (siehe README, „Blog“). Nochmal von vorn:
# docker compose -f wordpress/test/compose.yaml down -v, dann dieses Skript.
set -e
cd "$(dirname "$0")"

python=../../.venv/bin/python
if ! "$python" -c 'import markdown' 2>/dev/null; then
  echo "Python-Markdown fehlt: python3 -m venv .venv && .venv/bin/pip install -r requirements.txt" >&2
  exit 1
fi
"$python" beitraege.py

docker compose up -d --quiet-pull
wp() { docker compose exec -T cli wp "$@"; }

# wp-config.php legt der WordPress-Container beim ersten Start an; die Datenbank braucht ein paar Sekunden
versuche=0
until docker compose exec -T cli test -f /var/www/html/wp-config.php && wp db check >/dev/null 2>&1; do
  versuche=$((versuche + 1))
  if [ "$versuche" -ge 60 ]; then
    echo "WordPress oder die Datenbank startet nicht. Letzte Meldung:" >&2
    wp db check >&2 || true
    exit 1
  fi
  sleep 2
done

if wp core is-installed 2>/dev/null; then
  if [ "$(wp option get hs_test_eingerichtet 2>/dev/null)" = 1 ]; then
    echo "WordPress ist schon eingerichtet: http://localhost:8733"
    exit 0
  fi
  echo "Die letzte Einrichtung ist nicht fertig geworden. Von vorn:" >&2
  echo "  docker compose -f wordpress/test/compose.yaml down -v && wordpress/test/einrichten.sh" >&2
  exit 1
fi

# Die eingehängten Schriften gehören root; Uploads muss WordPress selbst schreiben dürfen
docker compose exec -T wp chown www-data:www-data /var/www/html/wp-content/uploads /var/www/html/wp-content/uploads/2022
wp core install --url=http://localhost:8733 --title=HeydenSattler --admin_user=admin --admin_password=admin \
  --admin_email=test@example.com --skip-email
# Das Image bringt WordPress 6.4 mit; heydensattler.com läuft mit einer neueren Version
if wp core update --quiet; then
  wp core update-db --quiet
else
  echo "WordPress nicht aktualisiert (offline?), es bleibt bei $(wp core version)"
fi
wp language core install de_DE --activate --quiet || true
wp option update timezone_string Europe/Berlin --quiet
# Keine Pingbacks an die Websites, auf die Beispielbeiträge verlinken
wp option update default_pingback_flag 0 --quiet
wp post delete 1 2 --force --quiet

wp theme activate heydensattler
wp eval 'hs_einrichten();'

echo "Beispielbeiträge:"
docker compose exec -T cli sh /arbeit/_import/import.sh
wp post create --post_type=page --post_status=publish --post_title=Impressum --post_name=impressum --quiet \
  --post_content='<p>Testinhalt: HeydenSattler GmbH, Amtsgericht Augsburg HRB 37232.</p><h2>Aufsichtsbehörde</h2><p>IHK München und Oberbayern.</p>'
# Wie auf heydensattler.com in Layout-Shortcodes von WPBakery; das Theme zeigt nur den Text
wp post create --post_type=page --post_status=publish --post_title='Datenschutzerklärung' --post_name=datenschutzerklaerung --quiet \
  --post_content='[vc_row][vc_column][vc_column_text]<h2>Datenschutz auf einen Blick</h2><p>Testinhalt Datenschutzerklärung.</p>[/vc_column_text][/vc_column][/vc_row]'

echo "Angebote:"
wp eval 'hs_angebote_aktualisieren(); $lauf = get_option("hs_angebote_lauf");
  foreach ($lauf["protokoll"] as $zeile) { echo "  {$zeile["text"]}\n"; }
  echo "  {$lauf["meldung"]}\n";'

wp option update hs_test_eingerichtet 1 --quiet
echo "Fertig: http://localhost:8733"
