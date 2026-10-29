<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests für den WPEM-Ersatz (docs/10-frontend.md): öffentliche Liste und Kalender, manuelle
 * Termine, einmalige WPEM-Übernahme.
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert. Alle Datenbankänderungen
 * (Testtermine, WPEM-Testbeiträge, Übernahme) laufen in EINER Transaktion und werden am Ende zurückgerollt;
 * die Prüfsumme der Veranstaltungstabelle wird vorher und nachher verglichen.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-frontend.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-frontend.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/veranstaltungen/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Frontend' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Frontend) ist nicht aktiv.\n" );
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
 * Rendert einen Shortcode mit den angegebenen GET-Parametern.
 *
 * @param string $shortcode Shortcode.
 * @param array  $get       GET-Parameter.
 * @return string HTML.
 */
function ec_render( $shortcode, array $get = array() ) {
	$_GET                   = $get;
	$_SERVER['REQUEST_URI'] = '/veranstaltungen/' . ( $get ? '?' . http_build_query( $get ) : '' );
	Eventcrawler_Frontend::reset_page_state();
	$html                   = do_shortcode( $shortcode );
	$_GET                   = array();
	return $html;
}

/**
 * IDs der gerenderten Termine in Reihenfolge.
 *
 * @param string $html HTML.
 * @return int[]
 */
function ec_ids( $html ) {
	preg_match_all( '/id="eventcrawler-event-(\d+)"/', $html, $m );
	return array_map( 'intval', $m[1] );
}

/**
 * Legt einen Testtermin an (Rohzeile, wie Crawler oder Formular sie speichern würden).
 *
 * @param array $data Felder.
 * @return int ID.
 */
function ec_fixture( array $data ) {
	$row = array_merge(
		array(
			'source'        => Eventcrawler_Manual_Events::SOURCE,
			'external_id'   => null,
			'title'         => 'Test',
			'description'   => null,
			'start_date'    => gmdate( 'Y-m-d' ),
			'start_time'    => null,
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => 0,
			'venue_name'    => null,
			'venue_address' => null,
			'city'          => 'Kulmbach',
			'event_url'     => null,
			'source_url'    => null,
		),
		$data
	);
	$row['event_key']    = Eventcrawler_Crawler::event_key( $row );
	$row['content_hash'] = Eventcrawler_Crawler::content_hash( $row );
	return ( new Eventcrawler_Repository() )->insert( $row );
}

global $wpdb;
$table    = Eventcrawler_Installer::table_name();
$checksum = function () use ( $wpdb, $table ) {
	return $wpdb->get_row( "CHECKSUM TABLE {$table}", ARRAY_A )['Checksum'];
};
$before   = $checksum();
$day      = function ( $offset ) {
	return wp_date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' ' . $offset . ' days' ) );
};
$today    = current_time( 'Y-m-d' );
$d1       = $day( '+1' );
$d2       = $day( '+2' );
$d5       = $day( '+5' );
wp_set_current_user( 0 );

$wpdb->query( 'START TRANSACTION' );

