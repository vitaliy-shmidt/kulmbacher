<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests der Export- und Filterregeln aus dem Kundenfeedback vom 2026-10-02
 * (docs/07-export.md Abschnitt 12, docs/05-filter.md).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Testdatensätze (source = 'dev-test') werden in einer Transaktion angelegt und per ROLLBACK verworfen; die
 * Filterregel-Option wird nach den Tests wiederhergestellt. Gespeicherte Daten bleiben unverändert (geprüft).
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-export-rules.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-export-rules.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Exporter' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler ist nicht aktiv.\n" );
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
 * Testdatensatz mit Standardwerten.
 *
 * @param array $fields Abweichende Felder.
 * @return array
 */
function ec_event( array $fields ) {
	return array_merge(
		array(
			'source'        => 'dev-test',
			'title'         => 'Testveranstaltung',
			'description'   => '',
			'start_date'    => '2026-10-15',
			'start_time'    => '19:00:00',
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => 0,
			'venue_name'    => '',
			'venue_address' => null,
			'city'          => 'Hof',
			'event_url'     => 'https://www.example.de/event/1',
			'source_url'    => 'https://www.example.de/event/1',
		),
		$fields
	);
}

/**
 * <p>-Zeilen für eine Liste von Datensätzen (wie build_xml(), ohne Datenbank).
 *
 * @param array[] $events Datensätze in Export-Reihenfolge.
 * @return string[]
 */
function ec_lines( array $events ) {
	$out = array();
	foreach ( Eventcrawler_Exporter::group_times( $events ) as $line ) {
		$out[] = Eventcrawler_Exporter::format_times( $line['times'] ) . $line['body'];
	}
	return $out;
}

// ---------------------------------------------------------------------------------------------------------------
echo "URL (sichtbar nur Hostname, gespeichert vollständig)\n";
$urls = array(
	'https://pages.et4.de/de/bad-steben/default/detail/Event/e_123'                => 'pages.et4.de',
	'https://www.burg-rabenstein.de/events/foo'                                     => 'www.burg-rabenstein.de',
	'https://veranstaltungen.coburg.de/details/foo?a=1'                             => 'veranstaltungen.coburg.de',
	'http://example.de/event'                                                       => 'example.de',
	'HTTPS://WWW.Example.DE/Pfad#info'                                              => 'www.example.de',
	'https://example.de:8443/event'                                                 => 'example.de',
	'mailto:foo@example.de'                                                         => 'mailto:foo@example.de',
	'ftp://example.de/datei'                                                        => 'ftp://example.de/datei',
	'www.example.de/ohne-schema'                                                    => 'www.example.de/ohne-schema',
	'https://'                                                                      => 'https://',
);
foreach ( $urls as $url => $expected ) {
	ec_check( "display_url( {$url} )", Eventcrawler_Exporter::display_url( $url ), $expected );
}
ec_check(
	'Filter eventcrawler_export_display_url liefert weiterhin die volle URL',
	( function () {
		$f = function ( $display, $url ) {
			return $url;
		};
		add_filter( 'eventcrawler_export_display_url', $f, 10, 2 );
		$line = Eventcrawler_Exporter::format_line( ec_event( array() ) );
		remove_filter( 'eventcrawler_export_display_url', $f, 10 );
		return $line;
	} )(),
	'19:00 Uhr Testveranstaltung. Info: https://www.example.de/event/1'
);

