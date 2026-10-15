<?php
/**
 * Source Adapter: Concertbüro Franken.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest die Konzerte des Concertbüro Franken (Contao) aus den Monatsansichten „konzerte-nach-monat.html?month=JJJJMM“.
 * Ein Listeneintrag = ein Termin mit eigener Detailseite. Detailseiten werden nicht abgerufen: Beginn, Stadt und Ort der
 * Liste entsprechen der Detailseite (Stichprobe 2026-10-15). Analyse und Felder: docs/04-quellen.md, Abschnitt 15.
 *
 * Kundenvorgabe (A93): Übernommen werden nur Termine, deren Stadt exakt Nürnberg oder Fürth ist. Alle anderen Städte
 * werden hier ausgeschlossen und nicht an den Crawler geliefert (Zählung über get_geo_stats()). Keine Region, keine
 * Entfernung, keine Ortsteil- oder Ähnlichkeitslogik.
 *
 * Monatsansicht, je Termin div.event:
 *   time[datetime]                       "2026-10-19T20:00:00+02:00" (Uhrzeit = Beginn); span "20:00–23:59" (23:59 = Vorlage)
 *   h3.vee-listing-headline a[href]      Titel, Detailseite /konzert-details/{alias}.html
 *   zweites p.info                       "Nürnberg • Hirsch" (Stadt • Ort)
 *   div.eventListProperties              Badges ("AUSVERKAUFT", "ABGESAGT", …), nicht gespeichert
 * Monatsnavigation: a[href*="konzerte-nach-monat.html?month=JJJJMM"].
 */
class Eventcrawler_Source_Concertbuero_Franken extends Eventcrawler_Source {

	const BASE       = 'https://www.concertbuero-franken.de';
	const MONTH_URL  = 'https://www.concertbuero-franken.de/konzerte-nach-monat.html?month=';
	const MAX_MONTHS = 24; // Schutz; 2026-10-15: 19 Monate mit Terminen.
	const TIMEZONE   = 'Europe/Berlin';

	/**
	 * Erlaubte Städte (Kundenvorgabe): normalisierter Wert => gespeicherter Wert. Nur in der Quelle beobachtete Schreibweise.
	 */
	const CITIES = array(
		'nürnberg' => 'Nürnberg',
		'fürth'    => 'Fürth',
	);

	/**
	 * @var array Zählung des letzten parse(): total, Städte (Stadt => Anzahl), excluded, unclear.
	 */
	private $geo = array();

	public function get_slug() {
		return 'concertbuero-franken';
	}

	public function get_name() {
		return 'Concertbüro Franken';
	}

	public function get_url() {
		return self::MONTH_URL;
	}

	/**
	 * Jede Detail-URL bezeichnet genau einen Termin (404 Termine = 404 URLs, 2026-10-15).
	 */
	public function has_unique_detail_urls() {
		return true;
	}

	/**
	 * Zweite Sicherung der Kundenvorgabe über die zentrale Ortsregel (docs/05-filter.md Abschnitt 5). Der Adapter liefert
	 * ohnehin nur Nürnberg und Fürth.
	 */
	public function get_allowed_cities() {
		return array_values( self::CITIES );
	}

	/**
	 * @return array Geo-Statistik des letzten parse().
	 */
	public function get_geo_stats() {
		return $this->geo;
	}

