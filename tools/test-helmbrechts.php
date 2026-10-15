<?php
/**
 * NUR FÜR ENTWICKLUNG: Parsertests des Adapters Helmbrechts (docs/04-quellen.md Abschnitt 12).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Arbeitet nur mit eingebetteten HTML-Ausschnitten im Aufbau der echten Seiten (TYPO3 news + calendarize,
 * Stand 2026-10-15): keine HTTP-Abrufe, keine Datenbankänderung.
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-helmbrechts.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-helmbrechts.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Source_Helmbrechts' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Quelle Helmbrechts) ist nicht aktiv.\n" );
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
 * @param string $slug  Slug.
 * @param string $date  "15.10.2026".
 * @param string $time  "Beginn: 19:30 Uhr" oder ''.
 * @param string $title Titel (HTML).
 * @return string
 */
function hb_entry( $slug, $date, $time, $title ) {
	return '<li><a href="/news-details-aktuell/' . $slug . '"><div class="caltitle">' . $title . '</div><span class="innerwrap">' . $date . '</span>' . ( '' === $time ? '' : '<span class="caltime innerwrap"> ' . $time . '</span>' ) . '</a></li>';
}

/**
 * Monatsansicht.
 *
 * @param array $days Tag => Einträge (HTML).
 * @return string
 */
function hb_month( array $days ) {
	$cells = '';
	foreach ( $days as $day => $entries ) {
		$cells .= '<td class="active hasEvents "> ' . $day . ' <ul class="events">' . implode( '', $entries ) . '</ul></td>';
	}
	return '<html><body><div class="calendarize"><table class="table"><tbody class="regularMonthView"><tr>' . $cells . '</tr></tbody></table></div></body></html>';
}

/**
 * Detailseite.
 *
 * @param string      $date    "15.10.2026".
 * @param string      $timing  z. B. "Beginn: 16:00 Uhr</span><span>Ende: 18:00 Uhr".
 * @param string|null $venue   Name oder null (kein Ortsblock).
 * @param string      $address Adresszeile.
 * @param string      $teaser  Teaser.
 * @return string
 */
function hb_detail( $date, $timing, $venue, $address, $teaser = '' ) {
	$loc = null === $venue ? '' : '<div class="row thelocation"><div class="col-12 locationtag"><div class="location" itemprop="description"><strong>Veranstaltungsort:</strong>' . $venue . '<br/>' . $address . '</div></div></div>';
	return '<html><body><div class="news-single"><div class="termindaten"><h4>Daten zur Veranstaltung</h4><p>Datum: <span>' . $date . '</span></p><p>Preis: kostenlos</p><p><span>' . $timing . '</span></p>' . $loc
		. '<div class="row thelocation"><div class="col-12 locationtag"><div class="location"><strong>Veranstalter:</strong>Verein<br/><i class="fa fa-phone"></i> 09252 1</div></div></div></div>'
		. '<h3>Beschreibung</h3><div class="teaser-text"><p>' . $teaser . '</p></div><div class="news-text-wrap"><p>Langtext mit Stammtisch und Einlass: 18.30 Uhr</p></div></div></body></html>';
}

$source = new Eventcrawler_Source_Helmbrechts();
$url    = function ( $slug ) {
	return 'https://stadt-helmbrechts.de/news-details-aktuell/' . $slug;
};
$run    = function ( array $months, array $details, $from = '2026-10-15' ) use ( $source ) {
	return $source->parse( $months, $from, $details );
};
$std    = hb_detail( '15.10.2026', 'Beginn: 19:30 Uhr', 'Bürgersaal Helmbrechts', 'Luitpoldstraße 21,' . "\n" . '95233 Helmbrechts', 'A-Cappella Comedy' );

echo "Registrierung\n";
$sources = Eventcrawler_Crawler::get_sources();
ec_check( 'Slug im Register', isset( $sources['helmbrechts'] ), true );
ec_check( 'Reihenfolge', array_keys( $sources ), array( 'burg-rabenstein', 'bad-steben', 'coburg', 'theater-selb', 'das-zentrum-bayreuth', 'schuetzenhaus-muenchberg', 'stadtsteinach', 'helmbrechts', 'kronach', 'bayreuth-tourismus' ) );
ec_check( 'Anzeigename', Eventcrawler_Crawler::get_source_name( 'helmbrechts' ), 'Helmbrechts' );

