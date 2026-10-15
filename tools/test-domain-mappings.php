<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests der Link-Bezeichnungen (Domain -> Ausgabe im XML, docs/07-export.md Abschnitt 13)
 * und des Repository-Zugriffs für das Detail-Modal (docs/06-admin.md Abschnitt 11).
 *
 * Liegt bewusst außerhalb von wp-plugin/ und wird nicht mit dem Plugin ausgeliefert.
 * Die Option eventcrawler_export_domain_mappings wird vorher gesichert und danach wiederhergestellt (bzw. gelöscht,
 * falls sie vorher nicht existierte). Die Veranstaltungstabelle wird nur gelesen (Prüfsumme vorher/nachher).
 *
 * Aufruf (Plugin muss aktiviert sein):
 *   php tools/test-domain-mappings.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Aufruf: php test-domain-mappings.php <pfad/zu/wp-load.php>\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];

if ( ! class_exists( 'Eventcrawler_Domain_Mappings' ) ) {
	fwrite( STDERR, "Plugin Eventcrawler (mit Link-Bezeichnungen) ist nicht aktiv.\n" );
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
 * Fehlermeldung bzw. 'ok' einer Mapping-Operation.
 *
 * @param mixed $result Ergebnis.
 * @return string
 */
function ec_result( $result ) {
	return is_wp_error( $result ) ? $result->get_error_message() : 'ok';
}

/**
 * Sichtbare "Info:"-Angabe eines Testtermins mit den aktuell aktiven Link-Bezeichnungen.
 *
 * @param string $url event_url.
 * @return string
 */
function ec_info( $url ) {
	$line = Eventcrawler_Exporter::format_body(
		array(
			'title'      => 'Test',
			'city'       => 'Hof',
			'event_url'  => $url,
			'source_url' => $url,
		),
		Eventcrawler_Domain_Mappings::get_lookup()
	);
	return (string) substr( $line, strpos( $line, 'Info: ' ) + 6 );
}

/**
 * @param string $domain Domain.
 * @return string|null ID der Zuordnung.
 */
function ec_id( $domain ) {
	foreach ( Eventcrawler_Domain_Mappings::get_all() as $mapping ) {
		if ( $mapping['domain'] === $domain ) {
			return $mapping['id'];
		}
	}
	return null;
}

global $wpdb;
$table          = Eventcrawler_Installer::table_name();
$checksum       = function () use ( $wpdb, $table ) {
	return $wpdb->get_row( "CHECKSUM TABLE {$table}", ARRAY_N )[1]; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$checksum_start = $checksum();
$saved_option   = get_option( Eventcrawler_Domain_Mappings::OPTION );
delete_option( Eventcrawler_Domain_Mappings::OPTION );

$bs   = 'https://pages.et4.de/de/bad-steben/default/detail/Event/e_100123?x=1';
$cb   = 'https://veranstaltungen.coburg.de/details/stadtfuehrung-1';
$br   = 'https://www.burg-rabenstein.de/events/alle-events-im-ueberblick/feuriger-abend';
$none = 'https://www.example.de/event/1';

try {
	echo "Domain-Normalisierung\n";
	foreach ( array(
		'pages.et4.de'                           => 'pages.et4.de',
		'PAGES.ET4.DE'                           => 'pages.et4.de',
		'https://pages.et4.de'                   => 'pages.et4.de',
		'http://pages.et4.de/foo'                => 'pages.et4.de',
		'www.example.de/path'                    => 'www.example.de',
		'  https://Www.Example.DE/a/b?c=1#d  '   => 'www.example.de',
		'https://example.de:8443/x'              => 'example.de',
		'https://user:pw@example.de/x'           => '',
		'ftp://example.de'                       => '',
		'example.de.'                            => 'example.de',
		'//example.de/x'                         => 'example.de',
		''                                       => '',
		'   '                                    => '',
		'https://'                               => '',
		'foo bar.de'                             => '',
		'exa_mple.de'                            => '',
		'-example.de'                            => '',
		'<script>alert(1)</script>'              => '',
		'javascript:alert(1)'                    => '',
		'mailto:info@example.de'                 => '',
	) as $input => $expected ) {
		ec_check( 'normalize_domain(' . var_export( $input, true ) . ')', Eventcrawler_Domain_Mappings::normalize_domain( $input ), $expected );
	}

	echo "\nAnlegen, Validierung, Dubletten\n";
	ec_check( 'ohne Mapping: pages.et4.de', ec_info( $bs ), 'pages.et4.de' );
	ec_check( 'anlegen pages.et4.de -> Bad Steben', ec_result( Eventcrawler_Domain_Mappings::add( 'pages.et4.de', 'Bad Steben' ) ), 'ok' );
	ec_check( 'anlegen per https-URL mit Pfad (Coburg)', ec_result( Eventcrawler_Domain_Mappings::add( 'https://veranstaltungen.coburg.de/details/foo?bar=1', 'Coburg' ) ), 'ok' );
	ec_check( 'anlegen www.-Text als Ausgabe', ec_result( Eventcrawler_Domain_Mappings::add( 'www.burg-rabenstein.de', 'www.burg-rabenstein.de' ) ), 'ok' );
	ec_check( 'gespeichert nur Host (Coburg)', null !== ec_id( 'veranstaltungen.coburg.de' ), true );
	ec_check( 'Dublette PAGES.ET4.DE', ec_result( Eventcrawler_Domain_Mappings::add( 'PAGES.ET4.DE', 'Anders' ) ), 'Für die Domain „pages.et4.de“ gibt es bereits eine Link-Bezeichnung.' );
	ec_check( 'Dublette https://pages.et4.de/x', ec_result( Eventcrawler_Domain_Mappings::add( 'https://pages.et4.de/x', 'Anders' ) ), 'Für die Domain „pages.et4.de“ gibt es bereits eine Link-Bezeichnung.' );
	ec_check( 'leere Domain', ec_result( Eventcrawler_Domain_Mappings::add( '  ', 'X' ) ), 'Die Domain darf nicht leer sein.' );
	ec_check( 'ungültige Domain', ec_result( Eventcrawler_Domain_Mappings::add( 'foo bar', 'X' ) ), 'Ungültige Domain. Erlaubt sind z. B. „pages.et4.de“ oder eine vollständige URL.' );
	ec_check( 'leeres Label', ec_result( Eventcrawler_Domain_Mappings::add( 'leer.example.de', '   ' ) ), 'Die Ausgabe im XML darf nicht leer sein (HTML wird entfernt).' );
	ec_check( 'XSS-Label nur Tags -> leer', ec_result( Eventcrawler_Domain_Mappings::add( 'xss.example.de', '<script>alert(1)</script>' ) ), 'Die Ausgabe im XML darf nicht leer sein (HTML wird entfernt).' );
	ec_check( 'Label zu lang (101 Zeichen)', ec_result( Eventcrawler_Domain_Mappings::add( 'lang.example.de', str_repeat( 'ä', 101 ) ) ), 'Die Ausgabe im XML ist zu lang (höchstens 100 Zeichen).' );
	ec_check( 'Label genau 100 Zeichen (Umlaute)', ec_result( Eventcrawler_Domain_Mappings::add( 'lang.example.de', str_repeat( 'ä', 100 ) ) ), 'ok' );
	ec_check( 'XSS-Label mit Text -> HTML entfernt', ec_result( Eventcrawler_Domain_Mappings::add( 'xss.example.de', '<b onmouseover="x()">Fett</b><script>alert(1)</script> Text' ) ), 'ok' );
	ec_check( 'XSS-Label gespeichert ohne HTML', ec_info( 'https://xss.example.de/a' ), 'Fett Text' );
	ec_check( 'Label mit Zeilenumbruch/Tabs -> ein Leerzeichen', ec_result( Eventcrawler_Domain_Mappings::add( 'ws.example.de', "  Kultur\n\tund   Tourismus  " ) ), 'ok' );
	ec_check( 'Label Whitespace bereinigt', ec_info( 'https://ws.example.de/' ), 'Kultur und Tourismus' );
	ec_check( 'Anzahl gespeicherter Einträge', count( Eventcrawler_Domain_Mappings::get_all() ), 6 );

	echo "\nExport-Anzeige (Info:)\n";
	ec_check( 'pages.et4.de -> Bad Steben', ec_info( $bs ), 'Bad Steben' );
	ec_check( 'veranstaltungen.coburg.de -> Coburg', ec_info( $cb ), 'Coburg' );
	ec_check( 'www.burg-rabenstein.de -> www.burg-rabenstein.de', ec_info( $br ), 'www.burg-rabenstein.de' );
	ec_check( 'Mapping fehlt -> Domain', ec_info( $none ), 'www.example.de' );
	ec_check( 'Groß-/Kleinschreibung der URL', ec_info( 'HTTPS://PAGES.ET4.DE/De/X' ), 'Bad Steben' );
	ec_check( 'http:// statt https://', ec_info( 'http://pages.et4.de/x' ), 'Bad Steben' );
	ec_check( 'Subdomain zählt nicht (exakter Host)', ec_info( 'https://www.pages.et4.de/x' ), 'www.pages.et4.de' );
	ec_check( 'ohne Schema bleibt unverändert, kein Mapping', ec_info( 'pages.et4.de/ohne-schema' ), 'pages.et4.de/ohne-schema' );
	ec_check( 'event_url leer -> source_url mit Mapping', Eventcrawler_Exporter::format_body( array( 'title' => 'T', 'city' => 'Hof', 'event_url' => '', 'source_url' => $bs ), Eventcrawler_Domain_Mappings::get_lookup() ), 'T. Info: Bad Steben' );
	ec_check( 'ohne Lookup-Parameter (Standard) -> Domain', Eventcrawler_Exporter::format_body( array( 'title' => 'T', 'city' => 'Hof', 'event_url' => $bs ) ), 'T. Info: pages.et4.de' );

	$filter = function ( $display, $url ) {
		return $url;
	};
	add_filter( 'eventcrawler_export_display_url', $filter, 10, 2 );
	ec_check( 'Filter eventcrawler_export_display_url hat Vorrang (volle URL)', ec_info( $bs ), $bs );
	remove_filter( 'eventcrawler_export_display_url', $filter, 10 );

	echo "\nÄndern, Deaktivieren, Löschen\n";
	$id_bs = ec_id( 'pages.et4.de' );
	ec_check( 'ändern: Label mit Umlaut', ec_result( Eventcrawler_Domain_Mappings::update( $id_bs, 'pages.et4.de', 'Tourismus Bad Steben – Kurort für Gäste', true ) ), 'ok' );
	ec_check( 'geänderter Text im Export', ec_info( $bs ), 'Tourismus Bad Steben – Kurort für Gäste' );
	ec_check( 'ändern: Label mit &', ec_result( Eventcrawler_Domain_Mappings::update( $id_bs, 'pages.et4.de', 'Kultur & Tourismus', true ) ), 'ok' );
	ec_check( 'Label mit & im Export (Klartext)', ec_info( $bs ), 'Kultur & Tourismus' );
	ec_check( 'ändern: Label www.-Text', ec_result( Eventcrawler_Domain_Mappings::update( $id_bs, 'pages.et4.de', 'www.bad-steben.de', true ) ), 'ok' );
	ec_check( 'www.-Text im Export', ec_info( $bs ), 'www.bad-steben.de' );
	ec_check( 'ändern auf vorhandene Domain -> Dublette', ec_result( Eventcrawler_Domain_Mappings::update( $id_bs, 'VERANSTALTUNGEN.coburg.de', 'X', true ) ), 'Für die Domain „veranstaltungen.coburg.de“ gibt es bereits eine Link-Bezeichnung.' );
	ec_check( 'nach abgelehnter Änderung unverändert', ec_info( $bs ), 'www.bad-steben.de' );
	ec_check( 'eigene Domain in anderer Schreibweise speichern', ec_result( Eventcrawler_Domain_Mappings::update( $id_bs, 'https://PAGES.et4.de/neu', 'Bad Steben', true ) ), 'ok' );
	ec_check( 'Domain ändern', ec_result( Eventcrawler_Domain_Mappings::update( ec_id( 'lang.example.de' ), 'kurz.example.de', 'Kurz', true ) ), 'ok' );
	ec_check( 'alte Domain -> Fallback', ec_info( 'https://lang.example.de/' ), 'lang.example.de' );
	ec_check( 'neue Domain -> Label', ec_info( 'https://kurz.example.de/' ), 'Kurz' );
	ec_check( 'unbekannte ID ändern', ec_result( Eventcrawler_Domain_Mappings::update( 'dgibtsnicht', 'a.de', 'A', true ) ), 'Link-Bezeichnung nicht gefunden.' );
	ec_check( 'deaktivieren', Eventcrawler_Domain_Mappings::toggle( $id_bs ), false );
	ec_check( 'deaktiviert -> Domain-Fallback', ec_info( $bs ), 'pages.et4.de' );
	ec_check( 'deaktiviert nicht im Lookup', isset( Eventcrawler_Domain_Mappings::get_lookup()['pages.et4.de'] ), false );
	ec_check( 'beim Bearbeiten aktiv setzen', ec_result( Eventcrawler_Domain_Mappings::update( $id_bs, 'pages.et4.de', 'Bad Steben', true ) ), 'ok' );
	ec_check( 'wieder aktiv -> Label', ec_info( $bs ), 'Bad Steben' );
	ec_check( 'unbekannte ID umschalten', Eventcrawler_Domain_Mappings::toggle( 'dgibtsnicht' ), null );
	ec_check( 'löschen', Eventcrawler_Domain_Mappings::delete( ec_id( 'kurz.example.de' ) ), true );
	ec_check( 'gelöscht -> Domain-Fallback', ec_info( 'https://kurz.example.de/' ), 'kurz.example.de' );
	ec_check( 'unbekannte ID löschen', Eventcrawler_Domain_Mappings::delete( 'dgibtsnicht' ), false );

	echo "\nBeschädigte Option\n";
	update_option( Eventcrawler_Domain_Mappings::OPTION, 'kaputt' );
	ec_check( 'is_corrupt()', Eventcrawler_Domain_Mappings::is_corrupt(), true );
	ec_check( 'beschädigt -> Domain-Fallback', ec_info( $bs ), 'pages.et4.de' );
	update_option( Eventcrawler_Domain_Mappings::OPTION, array( array( 'id' => 'dx', 'domain' => 'pages.et4.de', 'label' => '' ), 'muell', array( 'id' => 'dy', 'domain' => 'pages.et4.de', 'label' => 'Bad Steben', 'enabled' => 1 ) ) );
	ec_check( 'ungültige Einträge werden ignoriert', count( Eventcrawler_Domain_Mappings::get_all() ), 1 );
	delete_option( Eventcrawler_Domain_Mappings::OPTION );

	echo "\nGesamtexport mit echten Daten\n";
	$exporter = new Eventcrawler_Exporter( new Eventcrawler_Repository() );
	$plain    = $exporter->build_xml();
	$lines    = $exporter->lines;
	Eventcrawler_Domain_Mappings::add( 'pages.et4.de', 'Kultur & Tourismus' );
	Eventcrawler_Domain_Mappings::add( 'veranstaltungen.coburg.de', 'Coburg' );
	Eventcrawler_Domain_Mappings::add( 'www.burg-rabenstein.de', 'www.burg-rabenstein.de' );

	$loads = 0;
	$count = function ( $value ) use ( &$loads ) {
		$loads++;
		return $value;
	};
	add_filter( 'option_' . Eventcrawler_Domain_Mappings::OPTION, $count );
	$mapped = $exporter->build_xml();
	remove_filter( 'option_' . Eventcrawler_Domain_Mappings::OPTION, $count );
	ec_check( 'Option einmal je Export gelesen (nicht je Event)', $loads, 1 );
	ec_check( 'beide Exporte erzeugt', is_string( $plain ) && is_string( $mapped ), true );
	ec_check( 'gleiche Zeilenzahl', $exporter->lines, $lines );

	$dom_plain  = new DOMDocument();
	$dom_mapped = new DOMDocument();
	ec_check( 'XML mit & wohlgeformt', $dom_plain->loadXML( $plain ) && $dom_mapped->loadXML( $mapped ), true );
	ec_check( '& korrekt escaped im XML', false !== strpos( $mapped, 'Info: Kultur &amp; Tourismus</p>' ) && false === strpos( $mapped, '&amp;amp;' ), true );
	// Nur die Geschwister h1/h2/p unter <Textabschnitt> vergleichen.
	$a       = iterator_to_array( $dom_plain->getElementsByTagName( 'Textabschnitt' )->item( 0 )->getElementsByTagName( '*' ) );
	$b       = iterator_to_array( $dom_mapped->getElementsByTagName( 'Textabschnitt' )->item( 0 )->getElementsByTagName( '*' ) );
	$same    = count( $a ) === count( $b );
	$changed = array( 'pages.et4.de' => 0, 'veranstaltungen.coburg.de' => 0, 'www.burg-rabenstein.de' => 0 );
	$labels  = array( 'pages.et4.de' => 'Kultur & Tourismus', 'veranstaltungen.coburg.de' => 'Coburg', 'www.burg-rabenstein.de' => 'www.burg-rabenstein.de' );
	for ( $i = 0; $same && $i < count( $a ); $i++ ) {
		if ( $a[ $i ]->nodeName !== $b[ $i ]->nodeName ) {
			$same = false;
			break;
		}
		if ( $a[ $i ]->textContent === $b[ $i ]->textContent ) {
			continue;
		}
		// Einzig erlaubte Änderung: "Info: {domain}" am Zeilenende -> "Info: {label}".
		$ok = false;
		foreach ( $labels as $domain => $label ) {
			$suffix = 'Info: ' . $domain;
			if ( 'p' === $a[ $i ]->nodeName && substr( $a[ $i ]->textContent, -strlen( $suffix ) ) === $suffix
				&& substr( $a[ $i ]->textContent, 0, -strlen( $suffix ) ) . 'Info: ' . $label === $b[ $i ]->textContent ) {
				$changed[ $domain ]++;
				$ok = true;
				break;
			}
		}
		$same = $ok;
	}
	ec_check( 'nur der Info-Wert unterscheidet sich (Elementfolge, h1, h2, Text sonst gleich)', $same, true );
	ec_check( 'Bad-Steben-Zeilen geändert', $changed['pages.et4.de'] > 0, true );
	ec_check( 'Coburg-Zeilen geändert', $changed['veranstaltungen.coburg.de'] > 0, true );
	ec_check( 'Rabenstein-Zeilen unverändert (Label = Domain)', $changed['www.burg-rabenstein.de'], 0 );
	echo '         geänderte <p>: ' . wp_json_encode( $changed ) . "\n";
	ec_check( 'äußere Form: kein BOM, Deklaration, kein LF am Ende', 0 === strpos( $mapped, '<?xml version="1.0" encoding="UTF-8"?>' . "\n<Root>" ) && "\n" !== substr( $mapped, -1 ), true );

	echo "\nRepository für das Detail-Modal\n";
	$repo  = new Eventcrawler_Repository();
	$first = (int) $wpdb->get_var( "SELECT MIN(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row   = $repo->get_by_id( $first );
	$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'get_by_id liefert alle Spalten der Tabelle', is_array( $row ) ? array_keys( $row ) : null, $cols );
	ec_check( 'nicht vorhandene ID -> null', $repo->get_by_id( PHP_INT_MAX ), null );
	$null_row = $wpdb->get_row( "SELECT * FROM {$table} WHERE end_time IS NULL LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'NULL bleibt null (nicht "")', $null_row ? $repo->get_by_id( $null_row['id'] )['end_time'] : 'kein Testfall', null );
	$time_row = $wpdb->get_row( "SELECT * FROM {$table} WHERE start_time IS NOT NULL LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Uhrzeit als DB-Wert HH:MM:SS', (bool) preg_match( '/^\d{2}:\d{2}:\d{2}$/', $repo->get_by_id( $time_row['id'] )['start_time'] ), true );
} finally {
	if ( false === $saved_option ) {
		delete_option( Eventcrawler_Domain_Mappings::OPTION );
	} else {
		update_option( Eventcrawler_Domain_Mappings::OPTION, $saved_option, false );
	}
}

echo "\nAufräumen\n";
ec_check( 'Option wiederhergestellt', get_option( Eventcrawler_Domain_Mappings::OPTION ), $saved_option );
ec_check( 'Veranstaltungstabelle unverändert (CHECKSUM TABLE)', $checksum(), $checksum_start );

echo "\n{$passed} bestanden, {$failed} fehlgeschlagen\n";
exit( $failed ? 1 : 0 );
