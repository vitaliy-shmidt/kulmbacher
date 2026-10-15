<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Stadtsteinach (docs/04-quellen.md Abschnitt 11).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten HTML-Ausschnitten im Aufbau der echten Seiten (TYPO3/calendarize, Stand 2026-10-15):
 * keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-stadtsteinach.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-stadtsteinach.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Stadtsteinach' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Stadtsteinach) ist nicht aktiv.\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;

/**
 * @param string $name     Testname.
 * @param mixed  $actual   Ergebnis.
 * @param mixed  $expected Erwartung.
 */
function ec_check( $name, $actual, $expected ) {
	global $passed, $failed;
	if ( $actual === $expected ) {
		$passed++;
		echo "  ok    {$name}\n";
		return;
	}
	$failed++;
	echo "  FEHLER {$name}\n         erwartet: " . var_export( $expected, true ) . "\n         erhalten: " . var_export( $actual, true ) . "\n";
}

/**
 * Detail-Pfad (relativ, wie im HTML mit &amp;).
 *
 * @param int $index Index-ID.
 * @return string
 */
function st_href( $index ) {
	return '/freizeit-kultur-tourismus/veranstaltungen/veranstaltungen-detailansicht?tx_calendarize_calendar%5Baction%5D=detail&amp;tx_calendarize_calendar%5Bcontroller%5D=Calendar&amp;tx_calendarize_calendar%5Bindex%5D=' . $index . '&amp;cHash=abc' . $index;
}

/**
 * Volle Detail-URL (wie der Adapter sie bildet).
 *
 * @param int $index Index-ID.
 * @return string
 */
function st_url( $index ) {
	return 'https://www.stadtsteinach.de' . html_entity_decode( st_href( $index ) );
}

/**
 * Ein Listeneintrag.
 *
 * @param int    $index Index-ID.
 * @param string $date  "15.10.2026".
 * @param string $time1 "09:30" oder ''.
 * @param string $time3 "- 11:00 Uhr", "Uhr" oder ''.
 * @param string $title Titel (HTML).
 * @return string
 */
function st_item( $index, $date, $time1, $time3, $title ) {
	$t = '' === $time1 && '' === $time3 ? '' : '<span class="event-time1">' . $time1 . '</span><span class="event-time3"> ' . $time3 . ' </span>';
	return '<div class="panel panel-default state-default"><div class="panel-body"><div class="media calendarize-item"><a href="' . st_href( $index ) . '"><img src="/x.png"></a><div class="media-body"><p class="event-list-date-time"><span class="event-date">' . $date . '</span>' . $t . '</p><p class="event-list-title"><a href="' . st_href( $index ) . '"> ' . $title . ' </a></p></div></div></div></div>';
}

/**
 * Listenseite mit optionalem Link "nächste".
 *
 * @param array  $items Einträge.
 * @param string $next  Pfad oder ''.
 * @return string
 */
function st_page( array $items, $next = '' ) {
	return '<html><body><div class="calendarize">' . implode( '', $items ) . '<ul class="f3-widget-paginator"><li class="current">1</li>' . ( '' === $next ? '' : '<li><a href="' . $next . '">2</a></li><li><a href="' . $next . '">nächste</a></li>' ) . '</ul></div></body></html>';
}

/**
 * Detailseite.
 *
 * @param string|null $venue Ortstext oder null (Feld fehlt).
 * @return string
 */
function st_detail( $venue ) {
	return '<html><body><div class="calendarize"><h3>X</h3><div class="text"><p>Langer Text mit Telefon 09225/1234</p></div><dl class="dl-horizontal meta">'
		. ( null === $venue ? '' : '<dt>Veranstaltungsort:</dt><dd> ' . $venue . ' </dd>' ) . '<dt>Veranstalter:</dt><dd>Verein</dd></dl></div></body></html>';
}

$source = new Eventcrawler_Source_Stadtsteinach();
$one    = function ( $item, $venue ) use ( $source ) {
	$list = $source->parse_list( array( st_page( array( $item ) ) ) );
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	return $source->parse( array( st_page( array( $item ) ) ), array( $list[0]['event_url'] => st_detail( $venue ) ) )[0];
};

echo "Registrierung\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['stadtsteinach'] ), true );
ec_check( 'Reihenfolge', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'stadtsteinach' ), 'Stadtsteinach' );

