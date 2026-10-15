<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Kronach (docs/04-quellen.md Abschnitt 14).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten HTML-Ausschnitten im Aufbau der echten Seiten (CMS recon, Stand 2026-10-15):
 * keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-kronach.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-kronach.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Kronach' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Kronach) ist nicht aktiv.\n" );
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
 * Ein Termin der Ergebnisliste.
 *
 * @param int    $id    Termin-ID.
 * @param string $day   " 21".
 * @param string $month " Okt".
 * @param string $time  " 19:00&nbsp;Uhr " oder ' '.
 * @param string $title Titel (HTML).
 * @param string $venue "Kreiskulturraum" oder '' (ohne Ort).
 * @return string
 */
function kr_entry( $id, $day, $month, $time, $title, $venue ) {
	return '<a href="/tourismus-kultur/veranstaltungen/index.html?ev%5Bstart%5D=15.10.2026&ev%5Bend%5D=11.11.2026&ev%5Bsearch%5D=&ev%5Bid%5D=' . $id . '" class="focal-point-bg   events-list-link" target="_self" title="x">'
		. '<div class="slidecontent-wrap"><div class="slidecontent-date"><span class="day">' . $day . '</span><span class="month">' . $month . '</span><span class="time">' . $time . '</span></div>'
		. '<div class="slidecontent-title" data-swiper-parallax="-300"><h2>' . $title . '</h2></div>'
		. '<div class="slidecontent-teaser clearfix" data-swiper-parallax="-200">' . ( '' === $venue ? '' : 'Ort: ' . $venue ) . '</div></div></a>';
}

/**
 * Listenseite.
 *
 * @param string[] $entries Einträge.
 * @param int      $pages   Seitenzahl laut Pagination (0 = keine Pagination).
 * @return string
 */
function kr_page( array $entries, $pages = 0 ) {
	$pager = $pages ? '<ul class="pagination" style="margin:20px 0"><li class="page-item"><a class="pager-currpage page-link page" href="#">1</a></li><li class="page-item"><a style="cursor: default;" class="page-link" href="#">(' . $pages . ' Seiten)</a></li></ul>' : '';
	return '<html><body><div class="col-lg-9 event-resultlist list-open"><div class="switch block">' . implode( '', $entries ) . '</div>' . $pager . '</div></body></html>';
}

/**
 * Detailseite.
 *
 * @param string     $venue    Ort ("Wo?").
 * @param string     $street   Straße.
 * @param string     $ort      "96317 Kronach".
 * @param int[]      $siblings IDs unter „Weitere Termine“.
 * @return string
 */
function kr_detail( $venue, $street, $ort, array $siblings = array() ) {
	$more = '';
	foreach ( $siblings as $id ) {
		$more .= '<a href="/tourismus-kultur/veranstaltungen/index.html?ev[id]=' . $id . '" class="events-list-link" target="_self" title="x";><div class="slidecontent-date"><span class="day"> 16</span><span class="month"> Okt</span></div></a>';
	}
	return '<html><body><h1 class="events-detail-title">T</h1><div class="events-address"><div class="events-address-header">Wo?</div>'
		. '<div class="events-address-title">' . $venue . '</div><div class="events-address-str">' . $street . '</div><div class="events-address-ort">' . $ort . '</div>'
		. '<div class="events-address-tel"> +499261 970</div></div>'
		. '<div id="eventcollapseOne" class="collapse show"><div class="event-collapse-body"><p>Langtext</p></div></div>'
		. ( $siblings ? '<div id="eventcollapseThree" class="collapse"><div class="event-collapse-body">' . $more . '</div></div>' : '' )
		. '</body></html>';
}

$source = new Eventcrawler_Source_Kronach();
$from   = new DateTimeImmutable( '2026-10-15' );
$run    = function ( array $pages, array $details, $start = null ) use ( $source, $from ) {
	return $source->parse( $pages, $start ? $start : $from, $details );
};
$krk    = kr_detail( 'Kreiskulturraum', 'Siechenangerstraße 13', '96317 Kronach' );

echo "Register\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['kronach'] ), true );
ec_check( 'Reihenfolge', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'kronach' ), 'Kronach' );
ec_check( 'Detail-URL je Termin eindeutig', $source->has_unique_detail_urls(), true );
ec_check( 'Such-URL wie Formular', Eventcrawler_Source_Kronach::search_url( $from, $from->modify( '+27 days' ) ), 'https://www.kronach.de/tourismus-kultur/veranstaltungen/index.html?ev%5Bstart%5D=15.10.2026&ev%5Bend%5D=11.11.2026&ev%5Bsearch%5D=' );

