<?php
// Startseite. Der Inhalt steht hier wie bisher in site/index.html; Angebote und „Aktuelles“ kommen aus Propstack
// und den Blogbeiträgen.

defined('ABSPATH') || exit;

$fragen = [
    ['In welchen Orten ist HeydenSattler als Immobilienmakler tätig?',
        'Wir verkaufen und vermieten Immobilien in Augsburg und Umgebung.'],
    ['Was übernimmt ein Makler beim Verkauf meiner Immobilie in Augsburg?',
        'Wir ermitteln den Verkaufswert, tragen die nötigen Unterlagen wie Energieausweis und Grundriss zusammen, machen Bilder und einen 360-Grad-Rundgang, veröffentlichen das Exposé, wählen Interessenten vor, führen die Besichtigungen durch, berichten Ihnen regelmäßig, unterstützen bei den Verhandlungen, setzen den Vertrag mit dem Notar auf, begleiten den Notartermin und übergeben die Immobilie.'],
    ['Wie finden Sie einen passenden Mieter für meine Wohnung?',
        'Wir veröffentlichen ein Exposé mit Bildern und aufbereitetem Grundriss auf den gängigen Plattformen, bearbeiten die Anfragen, wählen Interessenten vor und führen die Besichtigungen durch. Von den Bewerbern holen wir eine Selbstauskunft ein und prüfen die Bonität, bevor wir den Mietvertrag erstellen.'],
    ['Wie legen Sie die Miete für meine Wohnung fest?',
        'Wir ermitteln den Mietpreis anhand der Wohnung und ihrer Lage und berücksichtigen dabei die gesetzlichen Vorgaben, die in Augsburg gelten.'],
    ['Welche Qualifikationen haben die Makler von HeydenSattler?',
        'Cedric Heyden und Anna Heyden sind zertifizierte Immobilienmakler (IHK) und zertifizierte Immobilienverwalter (DFI). Beide haben einen Master of Engineering und mehrere Jahre Berufserfahrung in Architektur und Stadtplanung beziehungsweise in der Projektsteuerung von Bauprojekten.'],
    ['Wie erreiche ich HeydenSattler?',
        'Telefonisch unter <a href="tel:' . HS_TELEFON . '">' . HS_TELEFON_ANZEIGE . '</a> von Montag bis Freitag zwischen 9:00 und 17:00 Uhr oder per E-Mail an <a href="mailto:' . HS_MAIL . '">' . HS_MAIL . '</a>.'],
];

