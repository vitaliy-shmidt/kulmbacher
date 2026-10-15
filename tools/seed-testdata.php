<?php
/**
 * NUR FÜR ENTWICKLUNG: fügt Demo-Veranstaltungen in {prefix}eventcrawler_events ein bzw. entfernt sie.
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Alle Datensätze erhalten source = 'dev-test' und lassen sich mit --delete vollständig entfernen.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/seed-testdata.php <pfad/zu/wp-load.php>
 *   php tools/seed-testdata.php <pfad/zu/wp-load.php> --delete
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}

if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php seed-testdata.php <pfad/zu/wp-load.php> [--delete]\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Repository' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler ist nicht aktiv.\n" );
	exit( 1 );
}

global $wpdb;
$table  = Eventcrawler_Installer::table_name();
$source = 'dev-test';

if ( in_array( '--delete', $argv, true ) ) {
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = %s", $source ) );
	echo "{$deleted} Testdatensätze gelöscht.\n";
	exit( 0 );
}

$today = new DateTimeImmutable( current_time( 'Y-m-d' ) );
$day   = function ( $offset ) use ( $today ) {
	return $today->modify( "{$offset} days" )->format( 'Y-m-d' );
};

// Enthält bewusst: wiederkehrenden Termin mit gleichem Titel, vergangenes Event, Event ohne Uhrzeit,
// HTML/Script in der Beschreibung (Escaping-Test) und Umlaute.
$events = array(
	array( 'Ortsführung durch Bad Steben', 'Treffpunkt: Spielbank Bad Steben', $day( 1 ), '16:00', 'Spielbank Bad Steben', 'Bad Steben' ),
	array( 'Ortsführung durch Bad Steben', 'Treffpunkt: Spielbank Bad Steben', $day( 8 ), '16:00', 'Spielbank Bad Steben', 'Bad Steben' ),
	array( 'Wunschkonzert', 'Kurorchester spielt Wünsche des Publikums.', $day( 1 ), '19:30', 'Kurpark', 'Bad Steben' ),
	array( 'Familienführung auf Burg Rabenstein', '', $day( 2 ), '11:30', 'Burg Rabenstein', 'Ahorntal' ),
	array( 'Live im Heimathafen: "SHINY GNOMES"', 'Das Sommerkonzert <b>im Hirsch</b> <script>alert("xss")</script>', $day( 2 ), '20:00', 'Hirsch, Vogelweiherstr. 66', 'Nürnberg' ),
	array( 'Bierfest-Frühschoppen', '', $day( 3 ), null, 'Stadthalle', 'Kulmbach' ),
	array( 'Konzert ABC', 'Vergangene Veranstaltung', $day( -5 ), '19:00', 'Löwensaal', 'Nürnberg' ),
);

$repository = new Eventcrawler_Repository();
$count      = 0;

foreach ( $events as $i => $e ) {
	list( $title, $description, $date, $time, $venue, $city ) = $e;

	$url   = 'https://example.org/dev-test/event-' . ( $i + 1 );
	$event = array(
		'source'      => $source,
		'external_id' => 'dev-' . ( $i + 1 ),
		'title'       => $title,
		'description' => $description,
		'start_date'  => $date,
		'start_time'  => $time,
		'end_date'    => null,
		'end_time'    => null,
		'all_day'     => 0,
		'venue_name'  => $venue,
		'city'        => $city,
		'event_url'   => $url,
		'source_url'  => $url,
	);
	// Gleiche Formeln wie der Crawler (docs/02-datenbank.md).
	$event['event_key']    = Eventcrawler_Crawler::event_key( $event );
	$event['content_hash'] = Eventcrawler_Crawler::content_hash( $event );

	$id = $repository->insert( $event );

	if ( false === $id ) {
		fwrite( STDERR, "Fehler bei '{$title}': {$wpdb->last_error}\n" );
		continue;
	}
	$count++;
}

echo "{$count} Testdatensätze eingefügt (source = '{$source}').\n";