echo "Liste\n";
$e = $run( array( kr_page( array( kr_entry( 16259, ' 24', ' Okt', ' 19:30&nbsp;Uhr ', 'Ninotschka', 'Kreiskulturraum' ) ), 9 ) ), array( '16259' => $krk ) )[0];
ec_check( 'external_id = Termin-ID', $e['external_id'], '16259' );
ec_check( 'Titel', $e['title'], 'Ninotschka' );
ec_check( 'Datum (Jahr aus Suchbeginn)', $e['start_date'], '2026-10-24' );
ec_check( 'Beginn', $e['start_time'], '19:30' );
ec_check( 'kein Ende, nicht ganztägig, keine Beschreibung', array( $e['end_date'], $e['end_time'], $e['all_day'], $e['description'] ), array( null, null, false, '' ) );
ec_check( 'Ort aus "Ort: …"', $e['venue_name'], 'Kreiskulturraum' );
ec_check( 'Adresse und Stadt von der Detailseite', array( $e['venue_address'], $e['city'] ), array( 'Siechenangerstraße 13', 'Kronach' ) );
ec_check( 'URL ohne Suchparameter', array( $e['event_url'], $e['source_url'] ), array( 'https://www.kronach.de/tourismus-kultur/veranstaltungen/index.html?ev%5Bid%5D=16259', 'https://www.kronach.de/tourismus-kultur/veranstaltungen/index.html?ev%5Bid%5D=16259' ) );
ec_check( 'kein _error', isset( $e['_error'] ), false );
ec_check( 'Seitenzahl aus "(9 Seiten)"', $source->parse_page_count( kr_page( array(), 9 ) ), 9 );
ec_check( 'ohne Pagination 1 Seite', $source->parse_page_count( kr_page( array( kr_entry( 1, ' 24', ' Okt', ' ', 'A', 'B' ) ) ) ), 1 );

$e = $run( array( kr_page( array( kr_entry( 16237, ' 15', ' Okt', ' ', 'Die Sommerhalle auf der Festung Rosenberg', 'Festung Rosenberg' ) ) ) ), array( '16237' => kr_detail( 'Festung Rosenberg', 'Festung 1', '96317 Kronach' ) ) )[0];
ec_check( 'ohne Uhrzeit -> NULL', $e['start_time'], null );

echo "Datum / Jahreswechsel\n";
$dec = new DateTimeImmutable( '2026-12-20' );
$ev  = $run( array( kr_page( array( kr_entry( 1, ' 28', ' Dez', ' 18:00&nbsp;Uhr ', 'A', 'Kreiskulturraum' ), kr_entry( 2, ' 05', ' Jan', ' 18:00&nbsp;Uhr ', 'B', 'Kreiskulturraum' ) ) ) ), array( '1' => $krk, '2' => $krk ), $dec );
ec_check( 'Dez im Jahr des Suchbeginns, Jan im Folgejahr', array( $ev[0]['start_date'], $ev[1]['start_date'] ), array( '2026-12-28', '2027-01-05' ) );
$e = $run( array( kr_page( array( kr_entry( 3, ' 15', ' Okt.', ' ', 'A', 'Kreiskulturraum' ) ) ) ), array( '3' => $krk ) )[0];
ec_check( 'unbekanntes Monatskürzel -> _error', $e['_error'], 'Datum nicht lesbar: 15 okt.' );
$e = $run( array( kr_page( array( kr_entry( 3, ' 31', ' Nov', ' ', 'A', 'Kreiskulturraum' ) ) ) ), array( '3' => $krk ) )[0];
ec_check( '31. Nov -> _error', $e['_error'], 'Datum nicht lesbar: 31 nov' );
$e = $run( array( kr_page( array( kr_entry( 3, ' 15', ' Okt', ' ab 19 Uhr ', 'A', 'Kreiskulturraum' ) ) ) ), array( '3' => $krk ) )[0];
ec_check( 'unlesbare Uhrzeit -> _error', $e['_error'], 'Uhrzeit nicht lesbar: ab 19 Uhr' );

