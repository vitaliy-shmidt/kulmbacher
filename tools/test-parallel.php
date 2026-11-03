<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests Parallelbetrieb mit WP Kulmbacher Land (WPEM), Einstellungen, Frontend-Testseite
 * (docs/10-frontend.md Abschnitt 15, docs/06-admin.md Abschnitt 14).
 *
 * Alle Datenbankänderungen (Einstellungen, Testseite, Testbenutzer) laufen in einer Transaktion mit Rollback; die
 * Option eventcrawler_settings wird danach zusätzlich wiederhergestellt. Läuft mit aktivem und mit deaktiviertem WPEM;
 * der jeweils andere Zustand wird über den Filter eventcrawler_wpem_active simuliert.
 *
 * Aufruf: php tools/test-parallel.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

require __DIR__ . '/lib-test.php';
ec_bootstrap( 'test-parallel.php' );

global $wpdb, $shortcode_tags;

$settings_before = get_option( Eventcrawler_Settings::OPTION, null );
$test_page_before = get_option( Eventcrawler_Settings::TEST_PAGE_OPTION, null );
$wpem_really_active = defined( 'WPEM_VERSION' );
echo 'WPEM in dieser Installation ' . ( $wpem_really_active ? 'aktiv' : 'nicht aktiv' ) . "\n";

/**
 * Registriert die Shortcodes neu wie beim Seitenaufruf (WPEM-Shortcodes bleiben, eigene werden neu gesetzt).
 *
 * @param bool|null $simulate_wpem null = echter Zustand, sonst Erkennung übersteuern.
 */
function ec_reregister( $simulate_wpem ) {
	global $shortcode_tags;
	remove_all_filters( 'eventcrawler_wpem_active' );
	if ( null !== $simulate_wpem ) {
		add_filter( 'eventcrawler_wpem_active', $simulate_wpem ? '__return_true' : '__return_false' );
	}
	foreach ( array( 'events_list', 'events_calendar' ) as $tag ) {
		if ( isset( $shortcode_tags[ $tag ] ) && is_array( $shortcode_tags[ $tag ] ) && 'Eventcrawler_Frontend' === $shortcode_tags[ $tag ][0] ) {
			remove_shortcode( $tag );
		}
	}
	Eventcrawler_Settings::flush_cache();
	Eventcrawler_Frontend::register_shortcodes();
}

/**
 * @param string $tag Shortcode.
 * @return string wpem|eventcrawler|none
 */
function ec_owner( $tag ) {
	global $shortcode_tags;
	if ( ! isset( $shortcode_tags[ $tag ] ) ) {
		return 'none';
	}
	$cb = $shortcode_tags[ $tag ];
	return ( is_array( $cb ) && 'Eventcrawler_Frontend' === $cb[0] ) ? 'eventcrawler' : 'wpem';
}

/**
 * @param int $flag 0|1.
 */
function ec_set_alias_option( $flag ) {
	update_option( Eventcrawler_Settings::OPTION, array_merge( Eventcrawler_Settings::DEFAULTS, array( 'wpem_aliases' => $flag ) ) );
	Eventcrawler_Settings::flush_cache();
}

