<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Bayreuth Tourismus (docs/04-quellen.md Abschnitt 8).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten JSON-Antworten im Aufbau der echten Regional-API (Stand 2026-10-15):
 * keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-bayreuth-tourismus.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-bayreuth-tourismus.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Bayreuth_Tourismus' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Bayreuth Tourismus) ist nicht aktiv.\n" );
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
 * Ein Termin der API.
 *
 * @param string     $id    eventId (UUID).
 * @param string     $date  "2026-10-24".
 * @param string     $start "19:30:00".
 * @param string     $end   "22:00:00".
 * @param string     $title Titel.
 * @param array|null $venue name, room, street, houseNumber, postalCode, city (null = ohne Ort).
 * @param string     $desc  Kurztext.
 * @return array
 */
function bt_item( $id, $date, $start, $end, $title, $venue, $desc = 'Kurztext' ) {
	return array(
		'eventId'      => $id,
		'slug'         => 'slug-' . substr( $id, 0, 4 ),
		'detailLink'   => 'https://region-bayreuth.de/veranstaltung/slug-' . substr( $id, 0, 4 ),
		'dateInfo'     => array(
			'date'     => array( 'start' => $date, 'end' => $date, 'isPeriod' => false ),
			'start'    => $start,
			'end'      => $end,
			'type'     => 'UNIQUE',
			'interval' => 'NONE',
		),
		'title'        => $title,
		'description'  => $desc,
		'venue'        => null === $venue ? null : array(
			'name'       => $venue['name'],
			'room'       => isset( $venue['room'] ) ? $venue['room'] : '',
			'coordinate' => array( 'latitude' => 49.9, 'longitude' => 11.5 ),
			'address'    => array(
				'street'      => $venue['street'],
				'houseNumber' => $venue['houseNumber'],
				'city'        => $venue['city'],
				'postalCode'  => $venue['postalCode'],
			),
		),
		'districtCity' => array( 'districtCityId' => 'x', 'city' => 'Bayreuth', 'district' => 'Bayreuth (95444)' ),
		'isInternEvent' => false,
	);
}

/**
 * @param array[] $items      Termine.
 * @param int     $total_pages totalPages.
 * @return string JSON-Antwort.
 */
function bt_page( array $items, $total_pages = 1 ) {
	return wp_json_encode( array( 'values' => $items, 'totalItems' => count( $items ), 'totalPages' => $total_pages, 'currentPage' => 0 ) );
}

$id1     = '8f884118-0a5d-4a45-82e1-f3d4690a02ab';
$id2     = '691f1d41-cb82-4232-befa-6c28a133af31';
$idz     = 'aaaaaaaa-0a5d-4a45-82e1-f3d4690a02ab';
$spiel   = array( 'name' => 'Haus des Spiels', 'street' => 'Friedrichstraße', 'houseNumber' => '16', 'postalCode' => '95444', 'city' => 'Bayreuth' );
$zentrum = array( 'name' => 'DAS ZENTRUM - Internationales Jugendkulturzentrum Bayreuth e.V.', 'room' => 'Europasaal', 'street' => 'Äußere Badstraße', 'houseNumber' => '7a', 'postalCode' => '95448', 'city' => 'Bayreuth' );
$source  = new Eventcrawler_Source_Bayreuth_Tourismus();
$none    = array( bt_page( array() ) );
$one     = function ( array $item ) use ( $source, $none ) {
	return $source->parse( array( bt_page( array( $item ) ) ), $none )[0];
};

