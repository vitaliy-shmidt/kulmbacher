<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters DAS ZENTRUM Bayreuth (docs/04-quellen.md Abschnitt 5).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten API-Antworten im Aufbau der echten TEC-REST-API (Stand 2026-10-15):
 * keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-das-zentrum-bayreuth.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-das-zentrum-bayreuth.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Das_Zentrum_Bayreuth' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle DAS ZENTRUM Bayreuth) ist nicht aktiv.\n" );
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

$europasaal = array( 'id' => 58, 'venue' => 'DAS ZENTRUM – Europasaal', 'address' => 'Äußere Badstraße 7a', 'city' => 'Bayreuth', 'zip' => '95448' );
$kleinkunst = array( 'id' => 60, 'venue' => 'DAS ZENTRUM – Kleinkunstbühne' );

/**
 * Ein Eintrag im Aufbau der API (nur die verwendeten Felder).
 *
 * @param int    $id    Post-ID.
 * @param string $title Titel (mit Entities wie in der API).
 * @param string $start "Y-m-d H:i:s".
 * @param array  $venue Ort.
 * @param array  $more  Abweichende Felder.
 * @return array
 */
function dz_item( $id, $title, $start, array $venue, array $more = array() ) {
	return array_merge(
		array(
			'id'          => $id,
			'title'       => $title,
			'url'         => 'https://www.das-zentrum.de/veranstaltung/test-' . $id . '/',
			'start_date'  => $start,
			'end_date'    => $start, // Ende = Beginn wie bei 67 von 73 echten Terminen
			'all_day'     => false,
			'timezone'    => 'Europe/Berlin',
			'excerpt'     => '',
			'description' => '<p><strong>Langer Werbetext</strong></p>',
			'venue'       => $venue,
		),
		$more
	);
}

/**
 * @param array[] $items Einträge.
 * @param int     $pages total_pages.
 * @return string JSON wie die API.
 */
function dz_page( array $items, $pages = 1 ) {
	return wp_json_encode( array( 'events' => $items, 'total' => count( $items ), 'total_pages' => $pages ) );
}

$source = new Eventcrawler_Source_Das_Zentrum_Bayreuth();
$one    = function ( array $item ) use ( $source ) {
	$r = $source->parse( array( dz_page( array( $item ) ) ) );
	return is_wp_error( $r ) ? $r : $r[0];
};

