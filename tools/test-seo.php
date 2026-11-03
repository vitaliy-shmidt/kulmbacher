<?php
/**
 * NUR FÜR ENTWICKLUNG: Tests der SEO-Detailseiten (docs/12-seo.md): Slugs, Title, Description, Canonical, Robots,
 * JSON-LD, Sitemap, vergangene Termine, 404, Filter-URLs, Escaping, Parallelbetrieb mit WPEM.
 *
 * Teil 1 (immer): Funktionen im Prozess, Datenbankänderungen in einer Transaktion mit Rollback.
 * Teil 2 (nur wenn die Website per HTTP erreichbar ist, z. B. PHP-Built-in-Server): echte Seitenaufrufe. Dafür werden
 * Testtermine mit source = dev-test und eine Testseite angelegt und am Ende wieder gelöscht; die Optionen
 * eventcrawler_settings und blog_public werden vorher gesichert und danach wiederhergestellt.
 *
 * Aufruf: php tools/test-seo.php <pfad/zu/wp-load.php>
 *
 * @package Eventcrawler
 */

require __DIR__ . '/lib-test.php';
ec_bootstrap( 'test-seo.php' );

global $wpdb;
$table           = Eventcrawler_Installer::table_name();
$repository      = new Eventcrawler_Repository();
$settings_before = get_option( Eventcrawler_Settings::OPTION, null );
$public_before   = get_option( 'blog_public' );
$today           = current_time( 'Y-m-d' );
$day             = function ( $offset ) use ( $today ) {
	return wp_date( 'Y-m-d', strtotime( $today . ' ' . $offset . ' days' ) );
};

/**
 * @param array $data Felder.
 * @return array Gespeicherte Zeile.
 */
function ec_seo_fixture( array $data ) {
	$row = array_merge(
		array(
			'source'        => 'dev-test',
			'external_id'   => null,
			'title'         => 'SEO-Test',
			'description'   => null,
			'start_date'    => current_time( 'Y-m-d' ),
			'start_time'    => null,
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => 0,
			'venue_name'    => 'Stadthalle',
			'venue_address' => 'Hauptstraße 1',
			'city'          => 'Hof',
			'event_url'     => null,
			'source_url'    => null,
		),
		$data
	);
	$row['event_key']    = Eventcrawler_Crawler::event_key( $row );
	$row['content_hash'] = Eventcrawler_Crawler::content_hash( $row );
	$repo                = new Eventcrawler_Repository();
	return $repo->get_by_id( $repo->insert( $row ) );
}

/**
 * @param bool $on Detailseiten an/aus (ohne Rewrite-Flush; für Funktionen im Prozess).
 */
function ec_seo_enable( $on ) {
	update_option( Eventcrawler_Settings::OPTION, array_merge( Eventcrawler_Settings::DEFAULTS, array( 'seo_detail_pages' => $on ? 1 : 0 ) ) );
	Eventcrawler_Settings::flush_cache();
}

echo "Schema 4\n";
$all_sum = function () use ( $wpdb, $table ) {
	$wpdb->query( 'SET SESSION group_concat_max_len = 100000000' );
	return $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, slug, content_hash, updated_at, last_seen_at) ORDER BY id SEPARATOR '#')) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$sum_before = $all_sum();