echo "Normaler Termin\n";
$e = $run( array( hb_month( array( 15 => array( hb_entry( 'kulturwelten-lalelu', '15.10.2026', 'Beginn: 19:30 Uhr', 'Kulturwelten: LaLeLu' ) ) ) ) ), array( $url( 'kulturwelten-lalelu' ) => $std ) )[0];
ec_check( 'kein Fehler', isset( $e['_error'] ), false );
ec_check( 'Titel', $e['title'], 'Kulturwelten: LaLeLu' );
ec_check( 'description = Teaser (nicht Langtext)', $e['description'], 'A-Cappella Comedy' );
ec_check( 'Datum / Beginn', array( $e['start_date'], $e['start_time'] ), array( '2026-10-15', '19:30' ) );
ec_check( 'kein Ende, kein Ganztag', array( $e['end_date'], $e['end_time'], $e['all_day'] ), array( null, null, false ) );
ec_check( 'Ort / Adresse / Stadt', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Bürgersaal Helmbrechts', 'Luitpoldstraße 21', 'Helmbrechts' ) );
ec_check( 'external_id = Slug_Datum_n', $e['external_id'], 'kulturwelten-lalelu_2026-10-15_1' );
ec_check( 'event_url = source_url = Detailseite', array( $e['event_url'], $e['source_url'] ), array( $url( 'kulturwelten-lalelu' ), $url( 'kulturwelten-lalelu' ) ) );
ec_check( 'keine Hilfsfelder', array_key_exists( '_slug', $e ), false );

echo "Ende\n";
$e = $run( array( hb_month( array( 18 => array( hb_entry( 'repair', '18.10.2026', 'Beginn: 11:00 Uhr', 'Repair-Café' ) ) ) ) ), array( $url( 'repair' ) => hb_detail( '18.10.2026', 'Beginn: 11:00 Uhr</span><span>Ende: 16:00 Uhr', 'Textilmuseum', 'Münchberger Straße 17, 95233 Helmbrechts' ) ) )[0];
ec_check( 'Ende gleicher Tag', array( $e['end_date'], $e['end_time'] ), array( '2026-10-18', '16:00' ) );
$r = $run(
	array( hb_month( array( 14 => array( hb_entry( 'kreisschau', '14.11.2026', 'Beginn: 08:00 Uhr', 'Kreisschau' ) ), 15 => array( hb_entry( 'kreisschau', '15.11.2026', 'Beginn: 08:00 Uhr', 'Kreisschau' ) ) ) ) ),
	array( $url( 'kreisschau' ) => hb_detail( '14.11.2026', 'Beginn: 08:00 Uhr</span><span>Ende:15.11.2026 15:00 Uhr', 'Stofffabrik', 'Gustav-Weiß-Straße 7 95233 Helmbrechts' ) )
);
ec_check( 'mehrtägig: je Tag ein Termin (wie Quelle), Ende nur am Tag des Detail-Datums', array( $r[0]['end_date'], $r[0]['end_time'], $r[1]['end_date'], $r[1]['end_time'] ), array( '2026-11-15', '15:00', null, null ) );
ec_check( 'Adresse ohne Komma', array( $r[0]['venue_address'], $r[0]['city'] ), array( 'Gustav-Weiß-Straße 7', 'Helmbrechts' ) );

echo "Mehrere Termine einer Veranstaltung\n";
$r = $run(
	array( hb_month( array(
		16 => array( hb_entry( 'fgh', '16.10.2026', 'Beginn: 19:30 Uhr', 'FGH-Theater: Jubel, Trubel, Eitelkeit' ) ),
		17 => array( hb_entry( 'fgh', '17.10.2026', 'Beginn: 19:00 Uhr', 'FGH-Theater: Jubel, Trubel, Eitelkeit' ) ),
		18 => array( hb_entry( 'fgh', '18.10.2026', 'Beginn: 16:00 Uhr', 'FGH-Theater: Jubel, Trubel, Eitelkeit' ), hb_entry( 'fgh', '18.10.2026', 'Beginn: 19:30 Uhr', 'FGH-Theater: Jubel, Trubel, Eitelkeit' ) ),
	) ) ),
	array( $url( 'fgh' ) => hb_detail( '16.10.2026', 'Beginn: 19:30 Uhr', 'Vereinsheim FGH', 'Fliegerweg 6, 95233 Helmbrechts', 'Turbulente Komödie' ) )
);
ec_check( 'je Termin eigene ID, zwei am selben Tag nummeriert', array_column( $r, 'external_id' ), array( 'fgh_2026-10-16_1', 'fgh_2026-10-17_1', 'fgh_2026-10-18_1', 'fgh_2026-10-18_2' ) );
ec_check( 'je Termin eigene Uhrzeit aus der Monatsansicht', array_column( $r, 'start_time' ), array( '19:30', '19:00', '16:00', '19:30' ) );
$r2 = $run( array( hb_month( array( 16 => array( hb_entry( 'fgh', '16.10.2026', 'Beginn: 19:30 Uhr', 'X' ) ) ) ), hb_month( array( 16 => array( hb_entry( 'fgh', '16.10.2026', 'Beginn: 19:30 Uhr', 'X' ) ) ) ) ), array( $url( 'fgh' ) => hb_detail( '16.10.2026', 'Beginn: 19:30 Uhr', 'V', 'Fliegerweg 6, 95233 Helmbrechts' ) ) );
ec_check( 'gleicher Termin in zwei Monatsansichten nur einmal', count( $r2 ), 1 );

echo "Zeiten / Datum, nicht raten\n";
$e = $run( array( hb_month( array( 16 => array( hb_entry( 'dorf', '16.10.2026', 'Beginn: 00:00 Uhr', 'Dorfmeisterschaft' ) ) ) ) ), array( $url( 'dorf' ) => hb_detail( '13.10.2026', 'Beginn: 08:00 Uhr', 'Schützenhaus Wüstenselbitz', 'Schützenstraße 22, 95233 Helmbrechts' ) ) )[0];
ec_check( '"Beginn: 00:00 Uhr" = keine Uhrzeit (NULL)', $e['start_time'], null );
ec_check( 'Beginn der Detailseite (anderer Tag) wird nicht übertragen', array( $e['start_time'], $e['end_time'] ), array( null, null ) );
$e = $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', '', 'A' ) ) ) ) ), array( $url( 'a' ) => $std ) )[0];
ec_check( 'ohne Zeitangabe -> NULL', array( $e['start_time'], isset( $e['_error'] ) ), array( null, false ) );
ec_check( 'Beginn mit Punkt', $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: 9.30 Uhr', 'A' ) ) ) ) ), array( $url( 'a' ) => $std ) )[0]['start_time'], '09:30' );
ec_check( 'Beginn unlesbar -> _error', ! empty( $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: abends', 'A' ) ) ) ) ), array( $url( 'a' ) => $std ) )[0]['_error'] ), true );
ec_check( 'Datum ungültig -> _error', ! empty( $run( array( hb_month( array( 31 => array( hb_entry( 'a', '31.11.2026', 'Beginn: 19:00 Uhr', 'A' ) ) ) ) ), array( $url( 'a' ) => $std ) )[0]['_error'] ), true );
$r = $run( array( hb_month( array( 12 => array( hb_entry( 'alt', '12.10.2026', 'Beginn: 19:00 Uhr', 'Vergangen' ) ), 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: 19:00 Uhr', 'A' ) ) ) ) ), array( $url( 'a' ) => $std ) );
ec_check( 'Tage vor dem Stichtag werden nicht übernommen', array_column( $r, 'title' ), array( 'A' ) );
ec_check( 'Einlass im Langtext wird nicht verwendet', $run( array( hb_month( array( 15 => array( hb_entry( 'a', '15.10.2026', 'Beginn: 19:30 Uhr', 'A' ) ) ) ) ), array( $url( 'a' ) => $std ) )[0]['start_time'], '19:30' );

echo "Ort / Stadt\n";
$e = $run( array( hb_month( array( 30 => array( hb_entry( 'wein', '30.10.2026', 'Beginn: 19:00 Uhr', 'Weinfest' ) ) ) ) ), array( $url( 'wein' ) => hb_detail( '30.10.2026', 'Beginn: 19:00 Uhr', 'Vereinsheim FC Ort', 'Am Kirchspitz 21 95233 Helmbrechts / Ort' ) ) )[0];
ec_check( 'Stadt ohne Ortsteil-Zusatz " / Ort"', array( $e['venue_name'], $e['venue_address'], $e['city'] ), array( 'Vereinsheim FC Ort', 'Am Kirchspitz 21', 'Helmbrechts' ) );
$e = $run( array( hb_month( array( 20 => array( hb_entry( 'aus', '20.10.2026', 'Beginn: 19:00 Uhr', 'Auswärts' ) ) ) ) ), array( $url( 'aus' ) => hb_detail( '20.10.2026', 'Beginn: 19:00 Uhr', 'Stadthalle', 'Kirchplatz 1, 95213 Münchberg' ) ) )[0];
ec_check( 'anderer Ort: Stadt aus der Quelle (nicht Helmbrechts)', $e['city'], 'Münchberg' );
$e = $run( array( hb_month( array( 25 => array( hb_entry( 'markt', '25.10.2026', 'Beginn: 13:00 Uhr', 'Herbstmarkt zur Kärwa' ) ) ) ) ), array( $url( 'markt' ) => hb_detail( '25.10.2026', 'Beginn: 13:00 Uhr</span><span>Ende: 18:00 Uhr', null, '' ) ) )[0];
ec_check( 'ohne Ortsblock -> _error, keine Stadt geraten', array( $e['_error'], $e['city'] ), array( 'Veranstaltungsort fehlt', '' ) );
$e = $run( array( hb_month( array( 25 => array( hb_entry( 'nur', '25.10.2026', 'Beginn: 13:00 Uhr', 'Nur Name' ) ) ) ) ), array( $url( 'nur' ) => hb_detail( '25.10.2026', 'Beginn: 13:00 Uhr', 'Marktplatz', '' ) ) )[0];
ec_check( 'Ort ohne Adresszeile -> _error', $e['_error'], 'Stadt fehlt (Ort: Marktplatz)' );

echo "Sonderzeichen\n";
$e = $run( array( hb_month( array( 17 => array( hb_entry( 'b', '17.10.2026', 'Beginn: 19:30 Uhr', 'Kulturwelten: Bluegrass Cash &amp; Kikki Géron – „Live“' ) ) ) ) ), array( $url( 'b' ) => hb_detail( '17.10.2026', 'Beginn: 19:30 Uhr', 'Bürgersaal Helmbrechts', 'Luitpoldstraße 21, 95233 Helmbrechts', 'Country &amp; Bluegrass – „Weiß“' ) ) )[0];
ec_check( 'Titel & é – „“', $e['title'], 'Kulturwelten: Bluegrass Cash & Kikki Géron – „Live“' );
ec_check( 'Teaser & – „“ ß', $e['description'], 'Country & Bluegrass – „Weiß“' );

echo "Fehlerfälle\n";
ec_check( 'Detailseite fehlt -> _error', $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: 19:00 Uhr', 'A' ) ) ) ) ), array() )[0]['_error'], 'Detailseite: nicht abgerufen' );
ec_check( 'Detailseite 404 -> _error', $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: 19:00 Uhr', 'A' ) ) ) ) ), array( $url( 'a' ) => new WP_Error( 'eventcrawler_http', 'Quelle antwortet mit HTTP 404.' ) ) )[0]['_error'], 'Detailseite: Quelle antwortet mit HTTP 404.' );
ec_check( 'Detailseite ohne Datenblock -> _error', $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: 19:00 Uhr', 'A' ) ) ) ) ), array( $url( 'a' ) => '<html><body>x</body></html>' ) )[0]['_error'], 'Detailseite: Block "Daten zur Veranstaltung" fehlt' );
ec_check( 'fremder Link -> _error', ! empty( $run( array( hb_month( array( 20 => array( '<li><a href="https://example.org/x"><div class="caltitle">A</div><span class="innerwrap">20.10.2026</span></a></li>' ) ) ) ), array() )[0]['_error'] ), true );
ec_check( 'Titel fehlt -> leer (Crawler zählt "fehlerhaft")', $run( array( hb_month( array( 20 => array( hb_entry( 'a', '20.10.2026', 'Beginn: 19:00 Uhr', '' ) ) ) ) ), array( $url( 'a' ) => $std ) )[0]['title'], '' );
$r = $source->parse_months( array( '<html><body><h1>Wartung</h1></body></html>' ), '2026-10-15' );
ec_check( 'keine Monatsansicht -> WP_Error', is_wp_error( $r ) ? $r->get_error_code() : 'kein Fehler', 'eventcrawler_structure' );

echo "Filter (zentral)\n";
$rules = Eventcrawler_Filter_Rules::get_active();
$e     = $run( array( hb_month( array( 20 => array( hb_entry( 'spd', '20.10.2026', 'Beginn: 19:00 Uhr', 'SPD Ortsverein Testevent' ) ) ) ) ), array( $url( 'spd' ) => $std ) )[0];
ec_check( 'Adapter liefert den Termin', isset( $e['_error'] ), false );
$rule = Eventcrawler_Filter::find_matching_rule( $e['title'] . ' ' . $e['description'], $rules );
ec_check( 'zentraler Filter greift', $rule ? $rule['category'] : null, 'politics' );
$rule = Eventcrawler_Filter::find_matching_rule( 'Bürgerversammlung 2026', $rules );
ec_check( 'echter Titel "Bürgerversammlung 2026" wird gefiltert', $rule ? $rule['term'] : null, 'bürgerversammlung' );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
