<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Schützenhaus Münchberg (docs/04-quellen.md Abschnitt 9).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten HTML-Ausschnitten im Aufbau der echten Seite (Elementor/Ele Custom Skin,
 * Stand 2026-10-15): keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-schuetzenhaus-muenchberg.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-schuetzenhaus-muenchberg.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Schuetzenhaus_Muenchberg' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Schützenhaus Münchberg) ist nicht aktiv.\n" );
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
 * Ein Eintrag im Aufbau der echten Seite.
 *
 * @param int    $id       Post-ID ('' = ohne ID).
 * @param array  $headings Überschriften in Reihenfolge; Werte mit '<span>' werden als HTML übernommen.
 * @param array  $texts    Textblöcke.
 * @param string $slug     Slug für "Mehr Infos" ('' = Button fehlt).
 * @return string
 */
function mb_item( $id, array $headings, array $texts, $slug ) {
	$h = '';
	foreach ( $headings as $text ) {
		$h .= '<div class="elementor-element elementor-widget elementor-widget-heading"><div class="elementor-widget-container"><h2 class="elementor-heading-title elementor-size-default">' . $text . '</h2></div></div>';
	}
	foreach ( $texts as $text ) {
		$h .= '<div class="elementor-element elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><p>' . $text . '</p></div></div>';
	}
	if ( '' !== $slug ) {
		$h .= '<div class="elementor-button-wrapper"><a class="elementor-button elementor-button-link elementor-size-sm" href="https://schuetzenhaus-muenchberg.de/veranstaltung/' . $slug . '/"><span class="elementor-button-content-wrapper"><span class="elementor-button-text">Mehr Infos</span></span></a></div>';
	}
	$h .= '<div class="elementor-button-wrapper"><a class="elementor-button elementor-button-link elementor-size-sm" href="https://okticket.de/tickets-x"><span class="elementor-button-text">Zum Ticket VVK</span></a></div>';
	return '<article class="elementor-post elementor-grid-item ecs-post-loop post-' . $id . ' veranstaltung"' . ( '' === $id ? '' : ' id="post-' . $id . '"' ) . '>' . $h . '</article>';
}

$source = new Eventcrawler_Source_Schuetzenhaus_Muenchberg();
$parse  = function ( array $items ) use ( $source ) {
	return $source->parse( '<html><body><div class="ecs-posts"><h2 class="elementor-heading-title">Oktober</h2>' . implode( '', $items ) . '</div></body></html>' );
};
$one    = function ( $item ) use ( $parse ) {
	return $parse( array( $item ) )[0];
};
$std    = array( '<span>Krimi-Herbst</span> | <span>Vortrag</span>', 'Joe Bausch', 'Sa, 24.10.26', 'Beginn: 20:00 Uhr', 'Einlass: 19:00 Uhr' );

