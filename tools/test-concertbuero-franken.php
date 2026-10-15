<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Concertbüro Franken (docs/04-quellen.md Abschnitt 15).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten HTML-Ausschnitten im Aufbau der echten Monatsseiten (Contao, Stand 2026-10-15):
 * keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-concertbuero-franken.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-concertbuero-franken.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Concertbuero_Franken' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Concertbüro Franken) ist nicht aktiv.\n" );
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
 * Ein Termin der Monatsansicht.
 *
 * @param string $alias Alias der Detailseite.
 * @param string $dt    datetime, z. B. "2026-10-19T20:00:00+02:00".
 * @param string $title Titel (HTML).
 * @param string $place "Nürnberg &bull; Hirsch" (HTML) oder '' (ohne Zeile).
 * @param string $badge z. B. "AUSVERKAUFT".
 * @return string
 */
function cf_entry( $alias, $dt, $title, $place, $badge = '' ) {
	$url = 'https://www.concertbuero-franken.de/konzert-details/' . $alias . '.html';
	return '<div class="event vee-listing-8 layout_listcurrent even cal_3 col-12"><div class="vee-listing-block"><figure class="image_container"><a href="' . $url . '" title="x"><img src="a.jpg" alt="x"></a></figure>'
		. '<div class="vee-listing-content"><div class="eventListProperties">' . ( '' === $badge ? '' : '<div>' . $badge . '</div>' ) . '</div>'
		. '<p class="info"><time datetime="' . $dt . '">' . substr( $dt, 8, 2 ) . '.' . substr( $dt, 5, 2 ) . '.' . substr( $dt, 0, 4 ) . '<span class="vee-meta__time">' . substr( $dt, 11, 5 ) . '–23:59</span></time></p>'
		. '<h3 class="vee-listing-headline"><a href="' . $url . '" title="x">' . $title . '</a></h3>'
		. ( '' === $place ? '' : '<p class="info">' . $place . '</p>' ) . '</div></div></div>';
}

/**
 * @param string[] $entries Einträge.
 * @param string[] $months  Monatsnavigation JJJJMM.
 * @return string
 */
function cf_page( array $entries, array $months = array() ) {
	$nav = '';
	foreach ( $months as $m ) {
		$nav .= '<li><a href="konzerte-nach-monat.html?month=' . $m . '">' . $m . '</a></li>';
	}
	return '<html><body><ul class="nav">' . $nav . '</ul><div class="mod_eventlist">' . implode( '', $entries ) . '</div></body></html>';
}

$source = new Eventcrawler_Source_Concertbuero_Franken();
$run    = function ( array $entries, $from = '2026-10-15' ) use ( $source ) {
	return $source->parse( array( cf_page( $entries ) ), $from );
};

