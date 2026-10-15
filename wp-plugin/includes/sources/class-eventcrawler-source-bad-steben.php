<?php
/**
 * Source Adapter: Bad Steben.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Der Veranstaltungskalender auf bad-steben.de bettet die Plattform eT4/destination.one (pages.et4.de) per iframe ein.
 * Gelesen wird deren serverseitig gerenderte Trefferliste, seitenweise über die Pfadsegmente /Offset:n/Limit:n.
 * Analyse und Selektoren: docs/04-quellen.md, Abschnitt 1.
 *
 * Pro Termin ein Container:
 *   div.media[data-guid="e_…"]                      ID der Serie (nicht des Termins)
 *     h4.media-heading                              Titel
 *     p.result_city > span, span                    Veranstaltungsort, Stadt
 *     div.date-interval[data-start][data-end]       Beginn/Ende ISO 8601; sichtbarer Text "Sa, 26. Sep 19:30 - 20:15"
 *     h4.media-heading > a[href]                    Detailseite der Serie (/detail/Event/e_…/slug?From=…)
 */
class Eventcrawler_Source_Bad_Steben extends Eventcrawler_Source {

	const BASE_URL     = 'https://pages.et4.de/de/bad-steben/default/search/Event/view:gallery/sort:chronological/calView:agendaWeek';
	const PAGE_LIMIT   = 250;
	const MAX_PAGES    = 20;
	const PAGE_TIMEOUT = 30;

	public function get_slug() {
		return 'bad-steben';
	}

	public function get_name() {
		return 'Bad Steben';
	}

	/**
	 * Tatsächlich abgerufene Liste (Standardzeitraum der Plattform: die nächsten 3 Monate).
	 */
	public function get_url() {
		return self::BASE_URL;
	}