echo "Mehrere Termine / Weitere Termine\n";
$pages = array(
	kr_page( array( kr_entry( 15966, ' 15', ' Okt', ' 11:00&nbsp;Uhr ', 'Festungsführung mehrmals täglich', 'Festung Rosenberg' ), kr_entry( 15967, ' 16', ' Okt', ' 11:00&nbsp;Uhr ', 'Festungsführung mehrmals täglich', 'Festung Rosenberg' ) ), 2 ),
	kr_page( array( kr_entry( 15967, ' 16', ' Okt', ' 11:00&nbsp;Uhr ', 'Festungsführung mehrmals täglich', 'Festung Rosenberg' ), kr_entry( 15968, ' 17', ' Okt', ' 11:00&nbsp;Uhr ', 'Festungsführung mehrmals täglich', 'Festung Rosenberg' ) ), 2 ),
);
$ev = $run( $pages, array( '15966' => kr_detail( 'Festung Rosenberg', 'Festung 1', '96317 Kronach', array( 15967, 15968 ) ) ) );
ec_check( 'je Termin ein Datensatz, Doppel am Seitenübergang einmal', array_column( $ev, 'external_id' ), array( '15966', '15967', '15968' ) );
ec_check( 'Adresse über „Weitere Termine“ ohne eigenen Abruf', array_column( $ev, 'venue_address' ), array( 'Festung 1', 'Festung 1', 'Festung 1' ) );
ec_check( 'keine Fehler', array_filter( array_column( $ev, '_error' ) ), array() );
$ev = $run( array( kr_page( array( kr_entry( 20, ' 24', ' Okt', ' 19:30&nbsp;Uhr ', 'Ninotschka', 'Kreiskulturraum' ), kr_entry( 21, ' 25', ' Okt', ' 17:30&nbsp;Uhr ', 'Ninotschka', 'Kreiskulturraum' ) ) ) ), array( '20' => $krk ) );
ec_check( 'nicht genannter Termin ohne eigene Detailseite -> _error (kein Titel-Matching)', array( isset( $ev[0]['_error'] ), $ev[1]['_error'] ), array( false, 'Detailseite: nicht abgerufen' ) );