echo "Register\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['concertbuero-franken'] ), true );
ec_check( 'Reihenfolge', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus', 'concertbuero-franken' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'concertbuero-franken' ), 'Concertbüro Franken' );
ec_check( 'Detail-URL je Termin eindeutig', $source->has_unique_detail_urls(), true );
ec_check( 'zentrale Ortsregel als zweite Sicherung', $source->get_allowed_cities(), array( 'Nürnberg', 'Fürth' ) );

echo "Monatsnavigation\n";
ec_check( 'Folgemonate aufsteigend, ohne aktuellen/frühere, ohne Doppel', $source->parse_months( cf_page( array(), array( '202609', '202610', '202612', '202611', '202612', '202809' ) ), '202610' ), array( '202611', '202612', '202809' ) );
$nav = array_merge( array_map( fn( $i ) => (string) ( 202700 + $i ), range( 1, 12 ) ), array_map( fn( $i ) => (string) ( 202800 + $i ), range( 1, 12 ) ), array_map( fn( $i ) => (string) ( 202900 + $i ), range( 1, 6 ) ) );
ec_check( 'höchstens 23 Folgemonate (Schutz)', count( $source->parse_months( cf_page( array(), $nav ), '202610' ) ), 23 );

echo "Felder\n";
$e = $run( array( cf_entry( 'the-kilkennys-2026-10-19', '2026-10-19T20:00:00+02:00', 'THE KILKENNYS', 'Nürnberg &bull; Hirsch' ) ) )[0];
ec_check( 'external_id = Alias', $e['external_id'], 'the-kilkennys-2026-10-19' );
ec_check( 'Titel unverändert', $e['title'], 'THE KILKENNYS' );
ec_check( 'Datum und Beginn aus datetime', array( $e['start_date'], $e['start_time'] ), array( '2026-10-19', '20:00' ) );
ec_check( '23:59 der Vorlage ist kein Ende', array( $e['end_date'], $e['end_time'] ), array( null, null ) );
ec_check( 'nicht ganztägig, keine Beschreibung, keine Adresse', array( $e['all_day'], $e['description'], $e['venue_address'] ), array( false, '', '' ) );
ec_check( 'Ort und Stadt aus "Stadt • Ort"', array( $e['venue_name'], $e['city'] ), array( 'Hirsch', 'Nürnberg' ) );
ec_check( 'URL = Detailseite', array( $e['event_url'], $e['source_url'] ), array( 'https://www.concertbuero-franken.de/konzert-details/the-kilkennys-2026-10-19.html', 'https://www.concertbuero-franken.de/konzert-details/the-kilkennys-2026-10-19.html' ) );
ec_check( 'kein _error, kein internes Feld', array( isset( $e['_error'] ), isset( $e['_city'] ) ), array( false, false ) );
$e = $run( array( cf_entry( 'nena-2', '2026-10-19T20:00:00+02:00', 'NENA', 'Nürnberg &bull; Kia Metropol Arena' ) ) )[0];
ec_check( 'Alias ohne Datum', $e['external_id'], 'nena-2' );
$e = $run( array( cf_entry( 'x-2026-11-13', '2026-11-13T19:00:00+01:00', 'O&apos;MALLEY', 'Nürnberg &bull; Gutmann am Dutzendteich', 'AUSVERKAUFT' ) ) )[0];
ec_check( 'Winterzeit-Offset, Badge nicht im Titel', array( $e['start_time'], $e['title'] ), array( '19:00', "O'MALLEY" ) );

echo "Geografie (A93)\n";
$ev = $run(
	array(
		cf_entry( 'a-2026-10-20', '2026-10-20T20:00:00+02:00', 'A', 'Nürnberg &bull; Löwensaal' ),
		cf_entry( 'b-2026-11-13', '2026-11-13T19:00:00+01:00', 'FRANKEN KRIMI DINNER', 'Fürth &bull; Grüner Baum' ),
		cf_entry( 'c-2026-10-21', '2026-10-21T20:00:00+02:00', 'C', 'Erlangen &bull; E-Werk' ),
		cf_entry( 'd-2026-10-22', '2026-10-22T20:00:00+02:00', 'D', 'Schwabach &bull; Markgrafensaal' ),
		cf_entry( 'e-2026-10-23', '2026-10-23T20:00:00+02:00', 'E', 'Zirndorf &bull; Paul-Metz-Halle' ),
		cf_entry( 'f-2026-10-24', '2026-10-24T20:00:00+02:00', 'F', 'Spalt - Enderndorf &bull; Seebühne' ),
		cf_entry( 'g-2026-10-25', '2026-10-25T20:00:00+02:00', 'G', 'NÜRNBERG &bull; Hirsch' ),
		cf_entry( 'h-2026-10-26', '2026-10-26T20:00:00+02:00', 'H', 'Nürnberg-Langwasser &bull; Halle' ),
		cf_entry( 'i-2026-10-27', '2026-10-27T20:00:00+02:00', 'I', 'Fürth am Berg &bull; Saal' ),
		cf_entry( 'j-2026-10-28', '2026-10-28T20:00:00+02:00', 'J', 'Nuernberg &bull; Hirsch' ),
		cf_entry( 'k-2026-10-29', '2026-10-29T20:00:00+02:00', 'K', '' ),
	)
);
ec_check( 'nur Nürnberg und Fürth geliefert (exakt, Groß/klein egal)', array_column( array_filter( $ev, fn( $e ) => empty( $e['_error'] ) ), 'city', 'title' ), array( 'A' => 'Nürnberg', 'FRANKEN KRIMI DINNER' => 'Fürth', 'G' => 'Nürnberg' ) );
ec_check( 'ohne "Stadt • Ort" -> fehlerhaft, keine Stadt', array( $ev[ count( $ev ) - 1 ]['title'], $ev[ count( $ev ) - 1 ]['city'], $ev[ count( $ev ) - 1 ]['_error'] ), array( 'K', '', 'Stadt unklar (keine Angabe „Stadt • Ort“)' ) );
$stats = $source->get_geo_stats();
ec_check( 'Statistik: gesamt / ausgeschlossen / unklar', array( $stats['total'], $stats['excluded'], $stats['unclear'] ), array( 11, 7, 1 ) );
ec_check( 'Statistik: beobachtete Städte', $stats['cities']['Erlangen'] . '/' . $stats['cities']['Nürnberg-Langwasser'] . '/' . $stats['cities']['Fürth am Berg'] . '/' . $stats['cities']['Nuernberg'], '1/1/1/1' );
ec_check( 'Ortsteil-/Präfixformen und Umschrift nicht übernommen', count( array_filter( $ev, fn( $e ) => in_array( $e['title'], array( 'F', 'H', 'I', 'J' ), true ) ) ), 0 );

echo "Termine / Identität\n";
$ev = $run(
	array(
		cf_entry( 'clawfinger-2026-11-02', '2026-11-02T20:00:00+01:00', 'CLAWFINGER', 'Nürnberg &bull; Löwensaal', 'LOCATION VERLEGT' ),
		cf_entry( 'clawfinger-2-2026-11-02', '2026-11-02T20:00:00+01:00', 'CLAWFINGER', 'Nürnberg &bull; Löwensaal', 'LOCATION VERLEGT' ),
		cf_entry( 'clawfinger-2026-11-02', '2026-11-02T20:00:00+01:00', 'CLAWFINGER', 'Nürnberg &bull; Löwensaal' ),
		cf_entry( 'xandria-2026-10-19', '2026-10-19T19:30:00+02:00', 'XANDRIA', 'Frankfurt &bull; Das Bett' ),
		cf_entry( 'xandria-2026-10-22', '2026-10-22T20:00:00+02:00', 'XANDRIA', 'Nürnberg &bull; Hirsch' ),
	)
);
ec_check( 'zwei Aliasse = zwei Termine; gleicher Alias nur einmal; Tournee je Stadt getrennt', array_column( $ev, 'external_id' ), array( 'clawfinger-2026-11-02', 'clawfinger-2-2026-11-02', 'xandria-2026-10-22' ) );
ec_check( 'vergangene Termine des laufenden Monats übersprungen', count( $run( array( cf_entry( 'alt-2026-10-01', '2026-10-01T20:00:00+02:00', 'Alt', 'Nürnberg &bull; Hirsch' ) ) ) ), 0 );

echo "Sonderzeichen\n";
$e = $run( array( cf_entry( 'sz-2026-11-20', '2026-11-20T20:00:00+01:00', 'DREIßIG. &amp; ZSÁ ZSÁ – „Live“ MOTÖRBLAST', 'Nürnberg &bull; Z-Bau (Saal)' ) ) )[0];
ec_check( 'Titel ß & Á – „“ Ö', $e['title'], 'DREIßIG. & ZSÁ ZSÁ – „Live“ MOTÖRBLAST' );
ec_check( 'Ort mit Klammern', $e['venue_name'], 'Z-Bau (Saal)' );

echo "Fehlerfälle\n";
ec_check( 'Datum unlesbar -> _error', isset( $run( array( cf_entry( 'z-2026-11-20', 'kaputt', 'Z', 'Nürnberg &bull; Hirsch' ) ) )[0]['_error'] ), true );
ec_check( 'unmögliches Datum -> _error', isset( $run( array( cf_entry( 'z-2026-11-31', '2026-11-31T20:00:00+01:00', 'Z', 'Nürnberg &bull; Hirsch' ) ) )[0]['_error'] ), true );
ec_check( 'fremder Link -> _error', $run( array( str_replace( 'https://www.concertbuero-franken.de/konzert-details/z-2026-11-20.html', 'https://www.eventim.de/x', cf_entry( 'z-2026-11-20', '2026-11-20T20:00:00+01:00', 'Z', 'Nürnberg &bull; Hirsch' ) ) ) )[0]['_error'], 'Detail-Link fehlt oder hat unbekanntes Format' );
ec_check( 'Ort fehlt hinter der Stadt -> _error', $run( array( cf_entry( 'z-2026-11-20', '2026-11-20T20:00:00+01:00', 'Z', 'Nürnberg &bull; ' ) ) )[0]['_error'], 'Stadt unklar (keine Angabe „Stadt • Ort“)' );
ec_check( 'Titel fehlt -> leer (Crawler zählt "fehlerhaft")', $run( array( cf_entry( 'z-2026-11-20', '2026-11-20T20:00:00+01:00', '', 'Nürnberg &bull; Hirsch' ) ) )[0]['title'], '' );
$r = $source->parse( array( '<html><body><h1>Wartung</h1></body></html>' ), '2026-10-15' );
ec_check( 'keine Liste -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );

echo "Filter (zentral)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $run( array( cf_entry( 'spd-2026-11-20', '2026-11-20T20:00:00+01:00', 'SPD Ortsverein Testevent', 'Nürnberg &bull; Hirsch' ) ) )[0];
ec_check( 'Adapter liefert den Termin', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );
ec_check( 'zentrale Ortsregel lässt Nürnberg/Fürth durch', array( Eventcrawler_Filter::get_exclusion_reason( array( 'city' => 'Nürnberg', 'title' => 'X', 'description' => '' ), $source->get_allowed_cities(), $rules ), Eventcrawler_Filter::get_exclusion_reason( array( 'city' => 'Fürth', 'title' => 'X', 'description' => '' ), $source->get_allowed_cities(), $rules ) ), array( null, null ) );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
