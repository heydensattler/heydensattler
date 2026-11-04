<?php
// /leistungen/verkaufen/ (Seite mit der Titelform „verkaufen“)

defined('ABSPATH') || exit;

get_template_part('template-parts/leistung', null, [
    'slug' => 'verkaufen',
    'name' => 'Verkaufen',
    'titel' => 'Immobilie verkaufen in Augsburg – Makler mit Wertermittlung | HeydenSattler',
    'beschreibung' => 'Wir verkaufen Ihre Immobilie in Augsburg: Wertermittlung, Exposé mit Bildern und 360-Grad-Rundgang, Besichtigungen, Reporting, Notartermin und Übergabe. Jetzt Kontakt aufnehmen.',
    'leistung' => 'Verkauf von Wohnungen und Häusern in Augsburg',
    'art' => 'Immobilienverkauf',
    'einleitung' => ['Wir verkaufen Ihre Immobilie für Sie!',
        'Sie möchten Ihre Immobilie verkaufen? Doch Sie wissen nicht, auf was Sie alles achten müssen? Wir unterstützen Sie.'],
    'ablauf' => [
        ['Vorbereitung des Verkaufs', [
            'Erstes Kennenlernen',
            'Begehung der Immobilie',
            'Zusammentragen der für den Verkauf notwendigen Unterlagen (u. a. Energieausweis, Grundriss)',
            'Ermittlung des Verkaufswerts',
        ]],
        ['Vermarktung Ihrer Immobilie', [
            'Objektaufnahme inkl. aussagekräftiger Bilder',
            '360-Grad-Rundgang',
            'Aufbereitung der vorhandenen Unterlagen',
            'Veröffentlichung des Exposés auf ausgewählten Plattformen',
        ]],
        ['Anfragen und Besichtigungen', [
            'Bearbeitung von Anfragen',
            'Vorauswahl der Interessenten',
            'Organisation, Vorbereitung und Durchführung der Besichtigungen',
            'Regelmäßiges Reporting an den Eigentümer',
        ]],
        ['Vertragsabschluss und Übergabe', [
            'Unterstützung bei Vertragsverhandlungen',
            'Aufsetzen des Vertrages in Zusammenarbeit mit dem Notar',
            'Begleitung des Notartermins',
            'Objektübergabe nach erfolgtem Vertragsabschluss',
        ]],
    ],
    'fragen_titel' => 'Häufige Fragen zum Verkaufen',
    'fragen' => [
        ['Wie läuft der Verkauf meiner Immobilie mit HeydenSattler ab?',
            'In vier Schritten: Vorbereitung mit Kennenlernen, Begehung, Unterlagen und Wertermittlung; Vermarktung mit Bildern, 360-Grad-Rundgang und Exposé; Anfragen und Besichtigungen mit regelmäßigem Reporting an Sie; schließlich Vertragsverhandlung, Vertrag mit dem Notar, Notartermin und Übergabe.'],
        ['Ermitteln Sie den Wert meiner Immobilie?',
            'Ja. Nach der Begehung Ihrer Immobilie ermitteln wir den Verkaufswert, bevor die Vermarktung beginnt.'],
        ['Welche Unterlagen brauche ich für den Verkauf?',
            'Unter anderem den Energieausweis und den Grundriss. Wir tragen die notwendigen Unterlagen gemeinsam mit Ihnen zusammen und bereiten sie für das Exposé auf.'],
        ['Begleiten Sie mich zum Notartermin?',
            'Ja. Wir setzen den Kaufvertrag in Zusammenarbeit mit dem Notar auf, begleiten Sie zum Notartermin und übergeben die Immobilie nach dem Vertragsabschluss.'],
    ],
    'querlinks' => hs_querlink('/leistungen/vermieten/', 'Vermieten', 'Sie möchten Ihre Immobilie lieber vermieten?')
        . hs_querlink('/#angebote', 'Aktuelle Angebote', 'Wohnungen und Gewerbeflächen in Augsburg und Neusäß'),
]);
