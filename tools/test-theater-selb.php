<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Theater Selb (docs/04-quellen.md Abschnitt 4).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten HTML-Ausschnitten im Aufbau der echten Seiten (Stand 2026-10-15):
 * keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-theater-selb.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-theater-selb.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Theater_Selb' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Theater Selb) ist nicht aktiv.\n" );
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
 * Ein Listeneintrag im Aufbau von /spielplan/.
 *
 * @param string $date  "Samstag, 17. Oktober 2026".
 * @param string $title Titel (HTML).
 * @param string $slug  Slug der Detailseite.
 * @param array  $infos Bezeichnung => Wert.
 * @return string
 */
function ts_item( $date, $title, $slug, array $infos ) {
	$p = '';
	foreach ( $infos as $label => $value ) {
		$p .= '<p><span>' . $label . ': </span><span style="margin-left: 5px;">' . $value . '  </span></p>';
	}
	return '<div class="gmk-theater-single color-dark"><div class="gmk-theater-single-badges"></div><div class="d-flex gmk-theater-single-inner"><div class="gmk-theater-single-info">'
		. '<div class="gmk-theater-single-date"><p>' . $date . '</p></div><div class="gmk-theater-single-content"><h3>'
		. '<a class="gmk-theater-single-title" href="https://www.theater-selb.de/' . $slug . '/" title="">' . $title . '</a></h3>'
		. '<div class="gmk-theater-single-infos">' . $p . '</div></div></div></div></div>';
}

/**
 * Eine Detailseite im Aufbau der echten Beiträge.
 *
 * @param int    $id       Post-ID.
 * @param string $subtitle Untertitel (HTML) oder ''.
 * @param array  $infos    Bezeichnung => Wert ("Auf einen Blick").
 * @return string
 */
function ts_detail( $id, $subtitle, array $infos ) {
	$items = '';
	foreach ( $infos as $label => $value ) {
		$items .= '<div class="single-play-infos-overview-item"><p>' . $label . ': </p><span>' . $value . '</span></div>';
	}
	return '<html><head><meta charset="UTF-8"></head><body><article id="post-' . $id . '" class="post-' . $id . ' post type-post">'
		. '<div class="single-post-main-info-left"><h1 class="single-post-main-title">X</h1><div class="single-post-main-text">'
		. ( '' === $subtitle ? '' : '<p>' . $subtitle . '</p>' ) . '</div></div>'
		. '<div class="single-play-infos-overview-list">' . $items . '</div></article>'
		. '<footer>Rosenthal-Theater Selb Hohenberger Str. 9, 95100 Selb</footer></body></html>';
}

$source = new Eventcrawler_Source_Theater_Selb();
$url    = function ( $slug ) {
	return 'https://www.theater-selb.de/' . $slug . '/';
};
$parse  = function ( array $items, array $details ) use ( $source ) {
	return $source->parse( '<html><body><div class="gmk-theater-grid">' . implode( '', $items ) . '</div></body></html>', $details );
};