ec_check( 'Schema-Version 4', get_option( Eventcrawler_Installer::DB_VERSION_OPTION ), '4' );
ec_check( 'Spalte slug mit UNIQUE-Index', $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = 'slug' AND NON_UNIQUE = 0" ), '1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
Eventcrawler_Installer::upgrade();
ec_check( 'Erneutes Upgrade ändert nichts (idempotent)', $all_sum(), $sum_before );

echo "Teil 1: Funktionen\n";
$wpdb->query( 'START TRANSACTION' );
try {
	echo "Slugs\n";
	ec_check( 'Titel + Datum', Eventcrawler_Repository::slug_base( 'Ninotschka', '2026-10-24' ), 'ninotschka-2026-10-24' );
	ec_check( 'Umlaute/ß deterministisch', Eventcrawler_Repository::slug_base( 'Äußere Bühne: Größe & Café', '2026-11-15' ), 'aeussere-buehne-groesse-cafe-2026-11-15' );
	ec_check( 'Nur Sonderzeichen → „veranstaltung“', Eventcrawler_Repository::slug_base( '!!! ???', '2026-11-15' ), 'veranstaltung-2026-11-15' );
	ec_check( 'Lange Titel an Wortgrenze gekürzt (≤ 80 + Datum)', strlen( Eventcrawler_Repository::slug_base( str_repeat( 'Wort ', 40 ), '2026-11-15' ) ) <= 91, true );
	$a = ec_seo_fixture( array( 'title' => 'Doppelvorstellung', 'start_date' => '2026-12-01', 'start_time' => '18:00:00' ) );
	$b = ec_seo_fixture( array( 'title' => 'Doppelvorstellung', 'start_date' => '2026-12-01', 'start_time' => '20:00:00' ) );
	ec_check( 'Erster Termin ohne Zusatz', $a['slug'], 'doppelvorstellung-2026-12-01' );
	ec_check( 'Kollision: stabiler Zusatz = ID', $b['slug'], 'doppelvorstellung-2026-12-01-' . $b['id'] );
	$repository->update( $a['id'], array( 'title' => 'Doppelvorstellung (verlegt)', 'start_date' => '2026-12-02' ) );
	ec_check( 'Titel/Datum geändert: Slug bleibt (URL stabil)', $repository->get_by_id( $a['id'] )['slug'], 'doppelvorstellung-2026-12-01' );
	ec_check( 'Slug nicht im content_hash (Crawler-Updates unberührt)', Eventcrawler_Crawler::content_hash( array_merge( $a, array( 'slug' => 'x' ) ) ), Eventcrawler_Crawler::content_hash( $a ) );
	$m = Eventcrawler_Manual_Events::save( $repository, Eventcrawler_Manual_Events::prepare( array( 'title' => 'Manuell SEO', 'start_date' => '2026-12-03', 'city' => 'Hof' ) )['event'] );
	ec_check( 'Manuelle Termine bekommen ebenfalls einen Slug', $repository->get_by_id( $m )['slug'], 'manuell-seo-2026-12-03' );
	ec_check( 'Alle Termine haben einen eindeutigen Slug', (int) $wpdb->get_var( "SELECT COUNT(*) - COUNT(DISTINCT slug) + SUM(slug IS NULL) FROM {$table}" ), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	echo "URL und Einstellung\n";
	ec_seo_enable( false );
	ec_check( 'Detailseiten aus: keine URL', Eventcrawler_Seo::url( $a ), '' );
	Eventcrawler_Frontend::reset_page_state();
	$html_off = do_shortcode( '[eventcrawler_events view="day" per_page="100" title="" show_calendar="0" show_filters="0" date="2026-12-01"]' );
	ec_check( 'Fixture in der Tagesansicht', false !== strpos( $html_off, 'id="eventcrawler-event-' . $b['id'] . '"' ), true );
	ec_check( 'Detailseiten aus: Liste ohne Detail-Links (Ausgabe wie bisher)', false === strpos( $html_off, '/eventcrawler-veranstaltung/' ), true );
	ec_seo_enable( true );
	ec_check( 'Detailseiten an: URL mit Basis und Slug', Eventcrawler_Seo::url( $a ), home_url( '/eventcrawler-veranstaltung/doppelvorstellung-2026-12-01/' ) );
	Eventcrawler_Frontend::reset_page_state();
	$html_on = do_shortcode( '[eventcrawler_events view="day" per_page="100" title="" show_calendar="0" show_filters="0" date="2026-12-01"]' );
	ec_check( 'Detailseiten an: Kartentitel verlinkt die Detailseite', false !== strpos( $html_on, 'href="' . esc_url( Eventcrawler_Seo::url( $b ) ) . '"' ), true );

	echo "Title und Description\n";
	$e = array(
		'title'         => 'Ninotschka',
		'start_date'    => '2026-10-24',
		'start_time'    => '20:00:00',
		'end_date'      => '2026-10-24',
		'end_time'      => '22:00:00',
		'all_day'       => 0,
		'venue_name'    => 'Kreiskulturraum',
		'venue_address' => 'Siechenanger 1',
		'city'          => 'Kronach',
		'source'        => 'kronach',
		'description'   => 'Komödie',
		'event_url'     => 'https://example.org/n',
		'slug'          => 'ninotschka-2026-10-24',
	);
	ec_check( 'Title nach Standardmuster', Eventcrawler_Seo::title( $e, '{title} am {date} in {city}' ), 'Ninotschka am 24.10.2026 in Kronach' );
	ec_check( 'Title: fehlender Ort entfällt samt Wort', Eventcrawler_Seo::title( array_merge( $e, array( 'venue_name' => null ) ), '{title} im {venue} in {city}' ), 'Ninotschka in Kronach' );
	ec_check( 'Title: {time}', Eventcrawler_Seo::title( $e, '{title}, {date} {time}' ), 'Ninotschka, 24.10.2026 20:00–22:00 Uhr' );
	ec_check( 'Description vollständig', Eventcrawler_Seo::description( $e ), 'Ninotschka am 24.10.2026 um 20:00–22:00 Uhr in Kronach. Ort: Kreiskulturraum, Siechenanger 1.' );
	ec_check( 'Description ohne Uhrzeit/Ort: kürzer, nichts erfunden', Eventcrawler_Seo::description( array_merge( $e, array( 'start_time' => null, 'end_time' => null, 'venue_name' => null, 'venue_address' => null ) ) ), 'Ninotschka am 24.10.2026 in Kronach.' );
	ec_check( 'Description ganztägig: keine Uhrzeit', Eventcrawler_Seo::description( array_merge( $e, array( 'all_day' => 1 ) ) ), 'Ninotschka am 24.10.2026 in Kronach. Ort: Kreiskulturraum, Siechenanger 1.' );
	ec_check( 'Description höchstens 160 Zeichen', mb_strlen( Eventcrawler_Seo::description( array_merge( $e, array( 'title' => str_repeat( 'Sehr langer Titel ', 20 ) ) ) ) ) <= 160, true );

	echo "JSON-LD\n";
	$tz = wp_timezone_string();
	$ld = Eventcrawler_Seo::json_ld( $e );
	ec_check( '@context/@type/name', array( $ld['@context'], $ld['@type'], $ld['name'] ), array( 'https://schema.org', 'Event', 'Ninotschka' ) );
	ec_check( 'startDate mit Uhrzeit und Zeitzone der Website (' . $tz . ')', $ld['startDate'], date_create_immutable( '2026-10-24 20:00:00', wp_timezone() )->format( 'c' ) );
	ec_check( 'endDate mit Uhrzeit', $ld['endDate'], date_create_immutable( '2026-10-24 22:00:00', wp_timezone() )->format( 'c' ) );
	ec_check( 'location Place + PostalAddress', $ld['location'], array( '@type' => 'Place', 'name' => 'Kreiskulturraum', 'address' => array( '@type' => 'PostalAddress', 'streetAddress' => 'Siechenanger 1', 'addressLocality' => 'Kronach', 'addressCountry' => 'DE' ) ) );
	ec_check( 'url = Detailseite', $ld['url'], home_url( '/eventcrawler-veranstaltung/ninotschka-2026-10-24/' ) );
	ec_check( 'Gecrawlter Termin: keine description (Freigabe A100)', isset( $ld['description'] ), false );
	ec_check( 'Manueller Termin: description', Eventcrawler_Seo::json_ld( array_merge( $e, array( 'source' => 'manual' ) ) )['description'], 'Komödie' );
	$no_time = Eventcrawler_Seo::json_ld( array_merge( $e, array( 'start_time' => null, 'end_date' => null, 'end_time' => null ) ) );
	ec_check( 'startDate ohne Uhrzeit = nur Datum (keine 00:00)', $no_time['startDate'], '2026-10-24' );
	ec_check( 'ohne Ende: kein endDate', isset( $no_time['endDate'] ), false );
	ec_check( 'ganztägig mehrtägig: Datum ohne Zeit', array_intersect_key( Eventcrawler_Seo::json_ld( array_merge( $e, array( 'all_day' => 1, 'end_date' => '2026-10-26' ) ) ), array( 'startDate' => 1, 'endDate' => 1 ) ), array( 'startDate' => '2026-10-24', 'endDate' => '2026-10-26' ) );
	ec_check( 'fehlende Adresse: keine streetAddress', isset( Eventcrawler_Seo::json_ld( array_merge( $e, array( 'venue_address' => null ) ) )['location']['address']['streetAddress'] ), false );
	ec_check( 'Kein eventStatus, keine offers, kein image', array_intersect( array_keys( $ld ), array( 'eventStatus', 'offers', 'image', 'organizer' ) ), array() );
	$xss = Eventcrawler_Seo::json_ld_string( array_merge( $e, array( 'title' => '</script><script>alert(1)</script>' ) ) );
	ec_check( 'JSON-LD: </script> maskiert', false === stripos( $xss, '</script>' ) && false !== strpos( $xss, '\u003C/script\u003E' ), true );
	ec_check( 'JSON-LD gültiges JSON', is_array( json_decode( $xss, true ) ), true );

	echo "Indexierbarkeit, vergangene Termine, Admin-Hinweise\n";
	ec_check( 'Mit Ort: indexierbar', Eventcrawler_Seo::indexable( $e ), true );
	ec_check( 'Nur Adresse: indexierbar', Eventcrawler_Seo::indexable( array_merge( $e, array( 'venue_name' => '' ) ) ), true );
	ec_check( 'Ohne Ort und Adresse: nicht indexierbar', Eventcrawler_Seo::indexable( array_merge( $e, array( 'venue_name' => null, 'venue_address' => null ) ) ), false );
	ec_check( 'Vergangen', Eventcrawler_Seo::is_past( array( 'start_date' => $day( '-1' ), 'end_date' => null ) ), true );
	ec_check( 'Mehrtägig bis heute: nicht vergangen', Eventcrawler_Seo::is_past( array( 'start_date' => $day( '-3' ), 'end_date' => $today ) ), false );
	$checks = wp_list_pluck( Eventcrawler_Seo::checks( array_merge( $e, array( 'venue_address' => null ) ) ), 'ok', 'label' );
	ec_check( 'Admin-Hinweise: Adresse fehlt (faktisch, keine Punktzahl)', isset( $checks['Adresse fehlt'] ) && false === $checks['Adresse fehlt'] && isset( $checks['Detailseite aktiv'] ), true );

	echo "Seiteninhalt\n";
	$html = Eventcrawler_Seo::render_content( array_merge( $e, array( 'title' => '<script>alert(1)</script>Konzert', 'start_date' => $day( '-2' ), 'end_date' => null, 'event_url' => 'javascript:alert(1)' ) ) );
	ec_check( 'Titel escaped in H1', false !== strpos( $html, '<h1 class="eventcrawler-detail__title">&lt;script&gt;alert(1)&lt;/script&gt;Konzert</h1>' ), true );
	ec_check( 'Vergangen: Hinweis', false !== strpos( $html, 'Diese Veranstaltung hat bereits stattgefunden.' ), true );
	ec_check( 'javascript:-Link nicht ausgegeben', false === strpos( $html, 'javascript:' ) && false === strpos( $html, 'Zur Veranstaltungsseite' ), true );
	$html = Eventcrawler_Seo::render_content( $e );
	ec_check( 'Original-Link mit target/rel', false !== strpos( $html, 'href="https://example.org/n" target="_blank" rel="noopener noreferrer">Zur Veranstaltungsseite' ), true );
	ec_check( 'Datum, Uhrzeit, Ort, Adresse, Stadt', false !== strpos( $html, 'Samstag, 24. Oktober 2026' ) && false !== strpos( $html, '20:00–22:00 Uhr' ) && false !== strpos( $html, 'Kreiskulturraum' ) && false !== strpos( $html, 'Siechenanger 1' ) && false !== strpos( $html, 'Kronach' ), true );
	ec_check( 'Gecrawlte Beschreibung nicht öffentlich', false === strpos( $html, 'Komödie' ), true );
	ec_check( 'Keine technischen Felder', 1 === preg_match( '/external_id|event_key|content_hash|source_url|last_seen/', $html ), false );

	echo "Sitemap-Auswahl\n";
	$past_in  = ec_seo_fixture( array( 'title' => 'Sitemap vergangen 30', 'start_date' => $day( '-30' ) ) );
	$past_out = ec_seo_fixture( array( 'title' => 'Sitemap vergangen 90', 'start_date' => $day( '-90' ) ) );
	$no_venue = ec_seo_fixture( array( 'title' => 'Sitemap ohne Ort', 'start_date' => $day( '+5' ), 'venue_name' => null, 'venue_address' => null ) );
	$future   = ec_seo_fixture( array( 'title' => 'Sitemap Zukunft', 'start_date' => $day( '+5' ) ) );
	$slugs    = wp_list_pluck( $repository->get_sitemap_rows( Eventcrawler_Seo::sitemap_from(), 100000, 1 ), 'slug' );
	ec_check( 'Zukünftige Termine enthalten', in_array( $future['slug'], $slugs, true ), true );
	ec_check( 'Vergangene der letzten 60 Tage enthalten', in_array( $past_in['slug'], $slugs, true ), true );
	ec_check( 'Älter als 60 Tage nicht enthalten', in_array( $past_out['slug'], $slugs, true ), false );
	ec_check( 'Nicht indexierbare nicht enthalten', in_array( $no_venue['slug'], $slugs, true ), false );
	ec_check( 'Anzahl = Zählung', count( $slugs ), $repository->count_sitemap_rows( Eventcrawler_Seo::sitemap_from() ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	if ( null === $settings_before ) {
		delete_option( Eventcrawler_Settings::OPTION );
	} else {
		update_option( Eventcrawler_Settings::OPTION, $settings_before );
	}
	Eventcrawler_Settings::flush_cache();
}

/* ------------------------------------------------------------------ Teil 2: HTTP */

$probe = wp_remote_get( home_url( '/' ), array( 'timeout' => 10 ) );
if ( is_wp_error( $probe ) ) {
	echo "\nTeil 2 übersprungen: " . home_url( '/' ) . " nicht erreichbar.\n";
	ec_finish();
}

echo "\nTeil 2: HTTP gegen " . home_url( '/' ) . "\n";

/**
 * @param string $url URL.
 * @return array code, body.
 */
function ec_get( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0 ) );
	return is_wp_error( $r ) ? array( 'code' => 0, 'body' => $r->get_error_message() ) : array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'body' => wp_remote_retrieve_body( $r ) );
}

/**
 * @param string $html Seite.
 * @return string[] robots-Direktiven (ohne Reihenfolge).
 */
function ec_robots( $html ) {
	if ( ! preg_match( "#<meta name='robots' content='([^']*)'#", $html, $m ) ) {
		return array();
	}
	$list = array_map( 'trim', explode( ',', $m[1] ) );
	sort( $list );
	return array_values( array_intersect( $list, array( 'index', 'noindex', 'follow', 'nofollow' ) ) );
}

/**
 * @param string $html Seite.
 * @return array|null JSON-LD.
 */
function ec_ld( $html ) {
	return preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m ) ? json_decode( $m[1], true ) : null;
}