try {
	echo "Leere Tabelle\n";
	$wpdb->query( "DELETE FROM {$table}" );
	$html = ec_render( '[eventcrawler_events]' );
	ec_check( 'leere DB: Hinweis statt Liste', false !== strpos( $html, 'Für diesen Zeitraum wurden keine Veranstaltungen gefunden.' ), true );
	ec_check( 'leere DB: keine Einträge', ec_ids( $html ), array() );
	ec_check( 'leere DB: Kalender ohne Termine', false === strpos( ec_render( '[eventcrawler_calendar]' ), 'has-events' ), true );
	ec_check( 'leere DB: keine Seitennavigation', false === strpos( $html, 'eventcrawler-pagination' ), true );

	echo "Testtermine\n";
	$a = ec_fixture(
		array(
			'title'         => 'Konzert A',
			'description'   => 'Eigene Beschreibung A',
			'start_date'    => $d1,
			'start_time'    => '18:30:00',
			'end_date'      => $d1,
			'end_time'      => '22:00:00',
			'venue_name'    => 'Stadthalle',
			'venue_address' => 'Hauptstraße 1',
			'city'          => 'Kulmbach',
			'event_url'     => 'https://example.org/a',
		)
	);
	$b = ec_fixture(
		array(
			'source'      => 'coburg',
			'external_id' => 'geheim-extern-123',
			'title'       => 'Markt B',
			'description' => 'Fremdtext der Quelle B',
			'start_date'  => $d1,
			'city'        => 'Bayreuth',
			'source_url'  => 'https://quelle.example/intern-b',
		)
	);
	$c = ec_fixture(
		array(
			'title'      => 'Vergangen C',
			'start_date' => $day( '-1' ),
			'start_time' => '10:00:00',
		)
	);
	$d = ec_fixture(
		array(
			'title'      => 'Ganztags D',
			'start_date' => $d2,
			'all_day'    => 1,
		)
	);
	$e = ec_fixture(
		array(
			'title'      => 'Ausstellung E',
			'start_date' => $d2,
			'start_time' => '10:00:00',
			'end_date'   => $d5,
			'end_time'   => '17:00:00',
		)
	);
	$f = ec_fixture(
		array(
			'title'       => '<script>alert(1)</script>X',
			'description' => '<img src=x onerror=alert(2)>',
			'start_date'  => $d2,
			'start_time'  => '20:00:00',
			'venue_name'  => '<b>Ort</b>',
			'event_url'   => 'javascript:alert(3)',
		)
	);
	$g = ec_fixture(
		array(
			'title'      => 'FTP G',
			'start_date' => $d2,
			'start_time' => '21:00:00',
			'event_url'  => 'ftp://example.org/g',
		)
	);
	$many = array();
	for ( $i = 1; $i <= 25; $i++ ) {
		$many[] = ec_fixture(
			array(
				'title'      => sprintf( 'Serie %02d', $i ),
				'start_date' => $d5,
				'start_time' => sprintf( '%02d:00:00', 8 + ( $i % 12 ) ),
				'city'       => 'Hof',
			)
		);
	}

	echo "Liste\n";
	$html = ec_render( '[eventcrawler_events]' );
	$ids  = ec_ids( $html );
	ec_check( 'zukünftige Termine: 20 auf Seite 1', count( $ids ), 20 );
	ec_check( 'vergangene Termine fehlen', in_array( $c, $ids, true ), false );
	ec_check( 'Reihenfolge Tag 1: ohne Uhrzeit vor 18:30', array_slice( $ids, 0, 2 ), array( $b, $a ) );
	ec_check( 'Reihenfolge Tag 2: ganztägig, dann 10:00, 20:00, 21:00', array_slice( $ids, 2, 4 ), array( $d, $e, $f, $g ) );
	ec_check( 'Anzahl gesamt', false !== strpos( $html, '31 Veranstaltungen' ), true );
	ec_check( 'Tagesüberschrift deutsch', false !== strpos( $html, esc_html( Eventcrawler_Frontend::format_day( $d1 ) ) ), true );
	ec_check( 'Uhrzeit mit Ende', false !== strpos( $html, '18:30–22:00 Uhr' ), true );
	ec_check( 'ganztägig', false !== strpos( $html, 'ganztägig' ), true );
	ec_check( 'nie 00:00', false === strpos( $html, '00:00' ), true );
	ec_check( 'mehrtägig: "bis" mit Enddatum und Endzeit', false !== strpos( $html, 'bis ' . Eventcrawler_Frontend::format_day( $d5 ) . ', 17:00 Uhr' ), true );
	ec_check( 'Ort und Adresse, Stadt in der Karte', false !== strpos( $html, '<p class="eventcrawler-event__venue">Stadthalle</p>' ) && false !== strpos( $html, '<p class="eventcrawler-event__address">Hauptstraße 1, Kulmbach</p>' ), true );
	ec_check( 'Adresse angezeigt', false !== strpos( $html, '<dd>Hauptstraße 1</dd>' ), true );
	ec_check( 'ohne Adresse kein Adressfeld bei B', 1, substr_count( $html, '<dt>Adresse</dt>' ) );
	ec_check( 'Beschreibung manueller Termin sichtbar', false !== strpos( $html, 'Eigene Beschreibung A' ), true );
	ec_check( 'Beschreibung gecrawlter Termin verborgen', false === strpos( $html, 'Fremdtext der Quelle B' ), true );
	ec_check( 'sichere URL verlinkt', false !== strpos( $html, 'href="https://example.org/a"' ), true );
	ec_check( 'javascript:-URL nicht verlinkt', false === stripos( $html, 'javascript:' ), true );
	ec_check( 'ftp:-URL nicht verlinkt', false === strpos( $html, 'ftp://' ), true );
	ec_check( 'XSS Titel escaped', false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;X' ) && false === strpos( $html, '<script>alert(1)' ), true );
	ec_check( 'XSS Ort escaped', false !== strpos( $html, '&lt;b&gt;Ort&lt;/b&gt;' ), true );
	ec_check( 'XSS Beschreibung escaped', false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;img src=x onerror=alert(2)&gt;' ), true );
	ec_check( 'keine internen Felder (external_id)', false === strpos( $html, 'geheim-extern-123' ), true );
	ec_check( 'keine internen Felder (source_url)', false === strpos( $html, 'quelle.example' ), true );
	$row = ( new Eventcrawler_Repository() )->get_by_id( $a );
	ec_check( 'keine internen Felder (content_hash, event_key)', false === strpos( $html, $row['content_hash'] ) && false === strpos( $html, $row['event_key'] ), true );
	ec_check( 'kein Bearbeiten-Link für Besucher', false === strpos( $html, 'eventcrawler__edit' ) && false === strpos( $html, 'page=eventcrawler-event' ), true );
	ec_check( 'Seitennavigation: Seite 1 von 2', false !== strpos( $html, 'Seite 1 von 2' ), true );
	ec_check( 'Pagination-Link auf ec_page=2', false !== strpos( $html, 'ec_page=2' ), true );

	$page2 = ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_page' => '2' ) ) );
	ec_check( 'Seite 2: restliche 11', count( $page2 ), 11 );
	ec_check( 'Seiten überschneiden sich nicht', array_values( array_intersect( $ids, $page2 ) ), array() );
	ec_check( 'zu große Seitenzahl → letzte Seite', ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_page' => '999' ) ) ), $page2 );
	ec_check( 'per_page-Attribut', count( ec_ids( ec_render( '[eventcrawler_events per_page="5"]' ) ) ), 5 );
	ec_check( 'per_page begrenzt auf 100', count( ec_ids( ec_render( '[eventcrawler_events per_page="100000"]' ) ) ), 31 );

	echo "Filter und Suche\n";
	ec_check( 'Stadtfilter', ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_city' => 'Bayreuth' ) ) ), array( $b ) );
	ec_check( 'Stadtfilter unbekannte Stadt', ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_city' => 'Atlantis' ) ) ), array() );
	ec_check( 'Stadt-Auswahl enthält Städte', false !== strpos( $html, '<option value="Hof">Hof</option>' ), true );
	ec_check( 'Suche im Titel', ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_search' => 'konzert' ) ) ), array( $a ) );
	ec_check( 'Suche in der Adresse', ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_search' => 'Hauptstraße' ) ) ), array( $a ) );
	ec_check( 'Suche mit SQL-Sonderzeichen', ec_ids( ec_render( '[eventcrawler_events]', array( 'ec_search' => "%' OR 1=1 -- " ) ) ), array() );
	ec_check( 'Suchbegriff escaped im Formular', false === strpos( ec_render( '[eventcrawler_events]', array( 'ec_search' => '"><script>x</script>' ) ), '<script>x' ), true );
	ec_check( 'ab Datum', ec_ids( ec_render( '[eventcrawler_events per_page="100"]', array( 'date' => $d5 ) ) ), array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE start_date = %s ORDER BY start_time, title, id", $d5 ) ) ) );
	ec_check( 'ungültiges Datum → ab heute', count( ec_ids( ec_render( '[eventcrawler_events per_page="100"]', array( 'date' => '2026-02-30' ) ) ) ), 31 );
	ec_check( 'Quelle manual', in_array( $b, ec_ids( ec_render( '[eventcrawler_events sources="manual" per_page="100"]' ) ), true ), false );
	ec_check( 'Quelle coburg', ec_ids( ec_render( '[eventcrawler_events sources="coburg"]' ) ), array( $b ) );
	ec_check( 'unbekannte Quelle → nichts statt alles', ec_ids( ec_render( '[eventcrawler_events sources="gibtsnicht"]' ) ), array() );
	ec_check( 'feste Stadt per Attribut', ec_ids( ec_render( '[eventcrawler_events city="Bayreuth"]' ) ), array( $b ) );

	echo "Tagesansicht\n";
	$html = ec_render( '[eventcrawler_events view="day"]', array( 'date' => $d2 ) );
	ec_check( 'nur Termine des Tages', ec_ids( $html ), array( $d, $e, $f, $g ) );
	ec_check( 'Vor/Zurück-Links', false !== strpos( $html, 'date=' . $d1 ) && false !== strpos( $html, 'date=' . $day( '+3' ) ), true );
	ec_check( 'Tag ohne Termine', false !== strpos( ec_render( '[eventcrawler_events view="day"]', array( 'date' => $day( '+3' ) ) ), 'Für diesen Tag wurden keine Veranstaltungen gefunden.' ), true );
	ec_check( 'Tag bleibt im Filterformular erhalten', false !== strpos( $html, 'type="hidden" name="date" value="' . $d2 . '"' ), true );
	ec_check( 'vergangener Tag abrufbar', ec_ids( ec_render( '[eventcrawler_events view="day"]', array( 'date' => $day( '-1' ) ) ) ), array( $c ) );
	ec_check( 'Suche in Tagesansicht sucht alle kommenden Tage', ec_ids( ec_render( '[eventcrawler_events view="day"]', array( 'ec_search' => 'Markt' ) ) ), array( $b ) );

	echo "Kalender\n";
	$month = substr( $d5, 0, 7 );
	$cal   = ec_render( '[eventcrawler_calendar]', array( 'ec_month' => $month ) );
	ec_check( 'Monatstitel', false !== strpos( $cal, Eventcrawler_Exporter::MONTHS[ (int) substr( $month, 5, 2 ) ] . ' ' . substr( $month, 0, 4 ) ), true );
	ec_check( 'Tag mit Terminen verlinkt', false !== strpos( $cal, 'date=' . $d5 ), true );
	ec_check( 'Anzahl am Tag', false !== strpos( $cal, '<span class="eventcrawler-calendar__count" aria-hidden="true">25</span>' ), true );
	ec_check( '+n weitere', false !== strpos( $cal, '+22 weitere' ), true );
	ec_check( 'Titel verlinkt auf Anker', 1 === preg_match( '/date=' . preg_quote( $d5, '/' ) . '#eventcrawler-event-\d+/', $cal ), true );
	ec_check( 'Vor/Zurück-Monat', false !== strpos( $cal, 'ec_month=' ), true );
	ec_check( 'ungültiger Monat → aktueller Monat', false !== strpos( ec_render( '[eventcrawler_calendar]', array( 'ec_month' => '2026-13' ) ), Eventcrawler_Exporter::MONTHS[ (int) current_time( 'n' ) ] ), true );
	ec_check( 'link-Attribut wird genutzt', false !== strpos( ec_render( '[eventcrawler_calendar link="https://example.org/liste/"]', array( 'ec_month' => $month ) ), 'https://example.org/liste/?date=' . $d5 ), true );
	ec_check( 'link-Attribut javascript: ignoriert', false === stripos( ec_render( '[eventcrawler_calendar link="javascript:alert(1)"]', array( 'ec_month' => $month ) ), 'javascript:' ), true );
	$grid = ec_render( '[eventcrawler_calendar month="2" year="2027"]' );
	ec_check( 'Raster Februar 2027: 4 Wochen, 28 Tage', array( substr_count( $grid, '<tr>' ) - 1, substr_count( $grid, 'eventcrawler-calendar__number' ) ), array( 4, 28 ) );
	$grid = ec_render( '[eventcrawler_calendar month="11" year="2026"]' );
	ec_check( 'Nachbarmonate grau ohne Link (Nov. 2026: 6 + 6 Tage)', substr_count( $grid, 'is-outside' ), 12 );
	ec_check( 'Raster mit Beschriftung und Wochentagen', false !== strpos( $grid, '<caption class="eventcrawler-sr-only">Veranstaltungen im November 2026</caption>' ) && false !== strpos( $grid, '<abbr title="Montag">Mo</abbr>' ), true );
	ec_check( 'Monatspfeile mit aria-label', false !== strpos( $grid, 'aria-label="Vorheriger Monat: Oktober 2026"' ) && false !== strpos( $grid, 'aria-label="Nächster Monat: Dezember 2026"' ), true );
	$raster = substr( $cal, 0, (int) strpos( $cal, 'class="eventcrawler-calendar__agenda"' ) );
	ec_check( 'Raster: 3 Titel am vollen Tag', substr_count( $raster, 'date=' . $d5 . '#eventcrawler-event-' ), 3 );
	ec_check( 'Kalendertitel ungekürzt', false !== strpos( $raster, '<span class="eventcrawler-calendar__event-title">Serie 01</span>' ), true );
	ec_check( '+n weitere mit vollständigem aria-label', false !== strpos( $cal, 'aria-label="Alle 25 Veranstaltungen am ' . Eventcrawler_Frontend::format_day( $d5 ) . ' anzeigen"' ), true );
	ec_check( 'Agenda für Smartphone vorhanden', false !== strpos( $cal, 'eventcrawler-calendar__agenda-days' ) && 2 === substr_count( $cal, '+22 weitere' ), true );
	ec_check( 'Agenda: leerer Monat', false !== strpos( ec_render( '[eventcrawler_calendar month="2" year="2027"]' ), 'Im Februar 2027 sind keine Veranstaltungen eingetragen.' ), true );
	$sel = ec_render( '[eventcrawler_calendar]', array( 'ec_month' => $month, 'date' => $d5 ) );
	ec_check( 'ausgewählter Tag markiert (Raster und Agenda)', substr_count( $sel, 'is-selected' ), 2 );
	ec_check( 'ausgewählter Tag im aria-label', false !== strpos( $sel, Eventcrawler_Frontend::format_day( $d5 ) . ( $d5 === $today ? ', heute' : '' ) . ', ausgewählt: 25 Veranstaltungen' ), true );
	$now = ec_render( '[eventcrawler_calendar]' );
	ec_check( 'heute: aria-current="date"', 1, substr_count( $now, 'aria-current="date"' ) );
	ec_check( 'Kalender: Überschrift Veranstaltungskalender', false !== strpos( $now, '<h2 class="eventcrawler-section-title">Veranstaltungskalender</h2>' ), true );
	ec_check( 'Kalender: title="" ohne Überschrift', false === strpos( ec_render( '[eventcrawler_calendar title=""]' ), 'eventcrawler-section-title' ), true );

	echo "Darstellung\n";
	$html = ec_render( '[eventcrawler_events per_page="100"]' );
	$card = function ( $id ) use ( $html ) {
		return preg_match( '#<li class="eventcrawler-event" id="eventcrawler-event-' . $id . '">.*?</li>\s*(?=<li class="eventcrawler-event"|</ul>)#s', $html, $m ) ? $m[0] : '';
	};
	ec_check( 'Überschrift Veranstaltungen', false !== strpos( $html, '<h2 class="eventcrawler-section-title">Veranstaltungen</h2>' ), true );
	ec_check( 'title="" ohne Überschrift', false === strpos( ec_render( '[eventcrawler_events title=""]' ), 'eventcrawler-section-title' ), true );
	ec_check( 'Titel als Überschrift', false !== strpos( $card( $a ), '<h4 class="eventcrawler-event__title">Konzert A</h4>' ), true );
	ec_check( 'Uhrzeit mit <time datetime>', false !== strpos( $card( $a ), '<time datetime="' . $d1 . 'T18:30">18:30–22:00 Uhr</time>' ), true );
	ec_check( 'ohne Uhrzeit kein leeres Zeitfeld', false === strpos( $card( $b ), 'eventcrawler-event__time' ) && '' !== $card( $b ), true );
	ec_check( 'ohne Uhrzeit kein "Uhrzeit"-Feld in Details', false === strpos( $card( $b ), '<dt>Uhrzeit</dt>' ), true );
	ec_check( 'ganztägig nur bei all_day', array( false !== strpos( $card( $d ), 'ganztägig' ), false !== strpos( $card( $b ), 'ganztägig' ) ), array( true, false ) );
	ec_check( 'ohne Ort/Adresse nur Stadt, keine leeren Trenner', false === strpos( $card( $b ), 'eventcrawler-event__venue' ) && false !== strpos( $card( $b ), '<p class="eventcrawler-event__address">Bayreuth</p>' ) && false === strpos( $card( $b ), ', <' ), true );
	ec_check( 'Details anzeigen/schließen', false !== strpos( $card( $a ), 'Details anzeigen' ) && false !== strpos( $card( $a ), 'Details schließen' ) && false !== strpos( $card( $a ), '<details class="eventcrawler-event__more">' ), true );
	ec_check( 'Link "Zur Veranstaltungsseite" neues Fenster, noopener', 1 === preg_match( '#<a class="eventcrawler-button eventcrawler-button--secondary" href="https://example\.org/a" target="_blank" rel="noopener noreferrer">Zur Veranstaltungsseite#', $card( $a ) ), true );
	ec_check( 'ohne sicheren Link kein Linkbereich', false === strpos( $card( $f ), 'eventcrawler-event__links' ), true );
	ec_check( 'ohne Beschreibung kein Beschreibungsblock', false === strpos( $card( $d ), 'eventcrawler-event__description' ), true );
	ec_check( 'Quelle nicht öffentlich', false === strpos( $card( $b ), 'coburg' ) && false === stripos( $html, 'source' ), true );
	ec_check( 'Filter: Labels, Platzhalter, Buttons', false !== strpos( $html, '>Suche</label>' ) && false !== strpos( $html, '>Stadt</label>' ) && false !== strpos( $html, 'placeholder="Titel, Ort oder Stadt …"' ) && false !== strpos( $html, '>Anzeigen</button>' ) && false !== strpos( $html, '>Zurücksetzen</a>' ), true );
	ec_check( 'Monatsübersicht deutsch', false !== strpos( $html, 'Monatsübersicht</summary>' ), true );
	$english = array( 'Event-Calendar', 'Event-List', '>Search', '>City', '>Reset', 'Previous', 'Next day', 'Month overview', 'more<', 'Details<' );
	$found   = array();
	foreach ( array( $html, ec_render( '[eventcrawler_events view="day"]', array( 'date' => $d2 ) ), $cal ) as $out ) {
		foreach ( $english as $word ) {
			if ( false !== stripos( $out, $word ) ) {
				$found[] = $word;
			}
		}
	}
	ec_check( 'keine englischen UI-Texte', $found, array() );
	$found = ec_render( '[eventcrawler_events]', array( 'ec_search' => 'Serie' ) );
	ec_check( 'Suchergebnis: "n Veranstaltungen gefunden"', false !== strpos( $found, '25 Veranstaltungen gefunden' ), true );
	$none = ec_render( '[eventcrawler_events]', array( 'ec_search' => 'gibtsnichtxyz' ) );
	ec_check( 'leere Suche: Hinweis und Filter zurücksetzen', false !== strpos( $none, 'Für Ihre Suche wurden keine Veranstaltungen gefunden.' ) && false !== strpos( $none, 'Filter zurücksetzen</a>' ), true );
	$pages = ec_render( '[eventcrawler_events per_page="5"]', array( 'ec_page' => '3' ) );
	ec_check( 'Pagination: aktuelle Seite aria-current', false !== strpos( $pages, 'aria-current="page"><span class="eventcrawler-sr-only">Seite </span>3</span>' ), true );
	ec_check( 'Pagination: 1 2 3 4 … 7 mit Zurück/Weiter', array( preg_match_all( '/eventcrawler-pagination__page[ "]/', $pages ), substr_count( $pages, 'eventcrawler-pagination__gap' ), false !== strpos( $pages, 'Zurück</a>' ) && false !== strpos( $pages, 'Weiter' ) ), array( 5, 1, true ) );
	ec_check( 'Pagination: "Seite 3 von 7" für schmale Bildschirme', false !== strpos( $pages, 'Seite 3 von 7' ), true );
	$day_html = ec_render( '[eventcrawler_events view="day"]', array( 'date' => $d2 ) );
	ec_check( 'Tagesnavigation: Datum als Überschrift, Vor/Zurück', false !== strpos( $day_html, '<h3 class="eventcrawler-day-nav__current">' . Eventcrawler_Frontend::format_day( $d2 ) . '</h3>' ) && false !== strpos( $day_html, 'Vorheriger Tag</a>' ) && false !== strpos( $day_html, 'Nächster Tag<svg' ), true );
	$two = ec_render( '[eventcrawler_events][eventcrawler_events]' );
	ec_check( 'Kalender über der Liste: keine zweite Monatsübersicht', array( substr_count( ec_render( '[eventcrawler_calendar][eventcrawler_events view="day"]', array( 'ec_month' => $month ) ), 'class="eventcrawler-calendar"' ), substr_count( ec_render( '[eventcrawler_events view="day"]', array( 'ec_month' => $month ) ), 'class="eventcrawler-calendar"' ) ), array( 1, 1 ) );
	preg_match_all( '/id="(eventcrawler-search[^"]*)"/', $two, $two_ids );
	ec_check( 'zwei Listen: eindeutige Feld-IDs', array( count( $two_ids[1] ), count( array_unique( $two_ids[1] ) ) ), array( 2, 2 ) );
	$css = (string) file_get_contents( EVENTCRAWLER_PATH . 'assets/eventcrawler-frontend.css' );
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	preg_match_all( '#(?:^|})\s*([^{}@]+?)\s*\{#', preg_replace( '#@media[^{]+\{#', '}', $css ), $sel_m );
	$global = array();
	foreach ( $sel_m[1] as $group ) {
		foreach ( explode( ',', $group ) as $selector ) {
			if ( 0 !== strpos( trim( $selector ), '.eventcrawler' ) ) {
				$global[] = trim( $selector );
			}
		}
	}
	ec_check( 'CSS: alle Selektoren unter .eventcrawler', array_values( array_diff( $global, array( '' ) ) ), array() );
	ec_check( 'CSS: !important nur bei reduzierter Bewegung', substr_count( $css, '!important' ), 1 );

	echo "WPEM-Aliase\n";
	$wpem_active = post_type_exists( 'wpem_event' ) && class_exists( 'WPEM_Frontend' );
	ec_check( 'Alias nur ohne WPEM registriert', $GLOBALS['shortcode_tags']['events_list'] === array( 'Eventcrawler_Frontend', 'shortcode_wpem_events_list' ), ! $wpem_active );
	$alias = Eventcrawler_Frontend::shortcode_wpem_events_list( array( 'date' => $d2 ) );
	ec_check( 'Alias events_list = Tagesansicht', ec_ids( $alias ), array( $d, $e, $f, $g ) );
	ec_check( 'Alias events_calendar = Kalender', false !== strpos( Eventcrawler_Frontend::shortcode_wpem_events_calendar( array( 'month' => (int) substr( $month, 5, 2 ), 'year' => (int) substr( $month, 0, 4 ) ) ), 'date=' . $d5 ), true );

	echo "Redakteur\n";
	wp_set_current_user( 1 );
	$html = ec_render( '[eventcrawler_events]' );
	ec_check( 'Bearbeiten-Link für manuelle Termine', false !== strpos( $html, 'page=eventcrawler-event&#038;id=' . $a ), true );
	ec_check( 'kein Bearbeiten-Link für gecrawlte', false === strpos( $html, 'page=eventcrawler-event&#038;id=' . $b ), true );
	wp_set_current_user( 0 );

	echo "Manuelle Termine: Normalisierung\n";
	$base = array(
		'title'      => 'Neu',
		'start_date' => $d1,
		'city'       => 'Kulmbach',
	);
	$ok   = Eventcrawler_Manual_Events::prepare( $base );
	ec_check( 'Minimal gültig', array( $ok['errors'], $ok['event']['source'], $ok['event']['start_time'], $ok['event']['end_date'], $ok['event']['venue_name'], $ok['event']['description'], $ok['event']['event_url'], $ok['event']['source_url'] ), array( array(), 'manual', null, null, null, null, null, null ) );
	ec_check( 'Titel fehlt', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'title' => ' ' ) ) )['errors'], array( 'Titel fehlt.' ) );
	ec_check( 'Stadt fehlt', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'city' => '' ) ) )['errors'], array( 'Stadt fehlt.' ) );
	ec_check( 'Datum fehlt', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_date' => '' ) ) )['errors'], array( 'Beginn – Datum fehlt.' ) );
	ec_check( 'Datum ungültig', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_date' => '2026-02-30' ) ) )['errors'], array( 'Beginn – Datum ist ungültig.' ) );
	ec_check( 'Uhrzeit ungültig', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_time' => '25:00' ) ) )['errors'], array( 'Beginn – Uhrzeit ist ungültig (HH:MM).' ) );
	ec_check( 'Uhrzeit HH:MM → HH:MM:00', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_time' => '09:05' ) ) )['event']['start_time'], '09:05:00' );
	ec_check( 'Endzeit ohne Enddatum → gleicher Tag', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_time' => '18:00', 'end_time' => '20:00' ) ) )['event']['end_date'], $d1 );
	ec_check( 'Ende vor Beginn: Formular-Fehler', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_time' => '20:00', 'end_time' => '18:00' ) ) )['errors'], array( 'Das Ende liegt nicht nach dem Beginn.' ) );
	$lax = Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'start_time' => '20:00', 'end_time' => '18:00' ) ), false );
	ec_check( 'Ende vor Beginn: Übernahme verwirft Ende', array( $lax['event']['end_date'], $lax['event']['end_time'], count( $lax['warnings'] ) ), array( null, null, 1 ) );
	$allday = Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'all_day' => 1, 'start_time' => '10:00', 'end_time' => '12:00' ) ) )['event'];
	ec_check( 'ganztägig: Uhrzeiten NULL', array( $allday['all_day'], $allday['start_time'], $allday['end_time'] ), array( 1, null, null ) );
	ec_check( 'Link ohne Schema: http:// ergänzt (wie esc_url_raw in WordPress/WPEM)', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'event_url' => 'www.example.org' ) ) )['event']['event_url'], 'http://www.example.org' );
	ec_check( 'Link mailto: Fehler', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'event_url' => 'mailto:info@example.org' ) ) )['errors'], array( 'Webseiten-Link muss mit http:// oder https:// beginnen.' ) );
	ec_check( 'Link javascript: Fehler', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'event_url' => 'javascript:alert(1)' ) ) )['errors'], array( 'Webseiten-Link muss mit http:// oder https:// beginnen.' ) );
	ec_check( 'Link https gespeichert', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'event_url' => 'https://example.org/x?a=1' ) ) )['event']['event_url'], 'https://example.org/x?a=1' );
	ec_check( 'HTML im Titel entfernt', Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'title' => '<b>Fett</b> Titel' ) ) )['event']['title'], 'Fett Titel' );
	ec_check( 'Hash = Crawler-Formel', $ok['event']['content_hash'], Eventcrawler_Crawler::content_hash( $ok['event'] ) );

	echo "Manuelle Termine: Speichern\n";
	$repo   = new Eventcrawler_Repository();
	$new_id = Eventcrawler_Manual_Events::save( $repo, $ok['event'] );
	ec_check( 'Anlegen', is_int( $new_id ) && $new_id > 0, true );
	$saved = $repo->get_by_id( $new_id );
	ec_check( 'gespeichert: NULL statt leer, keine 00:00', array( $saved['start_time'], $saved['venue_name'], $saved['source_url'] ), array( null, null, null ) );
	ec_check( 'gespeicherter Hash = neu berechnet', $saved['content_hash'], Eventcrawler_Crawler::content_hash( $saved ) );
	$wpdb->update( $table, array( 'external_id' => 'wpem-999999' ), array( 'id' => $new_id ) );
	$upd = Eventcrawler_Manual_Events::prepare( array_merge( $base, array( 'title' => 'Geändert' ) ) );
	ec_check( 'Bearbeiten', Eventcrawler_Manual_Events::save( $repo, $upd['event'], $new_id ), $new_id );
	$saved = $repo->get_by_id( $new_id );
	ec_check( 'Bearbeiten: Titel neu, Herkunft bleibt', array( $saved['title'], $saved['external_id'] ), array( 'Geändert', 'wpem-999999' ) );
	$err = Eventcrawler_Manual_Events::save( $repo, $upd['event'], $b );
	ec_check( 'gecrawlter Termin nicht bearbeitbar', is_wp_error( $err ) ? $err->get_error_code() : 'kein Fehler', 'eventcrawler_not_manual' );
	ec_check( 'gecrawlter Termin unverändert', $repo->get_by_id( $b )['title'], 'Markt B' );
	ec_check( 'Quellenname "Manuell"', Eventcrawler_Crawler::get_source_name( 'manual' ), 'Manuell' );
	ec_check( 'Quelle manual nicht im Crawler-Register', isset( Eventcrawler_Crawler::get_sources()['manual'] ), false );

	echo "Manuelle Termine im XML\n";
	$xml = ( new Eventcrawler_Exporter( $repo ) )->build_xml( array( 'source' => 'manual' ) );
	ec_check( 'Export manuell wohlgeformt', is_string( $xml ) && false !== strpos( $xml, '<p>18:30 Uhr Konzert A, Eigene Beschreibung A, Stadthalle, Hauptstraße 1. Info: example.org</p>' ), true );
	ec_check( 'Export: ohne source_url kein falsches Info', false !== strpos( $xml, '<p>Geändert</p>' ), true );

	echo "WPEM-Übernahme\n";
	if ( ! post_type_exists( 'wpem_event' ) ) {
		register_post_type( 'wpem_event', array( 'public' => false ) );
	}
	if ( ! taxonomy_exists( 'wpem_venue' ) ) {
		register_taxonomy( 'wpem_venue', 'wpem_event' );
	}
	$venue = function ( $name, $city, $address = '' ) {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), 'wpem_venue' );
		$wpdb_term = (int) $term['term_id'];
		wp_update_term( $wpdb_term, 'wpem_venue', array( 'name' => $name ) );
		update_term_meta( $wpdb_term, 'wpem_venue_city', $city );
		update_term_meta( $wpdb_term, 'wpem_venue_address', $address );
		return $wpdb_term;
	};
	$wpem = function ( $title, array $meta, $venue_id = 0, $status = 'publish', $content = '' ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'wpem_event',
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, '_wpem_' . $key, $value );
		}
		if ( $venue_id ) {
			wp_set_object_terms( $id, array( $venue_id ), 'wpem_venue' );
		}
		return $id;
	};
	$german = function ( $ymd ) {
		return substr( $ymd, 8, 2 ) . '.' . substr( $ymd, 5, 2 ) . '.' . substr( $ymd, 0, 4 );
	};
	$v_hall = $venue( 'Kurhaus', 'Bad Steben', "Badstraße 1\n95138 Bad Steben" );
	$v_town = $venue( 'Limmersdorf', '' );
	$p1     = $wpem( 'Kurkonzert &amp; Tanz', array( 'start_date' => $german( $d1 ), 'start_time' => '19:30', 'end_time' => '21:00', 'all_day' => '0', 'website_url' => 'https://www.bad-steben.de/kurkonzert' ), $v_hall, 'publish', '<p>Mit <strong>Orchester</strong></p>' );
	$p2     = $wpem( '14.30 Uhr Lindenkerwa', array( 'start_date' => $german( $d1 ), 'start_time' => '', 'all_day' => '1' ), $v_town );
	$p3     = $wpem( 'Ohne Ort', array( 'start_date' => $german( $d1 ), 'start_time' => '10:00' ) );
	$p4     = $wpem( 'Entwurf', array( 'start_date' => $german( $d1 ) ), $v_hall, 'draft' );
	$p5     = $wpem( 'Falsches Datum', array( 'start_date' => '31.02.2026' ), $v_hall );
	$p6     = $wpem( 'Gleiche URL wie A', array( 'start_date' => $german( $d1 ), 'website_url' => 'HTTPS://EXAMPLE.org/a#info' ), $v_hall );
	$p7     = $wpem( 'markt  b', array( 'start_date' => $german( $d1 ) ), $venue( 'Bayreuth', 'Bayreuth' ) );
	$p8     = $wpem( 'Gleiche URL, anderer Tag', array( 'start_date' => $german( $d2 ), 'website_url' => 'https://example.org/a' ), $v_hall );
	$mine   = array( $p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8 );

	$posts_sum = function () use ( $wpdb ) {
		return array( $wpdb->get_row( "CHECKSUM TABLE {$wpdb->posts}", ARRAY_A )['Checksum'], $wpdb->get_row( "CHECKSUM TABLE {$wpdb->postmeta}", ARRAY_A )['Checksum'], $wpdb->get_row( "CHECKSUM TABLE {$wpdb->termmeta}", ARRAY_A )['Checksum'] );
	};
	$migration = new Eventcrawler_Wpem_Migration( $repo );
	$status    = function () use ( $migration, $mine ) {
		$out = array();
		foreach ( $migration->analyze()['items'] as $item ) {
			if ( in_array( $item['post_id'], $mine, true ) ) {
				$out[ $item['post_id'] ] = $item;
			}
		}
		return $out;
	};
	$items = $status();
	ec_check(
		'Probelauf: Status je Beitrag',
		array_map(
			function ( $item ) {
				return $item['status'];
			},
			$items
		),
		array(
			$p1 => 'new',
			$p2 => 'new',
			$p3 => 'invalid',
			$p4 => 'not_published',
			$p5 => 'invalid',
			$p6 => 'duplicate',
			$p7 => 'duplicate',
			$p8 => 'new',
		)
	);
	$e1 = $items[ $p1 ]['event'];
	ec_check( 'Mapping Datum/Zeit/Ende', array( $e1['start_date'], $e1['start_time'], $e1['end_date'], $e1['end_time'], $e1['all_day'] ), array( $d1, '19:30:00', $d1, '21:00:00', 0 ) );
	ec_check( 'Mapping Titel (Entities), Beschreibung (ohne HTML)', array( $e1['title'], $e1['description'] ), array( 'Kurkonzert & Tanz', 'Mit Orchester' ) );
	ec_check( 'Mapping Ort, Adresse (Zeilen → Komma), Stadt, Link', array( $e1['venue_name'], $e1['venue_address'], $e1['city'], $e1['event_url'], $e1['source_url'], $e1['external_id'] ), array( 'Kurhaus', 'Badstraße 1, 95138 Bad Steben', 'Bad Steben', 'https://www.bad-steben.de/kurkonzert', null, 'wpem-' . $p1 ) );
	$e2 = $items[ $p2 ]['event'];
	ec_check( 'Ort ohne Stadt → Ortsname als Stadt, kein doppelter Ort', array( $e2['city'], $e2['venue_name'], $e2['all_day'], $e2['start_time'] ), array( 'Limmersdorf', null, 1, null ) );
	ec_check( 'ohne Ort → ungültig', $items[ $p3 ]['reason'], 'Stadt fehlt.' );
	ec_check( 'ungültiges Datum → ungültig', $items[ $p5 ]['reason'], 'Beginn – Datum ist ungültig.' );
	ec_check( 'Dublette über URL (Groß/Klein, #Fragment)', array( $items[ $p6 ]['match_id'], $items[ $p6 ]['reason'] ), array( $a, 'gleiches Datum und gleicher Webseiten-Link' ) );
	ec_check( 'Dublette über Titel + Stadt (Leerzeichen, Groß/Klein)', array( $items[ $p7 ]['match_id'], $items[ $p7 ]['reason'] ), array( $b, 'gleiches Datum, gleicher Titel und gleiche Stadt' ) );
	ec_check( 'gleiche URL an anderem Tag ist keine Dublette', $items[ $p8 ]['status'], 'new' );

	$wpem_before = $posts_sum();
	$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$run1        = $migration->run();
	ec_check( 'Übernahme: 0 fehlgeschlagen', $run1['failed'], 0 );
	ec_check( 'Übernahme: genau die neuen Termine (inkl. Testtermine anderer Läufe)', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) - $count_before, $run1['inserted'] );
	$items = $status();
	ec_check( 'nach Übernahme: Status migrated', array( $items[ $p1 ]['status'], $items[ $p2 ]['status'], $items[ $p8 ]['status'] ), array( 'migrated', 'migrated', 'migrated' ) );
	$migrated_row = $repo->get_by_id( $items[ $p1 ]['match_id'] );
	ec_check( 'übernommener Termin: source manual, Hash korrekt', array( $migrated_row['source'], $migrated_row['content_hash'] ), array( 'manual', Eventcrawler_Crawler::content_hash( $migrated_row ) ) );
	$run2 = $migration->run();
	ec_check( 'zweiter Lauf legt nichts an (idempotent)', array( $run2['inserted'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) - $count_before ), array( 0, $run1['inserted'] ) );
	ec_check( 'WPEM-Daten unverändert', $posts_sum(), $wpem_before );
	ec_check( 'übernommener Termin im Frontend', in_array( (int) $items[ $p1 ]['match_id'], ec_ids( ec_render( '[eventcrawler_events per_page="100"]', array( 'date' => $d1 ) ) ), true ), true );
	$tags = ec_render( '[eventcrawler_events per_page="100"]', array( 'date' => $d1 ) );
	ec_check( 'Beschreibung übernommener Termin sichtbar', false !== strpos( $tags, 'Mit Orchester' ), true );
} catch ( Throwable $e ) {
	$failed++;
	echo '  FEHLER Ausnahme: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n";
}

$wpdb->query( 'ROLLBACK' );
wp_cache_flush();
ec_check( 'Veranstaltungstabelle nach Rollback unverändert (CHECKSUM TABLE)', $checksum(), $before );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
