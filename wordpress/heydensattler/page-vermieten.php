<?php
// /leistungen/vermieten/ (Seite mit der Titelform „vermieten“)

defined('ABSPATH') || exit;

get_template_part('template-parts/leistung', null, [
    'slug' => 'vermieten',
    'name' => 'Vermieten',
    'titel' => 'Immobilie vermieten in Augsburg – Mietersuche vom Makler | HeydenSattler',
    'beschreibung' => 'Wir vermieten Ihre Wohnung in Augsburg: Mietpreisfindung, Exposé, Vorauswahl, Besichtigungen, Bonitätscheck, Mietvertrag und Übergabe mit Protokoll. Jetzt Kontakt aufnehmen.',
    'leistung' => 'Vermietung von Wohnungen und Häusern in Augsburg',
    'art' => 'Vermietung',
    'einleitung' => ['Sie suchen einen neuen Mieter für Ihre Immobilie?',
        'Mit unserem Umzugsservice unterstützen wir Sie von der Erstellung eines aussagekräftigen Exposés bis zur Übergabe des Objekts an den neuen Mieter.'],
    'ablauf' => [
        ['Vorbereitung & Vermarktung', [
            'Objektaufnahme inkl. Bilder',
            'Aufbereitung des vorhandenen Grundrisses',
            'Mietpreisfindung unter Berücksichtigung gesetzlicher Vorgaben',
            'Veröffentlichung des Exposés auf den gängigen Plattformen',
        ]],
        ['Anfragen & Besichtigungen', [
            'Bearbeitung von Anfragen',
            'Vorauswahl der Interessenten',
            'Organisation, Vorbereitung und Durchführung der Besichtigungen',
            'Einholen von Selbstauskünften und Bonitätscheck',
        ]],
        ['Mietvertrag & Übergabe', [
            'Erstellung des Mietvertrags',
            'Teilnahme bei Besprechung und Unterzeichnung des Mietvertrags',
            ['Rücknahme der Wohnung des Vormieters mit Protokollierung von Mängeln und Ablesen der Zählerstände', true],
            ['Wohnungsübergabe an den neuen Mieter mit Protokollierung von Mängeln und Ablesen der Zählerstände', true],
        ]],
    ],
    'fragen_titel' => 'Häufige Fragen zum Vermieten',
    'fragen' => [
        ['Wie läuft die Vermietung meiner Wohnung mit HeydenSattler ab?',
            'In drei Schritten: Zuerst nehmen wir die Wohnung mit Bildern auf, bereiten den Grundriss auf, ermitteln den Mietpreis und veröffentlichen das Exposé. Dann bearbeiten wir die Anfragen, wählen Interessenten vor und führen die Besichtigungen durch. Zum Schluss erstellen wir den Mietvertrag, sind bei der Unterzeichnung dabei und übergeben die Wohnung auf Wunsch an den neuen Mieter.'],
        ['Prüfen Sie die Bonität der Mietinteressenten?',
            'Ja. Bevor wir den Mietvertrag erstellen, holen wir von den Interessenten eine Selbstauskunft ein und prüfen ihre Bonität.'],
        ['Wie wird die Miete für meine Wohnung in Augsburg festgelegt?',
            'Wir ermitteln den Mietpreis anhand der Wohnung und ihrer Lage und berücksichtigen dabei die gesetzlichen Vorgaben.'],
        ['Übernehmen Sie auch die Wohnungsübergabe?',
            'Auf Wunsch ja: Wir nehmen die Wohnung vom Vormieter zurück und übergeben sie an den neuen Mieter, jeweils mit Protokoll über Mängel und abgelesenen Zählerständen.'],
    ],
    'querlinks' => hs_querlink('/leistungen/verkaufen/', 'Verkaufen', 'Sie möchten Ihre Immobilie lieber verkaufen?')
        . hs_querlink('/#angebote', 'Aktuelle Angebote', 'Wohnungen und Gewerbeflächen in Augsburg und Neusäß'),
]);