echo "Register\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['bayreuth-tourismus'] ), true );
ec_check( 'Reihenfolge', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus', 'concertbuero-franken' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'bayreuth-tourismus' ), 'Bayreuth Tourismus' );
ec_check( 'Detail-URL je Veranstaltung (nicht eindeutig je Termin)', $source->has_unique_detail_urls(), false );
$url = Eventcrawler_Source_Bayreuth_Tourismus::search_url( new DateTimeImmutable( '2026-10-15' ), new DateTimeImmutable( '2026-11-11' ) );
ec_check( 'Such-URL mit Stadtteilen und Zeitraum', 1 === preg_match( '#^https://api\.region-bayreuth\.de/event-management/events/search\?page=\{page\}&size=1000&sort=eventDateTimes\.date:asc(&districts=[0-9a-f-]{36}){4}&from=2026-10-15&to=2026-11-11$#', $url ), true );

echo "Felder\n";
$e = $one( bt_item( $id1, '2026-10-24', '19:30:00', '22:00:00', 'Sarah Bosetti: Make Democracy Great Again!', $spiel, "\"Make Democracy Great Again!\" ist ein\nlustiges Plädoyer." ) );
ec_check( 'external_id = eventId_Datum_n', $e['external_id'], $id1 . '_2026-10-24_1' );
ec_check( 'Titel unverändert', $e['title'], 'Sarah Bosetti: Make Democracy Great Again!' );
ec_check( 'Kurztext als Beschreibung, Zeilenumbruch bereinigt', $e['description'], '"Make Democracy Great Again!" ist ein lustiges Plädoyer.' );
ec_check( 'Datum und Beginn', array( $e['start_date'], $e['start_time'] ), array( '2026-10-24', '19:30' ) );
ec_check( 'Ende wie geliefert', array( $e['end_date'], $e['end_time'] ), array( '2026-10-24', '22:00' ) );
ec_check( 'nicht ganztägig', $e['all_day'], false );
ec_check( 'Ort, Straße + Hausnummer, Stadt', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Haus des Spiels', 'Friedrichstraße 16', 'Bayreuth' ) );
ec_check( 'URL = detailLink', array( $e['event_url'], $e['source_url'] ), array( 'https://region-bayreuth.de/veranstaltung/slug-8f88', 'https://region-bayreuth.de/veranstaltung/slug-8f88' ) );
ec_check( 'kein _error, kein internes Feld', array( isset( $e['_error'] ), isset( $e['_id'] ) ), array( false, false ) );
$e = $one( bt_item( $id1, '2026-10-18', '00:00:00', '05:00:00', 'Festspiele – Tag 2', $spiel ) );
ec_check( 'echter Beginn 00:00 (Ende 05:00) bleibt erhalten', array( $e['start_time'], $e['end_time'] ), array( '00:00', '05:00' ) );
$e = $one( bt_item( $id1, '2026-10-18', '', '', 'Ohne Zeit', $spiel ) );
ec_check( 'ohne Beginn -> NULL, kein Ende', array( $e['start_time'], $e['end_date'], $e['end_time'] ), array( null, null, null ) );
$e = $one( bt_item( $id1, '2026-10-18', '', '17:00:00', 'Nur Ende', $spiel ) );
ec_check( 'Ende ohne Beginn wird nicht übernommen', array( $e['end_date'], $e['end_time'] ), array( null, null ) );
$m             = bt_item( $id1, '2026-11-14', '08:00:00', '15:00:00', 'Mehrtägig', $spiel );
$m['dateInfo']['date']['end'] = '2026-11-15';
$e = $one( $m );
ec_check( 'Enddatum aus date.end', array( $e['end_date'], $e['end_time'] ), array( '2026-11-15', '15:00' ) );
$o = $spiel;
$o['city'] = 'Eckersdorf';
$e = $one( bt_item( $id1, '2026-10-18', '10:00:00', '12:00:00', 'Umland', $o ) );
ec_check( 'Stadt aus der Adresse (nicht pauschal Bayreuth)', $e['city'], 'Eckersdorf' );

echo "Termine / Identität\n";
$ev = $source->parse(
	array(
		bt_page(
			array(
				bt_item( $id2, '2026-10-15', '08:00:00', '19:00:00', 'Rundparcours', $spiel ),
				bt_item( $id2, '2026-10-16', '08:00:00', '19:00:00', 'Rundparcours', $spiel ),
				bt_item( $id2, '2026-10-16', '14:00:00', '15:00:00', 'Rundparcours', $spiel ),
				bt_item( $id2, '2026-10-16', '08:00:00', '19:00:00', 'Rundparcours', $spiel ),
			)
		),
	),
	$none
);
ec_check( 'je Termin ein Datensatz, gleicher Termin nur einmal', array_column( $ev, 'external_id' ), array( $id2 . '_2026-10-15_1', $id2 . '_2026-10-16_1', $id2 . '_2026-10-16_2' ) );
$a = $one( bt_item( $id1, '2026-10-24', '19:30:00', '22:00:00', 'A', $spiel ) );
$b = $one( bt_item( $id1, '2026-10-24', '20:00:00', '22:30:00', 'A – neu', $spiel ) );
ec_check( 'andere Uhrzeit/Titel -> gleiche external_id (Update)', $a['external_id'], $b['external_id'] );
$c = $one( bt_item( $id1, '2026-10-25', '19:30:00', '22:00:00', 'A', $spiel ) );
ec_check( 'anderes Datum -> andere external_id (keine Termin-ID in der Quelle)', $a['external_id'] !== $c['external_id'], true );

echo "Pagination\n";
$p1 = wp_json_encode( array( 'values' => array( bt_item( $id1, '2026-10-24', '19:30:00', '22:00:00', 'Seite 1', $spiel ) ), 'totalItems' => 2, 'totalPages' => 2, 'currentPage' => 0 ) );
$p2 = wp_json_encode( array( 'values' => array( bt_item( $id2, '2026-10-25', '19:30:00', '22:00:00', 'Seite 2', $spiel ) ), 'totalItems' => 2, 'totalPages' => 2, 'currentPage' => 1 ) );
ec_check( 'Termine aller Seiten', array_column( $source->parse( array( $p1, $p2 ), $none ), 'title' ), array( 'Seite 1', 'Seite 2' ) );
ec_check( 'kaputte Folgeseite -> WP_Error', is_wp_error( $source->parse( array( $p1, '{"fehler":' ), $none ) ), true );

echo "DAS ZENTRUM (A91)\n";
$items = array(
	bt_item( $idz, '2026-10-24', '20:00:00', '22:00:00', 'AUSVERKAUFT: Claus von Wagner: Projekt Equilibrium', $zentrum ),
	bt_item( $id1, '2026-10-24', '19:30:00', '22:00:00', 'Spieleabend', $spiel ),
	bt_item( $idz, '2026-10-28', '20:00:00', '22:00:00', 'AUSVERKAUFT: Claus von Wagner: Projekt Equilibrium', $zentrum ),
);
$ev = $source->parse( array( bt_page( $items ) ), array( bt_page( array( $items[0], $items[2] ) ) ) );
ec_check( 'Termine im Ortsfilter DAS ZENTRUM nicht übernommen', array_column( $ev, 'title' ), array( 'Spieleabend' ) );
ec_check( 'Ausschlüsse gezählt (nicht in "gefunden")', $source->get_excluded_count(), 2 );
$schoko = array( 'name' => 'Schoko e.V. - Jugendkultur- und Sportzentrum Schokofabrik', 'street' => 'Bindlacher Straße', 'houseNumber' => '4', 'postalCode' => '95448', 'city' => 'Bayreuth' );
$ev = $source->parse( array( bt_page( array( bt_item( $id1, '2026-10-24', '19:30:00', '22:00:00', 'Party', $schoko ) ) ) ), array( bt_page( array() ) ) );
ec_check( 'anderer Ort mit "zentrum" im Namen bleibt (kein contains)', array( count( $ev ), $source->get_excluded_count() ), array( 1, 0 ) );
$ev = $source->parse( array( bt_page( array( bt_item( $idz, '2026-10-24', '20:00:00', '22:00:00', 'X', $zentrum ) ) ) ), array( bt_page( array() ) ) );
ec_check( 'Name DAS ZENTRUM ohne Ortsfilter-Treffer -> _error (nicht speichern)', $ev[0]['_error'], 'Ort DAS ZENTRUM ohne Treffer im Ortsfilter' );
$z2         = $zentrum;
$z2['name'] = 'DAS ZENTRUM';
ec_check( 'nur exakter Name zählt ("DAS ZENTRUM" allein ist ein anderer Ort)', isset( $one( bt_item( $id1, '2026-10-24', '20:00:00', '22:00:00', 'X', $z2 ) )['_error'] ), false );
ec_check( 'kaputte Ortsfilter-Antwort -> WP_Error', is_wp_error( $source->parse( array( bt_page( $items ) ), array( 'kein json' ) ) ), true );

echo "Sonderzeichen\n";
$e = $one( bt_item( $id1, '2026-10-24', '19:30:00', '22:00:00', '„NeuBayreuth Reloaded – Festspiele“ Mit DJ HELL & Mina Richman – Lucía', $zentrum + array(), 'Café, Äußere Straße & mehr – „Live“' ) );
ec_check( 'Titel „“ – & í', $e['title'], '„NeuBayreuth Reloaded – Festspiele“ Mit DJ HELL & Mina Richman – Lucía' );
ec_check( 'Beschreibung é Ä & –', $e['description'], 'Café, Äußere Straße & mehr – „Live“' );
ec_check( 'Adresse mit Umlaut und Hausnummer mit Buchstabe', $e['venue_address'], 'Äußere Badstraße 7a' );

echo "Fehlende Felder\n";
ec_check( 'ohne Ort -> _error', $one( bt_item( $id1, '2026-10-24', '09:30:00', '17:00:00', 'Tageswanderung', null ) )['_error'], 'Veranstaltungsort fehlt' );
$o         = $spiel;
$o['city'] = '';
ec_check( 'ohne Stadt -> _error, keine Stadt geraten', $one( bt_item( $id1, '2026-10-24', '09:30:00', '17:00:00', 'A', $o ) )['_error'], 'Stadt fehlt (Ort: Haus des Spiels)' );
ec_check( 'ohne Datum -> _error', $one( bt_item( $id1, '', '09:30:00', '17:00:00', 'A', $spiel ) )['_error'], 'Datum nicht lesbar' );
ec_check( 'unmögliches Datum -> _error', $one( bt_item( $id1, '2026-11-31', '09:30:00', '17:00:00', 'A', $spiel ) )['_error'], 'Datum nicht lesbar' );
ec_check( 'unlesbarer Beginn -> _error', $one( bt_item( $id1, '2026-10-24', '19 Uhr', '', 'A', $spiel ) )['_error'], 'Beginn nicht lesbar' );
ec_check( 'ohne eventId -> _error', $one( bt_item( 'kaputt', '2026-10-24', '19:30:00', '', 'A', $spiel ) )['_error'], 'eventId fehlt' );
ec_check( 'ohne Titel -> leer (Crawler zählt "fehlerhaft")', $one( bt_item( $id1, '2026-10-24', '19:30:00', '', '', $spiel ) )['title'], '' );
ec_check( 'leere Ergebnisliste -> WP_Error', is_wp_error( $source->parse( array( bt_page( array() ) ), $none ) ), true );
ec_check( 'kaputtes JSON -> WP_Error', is_wp_error( $source->parse( array( '<html>Wartung</html>' ), $none ) ), true );

echo "Filter (zentral)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $one( bt_item( $id1, '2026-10-24', '19:30:00', '', 'SPD Ortsverein Testevent', $spiel ) );
ec_check( 'Adapter liefert den Termin', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );
$rule = Eventcrawler_Filter::find_matching_rule( 'Bürgerinnen- und Bürgerversammlung', $rules );
ec_check( 'echter Titel "Bürgerinnen- und Bürgerversammlung" wird gefiltert', $rule ? $rule['term'] : null, 'bürgerversammlung' );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
