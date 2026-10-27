#!/bin/sh
# Lädt die CI-Schriften (Söhne von Klim) von heydensattler.com in site/.
# Sie sind lizenziert und deshalb nicht im Repository.
set -e
ziel="$(dirname "$0")/../site/wp-content/uploads/2022/05"
mkdir -p "$ziel"
for f in soehne-buch.woff2 soehne-breit-kraftig.woff2; do
  curl -sfL -o "$ziel/$f" "https://heydensattler.com/wp-content/uploads/2022/05/$f"
done
echo "Schriften liegen in $ziel"