	/**
	 * Die Detail-URL bezeichnet die Serie, nicht den Termin – daher kein Matching über die URL.
	 * Identifikation über external_id (Priorität 1), siehe build_external_ids().
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$pages = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$html = $this->fetch_html( self::BASE_URL . '/Offset:' . ( $page * self::PAGE_LIMIT ) . '/Limit:' . self::PAGE_LIMIT, self::PAGE_TIMEOUT );
			if ( is_wp_error( $html ) ) {
				return $html; // Unvollständige Daten nicht verarbeiten.
			}
			$pages[] = $html;

			if ( substr_count( $html, 'data-guid=' ) < self::PAGE_LIMIT ) {
				break;
			}
			sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
		}

		return $this->parse( $pages );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string[] $pages HTML der Ergebnisseiten.
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages ) {
		$events = array();
		$seen   = array();

		foreach ( $pages as $html ) {
			$xpath      = $this->create_xpath( $html );
			$containers = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' media ') and @data-guid]" );
			foreach ( $containers as $container ) {
				$event = $this->parse_event( $xpath, $container );
				// Seitenübergreifende Doppelungen (gleiche Serie, gleicher Beginn) nur einmal liefern.
				$key = $event['_guid'] . '|' . $event['start_date'] . ' ' . $event['start_time'];
				if ( empty( $event['_error'] ) && isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$events[]     = $event;
			}
		}

		if ( empty( $events ) ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}

		return $this->build_external_ids( $events );
	}

	/**
	 * @param DOMXPath   $xpath     XPath.
	 * @param DOMElement $container Termin-Container.
	 * @return array
	 */
	private function parse_event( DOMXPath $xpath, DOMElement $container ) {
		$link       = $this->first( $xpath, ".//h4[contains(concat(' ', normalize-space(@class), ' '), ' media-heading ')]/a", $container );
		$detail_url = $link ? $this->absolute_url( $link->getAttribute( 'href' ) ) : ''; // ohne ?From=… (ändert sich täglich)

		$spans = array();
		foreach ( $xpath->query( ".//p[contains(concat(' ', normalize-space(@class), ' '), ' result_city ')]/span", $container ) as $span ) {
			$spans[] = $this->clean_text( $span );
		}
		$venue = isset( $spans[0] ) ? $spans[0] : '';

		$event = array(
			'title'       => $this->clean_text( $this->first( $xpath, ".//h4[contains(concat(' ', normalize-space(@class), ' '), ' media-heading ')]", $container ) ),
			'description' => '', // Die Trefferliste enthält keine Beschreibung.
			'start_date'  => '',
			'start_time'  => null,
			'end_date'    => null,
			'end_time'    => null,
			'all_day'     => false, // Die Quelle kennzeichnet keine ganztägigen Termine (geprüft 2026-09-24).
			'venue_name'  => $venue,
			'city'        => $this->normalize_city( isset( $spans[1] ) ? $spans[1] : '', $venue ),
			'event_url'   => $detail_url,
			'source_url'  => $detail_url,
			'external_id' => null, // wird in build_external_ids() gesetzt
			'_guid'       => $container->getAttribute( 'data-guid' ),
		);

		$interval = $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' date-interval ')]", $container );
		if ( ! $interval || '' === $event['_guid'] ) {
			$event['_error'] = 'Termin ohne data-guid oder date-interval';
			return $event;
		}

		// Beginn: strukturiertes Attribut, lokale Zeit mit Offset, z. B. "2026-09-26T19:30:00+02".
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $interval->getAttribute( 'data-start' ), $start ) ) {
			$event['_error'] = 'data-start ungültig: ' . $interval->getAttribute( 'data-start' );
			return $event;
		}
		$event['start_date'] = $start[1];
		$event['start_time'] = $start[2];

		// Sichtbarer Text muss dieselbe Startzeit zeigen. Ende nur, wenn der Text einen Bereich zeigt:
		// ohne sichtbares Ende setzt die Quelle data-end auf 23:59 (Platzhalter, keine echte Endzeit).
		$text = $this->clean_text( $interval );
		if ( ! preg_match( '/(\d{1,2}):(\d{2})(?:\s*-\s*(\d{1,2}):(\d{2}))?\s*$/u', $text, $m ) || sprintf( '%02d:%02d', $m[1], $m[2] ) !== $start[2] ) {
			$event['_error'] = 'Zeitangabe passt nicht zu data-start: ' . $text;
			return $event;
		}
		if ( ! empty( $m[3] ) ) {
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $interval->getAttribute( 'data-end' ), $end )
				|| sprintf( '%02d:%02d', $m[3], $m[4] ) !== $end[2] ) {
				$event['_error'] = 'Endzeit passt nicht zu data-end: ' . $text;
				return $event;
			}
			$event['end_date'] = $end[1];
			$event['end_time'] = $end[2];
		}

		return $event;
	}

	/**
	 * Stadt aus dem zweiten Feld; Zusätze in Klammern entfallen ("Hof (95028 Hof/Saale)" -> "Hof",
	 * "Bad Steben (Bobengrün)" -> "Bad Steben"). Fehlt sie, nur aus einer Postanschrift im Ort ("…, 95138 Bad Steben").
	 * Sonst leer – der Crawler überspringt den Termin, statt einen Ort zu raten.
	 *
	 * @param string $city  Stadtfeld der Quelle.
	 * @param string $venue Ortsfeld der Quelle.
	 * @return string
	 */
	private function normalize_city( $city, $venue ) {
		$city = trim( preg_replace( '/\s*\(.*\)\s*$/u', '', $city ) );
		if ( '' === $city && preg_match( '/,\s*\d{5}\s+([^,]+)$/u', $venue, $m ) ) {
			$city = trim( $m[1] );
		}
		return $city;
	}

	/**
	 * external_id je Termin: Serien-ID + Datum + laufende Nummer der Serie an diesem Tag (nach Beginn sortiert),
	 * z. B. "e_100052347_2026-09-25_2". Stabil bei geänderter Uhrzeit am selben Tag; eine Datumsverschiebung
	 * ergibt einen neuen Termin (docs/04-quellen.md).
	 *
	 * @param array[] $events Termine.
	 * @return array[]
	 */
	private function build_external_ids( array $events ) {
		$groups = array();
		foreach ( $events as $i => $event ) {
			if ( empty( $event['_error'] ) ) {
				$groups[ $event['_guid'] . '_' . $event['start_date'] ][] = $i;
			}
		}

		foreach ( $groups as $prefix => $indexes ) {
			usort(
				$indexes,
				function ( $a, $b ) use ( $events ) {
					return strcmp( $events[ $a ]['start_time'], $events[ $b ]['start_time'] ) ?: $a - $b;
				}
			);
			foreach ( $indexes as $n => $i ) {
				$events[ $i ]['external_id'] = $prefix . '_' . ( $n + 1 );
			}
		}

		foreach ( $events as $i => $event ) {
			unset( $events[ $i ]['_guid'] );
		}

		return array_values( $events );
	}
}