$created = array();
$page_id = 0;
try {
	update_option( 'blog_public', '1' );
	update_option( Eventcrawler_Settings::OPTION, array_merge( Eventcrawler_Settings::DEFAULTS, array( 'seo_detail_pages' => 1 ) ) );
	Eventcrawler_Settings::flush_cache();
	ec_get( home_url( '/' ) ); // Rewrite-Regeln werden beim nächsten Request erzeugt

	$f_future = ec_seo_fixture( array( 'title' => 'HTTP SEO Zukunft <b>&</b>', 'start_date' => $day( '+10' ), 'start_time' => '19:30:00', 'end_date' => $day( '+10' ), 'end_time' => '21:00:00', 'event_url' => 'https://example.org/seo' ) );
	$f_past   = ec_seo_fixture( array( 'title' => 'HTTP SEO Vergangen', 'start_date' => $day( '-20' ) ) );
	$f_old    = ec_seo_fixture( array( 'title' => 'HTTP SEO Alt', 'start_date' => $day( '-200' ) ) );
	$f_noaddr = ec_seo_fixture( array( 'title' => 'HTTP SEO Ohne Adresse', 'start_date' => $day( '+11' ), 'venue_address' => null ) );
	$f_noloc  = ec_seo_fixture( array( 'title' => 'HTTP SEO Ohne Ort', 'start_date' => $day( '+12' ), 'venue_name' => null, 'venue_address' => null ) );
	$f_xss    = ec_seo_fixture( array( 'title' => '"><script>alert(1)</script>', 'start_date' => $day( '+13' ) ) );
	$created  = array( $f_future['id'], $f_past['id'], $f_old['id'], $f_noaddr['id'], $f_noloc['id'], $f_xss['id'] );
	$page_id  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SEO Testliste', 'post_name' => 'ec-seo-testliste', 'post_content' => '[eventcrawler_events]' ) );

	echo "Detailseite\n";
	$url = Eventcrawler_Seo::url( $f_future );
	$r   = ec_get( $url );
	ec_check( 'Detailseite 200', $r['code'], 200 );
	ec_check( 'H1 = Titel (escaped)', false !== strpos( $r['body'], '<h1 class="eventcrawler-detail__title">HTTP SEO Zukunft &lt;b&gt;&amp;&lt;/b&gt;</h1>' ) || false !== strpos( $r['body'], '<h1 class="eventcrawler-detail__title">HTTP SEO Zukunft &amp;</h1>' ), true );
	ec_check( '<title> nach Muster', 1 === preg_match( '#<title>HTTP SEO Zukunft .* am \d\d\.\d\d\.\d{4} in Hof</title>#', $r['body'] ), true );
	ec_check( 'Self-Canonical (genau einer)', array( substr_count( $r['body'], 'rel="canonical"' ) + substr_count( $r['body'], "rel='canonical'" ), false !== strpos( $r['body'], '<link rel="canonical" href="' . esc_url( $url ) . '" />' ) ), array( 1, true ) );
	ec_check( 'robots index, follow', ec_robots( $r['body'] ), array( 'follow', 'index' ) );
	ec_check( '<title> escaped (kein rohes <b>)', false === strpos( $r['body'], '<title>HTTP SEO Zukunft <b>' ), true );
	ec_check( 'Meta-Description', 1 === preg_match( '#<meta name="description" content="HTTP SEO Zukunft [^"]* um 19:30–21:00 Uhr in Hof\. Ort: Stadthalle, Hauptstraße 1\." />#', $r['body'] ), true );
	$ld = ec_ld( $r['body'] );
	ec_check( 'JSON-LD vorhanden, startDate mit Zeit', is_array( $ld ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT19:30:00[+-]\d\d:\d\d$/', $ld['startDate'] ), true );
	ec_check( 'JSON-LD endDate', is_array( $ld ) && 1 === preg_match( '/T21:00:00/', $ld['endDate'] ), true );
	ec_check( 'Original-Link', false !== strpos( $r['body'], 'href="https://example.org/seo" target="_blank" rel="noopener noreferrer"' ), true );
	ec_check( 'Keine interne Daten (dev-test, event_key, content_hash)', 1 === preg_match( '/dev-test|event_key|content_hash|' . preg_quote( $f_future['content_hash'], '/' ) . '/', $r['body'] ), false );

	$r = ec_get( Eventcrawler_Seo::url( $f_past ) );
	ec_check( 'Vergangener Termin: 200 mit Hinweis', array( $r['code'], false !== strpos( $r['body'], 'Diese Veranstaltung hat bereits stattgefunden.' ) ), array( 200, true ) );
	$r = ec_get( Eventcrawler_Seo::url( $f_noaddr ) );
	$ld = ec_ld( $r['body'] );
	ec_check( 'Fehlende Adresse: indexierbar, JSON-LD ohne streetAddress', array( $r['code'], is_array( $ld ) && ! isset( $ld['location']['address']['streetAddress'] ) ), array( 200, true ) );
	$r = ec_get( Eventcrawler_Seo::url( $f_noloc ) );
	ec_check( 'Ohne Ort und Adresse: 200, noindex, kein JSON-LD', array( $r['code'], ec_robots( $r['body'] ), null === ec_ld( $r['body'] ) ), array( 200, array( 'follow', 'noindex' ), true ) );
	$r = ec_get( Eventcrawler_Seo::url( $f_xss ) );
	ec_check( 'XSS im Titel: escaped in H1, title und JSON-LD', false === strpos( $r['body'], '<script>alert(1)</script>' ) && null !== ec_ld( $r['body'] ), true );

	echo "404\n";
	$r = ec_get( home_url( '/eventcrawler-veranstaltung/gibt-es-nicht-2020-01-01/' ) );
	ec_check( 'Unbekannter Slug: echte 404', $r['code'], 404 );
	ec_check( '404 ohne Eventcrawler-Canonical/JSON-LD', false === strpos( $r['body'], 'eventcrawler-detail' ) && null === ec_ld( $r['body'] ), true );
	ec_check( 'Ungültige Zeichen im Slug: 404', ec_get( home_url( '/eventcrawler-veranstaltung/%3Cscript%3E/' ) )['code'], 404 );

	echo "Sitemap\n";
	$r = ec_get( home_url( '/wp-sitemap.xml' ) );
	ec_check( 'Sitemap-Index enthält eventcrawler', array( $r['code'], false !== strpos( $r['body'], 'wp-sitemap-eventcrawler-1.xml' ) ), array( 200, true ) );
	$r = ec_get( home_url( '/wp-sitemap-eventcrawler-1.xml' ) );
	ec_check( 'Eventcrawler-Sitemap 200', $r['code'], 200 );
	ec_check( 'Zukunft enthalten (mit lastmod)', false !== strpos( $r['body'], '<loc>' . esc_url( Eventcrawler_Seo::url( $f_future ) ) . '</loc><lastmod>' ), true );
	ec_check( 'Vergangen (20 Tage) enthalten', false !== strpos( $r['body'], esc_url( Eventcrawler_Seo::url( $f_past ) ) ), true );
	ec_check( 'Alt (200 Tage) nicht enthalten', false === strpos( $r['body'], esc_url( Eventcrawler_Seo::url( $f_old ) ) ), true );
	ec_check( 'Ohne Ort nicht enthalten', false === strpos( $r['body'], esc_url( Eventcrawler_Seo::url( $f_noloc ) ) ), true );
	ec_check( 'Keine Filter-/Query-URLs', 1 === preg_match( '#<loc>[^<]*\?[^<]*</loc>#', $r['body'] ), false );
	$pages = ec_get( home_url( '/wp-sitemap-posts-page-1.xml' ) );
	ec_check( 'Listenseite nur ohne Parameter in der Seiten-Sitemap', false !== strpos( $pages['body'], '/ec-seo-testliste/</loc>' ) && false === strpos( $pages['body'], 'ec_city' ), true );

	echo "Filter-URLs der Liste\n";
	$list = get_permalink( $page_id );
	$r    = ec_get( $list );
	ec_check( 'Listenseite ohne Parameter: kein noindex durch den Eventcrawler', in_array( 'noindex', ec_robots( $r['body'] ), true ), false );
	foreach ( array( 'ec_city=Hof', 'date=' . $today, 'ec_search=Konzert', 'ec_page=2', 'ec_month=' . substr( $today, 0, 7 ) ) as $query ) {
		$r = ec_get( $list . '?' . $query );
		ec_check( "?{$query}: noindex, follow", ec_robots( $r['body'] ), array( 'follow', 'noindex' ) );
		ec_check( "?{$query}: Canonical = Seite ohne Parameter", false !== strpos( $r['body'], '<link rel="canonical" href="' . $list . '" />' ), true );
	}

	echo "Parallelbetrieb WPEM\n";
	$wpem_slug = $wpdb->get_var( "SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'wpem_event' AND post_status = 'publish' AND post_name <> '' LIMIT 1" );
	if ( $wpem_slug && post_type_exists( 'wpem_event' ) ) {
		$r = ec_get( home_url( '/veranstaltung/' . $wpem_slug . '/' ) );
		ec_check( 'WPEM-Detailseite unverändert erreichbar (200, kein Redirect)', $r['code'], 200 );
		ec_check( 'WPEM-Seite ohne Eventcrawler-Detail/JSON-LD', false === strpos( $r['body'], 'eventcrawler-detail' ) && null === ec_ld( $r['body'] ), true );
		ec_check( 'WPEM-Seite: Canonical zeigt auf sich selbst', false !== strpos( $r['body'], '/veranstaltung/' . $wpem_slug . '/' . '" />' ), true );
	} else {
		echo "  (WPEM nicht aktiv oder ohne veröffentlichte Termine – übersprungen)\n";
	}

	echo "Detailseiten ausgeschaltet\n";
	update_option( Eventcrawler_Settings::OPTION, array_merge( Eventcrawler_Settings::DEFAULTS, array( 'seo_detail_pages' => 0 ) ) );
	ec_get( home_url( '/' ) );
	ec_check( 'Aus: Detail-URL = 404', ec_get( home_url( '/eventcrawler-veranstaltung/' . $f_future['slug'] . '/' ) )['code'], 404 );
	ec_check( 'Aus: keine Eventcrawler-Sitemap', false === strpos( ec_get( home_url( '/wp-sitemap.xml' ) )['body'], 'wp-sitemap-eventcrawler' ), true );
	ec_check( 'Aus: Liste ohne Detail-Links', false === strpos( ec_get( $list )['body'], '/eventcrawler-veranstaltung/' ), true );
} finally {
	if ( $created ) {
		$repository->delete_many( $created );
	}
	if ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
	update_option( 'blog_public', $public_before );
	if ( null === $settings_before ) {
		delete_option( Eventcrawler_Settings::OPTION );
	} else {
		update_option( Eventcrawler_Settings::OPTION, $settings_before );
	}
	ec_get( home_url( '/' ) ); // Rewrite-Zustand wie vorher
}
ec_check( 'Testdaten entfernt', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", 'dev-test' ) ), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
ec_finish();