$titel = 'Immobilienmakler Augsburg – Verkaufen & Vermieten | HeydenSattler';
$start = hs_url('/');
$cedric = $start . '#cedric-heyden';
$anna = $start . '#anna-heyden';
$ld = [[
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'RealEstateAgent',
            '@id' => $start . '#firma',
            'name' => 'HeydenSattler GmbH',
            'alternateName' => 'HeydenSattler',
            'description' => 'Immobilienmakler in Augsburg für den Verkauf und die Vermietung von Wohnungen, Häusern und Gewerbeflächen in Augsburg und Umgebung.',
            'url' => $start,
            'logo' => HS_UPLOADS . '/2023/03/HeydenSattler_CombiLogo_DarkGreen-Cropped.png',
            'image' => HS_UPLOADS . '/2022/05/cover3.png',
            'telephone' => '+49 821 99982940',
            'email' => HS_MAIL,
            'address' => ['@type' => 'PostalAddress', 'postalCode' => '86159', 'addressLocality' => 'Augsburg',
                'addressRegion' => 'Bayern', 'addressCountry' => 'DE'],
            'openingHoursSpecification' => ['@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:00'],
            'areaServed' => [
                ['@type' => 'City', 'name' => 'Augsburg', 'sameAs' => 'https://de.wikipedia.org/wiki/Augsburg'],
                ['@type' => 'City', 'name' => 'Neusäß', 'sameAs' => 'https://de.wikipedia.org/wiki/Neus%C3%A4%C3%9F'],
                ['@type' => 'AdministrativeArea', 'name' => 'Landkreis Augsburg'],
            ],
            'founder' => [['@id' => $cedric], ['@id' => $anna]],
            'employee' => [['@id' => $cedric], ['@id' => $anna]],
            'knowsLanguage' => 'de',
            'vatID' => 'DE352940488',
            'identifier' => ['@type' => 'PropertyValue', 'propertyID' => 'Handelsregister', 'value' => 'Amtsgericht Augsburg HRB 37232'],
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name' => 'Leistungen',
                'itemListElement' => [
                    ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', '@id' => $start . '#verkaufen',
                        'name' => 'Immobilie verkaufen in Augsburg', 'serviceType' => 'Immobilienverkauf',
                        'description' => 'Wertermittlung, Unterlagen, Objektaufnahme mit Bildern und 360-Grad-Rundgang, Exposé, Besichtigungen, Reporting, Vertragsverhandlung, Notartermin und Übergabe.',
                        'areaServed' => 'Augsburg']],
                    ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', '@id' => $start . '#vermieten',
                        'name' => 'Immobilie vermieten in Augsburg', 'serviceType' => 'Vermietung',
                        'description' => 'Mietpreisfindung nach den gesetzlichen Vorgaben, Exposé, Vorauswahl der Interessenten, Besichtigungen, Bonitätscheck, Mietvertrag und Wohnungsübergabe mit Protokoll.',
                        'areaServed' => 'Augsburg']],
                ],
            ],
        ],
        [
            '@type' => 'Person',
            '@id' => $cedric,
            'name' => 'Cedric Heyden',
            'honorificSuffix' => 'M. Eng.',
            'jobTitle' => 'Geschäftsführer, Immobilienmakler',
            'worksFor' => ['@id' => $start . '#firma'],
            'image' => HS_UPLOADS . '/2022/08/DSC09558b-e1694595155471-251x300.jpg',
            'knowsAbout' => ['Immobilienbewertung', 'Immobilienmarketing', 'Architektur', 'Stadtplanung', 'Energieeffizientes Bauen'],
            'hasCredential' => [
                ['@type' => 'EducationalOccupationalCredential', 'name' => 'Zertifizierter Immobilienmakler (IHK)', 'recognizedBy' => ['@type' => 'Organization', 'name' => 'IHK']],
                ['@type' => 'EducationalOccupationalCredential', 'name' => 'Zertifizierter Immobilienverwalter (DFI)', 'recognizedBy' => ['@type' => 'Organization', 'name' => 'DFI']],
                ['@type' => 'EducationalOccupationalCredential', 'credentialCategory' => 'degree', 'name' => 'Master of Engineering, Energie Effizienz Design'],
            ],
        ],
        [
            '@type' => 'Person',
            '@id' => $anna,
            'name' => 'Anna Heyden',
            'honorificSuffix' => 'M. Eng.',
            'jobTitle' => 'Geschäftsführerin, Immobilienmaklerin',
            'worksFor' => ['@id' => $start . '#firma'],
            'image' => HS_UPLOADS . '/2023/08/DSC00735-scaled-e1694546678891-251x300.jpg',
            'knowsAbout' => ['Vermietung', 'Immobilienverkauf', 'Projektsteuerung', 'Bauprojektmanagement'],
            'hasCredential' => [
                ['@type' => 'EducationalOccupationalCredential', 'name' => 'Zertifizierte Immobilienmaklerin (IHK)', 'recognizedBy' => ['@type' => 'Organization', 'name' => 'IHK']],
                ['@type' => 'EducationalOccupationalCredential', 'name' => 'Zertifizierte Immobilienverwalterin (DFI)', 'recognizedBy' => ['@type' => 'Organization', 'name' => 'DFI']],
                ['@type' => 'EducationalOccupationalCredential', 'credentialCategory' => 'degree', 'name' => 'Master of Engineering, Projektmanagement Bau und Immobilie'],
            ],
        ],
        [
            '@type' => 'WebSite',
            '@id' => $start . '#website',
            'url' => $start,
            'name' => 'HeydenSattler',
            'inLanguage' => 'de-DE',
            'publisher' => ['@id' => $start . '#firma'],
        ],
        [
            '@type' => 'WebPage',
            '@id' => $start . '#startseite',
            'url' => $start,
            'name' => $titel,
            'isPartOf' => ['@id' => $start . '#website'],
            'about' => ['@id' => $start . '#firma'],
            'inLanguage' => 'de-DE',
            'dateModified' => HS_STAND,
        ],
        hs_faq_ld($fragen),
    ],
]];
// Angebote als eigene Liste (aus Propstack)
$angebote_ld = hs_angebote_ld();
if ($angebote_ld) {
    $ld[] = $angebote_ld;
}