echo "Ort / Stadt\n";
$e = $run( array( kr_page( array( kr_entry( 16667, ' 21', ' Okt', ' 19:00&nbsp;Uhr ', 'Heeresmusikkorps in Nordhalben', 'Nordwaldhalle' ) ) ) ), array( '16667' => kr_detail( 'Nordwaldhalle', 'Kronacher Straße 57', '96365 Nordhalben' ) ) )[0];
ec_check( 'Fremdort bleibt erhalten (nicht Kronach)', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Nordwaldhalle', 'Kronacher Straße 57', 'Nordhalben' ) );
$e = $run( array( kr_page( array( kr_entry( 15134, ' 24', ' Okt', ' ', 'Kronacher Musiknacht', 'Innenstadt Kronach' ) ) ) ), array( '15134' => kr_detail( 'Innenstadt Kronach', '', '' ) ) )[0];
ec_check( 'Mapping "Innenstadt Kronach" -> nur Stadt, keine Adresse', array( $e['venue_name'], $e['venue_address'], $e['city'], isset( $e['_error'] ) ), array( 'Innenstadt Kronach', '', 'Kronach', false ) );
$e = $run( array( kr_page( array( kr_entry( 5, ' 24', ' Okt', ' ', 'A', 'Innenstadt' ) ) ) ), array( '5' => kr_detail( 'Innenstadt', '', '' ) ) )[0];
ec_check( 'kein unscharfes Mapping ("Innenstadt")', array( $e['city'], $e['_error'] ), array( '', 'Stadt fehlt (Ort: Innenstadt)' ) );
$e = $run( array( kr_page( array( kr_entry( 6, ' 24', ' Okt', ' ', 'A', 'Innenstadt Kronach Süd' ) ) ) ), array( '6' => kr_detail( 'Innenstadt Kronach Süd', '', '' ) ) )[0];
ec_check( 'kein Teilstring-Mapping ("Innenstadt Kronach Süd")', $e['_error'], 'Stadt fehlt (Ort: Innenstadt Kronach Süd)' );
$e = $run( array( kr_page( array( kr_entry( 7, ' 24', ' Okt', ' ', 'A', 'Marktplatz' ) ) ) ), array( '7' => kr_detail( 'Marktplatz', 'Marktplatz 1', '' ) ) )[0];
ec_check( 'Straße ohne PLZ/Ort -> keine Adresse, _error', array( $e['venue_address'], $e['_error'] ), array( '', 'Stadt fehlt (Ort: Marktplatz)' ) );
$e = $run( array( kr_page( array( kr_entry( 15533, ' 09', ' Nov', ' ', 'Gedenktag Reichspogromnacht 1938', '' ) ) ) ), array() )[0];
ec_check( 'ohne "Ort:" -> _error, keine Stadt geraten', array( $e['_error'], $e['city'] ), array( 'Veranstaltungsort fehlt', '' ) );
$e = $run( array( kr_page( array( kr_entry( 8, ' 24', ' Okt', ' ', 'A', 'Kreiskulturraum' ) ) ) ), array( '8' => kr_detail( 'Festung Rosenberg', 'Festung 1', '96317 Kronach' ) ) )[0];
ec_check( 'Ort der Detailseite weicht ab -> _error', $e['_error'], 'Ort der Detailseite weicht ab: Festung Rosenberg' );

echo "Sonderzeichen\n";
$e = $run( array( kr_page( array( kr_entry( 9, ' 28', ' Nov', ' 19:30&nbsp;Uhr ', '&raquo;Klangwelten aus Blech&laquo; Weimar Trombone Conexi&oacute;n &amp; Gäste – „Live“', 'Kühnlenzpassage' ) ) ) ), array( '9' => kr_detail( 'K&uuml;hnlenzpassage', 'Hussitengasse 3', '96317 Kronach' ) ) )[0];
ec_check( 'Titel » « ó & ä – „“', $e['title'], '»Klangwelten aus Blech« Weimar Trombone Conexión & Gäste – „Live“' );
ec_check( 'Ort mit Umlaut (Entity auf Detailseite)', array( $e['venue_name'], isset( $e['_error'] ) ), array( 'Kühnlenzpassage', false ) );
$e = $run( array( kr_page( array( kr_entry( 10, ' 28', ' Nov', ' ', 'Straße', 'Melchior-Otto-Platz / Ehrensäule' ) ) ) ), array( '10' => kr_detail( 'Melchior-Otto-Platz / Ehrensäule', 'Melchior-Otto-Platz', '96317 Kronach' ) ) )[0];
ec_check( 'ß und Schrägstrich im Ort', array( $e['title'], $e['venue_name'] ), array( 'Straße', 'Melchior-Otto-Platz / Ehrensäule' ) );

echo "Fehlerfälle\n";
$one = array( kr_page( array( kr_entry( 11, ' 20', ' Okt', ' 19:00&nbsp;Uhr ', 'A', 'Kreiskulturraum' ) ) ) );
ec_check( 'Detailseite 404 -> _error', $run( $one, array( '11' => new WP_Error( 'eventcrawler_http', 'Quelle antwortet mit HTTP 404.' ) ) )[0]['_error'], 'Detailseite: Quelle antwortet mit HTTP 404.' );
ec_check( 'Detailseite ohne "Wo?" -> _error', $run( $one, array( '11' => '<html><body>x</body></html>' ) )[0]['_error'], 'Detailseite: Block "Wo?" fehlt' );
ec_check( 'Link ohne Termin-ID -> _error', $run( array( kr_page( array( '<a href="/x.html" class="events-list-link"><span class="day">20</span><span class="month">Okt</span><h2>A</h2></a>' ) ) ), array() )[0]['_error'], 'Termin-ID fehlt im Link' );
ec_check( 'Titel fehlt -> leer (Crawler zählt "fehlerhaft")', $run( array( kr_page( array( kr_entry( 12, ' 20', ' Okt', ' ', '', 'Kreiskulturraum' ) ) ) ), array( '12' => $krk ) )[0]['title'], '' );
ec_check( 'Datum fehlt -> _error', $run( array( kr_page( array( kr_entry( 13, '', '', ' ', 'A', 'Kreiskulturraum' ) ) ) ), array( '13' => $krk ) )[0]['_error'], 'Datum nicht lesbar:  ' );
$r = $source->parse_list( array( '<html><body><h1>Wartung</h1></body></html>' ), $from );
ec_check( 'keine Ergebnisliste -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );

echo "Filter (zentral)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $run( array( kr_page( array( kr_entry( 14, ' 20', ' Okt', ' 19:00&nbsp;Uhr ', 'SPD Ortsverein Testevent', 'Kreiskulturraum' ) ) ) ), array( '14' => $krk ) )[0];
ec_check( 'Adapter liefert den Termin', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );
foreach ( array( '8. Stadtratssitzung' => 'stadtratssitzung', '3. Werkausschusssitzung für den Eigenbetrieb Stadtwerke' => 'ausschusssitzung', '4. Bau-, Stadtentwicklungs- und Wirtschaftsausschusssitzung' => 'ausschusssitzung' ) as $title => $term ) {
	$rule = Eventcrawler_Filter::find_matching_rule( $title, $rules );
	ec_check( 'echter Titel "' . $title . '" wird gefiltert', $rule ? $rule['term'] : null, $term );
}

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
