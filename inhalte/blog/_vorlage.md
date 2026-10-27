---
# Vorlage für einen Blogbeitrag. Dateien, die mit _ beginnen, erscheinen nie.
# Zum Schreiben kopieren, z. B. nach inhalte/blog/mein-thema.md, und die Werte ersetzen.
# Zeilen mit # sind Kommentare. Werte mit Doppelpunkt oder # in Anführungszeichen setzen.
#
# ACHTUNG: Das Repository ist öffentlich. Alles, was hier eingecheckt wird, ist auf GitHub sofort lesbar,
# auch wenn die Website den Beitrag erst ab publish_date zeigt. Entwürfe darum außerhalb des Repositorys halten.

# Pflicht: Überschrift des Beitrags
title: "Titel des Beitrags"

# Pflicht: ab wann der Beitrag erscheint (Zeit in Augsburg). Nur Datum = 00:00 Uhr.
# Der Build läuft alle 30 Minuten; der Beitrag erscheint also bis zu etwa 30 Minuten nach diesem Termin.
publish_date: 2026-11-03 09:00

# Für Suchmaschinen, Vorschau beim Teilen und die Karte im Blog, etwa 120 bis 155 Zeichen.
# Fehlt sie, nimmt der Build den Anfang des ersten Absatzes.
description: "Ein bis zwei Sätze, worum es geht."

# cedric-heyden oder anna-heyden; leer lassen für „HeydenSattler“
author: cedric-heyden

# Optional: Adresse /blog/<slug>/. Ohne Angabe aus dem Titel (ä wird ae usw.). Nach dem Erscheinen nicht mehr
# ändern, sonst gehen geteilte Links ins Leere.
# slug: titel-des-beitrags

# Optional: Bild relativ zu dieser Datei, am besten JPEG oder WebP, etwa 1600 px breit, unter 600 KB.
# image: bilder/mein-thema.jpg
# image_alt: "Was auf dem Bild zu sehen ist"

# Optional: Datum der letzten inhaltlichen Änderung
# updated: 2026-11-10

# true = Entwurf, erscheint nie (auch nicht nach publish_date)
draft: true
---

Der erste Absatz ist die Einleitung. Danach Zwischenüberschriften mit zwei Rauten:

## Zwischenüberschrift

Text mit **Hervorhebung**, einem [Link](https://heydensattler.com/) und einem Link auf einen anderen Beitrag:
[anderer Beitrag](anderer-beitrag.md).

- Aufzählung
- noch ein Punkt

1. nummerierte Liste
2. zweiter Schritt

> Ein Zitat oder ein Merksatz.

![Was auf dem Bild zu sehen ist](bilder/foto.jpg "Bildunterschrift (optional)")

| Spalte | Spalte |
| --- | --- |
| Wert | Wert |

<!-- HTML-Kommentare wie dieser erscheinen nicht auf der Website (im Repository sind sie trotzdem lesbar). -->