echo "Registrierung\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['schuetzenhaus-muenchberg'] ), true );
ec_check( 'Reihenfolge', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus', 'concertbuero-franken' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'schuetzenhaus-muenchberg' ), 'Schützenhaus Münchberg' );
ec_check( 'Crawl-URL', $source->get_url(), 'https://schuetzenhaus-muenchberg.de/veranstaltungen/' );

echo "Normaler Termin\n";
$e = $one( mb_item( 8624, $std, array( 'Ein Abend zwischen Gänsehaut und Erkenntnis.' ), 'joe-bausch' ) );
ec_check( 'kein Fehler', isset( $e['_error'] ), false );
ec_check( 'Titel (Kategorie-Überschrift ignoriert)', $e['title'], 'Joe Bausch' );
ec_check( 'Beschreibung = Kurztext', $e['description'], 'Ein Abend zwischen Gänsehaut und Erkenntnis.' );
ec_check( 'start_date', $e['start_date'], '2026-10-24' );
ec_check( 'start_time = Beginn, nicht Einlass', $e['start_time'], '20:00' );
ec_check( 'kein Ende', array( $e['end_date'], $e['end_time'] ), array( null, null ) );
ec_check( 'all_day false', $e['all_day'], false );
ec_check( 'Ort / Adresse / Stadt', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Schützenhaus Münchberg', 'Hofer Straße 69', 'Münchberg' ) );
ec_check( 'external_id = Post-ID-1', $e['external_id'], '8624-1' );
ec_check( 'event_url = source_url = Detailseite', array( $e['event_url'], $e['source_url'] ), array( 'https://schuetzenhaus-muenchberg.de/veranstaltung/joe-bausch/', 'https://schuetzenhaus-muenchberg.de/veranstaltung/joe-bausch/' ) );

echo "Ohne Kategorie (Hör Hin), Sonderzeichen\n";
$e = $one( mb_item( 8473, array( 'Hör Hin | Vol. 10', 'Do, 12.11.26', 'Beginn: 19:00 Uhr', 'Einlass: 18:00 Uhr' ), array( 'WELTWÄRTS, Wenzel &amp; Schmalz – „Weil’s raus muss“ – Café ß' ), 'hoer-hin-vol-10' ) );
ec_check( 'Titel mit "|"', $e['title'], 'Hör Hin | Vol. 10' );
ec_check( '& – „“ ’ é ß', $e['description'], 'WELTWÄRTS, Wenzel & Schmalz – „Weil’s raus muss“ – Café ß' );

echo "Ausverkauft, mehrere Tage\n";
$r = $parse( array( mb_item( 8572, array( '<span>Krimi-Herbst</span>', 'Stadtspaziergang: Mysteriöses Münchberg', 'Di, 27.10. &amp; Mi, 28.10.26', 'Beginn: 17:30 Uhr' ), array( 'Dieser besondere Stadtrundgang …', 'AUSVERKAUFT' ), '' ) ) );
ec_check( 'zwei Tage = zwei Termine', array_column( $r, 'start_date' ), array( '2026-10-27', '2026-10-28' ) );
ec_check( 'IDs je Termin', array_column( $r, 'external_id' ), array( '8572-1', '8572-2' ) );
ec_check( 'AUSVERKAUFT nicht als Beschreibung', $r[0]['description'], 'Dieser besondere Stadtrundgang …' );
ec_check( 'ohne "Mehr Infos": Programmseite als URL', $r[0]['event_url'], 'https://schuetzenhaus-muenchberg.de/veranstaltungen/' );
ec_check( 'ohne Einlass: Beginn', $r[1]['start_time'], '17:30' );
$e = $one( mb_item( 8443, array( 'Dr. Mark Benecke', 'Mi, 04.11.26', 'Beginn: 20:00 Uhr' ), array( 'AUSVERKAUFT' ), '' ) );
ec_check( 'nur AUSVERKAUFT -> Beschreibung leer', $e['description'], '' );

echo "Datum / Uhrzeit, nicht raten\n";
ec_check( 'Tippfehler der Quelle "Sa, 27.02.07" (Wochentag passt nicht) -> _error', ! empty( $one( mb_item( 11567, array( 'Huebnotix', 'Sa, 27.02.07', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'h' ) )['_error'] ), true );
ec_check( 'Jahreswechsel im Eintrag ohne eigenes Jahr -> _error', ! empty( $one( mb_item( 1, array( 'Silvester', 'Do, 31.12. &amp; Fr, 01.01.27', 'Beginn: 20:00 Uhr' ), array( 'x' ), 's' ) )['_error'] ), true );
$r = $parse( array( mb_item( 2, array( 'Silvester', 'Do, 31.12.26 &amp; Fr, 01.01.27', 'Beginn: 20:00 Uhr' ), array( 'x' ), 's' ) ) );
ec_check( 'Jahreswechsel mit Jahr an jedem Tag', array_column( $r, 'start_date' ), array( '2026-12-31', '2027-01-01' ) );
ec_check( 'Datum fehlt -> _error', ! empty( $one( mb_item( 3, array( 'A', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'a' ) )['_error'] ), true );
ec_check( 'Beginn unlesbar -> _error', ! empty( $one( mb_item( 4, array( 'A', 'Sa, 24.10.26', 'Beginn: abends' ), array( 'x' ), 'a' ) )['_error'] ), true );
$e = $one( mb_item( 5, array( 'A', 'Sa, 24.10.26', 'Einlass: 19:00 Uhr' ), array( 'x' ), 'a' ) );
ec_check( 'nur Einlass -> start_time NULL', array( $e['start_time'], isset( $e['_error'] ) ), array( null, false ) );
ec_check( 'Beginn mit Punkt "Beginn: 9.30 Uhr"', $one( mb_item( 6, array( 'A', 'So, 25.10.26', 'Beginn: 9.30 Uhr' ), array( 'x' ), 'a' ) )['start_time'], '09:30' );
$e = $one( mb_item( 7, array( '<span>Konzert</span>', 'Sa, 24.10.26', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'a' ) );
ec_check( 'Titel fehlt -> leer (Crawler zählt "fehlerhaft")', $e['title'], '' );
ec_check( 'ohne Post-ID -> _error', $one( mb_item( '', $std, array( 'x' ), 'a' ) )['_error'], 'Post-ID fehlt' );

echo "Zwei Aufführungen, Dubletten, Struktur\n";
$r = $parse(
	array(
		mb_item( 9186, array( 'Classic Rock meets Gospel', 'Fr, 09.04.27', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'classic-rock-meets-gospel' ),
		mb_item( 10093, array( 'Classic Rock meets Gospel', 'Sa, 10.04.27', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'classic-rock-meets-gospel-2' ),
		mb_item( 10093, array( 'Classic Rock meets Gospel', 'Sa, 10.04.27', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'classic-rock-meets-gospel-2' ),
	)
);
ec_check( 'zwei Beiträge = zwei Termine, Dublette einmal', array_column( $r, 'external_id' ), array( '9186-1', '10093-1' ) );
$r = $source->parse( '<html><body><h1>Wartungsarbeiten</h1></body></html>' );
ec_check( 'keine Einträge -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );

echo "Filter (zentral)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $one( mb_item( 9, array( 'SPD Ortsverein Testevent', 'Sa, 24.10.26', 'Beginn: 20:00 Uhr' ), array( 'x' ), 'a' ) );
ec_check( 'Adapter liefert den Termin', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