	public function fetch_events() {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( self::TIMEZONE ) );
		$first = $this->fetch_html( self::MONTH_URL . $today->format( 'Ym' ) );
		if ( is_wp_error( $first ) ) {
			return $first;
		}
		$pages = array( $first );
		foreach ( $this->parse_months( $first, $today->format( 'Ym' ) ) as $month ) {
			sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
			$html = $this->fetch_html( self::MONTH_URL . $month );
			if ( is_wp_error( $html ) ) {
				return $html; // Unvollständige Daten nicht verarbeiten.
			}
			$pages[] = $html;
		}
		return $this->parse( $pages, $today->format( 'Y-m-d' ) );
	}

	/**
	 * Monate aus der Monatsnavigation, nach dem aktuellen Monat, aufsteigend, höchstens MAX_MONTHS - 1.
	 *
	 * @param string $html    Erste Monatsseite.
	 * @param string $current Aktueller Monat JJJJMM.
	 * @return string[]
	 */
	public function parse_months( $html, $current ) {
		$months = array();
		$xpath  = $this->create_xpath( $html );
		foreach ( $xpath->query( "//a[contains(@href, 'konzerte-nach-monat.html?month=')]" ) as $a ) {
			if ( preg_match( '/[?&]month=(\d{6})(?:&|$)/', $a->getAttribute( 'href' ), $m ) && $m[1] > $current ) {
				$months[ $m[1] ] = true;
			}
		}
		$months = array_keys( $months );
		sort( $months );
		return array_slice( array_map( 'strval', $months ), 0, self::MAX_MONTHS - 1 );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string[] $pages HTML der Monatsseiten.
	 * @param string   $from  Erster Tag (Y-m-d); frühere Termine werden nicht übernommen.
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages, $from ) {
		$this->geo = array( 'total' => 0, 'cities' => array(), 'excluded' => 0, 'unclear' => 0 );
		$events    = array();
		$seen      = array();
		$found     = 0;

		foreach ( $pages as $html ) {
			$xpath = $this->create_xpath( $html );
			$items = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' event ')][.//h3[contains(concat(' ', normalize-space(@class), ' '), ' vee-listing-headline ')]]" );
			$found += $items->length;
			foreach ( $items as $item ) {
				$event = $this->parse_entry( $xpath, $item );
				if ( '' !== $event['start_date'] && $event['start_date'] < $from ) {
					continue;
				}
				if ( null !== $event['external_id'] ) {
					if ( isset( $seen[ $event['external_id'] ] ) ) {
						continue; // Gleicher Termin auf zwei Monatsseiten nur einmal.
					}
					$seen[ $event['external_id'] ] = true;
				}

				$this->geo['total']++;
				$city = $event['_city'];
				unset( $event['_city'] );
				if ( '' === $city ) {
					$this->geo['unclear']++;
					if ( empty( $event['_error'] ) ) {
						$event['_error'] = 'Stadt unklar (keine Angabe „Stadt • Ort“)';
					}
					$events[] = $event; // Als fehlerhaft zählen, nie als Nürnberg.
					continue;
				}
				$this->geo['cities'][ $city ] = ( isset( $this->geo['cities'][ $city ] ) ? $this->geo['cities'][ $city ] : 0 ) + 1;
				$key = mb_strtolower( $city, 'UTF-8' );
				if ( ! isset( self::CITIES[ $key ] ) ) {
					$this->geo['excluded']++;
					continue; // A93: andere Stadt.
				}
				$event['city'] = self::CITIES[ $key ];
				$events[]      = $event;
			}
		}

		// Keine Termine in der Liste = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( 0 === $found ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Konzerte gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}
		return $events;
	}

	/**
	 * @param DOMXPath   $xpath XPath.
	 * @param DOMElement $item  div.event.
	 * @return array Termin mit internem Feld _city (Rohwert, normalisiert).
	 */
	private function parse_entry( DOMXPath $xpath, DOMElement $item ) {
		$link  = $this->first( $xpath, ".//h3[contains(concat(' ', normalize-space(@class), ' '), ' vee-listing-headline ')]/a", $item );
		$href  = $link ? trim( $link->getAttribute( 'href' ) ) : '';
		$url   = '' === $href ? '' : $this->absolute_url( 0 === strpos( $href, 'http' ) ? $href : '/' . ltrim( $href, '/' ) );
		$alias = preg_match( '#^https://www\.concertbuero-franken\.de/konzert-details/([a-z0-9-]+)\.html$#', $url, $m ) ? $m[1] : null;

		$infos = $xpath->query( "./descendant::p[contains(concat(' ', normalize-space(@class), ' '), ' info ')]", $item );
		$place = $infos->length > 1 ? $this->clean_text( $infos->item( 1 ) ) : '';
		$parts = explode( ' • ', $place, 2 );

		$event = array(
			'title'         => $this->clean_text( $link ),
			'description'   => '', // Die Quelle hat keinen Kurztext; der Langtext der Detailseite wird nicht übernommen.
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null, // "–23:59" ist der Vorgabewert der Vorlage (403 von 403 Terminen), kein echtes Ende.
			'end_time'      => null,
			'all_day'       => false,
			'venue_name'    => 2 === count( $parts ) ? trim( $parts[1] ) : '',
			'venue_address' => '', // Die Quelle hat keine Straße.
			'city'          => '',
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => $alias,
			'_city'         => 2 === count( $parts ) ? trim( $parts[0] ) : '',
		);

		if ( null === $alias ) {
			$event['_error'] = 'Detail-Link fehlt oder hat unbekanntes Format';
			return $event;
		}

		$time = $this->first( $xpath, './/time[@datetime]', $item );
		$dt   = $time ? trim( $time->getAttribute( 'datetime' ) ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):\d{2}[+-]\d{2}:\d{2}$/', $dt, $d ) || ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) || (int) $d[4] > 23 || (int) $d[5] > 59 ) {
			$event['_error'] = 'Datum nicht lesbar: ' . $dt;
			return $event;
		}
		$event['start_date'] = $d[1] . '-' . $d[2] . '-' . $d[3];
		$event['start_time'] = $d[4] . ':' . $d[5]; // Ortszeit; laut Detailseite der Beginn, nicht der Einlass.

		if ( '' !== $event['_city'] && '' === $event['venue_name'] ) {
			$event['_error'] = 'Veranstaltungsort fehlt';
		}
		return $event;
	}
}