// ---------------------------------------------------------------------------------------------------------------
echo "Ort, Stadt, Adresse\n";
$rabenstein = array(
	'city'       => 'Ahorntal',
	'event_url'  => 'https://www.burg-rabenstein.de/events/alle-events-im-ueberblick/murder-mystery-dinner-kripo-tv-20260925',
	'source_url' => 'https://www.burg-rabenstein.de/events/alle-events-im-ueberblick/murder-mystery-dinner-kripo-tv-20260925',
);
ec_check(
	'Ort steht im Titel -> entfällt',
	Eventcrawler_Exporter::format_line( ec_event( array_merge( $rabenstein, array( 'title' => 'Familienführung auf Burg Rabenstein', 'venue_name' => 'Burg Rabenstein' ) ) ) ),
	'19:00 Uhr Familienführung auf Burg Rabenstein. Info: www.burg-rabenstein.de'
);
ec_check(
	'Ort nicht im Titel -> bleibt',
	Eventcrawler_Exporter::format_line( ec_event( array_merge( $rabenstein, array( 'title' => 'Murder Mystery Dinner "Kripo TV"', 'venue_name' => 'Burg Rabenstein' ) ) ) ),
	'19:00 Uhr Murder Mystery Dinner "Kripo TV", Burg Rabenstein. Info: www.burg-rabenstein.de'
);
ec_check(
	'Ort steht wörtlich in der Beschreibung -> entfällt (echter Kripo-TV-Teaser)',
	Eventcrawler_Exporter::format_line(
		ec_event(
			array_merge(
				$rabenstein,
				array(
					'title'       => 'Murder Mystery Dinner "Kripo TV"',
					'description' => '"Kripo TV" - ein neues Murder Mystery Dinner auf Burg Rabenstein! Erleben Sie zum 4-Gänge-Menü einen unterhaltsamen und spannenden Abend mit',
					'venue_name'  => 'Burg Rabenstein',
				)
			)
		)
	),
	'19:00 Uhr Murder Mystery Dinner "Kripo TV", "Kripo TV" - ein neues Murder Mystery Dinner auf Burg Rabenstein! Erleben Sie zum 4-Gänge-Menü einen unterhaltsamen und spannenden Abend mit. Info: www.burg-rabenstein.de'
);
ec_check(
	'Ort nur als Wortteil im Titel -> bleibt (Wortgrenze)',
	Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Hofgarten-Fest', 'venue_name' => 'Hof', 'city' => 'Rehau' ) ) ),
	'19:00 Uhr Hofgarten-Fest, Hof. Info: www.example.de'
);
ec_check(
	'Ort = Stadt (steht im <h2>) -> entfällt',
	Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Pflanzung im Familienwald im Kurpark 2026', 'venue_name' => 'Bad Rodach', 'city' => 'Bad Rodach', 'start_time' => '10:00:00' ) ) ),
	'10:00 Uhr Pflanzung im Familienwald im Kurpark 2026. Info: www.example.de'
);
ec_check(
	'Stadt im <h2>, Eigenname mit Stadt bleibt vollständig',
	Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Die historische Innenstadt von Coburg', 'description' => 'Stadtführung', 'venue_name' => 'Tourist-Information Coburg', 'city' => 'Coburg' ) ) ),
	'19:00 Uhr Die historische Innenstadt von Coburg, Stadtführung, Tourist-Information Coburg. Info: www.example.de'
);
ec_check(
	'Stadt wird nie separat angehängt',
	false !== strpos( Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Konzert', 'venue_name' => 'Kurpark', 'city' => 'Bad Steben' ) ) ), 'Bad Steben' ),
	false
);
ec_check(
	'Adresse vorhanden, Ort nicht im Titel -> Ort + Adresse',
	Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Pippi Langstrumpf', 'description' => 'Figurentheater für die ganze Familie', 'venue_name' => 'Kongresshaus Rosengarten', 'venue_address' => 'Berliner Platz 1', 'city' => 'Coburg' ) ) ),
	'19:00 Uhr Pippi Langstrumpf, Figurentheater für die ganze Familie, Kongresshaus Rosengarten, Berliner Platz 1. Info: www.example.de'
);
ec_check(
	'Adresse vorhanden, Ort im Titel -> nur Adresse (Kundenbeispiel, Adresse als Testwert)',
	Eventcrawler_Exporter::format_line( ec_event( array_merge( $rabenstein, array( 'title' => 'Murder Mystery Dinner auf Burg Rabenstein', 'venue_name' => 'Burg Rabenstein', 'venue_address' => 'Rabenstein 33' ) ) ) ),
	'19:00 Uhr Murder Mystery Dinner auf Burg Rabenstein, Rabenstein 33. Info: www.burg-rabenstein.de'
);
ec_check(
	'Adresse fehlt -> keine Adresse, keine leeren Kommata',
	Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Konzert', 'venue_name' => 'Kurpark', 'venue_address' => '' ) ) ),
	'19:00 Uhr Konzert, Kurpark. Info: www.example.de'
);
ec_check(
	'Adresse steht schon in der Beschreibung -> nicht doppelt',
	Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Führung', 'description' => 'Treffpunkt: Herrngasse 4', 'venue_name' => 'Tourist-Information Coburg', 'venue_address' => 'Herrngasse 4', 'city' => 'Coburg' ) ) ),
	'19:00 Uhr Führung, Treffpunkt: Herrngasse 4, Tourist-Information Coburg. Info: www.example.de'
);
ec_check(
	'Rock Rising (Kundenbeispiel) als Einzelzeile',
	Eventcrawler_Exporter::format_line(
		ec_event(
			array(
				'title'         => 'Rock Rising - The Supreme Classic Rock Show',
				'venue_name'    => 'Festsaal der Freiheitshalle Hof',
				'venue_address' => 'Kulmbacher Str. 4',
				'start_time'    => '18:30:00',
				'event_url'     => 'https://www.freiheitshalle.de/veranstaltungen/rock-rising',
			)
		)
	),
	'18:30 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de'
);

// ---------------------------------------------------------------------------------------------------------------
echo "Treffpunkt, Telefon, Kontakt, Anmeldung bleiben erhalten\n";
$keep = array(
	'Treffpunkt: Rathaus, Marktplatz.',
	'Tel.: 09221/9588-0',
	'Telefon: 09561 / 89-8000',
	'Telefonnummer: 09288 74-70',
	'Ansprechpartner: Coburg Marketing',
	'Kontakt: info@example.de',
	'Anmeldung bei: Tourist-Information',
	'Anmeldung erforderlich',
	'Anmeldung unter: 09221 9588-0',
	'Begrenzte Teilnehmerzahl',
	'Karten: 12,00 €',
	'Tickets: an der Abendkasse',
	'Dauer ca. 90 Minuten',
);
foreach ( $keep as $text ) {
	$line = Eventcrawler_Exporter::format_line( ec_event( array( 'title' => 'Stadtführung', 'description' => 'Rundgang durch die Altstadt. ' . $text . ' Bitte pünktlich sein.' ) ) );
	ec_check( "bleibt: {$text}", false !== strpos( $line, $text ), true );
}
ec_check(
	'Kellerführung (Kundenbeispiel) vollständig',
	Eventcrawler_Exporter::format_line(
		ec_event(
			array(
				'title'       => '„Kellerführung durch die Kulmbacher Unterwelt“',
				'description' => 'Treffpunkt: Rathaus, Marktplatz. Info + Anmeldung (Begrenzte Teilnehmerzahl): Tel.: 09221/9588-0',
				'start_time'  => '15:00:00',
				'city'        => 'Kulmbach',
				'event_url'   => 'https://www.kulmbach.de/veranstaltungen/kellerfuehrung',
			)
		)
	),
	'15:00 Uhr „Kellerführung durch die Kulmbacher Unterwelt“, Treffpunkt: Rathaus, Marktplatz. Info + Anmeldung (Begrenzte Teilnehmerzahl): Tel.: 09221/9588-0. Info: www.kulmbach.de'
);

// ---------------------------------------------------------------------------------------------------------------
echo "Mehrere Uhrzeiten (nur bei strikter Identität)\n";
$rock = array(
	'title'         => 'Rock Rising - The Supreme Classic Rock Show',
	'venue_name'    => 'Festsaal der Freiheitshalle Hof',
	'venue_address' => 'Kulmbacher Str. 4',
	'event_url'     => 'https://www.freiheitshalle.de/veranstaltungen/rock-rising',
);
ec_check(
	'2 gleiche Termine, andere Uhrzeit -> 1 Zeile',
	ec_lines( array( ec_event( $rock + array( 'start_time' => '18:30:00' ) ), ec_event( $rock + array( 'start_time' => '20:00:00' ) ) ) ),
	array( '18:30 Uhr & 20:00 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de' )
);
ec_check(
	'3 gleiche Termine, unsortiert -> chronologisch',
	ec_lines( array( ec_event( $rock + array( 'start_time' => '21:30:00' ) ), ec_event( $rock + array( 'start_time' => '18:30:00' ) ), ec_event( $rock + array( 'start_time' => '20:00:00' ) ) ) ),
	array( '18:30 Uhr, 20:00 Uhr & 21:30 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de' )
);
ec_check(
	'doppelte Uhrzeit -> nur einmal',
	ec_lines( array( ec_event( $rock + array( 'start_time' => '18:30:00' ) ), ec_event( $rock + array( 'start_time' => '18:30:00' ) ), ec_event( $rock + array( 'start_time' => '20:00:00' ) ) ) ),
	array( '18:30 Uhr & 20:00 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de' )
);
$not_grouped = array(
	'gleicher Titel, anderer Ort'          => array( 'venue_name' => 'Theater Hof' ),
	'gleicher Titel, andere Adresse'       => array( 'venue_address' => 'Kulmbacher Straße 4' ),
	'gleicher Titel, andere Beschreibung'  => array( 'description' => 'Zusatzvorstellung' ),
	'gleicher Titel, anderer Tag'          => array( 'start_date' => '2026-10-16' ),
	'gleicher Titel, andere Stadt'         => array( 'city' => 'Rehau' ),
	'gleicher Titel, andere Quelle'        => array( 'source' => 'dev-test-2' ),
	'gleicher Titel, andere Domain'        => array( 'event_url' => 'https://www.hof.de/rock-rising' ),
	'Titel nur in Groß-/Kleinschreibung anders' => array( 'title' => 'ROCK RISING - The Supreme Classic Rock Show' ),
);
foreach ( $not_grouped as $name => $diff ) {
	ec_check( "{$name} -> 2 Zeilen", count( ec_lines( array( ec_event( $rock + array( 'start_time' => '18:30:00' ) ), ec_event( array_merge( $rock, $diff, array( 'start_time' => '20:00:00' ) ) ) ) ) ), 2 );
}
ec_check(
	'ganztägig und mit Uhrzeit -> nicht gruppiert',
	ec_lines( array( ec_event( $rock + array( 'start_time' => '18:30:00' ) ), ec_event( $rock + array( 'start_time' => null, 'all_day' => 1 ) ) ) ),
	array(
		'18:30 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de',
		'Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de',
	)
);
ec_check(
	'zwei identische Termine ohne Uhrzeit -> nicht zusammengefasst',
	count( ec_lines( array( ec_event( $rock + array( 'start_time' => null ) ), ec_event( $rock + array( 'start_time' => null ) ) ) ) ),
	2
);
ec_check(
	'Coburg: andere Termin-URL, gleiche sichtbare Domain -> gruppiert',
	count( ec_lines( array( ec_event( $rock + array( 'start_time' => '11:00:00', 'event_url' => 'https://veranstaltungen.coburg.de/details/veranstaltung/a/datum/1.html' ) ), ec_event( $rock + array( 'start_time' => '14:00:00', 'event_url' => 'https://veranstaltungen.coburg.de/details/veranstaltung/a/datum/2.html' ) ) ) ) ),
	1
);

// Rock Rising über den echten Weg: 2 Datensätze in der DB -> 1 <p> im XML, DB bleibt getrennt.
global $wpdb;
$table   = Eventcrawler_Installer::table_name();
$repo    = new Eventcrawler_Repository();
$before  = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'START TRANSACTION' );
foreach ( array( '20:00:00', '18:30:00' ) as $i => $time ) {
	$event                 = ec_event( $rock + array( 'start_time' => $time, 'external_id' => 'rock-rising-' . $i ) );
	$event['event_key']    = Eventcrawler_Crawler::event_key( $event );
	$event['content_hash'] = Eventcrawler_Crawler::content_hash( $event );
	$repo->insert( $event );
}
$exporter = new Eventcrawler_Exporter( $repo );
$xml      = $exporter->build_xml( array( 'source' => 'dev-test' ) );
ec_check( 'Rock Rising: 2 Datensätze in der DB', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE source = 'dev-test'" ), 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
ec_check( 'Rock Rising: XML-Zähler (Termine / Zeilen)', array( $exporter->exported, $exporter->lines ), array( 2, 1 ) );
ec_check(
	'Rock Rising: XML exakt',
	$xml,
	"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Root>\n<Textabschnitt>\n<h1>15. Oktober Donnerstag</h1>\n<h2>Hof</h2>\n<p>18:30 Uhr &amp; 20:00 Uhr Rock Rising - The Supreme Classic Rock Show, Festsaal der Freiheitshalle Hof, Kulmbacher Str. 4. Info: www.freiheitshalle.de</p>\n</Textabschnitt>\n</Root>"
);
$wpdb->query( 'ROLLBACK' );
ec_check( 'Rock Rising: Testdaten zurückgerollt', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), $before ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// ---------------------------------------------------------------------------------------------------------------
echo "Politische / interne Termine (gespeicherte aktive Regeln)\n";
$rules    = Eventcrawler_Filter_Rules::get_active();
$filtered = array(
	'SPD-Ortsverein Hof: Jahreshauptversammlung',
	'SPD Ortsverein lädt ein',
	'Bürgerversammlung der Stadt Kulmbach',
	'Bürgerversammlungen 2026 in den Ortsteilen',
	'Öffentliche Stadtratssitzung',
	'Sommerfest der CSU',
	'Infoabend der AfD',
	'Bündnis 90/Die Grünen: Mitgliedertreffen',
	'Die Grünen am Marktplatz',
	'ÖDP-Infostand',
	'Freie Wähler Stammtisch',
	'Jusos-Treffen',
	'Gemeinderatssitzung',
	'Sitzung des Kreistags',
	'Ausschusssitzung Bau',
);
foreach ( $filtered as $title ) {
	ec_check( "gefiltert: {$title}", Eventcrawler_Filter::get_exclusion_reason( array( 'title' => $title, 'description' => '', 'city' => 'Hof' ), null, $rules ) !== null, true );
}
$kept = array(
	'CSUfest am See',
	'Grünenfest im Park',
	'Grüner Markt',
	'Geführte Wanderung entlang des Grünen Bandes',
	'SPDX-Workshop',
	'Die Linkenbacher Blaskapelle',
);
foreach ( $kept as $title ) {
	ec_check( "nicht gefiltert: {$title}", Eventcrawler_Filter::get_exclusion_reason( array( 'title' => $title, 'description' => '', 'city' => 'Hof' ), null, $rules ), null );
}
// Dokumentierte Grenze des Wortfilters: "Grüne" allein ist keine Regel (sonst "Grüne Soße" usw.).
ec_check( 'Grenze: "Infostand der Grünen" wird NICHT erkannt (nur "Die Grünen"/"Grüne Jugend")', Eventcrawler_Filter::get_exclusion_reason( array( 'title' => 'Infostand der Grünen', 'description' => '', 'city' => 'Hof' ), null, $rules ), null );

echo "Filterregeln: einmalige Ergänzung neuer Defaults\n";
$saved_rules   = get_option( Eventcrawler_Filter_Rules::OPTION );
$saved_version = get_option( Eventcrawler_Filter_Rules::VERSION_OPTION );
$count_term    = function ( $term ) {
	$n = 0;
	foreach ( Eventcrawler_Filter_Rules::get_all() as $rule ) {
		$n += ( mb_strtolower( $rule['term'] ) === $term ) ? 1 : 0;
	}
	return $n;
};
$without = array_values(
	array_filter(
		Eventcrawler_Filter_Rules::get_all(),
		function ( $rule ) {
			return 'bürgerversammlung' !== mb_strtolower( $rule['term'] );
		}
	)
);
update_option( Eventcrawler_Filter_Rules::OPTION, $without );
update_option( Eventcrawler_Filter_Rules::VERSION_OPTION, 1 );
Eventcrawler_Filter_Rules::maybe_initialize();
Eventcrawler_Filter_Rules::maybe_initialize();
ec_check( 'alte Installation (Version 1): "bürgerversammlung" genau einmal ergänzt', $count_term( 'bürgerversammlung' ), 1 );
ec_check( 'übrige Regeln unverändert', count( Eventcrawler_Filter_Rules::get_all() ), count( $without ) + 1 );
update_option( Eventcrawler_Filter_Rules::OPTION, $without );
Eventcrawler_Filter_Rules::maybe_initialize();
ec_check( 'danach gelöscht (Version 2) -> kommt nicht zurück', $count_term( 'bürgerversammlung' ), 0 );
$disabled   = $without;
$disabled[] = array( 'id' => 'rtestdisabled', 'term' => 'Bürgerversammlung', 'category' => 'politics', 'match' => 'word', 'enabled' => false );
update_option( Eventcrawler_Filter_Rules::OPTION, $disabled );
update_option( Eventcrawler_Filter_Rules::VERSION_OPTION, 1 );
Eventcrawler_Filter_Rules::maybe_initialize();
ec_check( 'eigene deaktivierte Regel "Bürgerversammlung" -> keine Dublette', $count_term( 'bürgerversammlung' ), 1 );
ec_check( 'eigene Regel bleibt deaktiviert', Eventcrawler_Filter_Rules::get( 'rtestdisabled' )['enabled'], false );
update_option( Eventcrawler_Filter_Rules::OPTION, 'kaputt' );
update_option( Eventcrawler_Filter_Rules::VERSION_OPTION, 1 );
Eventcrawler_Filter_Rules::maybe_initialize();
ec_check( 'beschädigte Option wird nicht angefasst', get_option( Eventcrawler_Filter_Rules::OPTION ), 'kaputt' );
update_option( Eventcrawler_Filter_Rules::OPTION, $saved_rules );
update_option( Eventcrawler_Filter_Rules::VERSION_OPTION, $saved_version );
ec_check( 'Filterregeln wiederhergestellt', get_option( Eventcrawler_Filter_Rules::OPTION ), $saved_rules );

// ---------------------------------------------------------------------------------------------------------------
echo "Gesamtexport (gespeicherte Daten)\n";
$checksum = $wpdb->get_row( "CHECKSUM TABLE {$table}", ARRAY_A )['Checksum']; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$exporter = new Eventcrawler_Exporter( $repo );
$xml      = $exporter->build_xml();
if ( is_wp_error( $xml ) ) {
	ec_check( 'Gesamtexport erzeugt', $xml->get_error_message(), 'XML' );
} else {
	$dom = new DOMDocument();
	ec_check( 'wohlgeformt', $dom->loadXML( $xml ), true );
	ec_check( 'gültiges UTF-8', mb_check_encoding( $xml, 'UTF-8' ), true );
	ec_check( 'kein BOM', substr( $xml, 0, 5 ), '<?xml' );
	ec_check( 'Deklaration', strtok( $xml, "\n" ), '<?xml version="1.0" encoding="UTF-8"?>' );
	ec_check( 'kein CR', strpos( $xml, "\r" ), false );
	ec_check( 'keine Einrückung', preg_match( '/\n[ \t]/', $xml ), 0 );
	ec_check( 'kein LF am Ende', substr( $xml, -7 ), '</Root>' );
	ec_check( 'kein &nbsp;', strpos( $xml, '&nbsp;' ), false );
	ec_check( 'Zähler: Termine = DB-Zeilen mit Stadt', $exporter->exported, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE city <> ''" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Zähler: <p> = Zeilen', substr_count( $xml, '<p>' ), $exporter->lines );
	ec_check( 'nur h1/h2/p unter Textabschnitt', $dom->getElementsByTagName( '*' )->length, 2 + substr_count( $xml, '<h1>' ) + substr_count( $xml, '<h2>' ) + substr_count( $xml, '<p>' ) );
	$bad = array();
	foreach ( $dom->getElementsByTagName( 'p' ) as $p ) {
		$t = $p->textContent;
		if ( preg_match( '/, ,|\. ,|,,|  |(?<!\.)\.\.(?!\.)|\s$|^\s|, \.(?!\.\.)|Info: https?:\/\/|Info: \S+\//u', $t ) ) {
			$bad[] = $t;
		}
	}
	ec_check( 'Interpunktion: keine ", ,", ". ,", ", .", ".." ("..." der Quelle erlaubt), doppelten Leerzeichen; Info nur Domain', array_slice( $bad, 0, 3 ), array() );
}
ec_check( 'DB durch Export unverändert', $wpdb->get_row( "CHECKSUM TABLE {$table}", ARRAY_A )['Checksum'], $checksum ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
