<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests des CSV-/XML-Imports (docs/11-import.md).
 *
 * Alle Datenbankänderungen laufen in einer Transaktion mit Rollback; die Optionen der Importquellen werden danach
 * wiederhergestellt und die Prüfsumme der Veranstaltungstabelle vorher/nachher verglichen.
 *
 * Aufruf: php tools/test-import.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

require __DIR__ . '/lib-test.php';
ec_bootstrap( 'test-import.php' );

global $wpdb;
$table    = Eventcrawler_Installer::table_name();
$checksum = function () use ( $wpdb, $table ) {
	return $wpdb->get_row( "CHECKSUM TABLE {$table}", ARRAY_A )['Checksum']; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$options_before = array(
	Eventcrawler_Importer::SOURCES_OPTION     => get_option( Eventcrawler_Importer::SOURCES_OPTION, null ),
	Eventcrawler_Importer::LAST_IMPORT_OPTION => get_option( Eventcrawler_Importer::LAST_IMPORT_OPTION, null ),
);
$table_before = $checksum();
$source       = 'ec-test-import';
$importer     = new Eventcrawler_Importer( new Eventcrawler_Repository() );

/**
 * CSV-Text → Zeilen mit Vorschlags-Mapping.
 *
 * @param string $csv CSV.
 * @return array rows, errors, parsed.
 */
function ec_csv_rows( $csv ) {
	$read   = Eventcrawler_Importer::read_content( $csv, 'test.csv' );
	$parsed = Eventcrawler_Importer::parse_csv( $read['content'] );
	return array(
		'rows'   => Eventcrawler_Importer::map_rows( $parsed['rows'], Eventcrawler_Importer::suggest_mapping( $parsed['header'] ) ),
		'errors' => $parsed['errors'],
		'parsed' => $parsed,
	);
}

/**
 * @param array  $analysis Ergebnis analyze().
 * @param int    $line     Zeile.
 * @return string Alle Fehler einer Zeile.
 */
function ec_line_errors( array $analysis, $line ) {
	return isset( $analysis['errors'][ $line ] ) ? implode( '; ', $analysis['errors'][ $line ] ) : '';
}

$wpdb->query( 'START TRANSACTION' );
try {
	echo "Datei prüfen\n";
	ec_check( 'Leere Datei abgelehnt', Eventcrawler_Importer::read_content( '', 'a.csv' )->get_error_code(), 'eventcrawler_import_empty' );
	ec_check( 'Nur Leerzeichen/BOM = leer', Eventcrawler_Importer::read_content( "\xEF\xBB\xBF \n ", 'a.csv' )->get_error_code(), 'eventcrawler_import_empty' );
	ec_check( 'Endung .exe abgelehnt', Eventcrawler_Importer::read_content( 'a;b', 'a.exe' )->get_error_code(), 'eventcrawler_import_type' );
	ec_check( 'Endung .xlsx abgelehnt', Eventcrawler_Importer::read_content( 'a;b', 'a.xlsx' )->get_error_code(), 'eventcrawler_import_type' );
	ec_check( 'ZIP/Excel-Inhalt mit .csv abgelehnt', Eventcrawler_Importer::read_content( "PK\x03\x04xyz", 'a.csv' )->get_error_code(), 'eventcrawler_import_type' );
	ec_check( 'Nicht-UTF-8 (Windows-1252) abgelehnt', Eventcrawler_Importer::read_content( "Titel;Stadt\nCaf\xE9;Hof", 'a.csv' )->get_error_code(), 'eventcrawler_import_encoding' );
	ec_check( 'Zu große Datei abgelehnt', Eventcrawler_Importer::read_content( str_repeat( 'a', Eventcrawler_Importer::MAX_BYTES + 1 ), 'a.csv' )->get_error_code(), 'eventcrawler_import_size' );
	ec_check( 'Größenmeldung verständlich', false !== strpos( Eventcrawler_Importer::size_message(), 'zu groß' ), true );
	ec_check( '.xml ohne XML-Inhalt abgelehnt', Eventcrawler_Importer::read_content( 'Titel;Stadt', 'a.xml' )->get_error_code(), 'eventcrawler_import_type' );
	$bom = Eventcrawler_Importer::read_content( "\xEF\xBB\xBFTitel;Datum;Stadt\nA;01.12.2026;Hof", 'a.csv' );
	ec_check( 'BOM entfernt, Format CSV', array( $bom['format'], substr( $bom['content'], 0, 5 ) ), array( 'csv', 'Titel' ) );
	ec_check( 'XML erkannt (auch mit .txt)', Eventcrawler_Importer::read_content( '<?xml version="1.0"?><events/>', 'a.txt' )['format'], 'xml' );
	$tmp = wp_tempnam( 'ec-import' );
	file_put_contents( $tmp, "Titel;Datum;Stadt\nA;01.12.2026;Hof" );
	ec_check( 'read_upload: Upload-Fehler INI_SIZE → Größenmeldung', Eventcrawler_Importer::read_upload( array( 'error' => UPLOAD_ERR_INI_SIZE ) )->get_error_code(), 'eventcrawler_import_size' );
	ec_check( 'read_upload: keine Datei', Eventcrawler_Importer::read_upload( array( 'error' => UPLOAD_ERR_NO_FILE ) )->get_error_code(), 'eventcrawler_import_nofile' );
	ec_check( 'read_upload: nicht hochgeladene Datei abgelehnt (is_uploaded_file)', Eventcrawler_Importer::read_upload( array( 'error' => 0, 'tmp_name' => $tmp, 'name' => 'a.csv' ) )->get_error_code(), 'eventcrawler_import_upload' );
	ec_check( 'read_upload: Datei lesen (ohne Upload-Prüfung, nur Test)', Eventcrawler_Importer::read_upload( array( 'error' => 0, 'tmp_name' => $tmp, 'name' => '../../a.csv', 'type' => 'application/x-php' ), false )['name'], 'a.csv' );
	unlink( $tmp );

	echo "CSV: Trennzeichen\n";
	$semi = Eventcrawler_Importer::parse_csv( "Titel;Datum;Stadt\n\"A, B\";01.12.2026;Hof\nC;02.12.2026;Kulmbach" );
	ec_check( 'Semikolon erkannt (Komma im Feld)', $semi['delimiter'], 'semicolon' );
	$comma = Eventcrawler_Importer::parse_csv( "Titel,Datum,Stadt\n\"A; B\",01.12.2026,Hof" );
	ec_check( 'Komma erkannt', $comma['delimiter'], 'comma' );
	$tab = Eventcrawler_Importer::parse_csv( "Titel\tDatum\tStadt\nA\t01.12.2026\tHof" );
	ec_check( 'Tabulator erkannt', $tab['delimiter'], 'tab' );
	$amb = Eventcrawler_Importer::parse_csv( "a;b,c\n1;2,3" );
	ec_check( 'Mehrdeutig: kein Trennzeichen, Kandidaten ; und ,', array( $amb['delimiter'], $amb['candidates'] ), array( null, array( 'semicolon', 'comma' ) ) );
	$bad = Eventcrawler_Importer::parse_csv( "Titel;Datum;Stadt\nA;01.12.2026\nB;1;2;3" );
	ec_check( 'Uneinheitlich: nicht erkannt (Admin wählt)', $bad['delimiter'], null );
	$forced = Eventcrawler_Importer::parse_csv( "Titel;Datum;Stadt\nA;01.12.2026\nB;02.12.2026;Hof", 'semicolon' );
	ec_check( 'Vorgegeben: falsche Spaltenzahl als Zeilenfehler (Zeile 2)', array( count( $forced['rows'] ), array_keys( $forced['errors'] ) ), array( 1, array( 2 ) ) );
	$multi = Eventcrawler_Importer::parse_csv( "Titel;Beschreibung;Datum;Stadt\nA;\"Zeile 1\nZeile 2\";01.12.2026;Hof\nB;x;02.12.2026;Hof" );
	ec_check( 'Zeilenumbruch im Feld: Tabellenzeilen 2 und 3 (wie in Excel)', wp_list_pluck( $multi['rows'], 'line' ), array( 2, 3 ) );
	ec_check( 'Leere Zeilen übersprungen', count( Eventcrawler_Importer::parse_csv( "Titel;Datum;Stadt\n\n;;\nA;01.12.2026;Hof\n" )['rows'] ), 1 );
	ec_check( 'Nur Kopfzeile = Fehler', Eventcrawler_Importer::parse_csv( "Titel;Datum;Stadt\n", 'semicolon' )->get_error_code(), 'eventcrawler_import_empty' );
	ec_check( 'Zu viele Zeilen = Fehler', Eventcrawler_Importer::parse_csv( "T;D\n" . str_repeat( "a;b\n", Eventcrawler_Importer::MAX_ROWS + 1 ), 'semicolon' )->get_error_code(), 'eventcrawler_import_rows' );

	echo "CSV: Zuordnung\n";
	$header = array( 'Titel', 'Datum', 'Beginn', 'Ende', 'Ort', 'Adresse', 'Stadt', 'URL', 'Veranstaltungsdatum', 'Titel' );
	ec_check(
		'Vorschläge nur exakt bekannt, Feld nur einmal',
		Eventcrawler_Importer::suggest_mapping( $header ),
		array( 'title', 'start_date', 'start_time', 'end_time', 'venue_name', 'venue_address', 'city', 'event_url', '', '' )
	);
	ec_check( 'Groß/klein und Leerzeichen egal', Eventcrawler_Importer::suggest_mapping( array( ' STADT ' ) ), array( 'city' ) );
	ec_check( 'Keine Teilstring-Vorschläge („Startzeit Uhr“)', Eventcrawler_Importer::suggest_mapping( array( 'Startzeit Uhr', 'Stadtteil' ) ), array( '', '' ) );
	ec_check( 'Pflichtfelder fehlen → 2 Meldungen', count( Eventcrawler_Importer::validate_mapping( array( 'title' ), array( 'T' ) ) ), 2 );
	ec_check( 'Feld doppelt zugeordnet → Meldung', count( Eventcrawler_Importer::validate_mapping( array( 'title', 'title', 'start_date', 'city' ), array( 'A', 'B', 'C', 'D' ) ) ), 1 );
	ec_check( 'Gültige Zuordnung', Eventcrawler_Importer::validate_mapping( array( 'title', 'start_date', 'city', '' ), array( 'A', 'B', 'C', 'D' ) ), array() );

	echo "Zeilen prüfen\n";
	$ok = Eventcrawler_Importer::prepare_row( array( 'title' => 'Konzert', 'start_date' => '15.11.2026', 'start_time' => '20.00 Uhr', 'end_time' => '22:00', 'city' => 'Bayreuth', 'venue_name' => 'DAS ZENTRUM', 'venue_address' => 'Äußere Badstraße 7a' ), $source );
	ec_check( 'Deutsches Datum, Uhrzeit „20.00 Uhr“, Endzeit = gleicher Tag', array( $ok['event']['start_date'], $ok['event']['start_time'], $ok['event']['end_date'], $ok['event']['end_time'] ), array( '2026-11-15', '20:00:00', '2026-11-15', '22:00:00' ) );
	ec_check( 'UTF-8 unverändert (Äußere Badstraße)', $ok['event']['venue_address'], 'Äußere Badstraße 7a' );
	ec_check( 'source = Importquelle, source_url NULL', array( $ok['event']['source'], $ok['event']['source_url'] ), array( $source, null ) );
	ec_check( 'event_key mit Importquelle berechnet', $ok['event']['event_key'], Eventcrawler_Crawler::event_key( $ok['event'] ) );
	ec_check( 'ISO-Datum', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'city' => 'Hof' ), $source )['event']['start_date'], '2026-11-15' );
	ec_check( 'Titel fehlt', Eventcrawler_Importer::prepare_row( array( 'start_date' => '2026-11-15', 'city' => 'Hof' ), $source )['errors'], array( 'Titel fehlt' ) );
	ec_check( 'Startdatum fehlt', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'city' => 'Hof' ), $source )['errors'], array( 'Startdatum fehlt' ) );
	ec_check( 'Stadt fehlt', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15' ), $source )['errors'], array( 'Stadt fehlt' ) );
	ec_check( 'Ungültiges Datum „32.10.2026“', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '32.10.2026', 'city' => 'Hof' ), $source )['errors'], array( 'Ungültiges Datum „32.10.2026“' ) );
	ec_check( 'Ungültiges Datum 30.02.2026', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '30.02.2026', 'city' => 'Hof' ), $source )['errors'], array( 'Ungültiges Datum „30.02.2026“' ) );
	ec_check( 'Kein Raten: „15/11/2026“ ungültig', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '15/11/2026', 'city' => 'Hof' ), $source )['errors'], array( 'Ungültiges Datum „15/11/2026“' ) );
	ec_check( 'Ungültige Uhrzeit 25:00', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'start_time' => '25:00', 'city' => 'Hof' ), $source )['errors'], array( 'Ungültige Uhrzeit „25:00“' ) );
	ec_check( 'Ungültige Endzeit', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'end_time' => 'abends', 'city' => 'Hof' ), $source )['errors'], array( 'Ungültige Endzeit „abends“' ) );
	$null = Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'start_time' => 'NULL', 'venue_name' => 'null', 'description' => 'NULL', 'city' => 'Hof' ), $source );
	ec_check( '„NULL“ = leer → NULL in der DB, keine 00:00', array( $null['event']['start_time'], $null['event']['venue_name'], $null['event']['description'] ), array( null, null, null ) );
	ec_check( 'Ohne Uhrzeit: start_time NULL (nie 00:00)', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'city' => 'Hof' ), $source )['event']['start_time'], null );
	$allday = Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'start_time' => '10:00', 'all_day' => 'ja', 'city' => 'Hof' ), $source );
	ec_check( 'all_day „ja“ → 1, Uhrzeit verworfen', array( $allday['event']['all_day'], $allday['event']['start_time'] ), array( 1, null ) );
	ec_check( 'all_day „0“ → 0', Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'all_day' => '0', 'city' => 'Hof' ), $source )['event']['all_day'], 0 );
	ec_check( 'all_day „vielleicht“ → Fehler', count( Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'all_day' => 'vielleicht', 'city' => 'Hof' ), $source )['errors'] ), 1 );
	$end = Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'start_time' => '22:00', 'end_time' => '01:00', 'city' => 'Hof' ), $source );
	ec_check( 'Ende vor Beginn: Ende verworfen + Hinweis (wie Crawler)', array( $end['event']['end_time'], count( $end['warnings'] ) ), array( null, 1 ) );
	ec_check( 'Mehrere Fehler einer Zeile gesammelt', count( Eventcrawler_Importer::prepare_row( array( 'start_date' => '32.13.2026', 'start_time' => 'x' ), $source )['errors'] ), 4 );

	echo "URLs und Text bereinigen\n";
	$js = Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'city' => 'Hof', 'event_url' => 'javascript:alert(1)', 'source_url' => 'ftp://example.org/x' ), $source );
	ec_check( 'javascript:-Link verworfen', $js['event']['event_url'], null );
	ec_check( 'ftp:-Herkunfts-URL verworfen', $js['event']['source_url'], null );
	ec_check( 'Zwei Hinweise', count( $js['warnings'] ), 2 );
	$url = Eventcrawler_Importer::prepare_row( array( 'title' => 'A', 'start_date' => '2026-11-15', 'city' => 'Hof', 'event_url' => 'https://example.org/a b?x=<1>', 'source_url' => 'https://example.org/' ), $source );
	ec_check( 'https-Link bereinigt (esc_url_raw)', $url['event']['event_url'], 'https://example.org/a%20b?x=1' );
	ec_check( 'https-Herkunfts-URL übernommen, im content_hash', array( $url['event']['source_url'], $url['event']['content_hash'] === Eventcrawler_Crawler::content_hash( $url['event'] ) ), array( 'https://example.org/', true ) );
	$xss = Eventcrawler_Importer::prepare_row( array( 'title' => '<script>alert(1)</script>Konzert <b>live</b>', 'start_date' => '2026-11-15', 'city' => 'Hof' ), $source );
	ec_check( 'HTML/Skript aus dem Titel entfernt', $xss['event']['title'], 'Konzert live' );
	ec_check( 'Nur Skript im Titel → Titel fehlt', Eventcrawler_Importer::prepare_row( array( 'title' => '<script>alert(1)</script>', 'start_date' => '2026-11-15', 'city' => 'Hof' ), $source )['errors'], array( 'Titel fehlt' ) );
	ec_check( 'CSV-Formel bleibt Text (wird nicht ausgeführt)', Eventcrawler_Importer::prepare_row( array( 'title' => '=HYPERLINK("x")', 'start_date' => '2026-11-15', 'city' => 'Hof' ), $source )['event']['title'], '=HYPERLINK("x")' );

	echo "XML\n";
	$xml_ok = '<?xml version="1.0" encoding="UTF-8"?>