echo "Registrierung\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['theater-selb'] ), true );
ec_check( 'Reihenfolge der Quellen', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus', 'concertbuero-franken' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'theater-selb' ), 'Theater Selb' );
ec_check( 'Crawl-URL', $source->get_url(), 'https://www.theater-selb.de/spielplan/' );

echo "Normaler Termin\n";
$events = $parse(
	array( ts_item( 'Donnerstag, 05. November 2026', 'Theater Hof in Selb: &#8222;Evviva Mamma!&#8220;', 'evviva', array( 'Genre' => 'Konzert', 'Beginn' => '19:30', 'Veranstalter' => 'Stadt Selb - Kulturamt' ) ) ),
	array( $url( 'evviva' ) => ts_detail( 53060, 'Sitten und Unsitten am Theater &#8211; Komische Oper', array( 'Datum' => '05.11.2026', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Straße 9', 'Genre' => 'Konzert' ) ) )
);
$e = $events[0];
ec_check( 'kein Fehler', isset( $e['_error'] ), false );
ec_check( 'Titel mit typografischen Anführungszeichen', $e['title'], 'Theater Hof in Selb: „Evviva Mamma!“' );
ec_check( 'Beschreibung = Untertitel, Gedankenstrich', $e['description'], 'Sitten und Unsitten am Theater – Komische Oper' );
ec_check( 'start_date', $e['start_date'], '2026-11-05' );
ec_check( 'start_time', $e['start_time'], '19:30' );
ec_check( 'end_date NULL', $e['end_date'], null );
ec_check( 'end_time NULL', $e['end_time'], null );
ec_check( 'all_day false', $e['all_day'], false );
ec_check( 'external_id = Post-ID', $e['external_id'], '53060' );
ec_check( 'venue_name', $e['venue_name'], 'Rosenthal-Theater Selb' );
ec_check( 'venue_address unverändert', $e['venue_address'], 'Hohenberger Straße 9' );
ec_check( 'city', $e['city'], 'Selb' );
ec_check( 'event_url = source_url = Detailseite', array( $e['event_url'], $e['source_url'] ), array( $url( 'evviva' ), $url( 'evviva' ) ) );

echo "Adressvarianten der Quelle\n";
foreach ( array(
	'Hohenberger Strasse 9'            => array( 'Hohenberger Strasse 9', 'Selb', 'Rosenthal-Theater Selb' ),
	'Hohenbergerstrasse 9 95100 Selb'  => array( 'Hohenbergerstrasse 9', 'Selb', 'Rosenthal-Theater Selb' ),
	'Hohenberger Str. 9, 95100 Selb'   => array( 'Hohenberger Str. 9', 'Selb', 'Rosenthal-Theater Selb' ),
	'Marktplatz 1, 95100 Selb'         => array( 'Marktplatz 1', 'Selb', '' ),
	'Ludwigstraße 6 95028 Hof'         => array( 'Ludwigstraße 6', 'Hof', '' ),
	'Hohenberger Straße 9 95028 Hof'   => array( 'Hohenberger Straße 9', 'Hof', '' ),
) as $address => $expected ) {
	$e = $parse(
		array( ts_item( 'Samstag, 17. Oktober 2026', 'A', 'a', array( 'Beginn' => '19:30' ) ) ),
		array( $url( 'a' ) => ts_detail( 1, '', array( 'Datum' => '17.10.2026', 'Beginn' => '19:30', 'Adresse' => $address ) ) )
	)[0];
	ec_check( "Adresse '{$address}'", array( $e['venue_address'], $e['city'], $e['venue_name'], isset( $e['_error'] ) ), array_merge( $expected, array( false ) ) );
}

echo "Nicht raten\n";
$cases = array(
	'falscher Wochentag'           => array( 'Freitag, 17. Oktober 2026', array( 'Beginn' => '19:30' ), array( 'Datum' => '17.10.2026', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Straße 9' ) ),
	'Datum ohne Jahr'              => array( 'Samstag, 17. Oktober', array( 'Beginn' => '19:30' ), array( 'Datum' => '17.10.2026', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Straße 9' ) ),
	'Beginn unlesbar'              => array( 'Samstag, 17. Oktober 2026', array( 'Beginn' => 'abends' ), array( 'Datum' => '17.10.2026', 'Adresse' => 'Hohenberger Straße 9' ) ),
	'Datum Liste ≠ Detail'         => array( 'Samstag, 17. Oktober 2026', array( 'Beginn' => '19:30' ), array( 'Datum' => '18.10.2026', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Straße 9' ) ),
	'Beginn Liste ≠ Detail'        => array( 'Samstag, 17. Oktober 2026', array( 'Beginn' => '19:30' ), array( 'Datum' => '17.10.2026', 'Beginn' => '20:00', 'Adresse' => 'Hohenberger Straße 9' ) ),
	'Adresse fehlt'                => array( 'Samstag, 17. Oktober 2026', array( 'Beginn' => '19:30' ), array( 'Datum' => '17.10.2026', 'Beginn' => '19:30' ) ),
	'fremde Adresse ohne Stadt'    => array( 'Samstag, 17. Oktober 2026', array( 'Beginn' => '19:30' ), array( 'Datum' => '17.10.2026', 'Beginn' => '19:30', 'Adresse' => 'Marktplatz 1' ) ),
);
foreach ( $cases as $name => $case ) {
	$e = $parse( array( ts_item( $case[0], 'A', 'a', $case[1] ) ), array( $url( 'a' ) => ts_detail( 1, '', $case[2] ) ) )[0];
	ec_check( $name . ' -> _error', ! empty( $e['_error'] ), true );
}
$e = $parse( array( ts_item( 'Samstag, 17. Oktober 2026', 'A', 'a', array( 'Beginn' => '19:30' ) ) ), array() )[0];
ec_check( 'Detailseite fehlt -> _error', ! empty( $e['_error'] ), true );
$e = $parse( array( ts_item( 'Samstag, 17. Oktober 2026', 'A', 'a', array( 'Beginn' => '19:30' ) ) ), array( $url( 'a' ) => new WP_Error( 'eventcrawler_http', 'Quelle antwortet mit HTTP 404.' ) ) )[0];
ec_check( 'Detailseite HTTP 404 -> _error', $e['_error'], 'Detailseite: Quelle antwortet mit HTTP 404.' );
$e = $parse( array( ts_item( 'Samstag, 17. Oktober 2026', 'A', 'a', array( 'Beginn' => '19:30' ) ) ), array( $url( 'a' ) => '<html><body><article id="main">x</article></body></html>' ) )[0];
ec_check( 'Post-ID fehlt -> _error', $e['_error'], 'Post-ID auf der Detailseite nicht gefunden' );

echo "Uhrzeit / Einlass\n";
$e = $parse(
	array( ts_item( 'Samstag, 17. Oktober 2026', 'A', 'a', array( 'Einlass' => '18:30', 'Genre' => 'Konzert' ) ) ),
	array( $url( 'a' ) => ts_detail( 1, '', array( 'Datum' => '17.10.2026', 'Einlass' => '18:30', 'Adresse' => 'Hohenberger Straße 9' ) ) )
)[0];
ec_check( 'nur Einlass -> start_time NULL, kein Fehler', array( $e['start_time'], isset( $e['_error'] ) ), array( null, false ) );
$e = $parse(
	array( ts_item( 'Samstag, 17. Oktober 2026', 'A', 'a', array( 'Einlass' => '18:30', 'Beginn' => '19:30' ) ) ),
	array( $url( 'a' ) => ts_detail( 1, '', array( 'Datum' => '17.10.2026', 'Einlass' => '18:30', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Straße 9' ) ) )
)[0];
ec_check( 'Einlass 18:30 + Beginn 19:30 -> 19:30', $e['start_time'], '19:30' );
$e = $parse(
	array( ts_item( 'Freitag, 01. Januar 2027', 'Neujahr', 'n', array( 'Beginn' => '9:05' ) ) ),
	array( $url( 'n' ) => ts_detail( 2, '', array( 'Datum' => '01.01.2027', 'Beginn' => '9:05', 'Adresse' => 'Hohenberger Straße 9' ) ) )
)[0];
ec_check( 'Jahreswechsel + einstellige Stunde', array( $e['start_date'], $e['start_time'] ), array( '2027-01-01', '09:05' ) );

echo "Mehrere Termine, Dubletten, Struktur\n";
$events = $parse(
	array(
		ts_item( 'Samstag, 07. November 2026', 'Zuckerguss', 'z', array( 'Beginn' => '19:30' ) ),
		ts_item( 'Samstag, 28. November 2026', 'Zuckerguss', 'z-2', array( 'Beginn' => '19:30' ) ),
		ts_item( 'Samstag, 28. November 2026', 'Zuckerguss', 'z-2', array( 'Beginn' => '19:30' ) ),
	),
	array(
		$url( 'z' )   => ts_detail( 53299, '', array( 'Datum' => '07.11.2026', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Strasse 9' ) ),
		$url( 'z-2' ) => ts_detail( 53302, '', array( 'Datum' => '28.11.2026', 'Beginn' => '19:30', 'Adresse' => 'Hohenberger Strasse 9' ) ),
	)
);
ec_check( 'zwei Aufführungen = zwei Termine, doppelter Eintrag einmal', array_column( $events, 'external_id' ), array( '53299', '53302' ) );
$r = $source->parse( '<html><body><h1>Wartungsarbeiten</h1></body></html>', array() );
ec_check( 'leere Struktur -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