echo "Normaler Termin mit Ende\n";
$e = $one( st_item( 26182, '15.10.2026', '09:30', '- 11:00 Uhr', 'Digital mit 60+' ), 'Sitzungssaal im Rathaus Stadtsteinach' );
ec_check( 'kein Fehler', isset( $e['_error'] ), false );
ec_check( 'Titel', $e['title'], 'Digital mit 60+' );
ec_check( 'description leer (Langtext nicht übernommen)', $e['description'], '' );
ec_check( 'Datum / Beginn / Ende', array( $e['start_date'], $e['start_time'], $e['end_date'], $e['end_time'] ), array( '2026-10-15', '09:30', null, '11:00' ) );
ec_check( 'all_day false', $e['all_day'], false );
ec_check( 'venue_name = Ortstext', $e['venue_name'], 'Sitzungssaal im Rathaus Stadtsteinach' );
ec_check( 'venue_address leer', $e['venue_address'], '' );
ec_check( 'city', $e['city'], 'Stadtsteinach' );
ec_check( 'external_id = Index-ID', $e['external_id'], '26182' );
ec_check( 'event_url = source_url = Detail-URL mit Query', array( $e['event_url'], $e['source_url'] ), array( st_url( 26182 ), st_url( 26182 ) ) );

echo "Uhrzeiten\n";
$e = $one( st_item( 1, '24.10.2026', '19:00', 'Uhr', 'Herbstball' ), 'Steinachtalhalle Stadtsteinach' );
ec_check( 'nur Beginn ("Uhr") -> kein Ende', array( $e['start_time'], $e['end_time'] ), array( '19:00', null ) );
$e = $one( st_item( 2, '23.01.2027', '', '', 'Gardenachmittag' ), 'Steinachtalhalle Stadtsteinach' );
ec_check( 'ohne Uhrzeit -> NULL, kein Ganztag', array( $e['start_time'], $e['end_time'], $e['all_day'], isset( $e['_error'] ) ), array( null, null, false, false ) );
ec_check( 'unlesbare Uhrzeit -> _error', ! empty( $one( st_item( 3, '24.10.2026', 'abends', 'Uhr', 'A' ), 'Rathaus Stadtsteinach' )['_error'] ), true );
ec_check( 'ungültiges Datum -> _error', ! empty( $one( st_item( 4, '31.02.2027', '19:00', 'Uhr', 'A' ), 'Rathaus Stadtsteinach' )['_error'] ), true );