hs_seite([
    'titel' => $titel,
    'beschreibung' => 'HeydenSattler ist Ihr IHK-zertifizierter Immobilienmakler in Augsburg. Wir verkaufen und vermieten Wohnungen und Häuser in Augsburg und Umgebung – mit Wertermittlung, Exposé, Besichtigungen und Vertrag.',
    'url' => $start,
    'og_titel' => 'HeydenSattler – Ihre Immobilienmakler aus Augsburg',
    'og_beschreibung' => 'Wir verkaufen und vermieten Wohnungen und Häuser in Augsburg und Umgebung – mit dem Blick von Ingenieuren aus Architektur und Bauprojektsteuerung.',
    'geo' => true,
    'extra' => '<link rel="sitemap" type="application/xml" href="' . esc_url(hs_url('/sitemap.xml')) . "\">\n",
    'ld' => $ld,
]);
get_header();
?>
<main id="inhalt">

  <section class="hero">
    <div class="hero-bild">
      <picture>
        <source type="image/webp" srcset="<?= esc_attr(hs_hero_srcset()) ?>" sizes="100vw">
        <img src="<?= esc_url(hs_bild('cover3-1400.jpg')) ?>" alt="" width="2049" height="1130" fetchpriority="high">
      </picture>
    </div>
    <div class="wrap">
      <h1>Ihre Immobilien&shy;makler aus Augsburg</h1>
      <p class="unterzeile">Experten für die Vermietung und den Verkauf Ihrer Immobilie in Augsburg und Umgebung</p>
    </div>
  </section>

  <div class="wrap">
    <div class="karten" id="leistungen">
      <a class="karte" href="<?= esc_url(hs_url('/leistungen/vermieten/')) ?>">
        <svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M31 15a12 12 0 0 0-21.5-3"/><path d="M9 6v6h6"/>
          <path d="M9 25a12 12 0 0 0 21.5 3"/><path d="M31 34v-6h-6"/>
        </svg>
        <h2>Vermieten</h2>
        <p>Sie möchten Ihr Objekt vermieten?<br>Wir helfen Ihnen, den geeigneten Mieter zu finden!</p>
      </a>
      <a class="karte" href="<?= esc_url(hs_url('/leistungen/verkaufen/')) ?>">
        <svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="20" cy="20" r="15"/>
          <path d="M25 14.5a7 7 0 1 0 0 11"/><path d="M12 18h9M12 22h9"/>
        </svg>
        <h2>Verkaufen</h2>
        <p>Beim Verkauf Ihrer Immobilie benötigen Sie Unterstützung?<br>Wir sind gerne für Sie da!</p>
      </a>
    </div>
  </div>

  <section class="abschnitt" id="team">
    <div class="wrap">
      <h2>Ihre Immobilienexperten:</h2>

      <article class="person">
        <img src="<?= HS_UPLOADS ?>/2022/08/DSC09558b-e1694595155471-251x300.jpg" alt="Cedric Heyden, Immobilienmakler bei HeydenSattler in Augsburg" width="251" height="300" loading="lazy" decoding="async">
        <div>
          <h3>Cedric Heyden, M. Eng.</h3>
          <ul class="haken">
            <li>Zertifizierter Immobilienmakler (IHK)</li>
            <li>Zertifizierter Immobilienverwalter (DFI)</li>
            <li>Mehrere Jahre Berufserfahrung in Architektur und Stadtplanung</li>
            <li>Masterstudium „Energie Effizienz Design“</li>
            <li>Bachelorstudium „Energieeffizientes Planen und Bauen“</li>
            <li>Technische Ausbildung</li>
          </ul>
          <p class="rolle">Fachmann für Immobilienbewertung und Marketing</p>
        </div>
      </article>

      <article class="person">
        <img src="<?= HS_UPLOADS ?>/2023/08/DSC00735-scaled-e1694546678891-251x300.jpg" alt="Anna Heyden, Immobilienmaklerin bei HeydenSattler in Augsburg" width="251" height="300" loading="lazy" decoding="async">
        <div>
          <h3>Anna Heyden, M. Eng.</h3>
          <ul class="haken">
            <li>Zertifizierte Immobilienmaklerin (IHK)</li>
            <li>Zertifizierte Immobilienverwalterin (DFI)</li>
            <li>Mehrere Jahre Berufserfahrung in der Projektsteuerung</li>
            <li>Masterstudium „Projektmanagement Bau und Immobilie“</li>
            <li>Bachelorstudium „Energieeffizientes Planen und Bauen“</li>
            <li>Ausgebildete Bankkauffrau</li>
          </ul>
          <p class="rolle">Spezialistin für Vermietung und Verkauf</p>
        </div>
      </article>

      <div class="team-aktion"><a class="btn" href="#kontakt">Kontakt aufnehmen</a></div>
    </div>
  </section>

  <section class="abschnitt" id="angebote">
    <div class="wrap">
      <h2>Unsere aktuellen Immobilienangebote:</h2>
<?php hs_angebote_startseite(); ?>
    </div>
  </section>
<?php hs_blog_aktuelles(); ?>

<?php hs_faq('Häufige Fragen an Ihren Makler in Augsburg', $fragen); ?>

<?php hs_kontakt('Sie möchten Ihre Immobilie in Augsburg verkaufen oder vermieten? Rufen Sie uns an oder schreiben Sie uns, wir melden uns bei Ihnen.',
    'Anfrage über heydensattler.com'); ?>
</main>
<?php
get_footer();
