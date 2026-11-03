<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests der Admin-Oberfläche (docs/06-admin.md): Navigation, Rechte, Übersicht, Quellen-Status,
 * Export-, Hilfe- und Listenseite, Detail-Modal, keine technischen Daten für Unberechtigte.
 *
 * Keine echten HTTP-Abrufe: pre_http_request liefert einen Netzwerkfehler. Datenbankänderungen (Testbenutzer,
 * Status-Option) laufen in einer Transaktion mit Rollback; die Status-Option wird zusätzlich wiederhergestellt.
 *
 * Aufruf: php tools/test-admin.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

require __DIR__ . '/lib-test.php';
ec_bootstrap( 'test-admin.php' );

global $wpdb, $submenu, $menu, $_wp_submenu_nopriv, $_registered_pages;

$status_before = get_option( Eventcrawler_Sources_Admin::STATUS_OPTION, null );
$table         = Eventcrawler_Installer::table_name();
$any_event     = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Kein Netzwerk: jeder Abruf scheitert wie ein Verbindungs-Timeout.
add_filter(
	'pre_http_request',
	function () {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out after 10001 milliseconds' );
	}
);
add_filter( 'wp_doing_ajax', '__return_true' ); // wp_send_json() endet dann mit wp_die() (abgefangen) statt die().

/**
 * Baut das Admin-Menü wie im Backend für den aktuellen Benutzer.
 *
 * @return string[] Titel der Untermenüs in Reihenfolge.
 */
function ec_build_menu() {
	global $submenu, $menu, $_registered_pages, $_parent_pages;
	$submenu           = array();
	$menu              = array();
	$_registered_pages = array();
	$_parent_pages     = array();
	Eventcrawler_Admin::register_menu();
	return isset( $submenu[ Eventcrawler_Admin::MENU_SLUG ] ) ? wp_list_pluck( $submenu[ Eventcrawler_Admin::MENU_SLUG ], 0 ) : array();
}