echo "Ort / Stadt (nicht raten)\n";
foreach ( array(
	// Stadtname im Ortstext (Regel seit A87).
	'Kirche St. Michael Stadtsteinach'      => 'Stadtsteinach',
	'Marktplatz Stadtsteinach'              => 'Stadtsteinach',
	'Steinachtalhalle Stadtsteinach'        => 'Stadtsteinach',
	// Belegte Zuordnungen Ort -> Stadt (A88), exakter Ortstext.
	'Bauernmarktscheune'                    => 'Stadtsteinach',
	'Steinachtalhalle'                      => 'Stadtsteinach',
	'Festplatz in der Knollenstraße'        => 'Stadtsteinach',
	'Burgruine Nordeck'                     => 'Stadtsteinach',
	'Pfarrzentrum St. Hedwig Kulmbach'      => 'Kulmbach', // Schutz gegen eine Pauschalregel "Stadtsteinach".
	// Normalisierung: Groß-/Kleinschreibung, Leerzeichen, geschütztes Leerzeichen, Entity.
	'  STEINACHTALHALLE  '                  => 'Stadtsteinach',
	'Festplatz in der&nbsp;Knollenstra&szlig;e' => 'Stadtsteinach',
	'Burgruine   Nordeck'                   => 'Stadtsteinach',
	// Negativ: ähnlich, aber nicht identisch -> keine Zuordnung, Termin übersprungen.
	'Stadtsteinacher Feuerwehrhaus'         => '',
	'Bauernmarktscheune Untersteinach'      => '',
	'Alte Bauernmarktscheune'               => '',
	'Steinachtalhalle Kulmbach'             => '',
	'Festplatz'                             => '',
	'Festplatz in der Knollenstraße 8'      => '',
	'Burgruine Nordeck Parkplatz'           => '',
	'Pfarrzentrum St. Hedwig'               => '',
	'Pfarrzentrum St. Hedwig Kulmbach-Nord' => '',
	'Unsere Liebe Frau Kulmbach'            => '',
) as $venue => $city ) {
	$e = $one( st_item( 5, '24.10.2026', '19:00', 'Uhr', 'A' ), $venue );
	ec_check( "Ort '{$venue}'", array( $e['city'], empty( $e['_error'] ) ), array( $city, '' !== $city ) );
}
$e = $one( st_item( 5, '17.10.2026', '08:00', '- 12:00 Uhr', 'Stadtsteinacher Bauernmarkt' ), 'Bauernmarktscheune' );
ec_check( 'Zuordnung: Ortstext bleibt wie in der Quelle, keine Adresse ergänzt', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Bauernmarktscheune', '', 'Stadtsteinach' ) );
$e = $one( st_item( 5, '24.10.2026', '19:00', 'Uhr', 'Herbstball' ), 'Pfarrzentrum St. Hedwig Kulmbach' );
ec_check( 'Herbstball: Stadt Kulmbach, nicht Stadtsteinach', $e['city'], 'Kulmbach' );
$e = $one( st_item( 6, '24.10.2026', '08:00', 'Uhr', 'Herbstwanderung auf dem Walberla-Weg' ), null );
ec_check( 'Ort fehlt -> _error', $e['_error'], 'Stadt nicht belegt (Ort: fehlt)' );

echo "Detailseite, Sonderzeichen\n";
$list = $source->parse_list( array( st_page( array( st_item( 7, '24.10.2026', '19:00', 'Uhr', 'A' ) ) ) ) );
ec_check( 'Detailseite fehlt -> _error', $source->parse( array( st_page( array( st_item( 7, '24.10.2026', '19:00', 'Uhr', 'A' ) ) ) ), array() )[0]['_error'], 'Detailseite: nicht abgerufen' );
ec_check( 'Detailseite 404 -> _error', $source->parse( array( st_page( array( st_item( 7, '24.10.2026', '19:00', 'Uhr', 'A' ) ) ) ), array( $list[0]['event_url'] => new WP_Error( 'eventcrawler_http', 'Quelle antwortet mit HTTP 404.' ) ) )[0]['_error'], 'Detailseite: Quelle antwortet mit HTTP 404.' );
ec_check( 'Detailseite ohne dl.meta -> _error', $source->parse( array( st_page( array( st_item( 7, '24.10.2026', '19:00', 'Uhr', 'A' ) ) ) ), array( $list[0]['event_url'] => '<html><body>x</body></html>' ) )[0]['_error'], 'Detailseite ohne Angaben-Block (dl.meta)' );
$e = $one( st_item( 8, '30.10.2026', '19:00', 'Uhr', 'Wir haben sie wohl geweckt &#8230; „Grusel“ – Café &amp; Spaß' ), 'Café Müller Stadtsteinach' );
ec_check( 'Titel … „“ – é & ß', $e['title'], 'Wir haben sie wohl geweckt … „Grusel“ – Café & Spaß' );
ec_check( 'Ort mit é/ü', $e['venue_name'], 'Café Müller Stadtsteinach' );
ec_check( 'Titel fehlt -> leer (Crawler zählt "fehlerhaft")', $one( st_item( 9, '24.10.2026', '19:00', 'Uhr', '' ), 'Rathaus Stadtsteinach' )['title'], '' );

echo "Mehrere Termine, Seiten, Struktur\n";
$pages = array(
	st_page( array( st_item( 18970, '17.10.2026', '08:00', '- 12:00 Uhr', 'Stadtsteinacher Bauernmarkt' ), st_item( 26266, '11.12.2026', '19:00', 'Uhr', 'Adventskonzert' ) ), '/freizeit-kultur-tourismus/veranstaltungen?tx_calendarize_calendar%5BcurrentPage%5D=2&amp;cHash=p2' ),
	st_page( array( st_item( 26266, '11.12.2026', '19:00', 'Uhr', 'Adventskonzert' ), st_item( 26267, '13.12.2026', '19:00', 'Uhr', 'Adventskonzert' ) ) ),
);
ec_check( 'zwei Termine derselben Veranstaltung = zwei IDs, Seitendublette einmal', array_column( $source->parse_list( $pages ), 'external_id' ), array( '18970', '26266', '26267' ) );
ec_check( 'Link "nächste" -> volle URL', $source->next_page_url( $pages[0] ), 'https://www.stadtsteinach.de/freizeit-kultur-tourismus/veranstaltungen?tx_calendarize_calendar%5BcurrentPage%5D=2&cHash=p2' );
ec_check( 'letzte Seite -> kein Link', $source->next_page_url( $pages[1] ), null );
$r = $source->parse_list( array( '<html><body><h1>Wartung</h1></body></html>' ) );
ec_check( 'keine Einträge -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );

echo "Filter (zentral)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $one( st_item( 10, '24.10.2026', '19:00', 'Uhr', 'SPD Ortsverein Testevent' ), 'Rathaus Stadtsteinach' );
ec_check( 'Adapter liefert den Termin', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
