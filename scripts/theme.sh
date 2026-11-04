#!/bin/sh
# Baut das installierbare WordPress-Theme: wordpress/heydensattler/ plus CSS, JavaScript und Hero-Bild aus site/.
# Ergebnis: _theme/heydensattler/ und _theme/heydensattler.zip (WordPress: Design → Themes → Theme hochladen).
#
# Die Version im Theme ist VERSION (z. B. der Tag bei einer Veröffentlichung), sonst Datum und Commit, mit „-lokal“,
# wenn Theme oder site/assets ungespeicherte Änderungen haben. Nach dem Hochladen ist nichts weiter zu tun: Das Theme
# liest fehlende oder geänderte Adressregeln selbst neu ein.
set -e
wurzel="$(cd "$(dirname "$0")/.." && pwd)"
ziel="$wurzel/_theme/heydensattler"
if [ -z "$VERSION" ]; then
  VERSION="$(date +%Y.%m.%d)-$(git -C "$wurzel" rev-parse --short HEAD)"
  if [ -n "$(git -C "$wurzel" status --porcelain -- wordpress/heydensattler site/assets)" ]; then
    VERSION="$VERSION-lokal"
  fi
fi
case "$VERSION" in
  *[!A-Za-z0-9._-]*) echo "VERSION darf nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich enthalten: $VERSION" >&2; exit 1 ;;
esac
version="$VERSION"

rm -rf "$wurzel/_theme"
mkdir -p "$ziel"
# Ohne assets/: der Ordner ist im Repository leer (nur Einhängepunkt der Testumgebung)
(cd "$wurzel/wordpress/heydensattler" && find . -type f ! -path './assets/*' ! -name '.DS_Store' | while read -r f; do
  mkdir -p "$ziel/$(dirname "$f")" && cp "$f" "$ziel/$f"
done)
mkdir -p "$ziel/assets/bilder"
cp "$wurzel/site/assets/style.css" "$wurzel/site/assets/site.js" "$wurzel/site/assets/galerie.js" "$ziel/assets/"
# Die Söhne-Schriften nicht: sie sind lizenziert und liegen schon in der Mediathek von heydensattler.com
cp "$wurzel"/site/wp-content/uploads/2022/05/cover3-* "$ziel/assets/bilder/"
sed "s/^Version: .*/Version: $version/" "$wurzel/wordpress/heydensattler/style.css" > "$ziel/style.css"

if command -v zip >/dev/null; then
  (cd "$wurzel/_theme" && zip -qr heydensattler.zip heydensattler)
  echo "Theme $version in $ziel und _theme/heydensattler.zip"
else
  echo "Theme $version in $ziel (ohne ZIP: zip ist nicht installiert)"
fi