$wpdb->query( 'START TRANSACTION' );
try {
	echo "Standardwerte\n";
	delete_option( Eventcrawler_Settings::OPTION );
	Eventcrawler_Settings::flush_cache();
	ec_check( 'Default: WPEM-Shortcodes übernehmen = aus', Eventcrawler_Settings::get( 'wpem_aliases' ), 0 );
	ec_check( 'Default: Detailseiten = aus', Eventcrawler_Settings::get( 'seo_detail_pages' ), 0 );
	ec_check( 'Default: URL-Basis eventcrawler-veranstaltung', Eventcrawler_Settings::get( 'seo_base' ), 'eventcrawler-veranstaltung' );
	ec_check( 'Default: 60 Tage vergangene Termine in der Sitemap', Eventcrawler_Settings::get( 'seo_sitemap_past_days' ), 60 );
	ec_check( 'Erkennung WPEM aktiv = Konstante WPEM_VERSION', Eventcrawler_Settings::wpem_active(), $wpem_really_active );

	echo "WPEM aktiv (echt oder simuliert)\n";
	ec_reregister( true );
	ec_check( '[events_list] nicht vom Eventcrawler registriert', ec_owner( 'events_list' ) !== 'eventcrawler', true );
	ec_check( '[events_calendar] nicht vom Eventcrawler registriert', ec_owner( 'events_calendar' ) !== 'eventcrawler', true );
	if ( $wpem_really_active ) {
		ec_check( '[events_list] gehört WPEM', ec_owner( 'events_list' ), 'wpem' );
		ec_check( '[events_calendar] gehört WPEM', ec_owner( 'events_calendar' ), 'wpem' );
	}
	ec_check( 'Modus Parallelbetrieb', Eventcrawler_Settings::mode(), 'parallel' );
	ec_set_alias_option( 1 );
	ec_reregister( true );
	ec_check( 'Option an, WPEM aktiv: Alias trotzdem nicht registriert', ec_owner( 'events_list' ) !== 'eventcrawler' && ec_owner( 'events_calendar' ) !== 'eventcrawler', true );
	ec_check( 'Option an, WPEM aktiv: Modus bleibt Parallelbetrieb', Eventcrawler_Settings::mode(), 'parallel' );
	ec_set_alias_option( 0 );

	echo "Eigene Shortcodes\n";
	ec_reregister( null );
	$_GET = array();
	Eventcrawler_Frontend::reset_page_state();
	$html = do_shortcode( '[eventcrawler_events title="" show_calendar="0"]' );
	ec_check( '[eventcrawler_events] liefert Eventcrawler-Markup', false !== strpos( $html, 'class="eventcrawler eventcrawler-events' ), true );
	Eventcrawler_Frontend::reset_page_state();
	$html = do_shortcode( '[eventcrawler_calendar title=""]' );
	ec_check( '[eventcrawler_calendar] liefert Kalender', false !== strpos( $html, 'eventcrawler-calendar' ), true );

	echo "WPEM inaktiv (simuliert, WPEM-Shortcodes entfernt)\n";
	$saved_tags = array();
	foreach ( array( 'events_list', 'events_calendar' ) as $tag ) {
		if ( 'wpem' === ec_owner( $tag ) ) {
			$saved_tags[ $tag ] = $shortcode_tags[ $tag ];
			remove_shortcode( $tag );
		}
	}
	ec_reregister( false );
	ec_check( 'Option aus, WPEM inaktiv: [events_list] NICHT automatisch aktiv', ec_owner( 'events_list' ), 'none' );
	ec_check( 'Option aus, WPEM inaktiv: [events_calendar] NICHT automatisch aktiv', ec_owner( 'events_calendar' ), 'none' );
	ec_check( 'Option aus, WPEM inaktiv: Modus eigenständig', Eventcrawler_Settings::mode(), 'standalone' );
	ec_check( 'Shortcode bleibt als Text stehen (keine fremde Ausgabe)', do_shortcode( '[events_list]' ), '[events_list]' );

	ec_set_alias_option( 1 );
	ec_reregister( false );
	ec_check( 'Option an, WPEM inaktiv: [events_list] = Eventcrawler', ec_owner( 'events_list' ), 'eventcrawler' );
	ec_check( 'Option an, WPEM inaktiv: [events_calendar] = Eventcrawler', ec_owner( 'events_calendar' ), 'eventcrawler' );
	ec_check( 'Option an, WPEM inaktiv: Modus Aliase', Eventcrawler_Settings::mode(), 'aliases' );
	Eventcrawler_Frontend::reset_page_state();
	ec_check( 'Alias [events_list] rendert Tagesansicht', false !== strpos( do_shortcode( '[events_list]' ), 'eventcrawler-events--day' ), true );
	Eventcrawler_Frontend::reset_page_state();
	ec_check( 'Alias [events_calendar] rendert Kalender', false !== strpos( do_shortcode( '[events_calendar]' ), 'eventcrawler-calendar' ), true );

	// Ursprünglichen Zustand wiederherstellen.
	ec_set_alias_option( 0 );
	ec_reregister( null );
	foreach ( $saved_tags as $tag => $callback ) {
		add_shortcode( $tag, $callback );
	}
	if ( $wpem_really_active ) {
		ec_check( 'WPEM-Shortcodes nach dem Test wieder bei WPEM', ec_owner( 'events_list' ) . '/' . ec_owner( 'events_calendar' ), 'wpem/wpem' );
	}

	echo "Einstellungen prüfen\n";
	$err = Eventcrawler_Settings::sanitize( array( 'seo_base' => 'Ungültig Basis!' ), true );
	ec_check( 'URL-Basis mit Leerzeichen/Großbuchstaben abgelehnt', is_wp_error( $err ), true );
	add_filter( 'eventcrawler_wpem_active', '__return_true' );
	ec_check( 'URL-Basis „veranstaltung“ bei aktivem WPEM abgelehnt', is_wp_error( Eventcrawler_Settings::sanitize( array( 'seo_base' => 'veranstaltung' ), true ) ), true );
	ec_check( 'URL-Basis „veranstaltungsort“ bei aktivem WPEM abgelehnt', is_wp_error( Eventcrawler_Settings::sanitize( array( 'seo_base' => 'veranstaltungsort' ), true ) ), true );
	remove_all_filters( 'eventcrawler_wpem_active' );
	add_filter( 'eventcrawler_wpem_active', '__return_false' );
	ec_check( 'URL-Basis „veranstaltung“ ohne WPEM erlaubt', Eventcrawler_Settings::sanitize( array( 'seo_base' => 'veranstaltung' ), true )['seo_base'], 'veranstaltung' );
	remove_all_filters( 'eventcrawler_wpem_active' );
	ec_check( 'Titelmuster ohne {title} abgelehnt', is_wp_error( Eventcrawler_Settings::sanitize( array( 'seo_title_pattern' => 'Termin in {city}' ), true ) ), true );
	ec_check( 'Tage negativ abgelehnt', is_wp_error( Eventcrawler_Settings::sanitize( array( 'seo_sitemap_past_days' => '-5' ), true ) ), true );
	$strict = Eventcrawler_Settings::sanitize( array(), true );
	ec_check( 'Formular ohne Haken: alle Schalter aus', array( $strict['wpem_aliases'], $strict['seo_detail_pages'], $strict['seo_sitemap'] ), array( 0, 0, 0 ) );
	update_option( Eventcrawler_Settings::OPTION, array( 'wpem_aliases' => 'x', 'seo_base' => '../etc', 'seo_sitemap_past_days' => 'abc' ) );
	Eventcrawler_Settings::flush_cache();
	ec_check( 'Beschädigte Option: Defaults statt ungültiger Werte', array( Eventcrawler_Settings::get( 'seo_base' ), Eventcrawler_Settings::get( 'seo_sitemap_past_days' ) ), array( 'eventcrawler-veranstaltung', 60 ) );
	delete_option( Eventcrawler_Settings::OPTION );
	Eventcrawler_Settings::flush_cache();

	echo "Einstellungen speichern (Handler)\n";
	wp_set_current_user( 0 );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'wpem_aliases' => '1' ) );
	ec_check( 'Gast: 403', array( $r['kind'], $r['status'] ), array( 'die', 403 ) );
	ec_user( 'editor' );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'wpem_aliases' => '1', 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_SAVE ) ) );
	ec_check( 'Redakteur ohne manage_options: 403', array( $r['kind'], $r['status'] ), array( 'die', 403 ) );
	ec_user( 'administrator' );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'wpem_aliases' => '1', 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_SAVE ) ), 'GET' );
	ec_check( 'Admin per GET: 405', array( $r['kind'], $r['status'] ), array( 'die', 405 ) );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'wpem_aliases' => '1', 'eventcrawler_settings_nonce' => 'falsch' ) );
	ec_check( 'Admin mit falschem Nonce: 403', array( $r['kind'], $r['status'] ), array( 'die', 403 ) );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'wpem_aliases' => '1', 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_TEST_PAGE ) ) );
	ec_check( 'Admin mit Nonce einer anderen Aktion: 403', array( $r['kind'], $r['status'] ), array( 'die', 403 ) );
	Eventcrawler_Settings::flush_cache();
	ec_check( 'Nach abgelehnten Anfragen nichts gespeichert', Eventcrawler_Settings::get( 'wpem_aliases' ), 0 );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'wpem_aliases' => '1', 'seo_base' => 'eventcrawler-veranstaltung', 'seo_sitemap_past_days' => '30', 'seo_title_pattern' => '{title} – {date}', 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_SAVE ) ) );
	ec_check( 'Admin mit Nonce: Redirect zur Einstellungsseite', $r['kind'] . ' ' . ( false !== strpos( $r['target'], 'page=eventcrawler-settings' ) ? 'ok' : $r['target'] ), 'redirect ok' );
	Eventcrawler_Settings::flush_cache();
	ec_check( 'Gespeichert: Alias-Option an, 30 Tage, Muster', array( Eventcrawler_Settings::get( 'wpem_aliases' ), Eventcrawler_Settings::get( 'seo_sitemap_past_days' ), Eventcrawler_Settings::get( 'seo_title_pattern' ) ), array( 1, 30, '{title} – {date}' ) );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_save' ), array( 'seo_base' => 'a b', 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_SAVE ) ) );
	Eventcrawler_Settings::flush_cache();
	ec_check( 'Ungültige Eingabe: nichts überschrieben', Eventcrawler_Settings::get( 'wpem_aliases' ), 1 );
	delete_transient( 'eventcrawler_settings_notice_' . get_current_user_id() );

	echo "Frontend-Testseite\n";
	delete_option( Eventcrawler_Settings::TEST_PAGE_OPTION );
	$pages_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" );
	$sum_before   = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(ID, post_status, post_title, MD5(post_content) ORDER BY ID)) FROM {$wpdb->posts} WHERE post_type = 'page'" );
	ec_user( 'subscriber' );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_test_page' ), array( 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_TEST_PAGE ) ) );
	ec_check( 'Abonnent: 403, keine Seite', array( $r['status'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ) ), array( 403, $pages_before ) );
	ec_user( 'administrator' );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_test_page' ), array( 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_TEST_PAGE ) ) );
	$page = Eventcrawler_Settings::test_page();
	ec_check( 'Admin: Testseite angelegt', $page instanceof WP_Post, true );
	ec_check( 'Testseite ist Entwurf (nicht veröffentlicht)', $page ? $page->post_status : null, 'draft' );
	ec_check( 'Titel „Eventcrawler Test“', $page ? $page->post_title : null, 'Eventcrawler Test' );
	ec_check( 'Inhalt: Kalender und Liste', $page ? $page->post_content : null, "[eventcrawler_calendar]\n\n[eventcrawler_events]" );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'handle_test_page' ), array( 'eventcrawler_settings_nonce' => wp_create_nonce( Eventcrawler_Settings_Admin::ACTION_TEST_PAGE ) ) );
	ec_check( 'Zweiter Klick legt keine zweite Seite an', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), $pages_before + 1 );
	ec_check( 'Bestehende Seiten unverändert', $wpdb->get_var( $wpdb->prepare( "SELECT MD5(GROUP_CONCAT(ID, post_status, post_title, MD5(post_content) ORDER BY ID)) FROM {$wpdb->posts} WHERE post_type = 'page' AND ID <> %d", $page ? $page->ID : 0 ) ), $sum_before );
	delete_transient( 'eventcrawler_settings_notice_' . get_current_user_id() );

	echo "Einstellungsseite (Ausgabe)\n";
	ec_user( 'administrator' );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'render_page' ), array(), 'GET' );
	ec_check( 'Seite zeigt WPEM-Kompatibilität', false !== strpos( $r['output'], 'WPEM-Kompatibilität' ), true );
	ec_check( 'Seite nennt eigene Shortcodes', false !== strpos( $r['output'], '[eventcrawler_events]' ) && false !== strpos( $r['output'], '[eventcrawler_calendar]' ), true );
	ec_check( 'Checkbox „WPEM-Shortcodes übernehmen“', false !== strpos( $r['output'], 'name="wpem_aliases"' ), true );
	ec_user( 'subscriber' );
	$r = ec_call( array( 'Eventcrawler_Settings_Admin', 'render_page' ), array(), 'GET' );
	ec_check( 'Abonnent sieht die Seite nicht (403)', array( $r['kind'], $r['status'], false !== strpos( $r['output'], 'WPEM' ) ), array( 'die', 403, false ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	remove_all_filters( 'eventcrawler_wpem_active' );
	if ( null === $settings_before ) {
		delete_option( Eventcrawler_Settings::OPTION );
	} else {
		update_option( Eventcrawler_Settings::OPTION, $settings_before );
	}
	if ( null === $test_page_before ) {
		delete_option( Eventcrawler_Settings::TEST_PAGE_OPTION );
	}
	Eventcrawler_Settings::flush_cache();
}

ec_check( 'Option eventcrawler_settings wiederhergestellt', get_option( Eventcrawler_Settings::OPTION, null ), $settings_before );
ec_finish();