echo "Registrierung\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['das-zentrum-bayreuth'] ), true );
ec_check( 'Reihenfolge der Quellen', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus', 'concertbuero-franken' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'das-zentrum-bayreuth' ), 'DAS ZENTRUM Bayreuth' );
ec_check( 'Crawl-URL', $source->get_url(), 'https://www.das-zentrum.de/wp-json/tribe/events/v1/events' );
ec_check( 'Matching nicht über URL', $source->has_unique_detail_urls(), false );

echo "Normaler Termin (Europasaal)\n";
$e = $one( dz_item( 185, 'Sarah Bosetti &#8211; Make Democracy Great Again', '2026-10-15 19:30:00', $europasaal ) );
ec_check( 'kein Fehler', isset( $e['_error'] ), false );
ec_check( 'Titel, Entity dekodiert', $e['title'], 'Sarah Bosetti – Make Democracy Great Again' );
ec_check( 'description leer (kein Kurztext, Langtext nicht übernommen)', $e['description'], '' );
ec_check( 'start_date', $e['start_date'], '2026-10-15' );
ec_check( 'start_time', $e['start_time'], '19:30' );
ec_check( 'Ende = Beginn -> end_date NULL', $e['end_date'], null );
ec_check( 'Ende = Beginn -> end_time NULL', $e['end_time'], null );
ec_check( 'all_day false', $e['all_day'], false );
ec_check( 'venue_name', $e['venue_name'], 'DAS ZENTRUM – Europasaal' );
ec_check( 'venue_address', $e['venue_address'], 'Äußere Badstraße 7a' );
ec_check( 'city', $e['city'], 'Bayreuth' );
ec_check( 'external_id = Post-ID', $e['external_id'], '185' );
ec_check( 'event_url = source_url = Detailseite', array( $e['event_url'], $e['source_url'] ), array( 'https://www.das-zentrum.de/veranstaltung/test-185/', 'https://www.das-zentrum.de/veranstaltung/test-185/' ) );

echo "Sonderzeichen\n";
$e = $one( dz_item( 400, 'Pippo Pollina &amp; Quartetto Acustico &#8211; La vita è bella così com&#8217;è. Joe Di Nardo &#8211; Don&#180;t &#8222;Tatschi&#8220; Äöüß', '2026-12-05 20:00:00', $europasaal ) );
ec_check( '& – ’ ´ „“ é ä ö ü ß', $e['title'], 'Pippo Pollina & Quartetto Acustico – La vita è bella così com’è. Joe Di Nardo – Don´t „Tatschi“ Äöüß' );
$e = $one( dz_item( 401, 'X', '2026-12-05 20:00:00', array( 'id' => 61, 'venue' => 'DAS ZENTRUM &#8211; Ganzes Haus', 'address' => 'Äußere Badstraße 7a', 'city' => 'Bayreuth' ) ) );
ec_check( 'Ortsname mit Entity', $e['venue_name'], 'DAS ZENTRUM – Ganzes Haus' );

echo "Ende / Ganztag\n";
$e = $one( dz_item( 277, 'Vortrag', '2026-11-17 19:00:00', $europasaal, array( 'end_date' => '2026-11-17 21:00:00' ) ) );
ec_check( 'echtes Ende 19:00–21:00', array( $e['start_time'], $e['end_date'], $e['end_time'] ), array( '19:00', '2026-11-17', '21:00' ) );
$e = $one( dz_item( 278, 'Ende vor Beginn', '2026-11-17 19:00:00', $europasaal, array( 'end_date' => '2026-11-17 18:00:00' ) ) );
ec_check( 'Ende vor Beginn -> kein Ende', array( $e['end_date'], $e['end_time'] ), array( null, null ) );
$e = $one( dz_item( 279, 'Party', '2026-12-12 22:00:00', $europasaal, array( 'end_date' => '2026-12-13 04:00:00' ) ) );
ec_check( 'Ende am Folgetag', array( $e['end_date'], $e['end_time'] ), array( '2026-12-13', '04:00' ) );
$e = $one( dz_item( 280, 'Fest', '2026-10-18 00:00:00', $europasaal, array( 'all_day' => true, 'end_date' => '2026-10-18 23:59:59' ) ) );
ec_check( 'ganztägig 1 Tag', array( $e['all_day'], $e['start_time'], $e['end_date'], $e['end_time'] ), array( true, null, null, null ) );
$e = $one( dz_item( 281, 'Fest', '2026-10-18 00:00:00', $europasaal, array( 'all_day' => true, 'end_date' => '2026-10-19 23:59:59' ) ) );
ec_check( 'ganztägig 2 Tage', array( $e['start_time'], $e['end_date'], $e['end_time'] ), array( null, '2026-10-19', null ) );

echo "Ort / Stadt\n";
$e = $one( dz_item( 199, 'Mamaladnamala &#8211; Die Impro-Show', '2026-10-16 20:00:00', $kleinkunst ) );
ec_check( 'Kleinkunstbühne (ID 60): Stadt Bayreuth, keine Adresse', array( $e['venue_name'], $e['venue_address'], $e['city'], isset( $e['_error'] ) ), array( 'DAS ZENTRUM – Kleinkunstbühne', '', 'Bayreuth', false ) );
$e = $one( dz_item( 500, 'Gastspiel', '2026-10-16 20:00:00', array( 'id' => 99, 'venue' => 'Stadthalle', 'address' => 'Jean-Paul-Platz 1', 'city' => 'Bayreuth' ) ) );
ec_check( 'anderer Ort mit Stadt: übernommen wie geliefert', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Stadthalle', 'Jean-Paul-Platz 1', 'Bayreuth' ) );
$e = $one( dz_item( 501, 'Gastspiel', '2026-10-16 20:00:00', array( 'id' => 98, 'venue' => 'Kulturscheune', 'address' => 'Hauptstraße 3', 'city' => 'Hof' ) ) );
ec_check( 'externe Stadt (Hof) wird übernommen, nicht Bayreuth', $e['city'], 'Hof' );
$e = $one( dz_item( 502, 'Unbekannt', '2026-10-16 20:00:00', array( 'id' => 97, 'venue' => 'Irgendwo' ) ) );
ec_check( 'anderer Ort ohne Stadt -> _error', $e['_error'], 'Ort ohne Stadtangabe: Irgendwo' );
$e = $one( dz_item( 503, 'Ohne Ort', '2026-10-16 20:00:00', array() ) );
ec_check( 'kein Ort -> _error', $e['_error'], 'Ort ohne Stadtangabe: (kein Ort)' );
$e = $one( dz_item( 504, 'ID 60 mit Adresse', '2026-10-16 20:00:00', array( 'id' => 60, 'venue' => 'DAS ZENTRUM – Kleinkunstbühne', 'address' => 'Äußere Badstraße 7a', 'city' => 'Bayreuth' ) ) );
ec_check( 'liefert die Quelle später eine Adresse, gilt diese', array( $e['venue_address'], $e['city'] ), array( 'Äußere Badstraße 7a', 'Bayreuth' ) );

echo "Fehlende / ungültige Felder\n";
ec_check( 'ohne ID -> _error', $one( dz_item( 0, 'A', '2026-10-16 20:00:00', $europasaal ) )['_error'], 'ID fehlt' );
ec_check( 'andere Zeitzone -> _error', $one( dz_item( 600, 'A', '2026-10-16 20:00:00', $europasaal, array( 'timezone' => 'UTC' ) ) )['_error'], 'unerwartete Zeitzone: UTC' );
ec_check( 'Beginn fehlt -> _error', $one( dz_item( 601, 'A', '', $europasaal ) )['_error'], 'Beginn nicht lesbar' );
ec_check( 'Beginn deutsch statt ISO -> _error', $one( dz_item( 602, 'A', '16.10.2026 20:00', $europasaal ) )['_error'], 'Beginn nicht lesbar' );
$e = $one( dz_item( 603, '', '2026-10-16 20:00:00', $europasaal ) );
ec_check( 'Titel leer bleibt leer (Crawler zählt "fehlerhaft")', array( $e['title'], isset( $e['_error'] ) ), array( '', false ) );

echo "Mehrere Termine, Seiten, Struktur\n";
$r = $source->parse(
	array(
		dz_page( array( dz_item( 281, 'Jahreskonzert &#8211; Erster Harmonika-Club Bayreuth', '2026-11-21 14:00:00', $europasaal ), dz_item( 1032, 'Jahreskonzert &#8211; Erster Harmonika-Club Bayreuth', '2026-11-21 19:00:00', $europasaal ) ), 2 ),
		dz_page( array( dz_item( 1032, 'Jahreskonzert &#8211; Erster Harmonika-Club Bayreuth', '2026-11-21 19:00:00', $europasaal ), dz_item( 199, 'Mamaladnamala', '2026-10-16 20:00:00', $kleinkunst ) ), 2 ),
	)
);
ec_check( 'zwei Termine am selben Tag = zwei IDs, Dublette über Seitengrenze einmal', array_column( $r, 'external_id' ), array( '281', '1032', '199' ) );
ec_check( 'zwei Termine: Uhrzeiten', array( $r[0]['start_time'], $r[1]['start_time'] ), array( '14:00', '19:00' ) );
foreach ( array(
	'kein JSON'             => '<html><body>Wartungsarbeiten</body></html>',
	'JSON ohne events'      => '{"code":"rest_no_route","message":"Keine Route"}',
	'leere Terminliste'     => dz_page( array() ),
) as $name => $body ) {
	$r = $source->parse( array( $body ) );
	ec_check( $name . ' -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );
}

echo "Filter (zentral, kein Adapter-Sonderfall)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $one( dz_item( 700, 'SPD Ortsverein Testevent', '2026-10-16 20:00:00', $europasaal ) );
ec_check( 'Adapter liefert den Termin (kein Filter im Adapter)', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );
$e    = $one( dz_item( 285, 'Ingmar Stadelmann &#8211; liest Höcke', '2026-11-25 19:33:00', $europasaal ) );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'echter Titel "liest Höcke" wird nicht gefiltert', $rule, null );
ec_check( 'ungewöhnliche Beginnzeit 19:33 unverändert', $e['start_time'], '19:33' );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
