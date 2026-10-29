<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters DAS ZENTRUM Bayreuth (docs/04-quellen.md Abschnitt 5).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten API-Antworten im Aufbau der echten TEC-REST-API (Stand 2026-10-15):
 * keine echten HTTP-Abrufe (Abruf-Tests ersetzen die Antwort über den Filter pre_http_request), keine
 * Datenbankänderung (der Crawler-Fehlertest läuft in einer Transaktion mit Rollback).
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

echo "Abruf: Timeout, ein Retry, Fehlerfälle (HTTP-Antworten über pre_http_request ersetzt, kein echter Abruf)\n";
$dz_queue    = array(); // Antworten in Abrufreihenfolge: string = Body (HTTP 200), int = HTTP-Status, WP_Error = Transportfehler.
$dz_requests = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$dz_queue, &$dz_requests ) {
		$dz_requests[] = $url;
		$next          = array_shift( $dz_queue );
		if ( null === $next ) {
			return new WP_Error( 'http_request_failed', 'Test: keine Antwort vorgesehen' );
		}
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => is_int( $next ) ? 'Fehlerseite' : $next,
			'response' => array( 'code' => is_int( $next ) ? $next : 200, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$timeout = new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out after 10004 milliseconds' );
$p1      = dz_page( array( dz_item( 233, 'Robert Alan &#8211; Pervekt', '2026-10-29 20:00:00', $kleinkunst ), dz_item( 237, 'Stadt.Land.Mord.', '2026-10-30 20:00:00', $europasaal ) ), 2 );
$p2      = dz_page( array( dz_item( 1064, 'Das 5. Rudelsingen &#8211; Gruppen-Karaoke', '2027-03-10 19:30:00', $europasaal ) ), 2 );
$run     = function ( array $answers ) use ( &$dz_queue, &$dz_requests, $source ) {
	$dz_queue    = $answers;
	$dz_requests = array();
	return $source->fetch_events();
};
$err_msg = function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_message() : 'kein Fehler';
};

$log_file = tempnam( get_temp_dir(), 'dz-log' );
$log_prev = ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky

$r = $run( array( $p1, $p2 ) );
ec_check( 'Erfolg: 2 Seiten, 2 Abrufe', array( is_array( $r ) ? count( $r ) : $err_msg( $r ), count( $dz_requests ) ), array( 3, 2 ) );
ec_check( 'Erfolg: produktive URLs', $dz_requests, array( Eventcrawler_Source_Das_Zentrum_Bayreuth::API_URL . '?per_page=50&page=1', Eventcrawler_Source_Das_Zentrum_Bayreuth::API_URL . '?per_page=50&page=2' ) );
ec_check( 'Erfolg: Stable ID, Venue, Adresse, Stadt (ID 60 -> Bayreuth)', array( $r[0]['external_id'], $r[0]['venue_name'], $r[0]['venue_address'], $r[0]['city'], $r[1]['venue_address'], $r[2]['external_id'] ), array( '233', 'DAS ZENTRUM – Kleinkunstbühne', '', 'Bayreuth', 'Äußere Badstraße 7a', '1064' ) );

$r = $run( array( $timeout, $p1, $p2 ) );
ec_check( 'Timeout Seite 1, Retry erfolgreich: 3 Events, 3 Abrufe', array( is_array( $r ) ? count( $r ) : $err_msg( $r ), count( $dz_requests ) ), array( 3, 3 ) );
ec_check( 'Retry: dieselbe URL', $dz_requests[0] === $dz_requests[1], true );

$r = $run( array( $p1, $timeout, $p2 ) );
ec_check( 'Timeout Seite 2, Retry erfolgreich', array( is_array( $r ) ? count( $r ) : $err_msg( $r ), count( $dz_requests ) ), array( 3, 3 ) );

$r = $run( array( $timeout, $timeout, $p1, $p2 ) );
ec_check( 'zweimal Timeout: Fehler nach genau 2 Abrufen', array( is_wp_error( $r ), count( $dz_requests ) ), array( true, 2 ) );
ec_check( 'zweimal Timeout: verständliche Meldung', $err_msg( $r ), 'Zeitüberschreitung auch beim zweiten Versuch. Quelle nicht erreichbar: cURL error 28: Connection timed out after 10004 milliseconds' );

$r = $run( array( $p1, $timeout, $timeout, $p2 ) );
ec_check( 'zweimal Timeout auf Seite 2: keine Teildaten', array( is_wp_error( $r ), count( $dz_requests ) ), array( true, 3 ) );

$r = $run( array( $timeout, 500 ) );
ec_check( 'Timeout, dann HTTP 500: Fehler, kein dritter Versuch', array( $err_msg( $r ), count( $dz_requests ) ), array( 'Quelle antwortet mit HTTP 500.', 2 ) );

foreach ( array( 500, 403, 404 ) as $code ) {
	$r = $run( array( $code, $p1, $p2 ) );
	ec_check( "HTTP {$code}: kein Retry", array( $err_msg( $r ), count( $dz_requests ) ), array( "Quelle antwortet mit HTTP {$code}.", 1 ) );
}
$r = $run( array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: www.das-zentrum.de' ), $p1, $p2 ) );
ec_check( 'anderer Netzwerkfehler (DNS): kein Retry', array( $err_msg( $r ), count( $dz_requests ) ), array( 'Quelle nicht erreichbar: cURL error 6: Could not resolve host: www.das-zentrum.de', 1 ) );
$r = $run( array( '<html>Wartung</html>', $p1 ) );
ec_check( 'ungültiges JSON: Strukturfehler, kein Retry', array( is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', count( $dz_requests ) ), array( 'eventcrawler_structure', 1 ) );
$r = $run( array( dz_page( array() ), $p1 ) );
ec_check( 'leere API-Antwort (0 Events): Fehler, kein Retry', array( is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', count( $dz_requests ) ), array( 'eventcrawler_structure', 1 ) );

ini_set( 'error_log', $log_prev ); // phpcs:ignore WordPress.PHP.IniSet.Risky
$log = (string) file_get_contents( $log_file );
unlink( $log_file );
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	ec_check( 'Debug-Log: Versuch 1/2 mit Quelle und URL', false !== strpos( $log, '[eventcrawler][das-zentrum-bayreuth] Zeitüberschreitung bei Versuch 1/2 (' . Eventcrawler_Source_Das_Zentrum_Bayreuth::API_URL . '?per_page=50&page=1)' ), true );
	ec_check( 'Debug-Log: Versuch 2/2 (finaler Fehler)', false !== strpos( $log, '[eventcrawler][das-zentrum-bayreuth] Fehler bei Versuch 2/2' ), true );
}

echo "Crawler: finaler Timeout lässt vorhandene Daten unverändert\n";
global $wpdb;
$table    = $wpdb->prefix . 'eventcrawler_events';
$snapshot = function () use ( $wpdb, $table ) {
	return md5( wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s ORDER BY id", 'das-zentrum-bayreuth' ), ARRAY_A ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$before = $snapshot();
$wpdb->query( 'START TRANSACTION' );
$dz_queue    = array( $timeout, $timeout );
$dz_requests = array();
$r           = ( new Eventcrawler_Crawler( new Eventcrawler_Repository() ) )->run( $source );
$after       = $snapshot();
$wpdb->query( 'ROLLBACK' );
ec_check( 'Crawler liefert WP_Error', is_wp_error( $r ), true );
ec_check( 'Datensätze inkl. last_seen_at/content_hash unverändert', $after, $before );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