$wpdb->query( 'START TRANSACTION' );
try {
	echo "Navigation\n";
	ec_user( 'administrator' );
	$titles   = ec_build_menu();
	$expected = array( 'Übersicht', 'Veranstaltungen', 'Veranstaltung hinzufügen', 'Import', 'Quellen', 'Filterregeln', 'Link-Bezeichnungen', 'Export', 'Einstellungen' );
	if ( Eventcrawler_Wpem_Migration::has_wpem_data() ) {
		$expected[] = 'WPEM-Übernahme';
	}
	$expected[] = 'Hilfe';
	ec_check( 'Untermenü in der Zielreihenfolge', $titles, $expected );
	ec_check( 'Top-Level heißt „Eventcrawler“', wp_list_pluck( array_filter( $menu, function ( $item ) { return Eventcrawler_Admin::MENU_SLUG === $item[2]; } ), 0 ), array( 26 => 'Eventcrawler' ) ); // phpcs:ignore
	ec_check( 'Alle Seiten verlangen manage_options', array_unique( wp_list_pluck( $submenu[ Eventcrawler_Admin::MENU_SLUG ], 1 ) ), array( 'manage_options' ) );
	ec_check( 'Liste behält page=eventcrawler', in_array( 'eventcrawler', wp_list_pluck( $submenu[ Eventcrawler_Admin::MENU_SLUG ], 2 ), true ), true );
	ec_check( 'Kein Top-Level-Menü „Veranstaltungen“ vom Eventcrawler', in_array( 'Veranstaltungen', wp_list_pluck( array_filter( $menu, function ( $item ) { return 0 === strpos( $item[2], 'eventcrawler' ); } ), 0 ), true ), false ); // phpcs:ignore
	ec_user( 'editor' );
	ec_check( 'Redakteur: Eventcrawler-Menü unsichtbar', ec_build_menu(), array() );

	echo "Seiten als Administrator\n";
	ec_user( 'administrator' );
	ec_build_menu();
	$pages = array(
		'Übersicht'     => array( 'Eventcrawler_Dashboard_Admin', 'render_overview' ),
		'Quellen'       => array( 'Eventcrawler_Sources_Admin', 'render_page' ),
		'Export'        => array( 'Eventcrawler_Dashboard_Admin', 'render_export' ),
		'Hilfe'         => array( 'Eventcrawler_Dashboard_Admin', 'render_help' ),
		'Import'        => array( 'Eventcrawler_Import_Admin', 'render_page' ),
		'Einstellungen' => array( 'Eventcrawler_Settings_Admin', 'render_page' ),
		'Filterregeln'  => array( 'Eventcrawler_Filter_Admin', 'render_page' ),
		'Link-Bezeichnungen' => array( 'Eventcrawler_Domain_Admin', 'render_page' ),
	);
	$out = array();
	foreach ( $pages as $name => $callback ) {
		$r            = ec_call( $callback, array(), 'GET' );
		$out[ $name ] = $r['output'];
		ec_check( "{$name}: wird angezeigt", array( $r['kind'], false !== strpos( $r['output'], '<h1' ) ), array( 'return', true ) );
		ec_check( "{$name}: kurzer Hilfetext", false !== strpos( $r['output'], 'class="eventcrawler-help"' ), true );
	}
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Übersicht: Gesamtzahl', false !== strpos( $out['Übersicht'], '>' . number_format_i18n( $total ) . '</span><span class="eventcrawler-label">Veranstaltungen gespeichert' ), true );
	ec_check( 'Übersicht: zukünftige, manuelle, aktive Quellen', false !== strpos( $out['Übersicht'], 'davon ab heute' ) && false !== strpos( $out['Übersicht'], 'manuelle Veranstaltungen' ) && false !== strpos( $out['Übersicht'], '>' . count( Eventcrawler_Crawler::get_sources() ) . '</span><span class="eventcrawler-label">aktive Quellen' ), true );
	ec_check( 'Übersicht: letzter Abruf und letzter Import', false !== strpos( $out['Übersicht'], 'Letzter Abruf' ) && false !== strpos( $out['Übersicht'], 'Letzter Import' ), true );
	foreach ( array( 'Alle Quellen abrufen', 'Veranstaltung hinzufügen', 'Datei importieren', 'XML exportieren' ) as $action ) {
		ec_check( "Übersicht: Schnellaktion „{$action}“", false !== strpos( $out['Übersicht'], $action ), true );
	}
	ec_check( 'Quellen: eine Karte je Quelle (11)', substr_count( $out['Quellen'], 'class="button eventcrawler-crawl-one"' ), count( Eventcrawler_Crawler::get_sources() ) );
	ec_check( 'Export: Formular mit Nonce und Aktion wie bisher', false !== strpos( $out['Export'], 'name="action" value="eventcrawler_export"' ) && false !== strpos( $out['Export'], 'name="eventcrawler_export_nonce"' ), true );
	foreach ( array( 'Quellen prüfen', 'Veranstaltungen abrufen', 'Veranstaltungen kontrollieren', 'Frontend-Testseite prüfen', 'Export erstellen' ) as $step ) {
		ec_check( "Hilfe: Schritt „{$step}“", false !== strpos( $out['Hilfe'], $step ), true );
	}
	foreach ( array( 'nicht erreichbar', 'Was bedeutet „gefiltert“', 'Was bedeutet „unverändert“', 'automatisch gelöscht', 'Was passiert beim Import', 'Parallelbetrieb mit WPEM' ) as $q ) {
		ec_check( "Hilfe: Frage „{$q}“", false !== strpos( $out['Hilfe'], $q ), true );
	}
	ec_check( 'Filterregeln: Hilfetext wie vorgegeben', false !== strpos( $out['Filterregeln'], 'Neue Regeln löschen keine bereits gespeicherten Veranstaltungen.' ), true );

	echo "Veranstaltungsliste\n";
	$_GET = array( 'page' => 'eventcrawler' );
	Eventcrawler_Admin::load_page();
	$r = ec_call( array( 'Eventcrawler_Admin', 'render_page' ), array(), 'GET', array( 'page' => 'eventcrawler' ) );
	ec_check( 'Liste: Überschrift „Veranstaltungen“', false !== strpos( $r['output'], '<h1 class="wp-heading-inline">Veranstaltungen</h1>' ), true );
	ec_check( 'Liste: kein technischer Begriff „Crawler starten“ mehr', false === strpos( $r['output'], 'Crawler starten' ), true );
	ec_check( 'Liste: keine technischen Spalten (content_hash, event_key, external_id)', 1 === preg_match( '/content_hash|event_key|external_id/', $r['output'] ), false );
	ec_check( 'Liste: „Details“-Button je Zeile', false !== strpos( $r['output'], 'eventcrawler-details' ), true );

	echo "Abruf-Status (simulierter Verbindungsfehler)\n";
	delete_option( Eventcrawler_Sources_Admin::STATUS_OPTION );
	$before = $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash, last_seen_at ORDER BY id)) FROM {$table} WHERE source = %s", 'burg-rabenstein' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$r      = ec_call( array( 'Eventcrawler_Admin', 'handle_crawl_source' ), array( 'source' => 'burg-rabenstein', 'nonce' => wp_create_nonce( 'eventcrawler_crawl_source' ) ) );
	$json   = json_decode( $r['output'], true );
	ec_check( 'AJAX-Antwort: Fehler mit Meldung', array( $json['success'], false !== strpos( $json['data']['message'], 'Vorhandene Veranstaltungen' ) ), array( false, true ) );
	$st = Eventcrawler_Sources_Admin::all_status()['burg-rabenstein'];
	ec_check( 'Status gespeichert: fehlgeschlagen, technische Meldung', array( $st['ok'], false !== strpos( $st['message'], 'cURL error 28' ) ), array( false, true ) );
	ec_check( 'Daten der Quelle unverändert', $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(id, content_hash, last_seen_at ORDER BY id)) FROM {$table} WHERE source = %s", 'burg-rabenstein' ) ), $before ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_check( 'Bewertung: Fehler', Eventcrawler_Sources_Admin::classify( $st ), array( 'error', 'Letzter Abruf fehlgeschlagen. Vorhandene Veranstaltungen bleiben erhalten.' ) );
	$r = ec_call( array( 'Eventcrawler_Sources_Admin', 'render_page' ), array(), 'GET' );
	ec_check( 'Quellen-Karte rot mit verständlichem Text', false !== strpos( $r['output'], 'eventcrawler-source-card--error' ) && false !== strpos( $r['output'], 'Vorhandene Veranstaltungen bleiben erhalten.' ), true );
	$pos_text = strpos( $r['output'], 'Letzter Abruf fehlgeschlagen' );
	$pos_tech = strpos( $r['output'], 'cURL error 28' );
	$pos_det  = strrpos( substr( $r['output'], 0, (int) $pos_tech ), '<details class="eventcrawler-tech">' );
	ec_check( 'Technische Meldung nur innerhalb „Technische Details“', false !== $pos_tech && false !== $pos_det && $pos_det > $pos_text && false === strpos( substr( $r['output'], $pos_det, $pos_tech - $pos_det ), '</details>' ), true );
	$r = ec_call( array( 'Eventcrawler_Dashboard_Admin', 'render_overview' ), array(), 'GET' );
	ec_check( 'Übersicht nennt die fehlerhafte Quelle', false !== strpos( $r['output'], 'Burg Rabenstein' ) && false !== strpos( $r['output'], 'eventcrawler-source-card--error' ), true );
	ec_check( 'Bewertung: erfolgreich = ok', Eventcrawler_Sources_Admin::classify( array( 'time' => current_time( 'mysql' ), 'ok' => true ) )[0], 'ok' );
	ec_check( 'Bewertung: alt = Hinweis', Eventcrawler_Sources_Admin::classify( array( 'time' => wp_date( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS ), 'ok' => true ) )[0], 'warning' );
	ec_check( 'Bewertung: nie abgerufen = grau', Eventcrawler_Sources_Admin::classify( null )[0], 'muted' );
	ec_check( 'Zeitangabe „heute HH:MM“', 0 === strpos( Eventcrawler_Sources_Admin::human_time( current_time( 'mysql' ) ), 'heute ' ), true );

	echo "Abruf-Endpunkt: Rechte und Nonce\n";
	$count = count( Eventcrawler_Sources_Admin::all_status() );
	ec_user( 'subscriber' );
	$r = ec_call( array( 'Eventcrawler_Admin', 'handle_crawl_source' ), array( 'source' => 'coburg', 'nonce' => wp_create_nonce( 'eventcrawler_crawl_source' ) ) );
	ec_check( 'Abonnent: „Keine Berechtigung“, kein Status geschrieben', array( json_decode( $r['output'], true )['data']['message'], count( Eventcrawler_Sources_Admin::all_status() ) ), array( 'Keine Berechtigung.', $count ) );
	ec_user( 'administrator' );
	$r = ec_call( array( 'Eventcrawler_Admin', 'handle_crawl_source' ), array( 'source' => 'coburg', 'nonce' => 'falsch' ) );
	ec_check( 'Falscher Nonce: 403, kein Status geschrieben', array( $r['status'], count( Eventcrawler_Sources_Admin::all_status() ) ), array( 403, $count ) );
	$r = ec_call( array( 'Eventcrawler_Admin', 'handle_crawl_source' ), array( 'source' => 'manual', 'nonce' => wp_create_nonce( 'eventcrawler_crawl_source' ) ) );
	ec_check( '„manual“ ist keine abrufbare Quelle', json_decode( $r['output'], true )['data']['message'], 'Unbekannte Quelle.' );

	echo "Detail-Modal\n";
	$r    = ec_call( array( 'Eventcrawler_Admin', 'handle_event_details' ), array( 'event_id' => (string) $any_event, 'nonce' => wp_create_nonce( 'eventcrawler_event_details' ) ) );
	$json = json_decode( $r['output'], true );
	ec_check( 'Admin: alle Spalten wie gespeichert', count( $json['data']['fields'] ), count( $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	ec_user( 'subscriber' );
	$r = ec_call( array( 'Eventcrawler_Admin', 'handle_event_details' ), array( 'event_id' => (string) $any_event, 'nonce' => wp_create_nonce( 'eventcrawler_event_details' ) ) );
	ec_check( 'Abonnent: „Keine Berechtigung“ ohne Daten', array( json_decode( $r['output'], true )['data']['message'], false !== strpos( $r['output'], 'content_hash' ) ), array( 'Keine Berechtigung.', false ) );
	$js = file_get_contents( EVENTCRAWLER_PATH . 'assets/eventcrawler-admin.js' );
	ec_check( 'Modal: technische Felder unter „Technische Informationen“', false !== strpos( $js, "'Technische Informationen'" ), true );
	ec_check( 'JS: deutsche Begriffe (kein „Crawler läuft“)', false === strpos( $js, 'Crawler läuft' ) && false !== strpos( $js, 'Abruf läuft' ), true );

	echo "Keine Daten für Unberechtigte\n";
	foreach ( $pages as $name => $callback ) {
		$r = ec_call( $callback, array(), 'GET' );
		ec_check( "{$name} als Abonnent: 403 ohne Inhalt", array( $r['kind'], $r['status'], false !== strpos( $r['output'], 'eventcrawler-card' ) ), array( 'die', 403, false ) );
	}
	wp_set_current_user( 0 );
	$r = ec_call( array( 'Eventcrawler_Dashboard_Admin', 'render_overview' ), array(), 'GET' );
	ec_check( 'Übersicht als Gast: 403', $r['status'], 403 );
	$r = ec_call( array( 'Eventcrawler_Admin', 'handle_export' ), array( 'eventcrawler_export_nonce' => 'x' ) );
	ec_check( 'Export als Gast: 403, kein XML', array( $r['status'], false !== strpos( $r['output'], '<Root>' ) ), array( 403, false ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	if ( null === $status_before ) {
		delete_option( Eventcrawler_Sources_Admin::STATUS_OPTION );
	} else {
		update_option( Eventcrawler_Sources_Admin::STATUS_OPTION, $status_before, false );
	}
}

ec_check( 'Status-Option wiederhergestellt', get_option( Eventcrawler_Sources_Admin::STATUS_OPTION, null ), $status_before );
ec_finish();