<events source="kulturamt-import" generated_at="2026-11-03T10:00:00Z">
  <event>
    <external_id>x-1</external_id>
    <title>Beispielveranstaltung</title>
    <description></description>
    <start_date>2026-11-15</start_date>
    <start_time>20:00</start_time>
    <end_date>2026-11-15</end_date>
    <end_time>22:00</end_time>
    <all_day>0</all_day>
    <venue_name>DAS ZENTRUM</venue_name>
    <venue_address>Äußere Badstraße 7a</venue_address>
    <city>Bayreuth</city>
    <event_url>https://example.org/event</event_url>
    <source_url>https://example.org/</source_url>
    <price>12 €</price>
  </event>
  <event><title><![CDATA[Café & Bar <live>]]></title><start_date>16.11.2026</start_date><city>Hof</city></event>
  <event><title>A</title><title>B</title><start_date>2026-11-17</start_date><city>Hof</city></event>
</events>';
	$x = Eventcrawler_Importer::parse_xml( $xml_ok );
	ec_check( 'XML gültig: 2 Zeilen, 1 Fehler (doppeltes Element)', array( count( $x['rows'] ), count( $x['errors'] ) ), array( 2, 1 ) );
	ec_check( 'source/generated_at gelesen', array( $x['source'], $x['generated_at'] ), array( 'kulturamt-import', '2026-11-03T10:00:00Z' ) );
	ec_check( 'Unbekanntes Element gemeldet', $x['unknown'], array( 'price' ) );
	ec_check( 'Zeilennummer des <event>', $x['rows'][0]['line'], 3 );
	ec_check( 'CDATA und &amp; korrekt', $x['rows'][1]['values']['title'], 'Café & Bar <live>' );
	$p = Eventcrawler_Importer::prepare_row( $x['rows'][0]['values'], $source );
	ec_check( 'XML-Zeile vollständig normalisiert', array( $p['event']['external_id'], $p['event']['start_time'], $p['event']['end_time'], $p['event']['venue_address'], $p['event']['event_url'], $p['event']['source_url'] ), array( 'x-1', '20:00:00', '22:00:00', 'Äußere Badstraße 7a', 'https://example.org/event', 'https://example.org/' ) );
	ec_check( 'Ungültiges XML: verständliche Meldung mit Zeile', 1 === preg_match( '/^Die XML-Datei ist nicht gültig \(Zeile \d+/', Eventcrawler_Importer::parse_xml( '<events><event><title>x</event></events>' )->get_error_message() ), true );
	ec_check( 'Falsches Wurzelelement', Eventcrawler_Importer::parse_xml( '<Root><Textabschnitt/></Root>' )->get_error_code(), 'eventcrawler_import_xml_root' );
	ec_check( 'Keine <event>-Einträge', Eventcrawler_Importer::parse_xml( '<events></events>' )->get_error_code(), 'eventcrawler_import_empty' );
	$secret = wp_tempnam( 'ec-xxe' );
	file_put_contents( $secret, 'GEHEIM-XXE' );
	$xxe = '<?xml version="1.0"?><!DOCTYPE events [<!ENTITY xxe SYSTEM "file:///' . str_replace( '\\', '/', $secret ) . '">]><events><event><title>&xxe;</title><start_date>2026-11-15</start_date><city>Hof</city></event></events>';
	$r   = Eventcrawler_Importer::parse_xml( $xxe );
	ec_check( 'XXE (externe Entity): abgelehnt', is_wp_error( $r ) ? $r->get_error_code() : 'angenommen', 'eventcrawler_import_xml_dtd' );
	ec_check( 'XXE: Dateiinhalt nirgends gelesen', false !== strpos( wp_json_encode( $r ), 'GEHEIM' ), false );
	unlink( $secret );
	ec_check( 'Billion Laughs (interne Entities): abgelehnt', Eventcrawler_Importer::parse_xml( '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><events><event><title>&b;</title></event></events>' )->get_error_code(), 'eventcrawler_import_xml_dtd' );
	ec_check( 'Netz-DTD (Kleinschreibung): abgelehnt', Eventcrawler_Importer::parse_xml( '<?xml version="1.0"?><!doctype events SYSTEM "http://127.0.0.1:9/x.dtd"><events/>' )->get_error_code(), 'eventcrawler_import_xml_dtd' );
	ec_check( 'Undefinierte Entity ohne DTD: ungültig', Eventcrawler_Importer::parse_xml( '<events><event><title>&xxe;</title></event></events>' )->get_error_code(), 'eventcrawler_import_xml' );

	echo "Importquellen\n";
	ec_check( 'manual gesperrt', '' !== Eventcrawler_Importer::validate_source( 'manual' ), true );
	ec_check( 'dev-test gesperrt', '' !== Eventcrawler_Importer::validate_source( 'dev-test' ), true );
	ec_check( 'Crawler-Slug gesperrt (coburg)', '' !== Eventcrawler_Importer::validate_source( 'coburg' ), true );
	ec_check( 'Ungültige Zeichen gesperrt', '' !== Eventcrawler_Importer::validate_source( 'Kultur Amt' ), true );
	ec_check( 'manual-import erlaubt', Eventcrawler_Importer::validate_source( 'manual-import' ), '' );
	ec_check( 'Kennung aus Bezeichnung', Eventcrawler_Importer::slug_from_label( 'Kulturamt Kulmbach – Übersicht' ), 'kulturamt-kulmbach-uebersicht' );

	echo "Probelauf, Import, Idempotenz\n";
	$manual_before = $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash ORDER BY id)) FROM {$table} WHERE source = %s", 'manual' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$others_before = $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash, last_seen_at ORDER BY id)) FROM {$table} WHERE source <> %s", $source ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$csv1 = "\xEF\xBB\xBFexternal_id;Titel;Datum;Beginn;Ende;Ort;Adresse;Stadt;URL;Ganztägig\n"
		. "1;Konzert A;15.11.2026;20:00;22:00;Stadthalle;Hauptstr. 1;Hof;https://example.org/a;0\n"
		. "2;Lesung B;16.11.2026;;;Bücherei;;Kulmbach;;0\n"
		. "3;Markt C;17.11.2026;;;;;Bayreuth;;ja\n"
		. "4;;18.11.2026;;;;;Hof;;0\n"
		. "5;Fehler D;32.10.2026;;;;;Hof;;0\n"
		. "6;Fehler E;19.11.2026;25:00;;;;Hof;;0\n"
		. "7;Doppelt F;20.11.2026;;;;;Hof;;0\n"
		. "7;Doppelt G;21.11.2026;;;;;Hof;;0\n";
	$c1 = ec_csv_rows( $csv1 );
	ec_check( 'Mapping-Vorschläge der Testdatei', Eventcrawler_Importer::suggest_mapping( $c1['parsed']['header'] ), array( 'external_id', 'title', 'start_date', 'start_time', 'end_time', 'venue_name', 'venue_address', 'city', 'event_url', 'all_day' ) );
	$a1 = $importer->analyze( $c1['rows'], $source );
	ec_check( 'Probelauf 1: gelesen/neu/aktualisiert/unverändert/fehlerhaft', $a1['counts'], array( 'read' => 8, 'new' => 3, 'update' => 0, 'unchanged' => 0, 'invalid' => 5 ) );
	ec_check( 'Zeile 5: Titel fehlt', ec_line_errors( $a1, 5 ), 'Titel fehlt' );
	ec_check( 'Zeile 6: Ungültiges Datum', ec_line_errors( $a1, 6 ), 'Ungültiges Datum „32.10.2026“' );
	ec_check( 'Zeile 7: Ungültige Uhrzeit', ec_line_errors( $a1, 7 ), 'Ungültige Uhrzeit „25:00“' );
	ec_check( 'Zeilen 8/9: doppelte external_id', ec_line_errors( $a1, 8 ) . ' | ' . ec_line_errors( $a1, 9 ), 'Externe ID „7“ kommt mehrfach in der Datei vor (Zeilen 8, 9) | Externe ID „7“ kommt mehrfach in der Datei vor (Zeilen 8, 9)' );
	ec_check( 'Probelauf schreibt nichts', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", $source ) ), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$r1 = $importer->run( $c1['rows'], $source, 'Testimport', array(), 'test.csv' );
	ec_check( 'Import 1: 3 eingefügt, 0 aktualisiert, 0 Fehler beim Speichern', array( $r1['counts']['inserted'], $r1['counts']['updated'], $r1['counts']['failed'] ), array( 3, 0, 0 ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s ORDER BY external_id", $source ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Gespeichert: 3 Termine der Importquelle', wp_list_pluck( $rows, 'external_id' ), array( '1', '2', '3' ) );
	ec_check( 'Konzert A korrekt', array( $rows[0]['title'], $rows[0]['start_date'], $rows[0]['start_time'], $rows[0]['end_time'], $rows[0]['venue_address'], $rows[0]['event_url'] ), array( 'Konzert A', '2026-11-15', '20:00:00', '22:00:00', 'Hauptstr. 1', 'https://example.org/a' ) );
	ec_check( 'Lesung B ohne Uhrzeit = NULL', $rows[1]['start_time'], null );
	ec_check( 'Markt C ganztägig', (int) $rows[2]['all_day'], 1 );
	ec_check( 'Hash = Formel des Crawlers', $rows[0]['content_hash'], Eventcrawler_Crawler::content_hash( $rows[0] ) );
	ec_check( 'Importquelle registriert', Eventcrawler_Importer::import_sources()[ $source ]['label'], 'Testimport' );
	ec_check( 'Anzeigename in Liste/Filter', array( Eventcrawler_Importer::source_name( $source ), isset( Eventcrawler_Admin::filter_source_options()[ $source ] ) ), array( 'Testimport', true ) );
	ec_check( 'Frontend-Attribut sources kennt die Importquelle', Eventcrawler_Frontend::parse_sources( $source ), array( $source ) );
	ec_check( 'Letzter Import gespeichert', get_option( Eventcrawler_Importer::LAST_IMPORT_OPTION )['file'], 'test.csv' );

	$stamp = $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash, updated_at, last_seen_at ORDER BY id)) FROM {$table} WHERE source = %s", $source ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$r2    = $importer->run( $c1['rows'], $source, 'Testimport' );
	ec_check( 'Import 2 (gleiche Datei): 0 neu, 0 aktualisiert, 3 unverändert', array( $r2['counts']['inserted'], $r2['counts']['updated'], $r2['counts']['unchanged'] ), array( 0, 0, 3 ) );
	ec_check( 'Unverändert: kein Schreiben (Zeitstempel gleich)', $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash, updated_at, last_seen_at ORDER BY id)) FROM {$table} WHERE source = %s", $source ) ), $stamp ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$csv2 = "external_id,Titel,Datum,Beginn,Stadt\n"
		. "1,Konzert A (verlegt),15.11.2026,19:30,Hof\n"
		. "3,Markt C,17.11.2026,,Bayreuth\n"
		. "8,Neu H,22.11.2026,,Hof\n";
	$c2 = ec_csv_rows( $csv2 );
	ec_check( 'Datei 2 mit Komma erkannt', $c2['parsed']['delimiter'], 'comma' );
	$a2 = $importer->analyze( $c2['rows'], $source );
	ec_check( 'Probelauf 2: 1 neu, 2 Aktualisierungen', array( $a2['counts']['new'], $a2['counts']['update'], $a2['counts']['unchanged'] ), array( 1, 2, 0 ) );
	$id_a = (int) $rows[0]['id'];
	$importer->run( $c2['rows'], $source, 'Testimport' );
	$a_after = ( new Eventcrawler_Repository() )->get_by_id( $id_a );
	ec_check( 'Update: gleicher Datensatz (ID), neuer Titel/Uhrzeit', array( (int) $a_after['id'], $a_after['title'], $a_after['start_time'] ), array( $id_a, 'Konzert A (verlegt)', '19:30:00' ) );
	ec_check( 'Update: Felder ohne Wert jetzt NULL (Ende, Ort, Link)', array( $a_after['end_time'], $a_after['venue_name'], $a_after['event_url'] ), array( null, null, null ) );
	ec_check( 'Fehlender Termin (Lesung B) NICHT gelöscht', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND external_id = %s", $source, '2' ) ), 1 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Gesamt jetzt 4 Termine', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", $source ) ), 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$r3 = $importer->run( $c2['rows'], $source, 'Testimport' );
	ec_check( 'Mehrfacher Import: dritte Runde nur unverändert', array( $r3['counts']['inserted'], $r3['counts']['updated'], $r3['counts']['unchanged'] ), array( 0, 0, 3 ) );

	echo "Matching ohne external_id (event_key)\n";
	$src2 = 'ec-test-import-noid';
	$csv3 = "Titel;Datum;Beginn;Stadt\nTheater;10.12.2026;18:00;Selb\nTheater;10.12.2026;20:00;Selb\nKino;11.12.2026;;Hof\n";
	$c3   = ec_csv_rows( $csv3 );
	$r    = $importer->run( $c3['rows'], $src2, 'Ohne IDs' );
	ec_check( 'Ohne ID: 3 neu (zwei Vorstellungen am selben Tag getrennt)', $r['counts']['inserted'], 3 );
	$r = $importer->run( $c3['rows'], $src2, 'Ohne IDs' );
	ec_check( 'Ohne ID, gleiche Datei: 3 unverändert, keine Duplikate', array( $r['counts']['inserted'], $r['counts']['unchanged'] ), array( 0, 3 ) );
	$c4 = ec_csv_rows( "Titel;Datum;Beginn;Stadt\nTheater;10.12.2026;18:30;Selb\nTheater;10.12.2026;20:00;Selb\nKino;11.12.2026;;Hof\n" );
	$r  = $importer->run( $c4['rows'], $src2, 'Ohne IDs' );
	ec_check( 'Ohne ID, geänderte Uhrzeit: 1 Update, kein neuer Termin', array( $r['counts']['inserted'], $r['counts']['updated'], $r['counts']['unchanged'] ), array( 0, 1, 2 ) );
	$c5 = ec_csv_rows( "Titel;Datum;Stadt\nTheater (neu);10.12.2026;Selb\n" );
	ec_check( 'Ohne ID, anderer Titel: neuer Termin (kein unscharfer Abgleich)', $importer->analyze( $c5['rows'], $src2 )['counts']['new'], 1 );
	ec_check( 'Andere Importquelle beeinflusst Matching nicht', $importer->analyze( $c3['rows'], 'ec-test-import-other' )['counts']['new'], 3 );

	echo "Keine Nebenwirkungen\n";
	ec_check( 'Manuelle Termine unverändert', $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash ORDER BY id)) FROM {$table} WHERE source = %s", 'manual' ) ), $manual_before ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Crawler- und andere Termine unverändert', $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash, last_seen_at ORDER BY id)) FROM {$table} WHERE source NOT IN (%s, %s)", $source, $src2 ) ), $others_before ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Keine WordPress-Posts angelegt (kein wpem_event)', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title IN ('Konzert A', 'Lesung B', 'Theater')" ), 0 );
	$mx = ( new Eventcrawler_Exporter( new Eventcrawler_Repository() ) )->build_xml( array( 'source' => $source, 'month' => 0, 'year' => 0 ) );
	ec_check( 'Importierte Termine im XML-Export (gleiches Format)', 0 === strpos( $mx, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Root>' ) && false !== strpos( $mx, 'Konzert A (verlegt)' ), true );

	echo "Admin-Handler\n";
	$token = 'AbCdEfGhIjKlMnOpQrSt';
	$state = array( 'name' => 'h.csv', 'format' => 'csv', 'content' => "Titel;Datum;Stadt\nHandlertest;05.12.2026;Hof\n" );
	wp_set_current_user( 0 );
	ec_check( 'Upload als Gast: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_upload' ) )['status'], 403 );
	ec_user( 'editor' );
	ec_check( 'Upload als Redakteur: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_upload' ), array( 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_upload' ) ) )['status'], 403 );
	$admin = ec_user( 'administrator' );
	ec_check( 'Upload per GET: 405', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_upload' ), array(), 'GET' )['status'], 405 );
	ec_check( 'Upload ohne Nonce: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_upload' ) )['status'], 403 );
	ec_check( 'Upload mit Nonce von „Import durchführen“: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_upload' ), array( 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_run' ) ) )['status'], 403 );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'handle_upload' ), array( 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_upload' ) ) );
	ec_check( 'Upload ohne Datei: zurück mit Meldung', array( $r['kind'], get_transient( 'eventcrawler_import_notice_' . $admin )[1] ), array( 'redirect', 'Bitte eine Datei auswählen.' ) );
	set_transient( 'eventcrawler_import_' . $admin . '_' . md5( $token ), $state, HOUR_IN_SECONDS );
	ec_user( 'editor' );
	ec_check( 'Zuordnung als Redakteur: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_map' ), array( 'token' => $token, 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_map' ) ) )['status'], 403 );
	ec_user( 'administrator' );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'handle_map' ), array( 'token' => $token, 'delimiter' => 'semicolon', 'mapping' => array( 'title', 'start_date', '' ), 'source_choice' => '__new', 'source_label' => 'Handler', 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_map' ) ) );
	ec_check( 'Zuordnung ohne Stadt: zurück zur Zuordnung mit Meldung', array( false !== strpos( $r['target'], 'step=map' ), false !== strpos( get_transient( 'eventcrawler_import_notice_' . $admin )[1], 'Stadt' ) ), array( true, true ) );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'handle_map' ), array( 'token' => $token, 'delimiter' => 'semicolon', 'mapping' => array( 'title', 'start_date', 'city' ), 'source_choice' => '__new', 'source_label' => 'Handler', 'source_slug' => 'manual', 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_map' ) ) );
	ec_check( 'Kennung „manual“ abgelehnt', false !== strpos( get_transient( 'eventcrawler_import_notice_' . $admin )[1], 'reserviert' ), true );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'handle_map' ), array( 'token' => $token, 'delimiter' => 'semicolon', 'mapping' => array( 'title', 'start_date', 'city' ), 'source_choice' => '__new', 'source_label' => 'Handler Test', 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_map' ) ) );
	ec_check( 'Gültige Zuordnung → Probelauf', false !== strpos( $r['target'], 'step=preview' ), true );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'render_page' ), array(), 'GET', array( 'page' => 'eventcrawler-import', 'step' => 'preview', 'token' => $token ) );
	ec_check( 'Vorschau zeigt Zählung und „Import durchführen“', false !== strpos( $r['output'], 'Zeilen gelesen' ) && false !== strpos( $r['output'], 'Import durchführen' ) && false !== strpos( $r['output'], 'Handlertest' ), true );
	ec_check( 'Probelauf hat nichts geschrieben', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", 'handler-test' ) ), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Fremdes Token: Datei nicht gefunden', false !== strpos( ec_call( array( 'Eventcrawler_Import_Admin', 'render_page' ), array(), 'GET', array( 'step' => 'preview', 'token' => 'ZZZZZZZZZZZZZZZZZZZZ' ) )['output'], 'nicht mehr vorhanden' ), true );
	ec_check( 'Import ohne Nonce: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'handle_run' ), array( 'token' => $token ) )['status'], 403 );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'handle_run' ), array( 'token' => $token, 'eventcrawler_import_nonce' => wp_create_nonce( 'eventcrawler_import_run' ) ) );
	ec_check( 'Import durchgeführt → Ergebnis', array( false !== strpos( $r['target'], 'step=done' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", 'handler-test' ) ) ), array( true, 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Datei nach dem Import verworfen', get_transient( 'eventcrawler_import_' . $admin . '_' . md5( $token ) ), false );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'render_page' ), array(), 'GET', array( 'step' => 'done' ) );
	ec_check( 'Ergebnisseite verständlich', false !== strpos( $r['output'], 'Import abgeschlossen: 1 neu angelegt' ), true );
	$xss_state = array( 'name' => '<img src=x onerror=alert(1)>.csv', 'format' => 'csv', 'content' => "Titel;Datum;Stadt\n\"<script>alert(1)</script>\";32.01.2026;\"<b>Hof</b>\"\n" );
	set_transient( 'eventcrawler_import_' . $admin . '_' . md5( $token ), $xss_state, HOUR_IN_SECONDS );
	$r = ec_call( array( 'Eventcrawler_Import_Admin', 'render_page' ), array(), 'GET', array( 'step' => 'map', 'token' => $token ) );
	ec_check( 'Zuordnung: Dateiname und Beispielwerte escaped', false === strpos( $r['output'], '<img src=x' ) && false === strpos( $r['output'], '<script>alert' ) && false !== strpos( $r['output'], '&lt;script&gt;' ), true );
	ec_user( 'subscriber' );
	ec_check( 'Importseite als Abonnent: 403', ec_call( array( 'Eventcrawler_Import_Admin', 'render_page' ), array(), 'GET' )['status'], 403 );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	foreach ( $options_before as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, false );
		}
	}
}

ec_check( 'Tabelle nach Rollback unverändert (Prüfsumme)', $checksum(), $table_before );
ec_finish();
