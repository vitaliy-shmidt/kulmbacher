<?php
/**
 * Source Adapter: Coburg (Veranstaltungsportal der Stadt Coburg).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest die Listenansicht von veranstaltungen.coburg.de (TYPO3, Extension "events2").
 * Die Liste zeigt je Seite ca. 3 Tage; weitere Seiten nur über den Link a.nextDay (offsetdate + cHash),
 * dem gefolgt wird, bis das Zeitfenster erreicht ist. Analyse und Selektoren: docs/04-quellen.md, Abschnitt 2.
 *
 * Aufbau:
 *   span.datumaufecke                         Tagesüberschrift ohne Jahr ("Donnerstag, 24. September")
 *   div.listviewItem                          ein Termin
 *     div.time-blue                           "11:00 Uhr" oder "ganztägig"
 *     div.event-location small (mit strong)   "Veranstaltungsort", Name (a), [Straße] (-> venue_address), "PLZ Stadt"
 *     h3 > a[href]                            Titel, Detail-URL /details/veranstaltung/{serie}/datum/{termin}.html
 *     div.ym-gbox-right > p                   Untertitel (kurz)
 */
class Eventcrawler_Source_Coburg extends Eventcrawler_Source {

	const START_URL   = 'https://veranstaltungen.coburg.de/alle-veranstaltungen.html';
	const WINDOW_DAYS = 42;  // Zeitfenster ab heute (ca. 15 Seiten).
	const MAX_PAGES   = 25;  // Schutz gegen Endlosschleifen.
	const TIMEZONE    = 'Europe/Berlin';

	public function get_slug() {
		return 'coburg';
	}

	public function get_name() {
		return 'Coburg';
	}

	public function get_url() {
		return self::START_URL;
	}

	/**
	 * Die Detail-URL enthält eine Termin-ID, deren Stabilität bei Änderungen nicht belegt ist.
	 * Identifikation daher über external_id (Serie + Datum + Nummer), siehe build_external_ids().
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$tz        = new DateTimeZone( self::TIMEZONE );
		$reference = new DateTimeImmutable( 'today', $tz );
		$limit     = $reference->modify( '+' . self::WINDOW_DAYS . ' days' );
		$url       = self::START_URL;
		$pages     = array();

		for ( $i = 0; $i < self::MAX_PAGES && $url; $i++ ) {
			if ( $i > 0 ) {
				sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
			}
			$html = $this->fetch_html( $url );
			if ( is_wp_error( $html ) ) {
				return $html; // Unvollständige Daten nicht verarbeiten.
			}
			$pages[] = array(
				'html'      => $html,
				'reference' => $reference->format( 'Y-m-d' ),
			);

			// Nächste Seite: Link a.nextDay mit offsetdate (Unix-Zeit, Mitternacht Ortszeit).
			$url  = null;
			$links = $this->create_xpath( $html )->query( "//a[contains(concat(' ', normalize-space(@class), ' '), ' nextDay ')]" );
			$next  = ( $links && $links->length ) ? $links->item( 0 ) : null;
			if ( $next && preg_match( '/offsetdate%5D=(\d+)/', $next->getAttribute( 'href' ), $m ) ) {
				$offset = ( new DateTimeImmutable( '@' . $m[1] ) )->setTimezone( $tz );
				if ( $offset <= $limit ) {
					$url       = 'https://veranstaltungen.coburg.de' . html_entity_decode( $next->getAttribute( 'href' ) );
					$reference = $offset->setTime( 0, 0 );
				}
			}
		}

		return $this->parse( $pages );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param array[] $pages Je Seite array( 'html' => string, 'reference' => 'Y-m-d' erster möglicher Tag ).
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages ) {
		$events = array();
		$seen   = array();

		foreach ( $pages as $page ) {
			$xpath     = $this->create_xpath( $page['html'] );
			$reference = new DateTimeImmutable( $page['reference'] );
			$date      = null;
			$day_text  = '';

			$nodes = $xpath->query(
				"//span[contains(concat(' ', normalize-space(@class), ' '), ' datumaufecke ')]"
				. " | //div[contains(concat(' ', normalize-space(@class), ' '), ' listviewItem ')]"
			);
			foreach ( $nodes as $node ) {
				if ( 'span' === $node->nodeName ) {
					$day_text = $this->clean_text( $node );
					$date     = $this->resolve_german_date( $day_text, $reference );
					continue;
				}

				$event = $this->parse_event( $xpath, $node );
				if ( null === $date ) {
					$event['_error'] = 'Tagesüberschrift nicht lesbar: ' . $day_text;
				} else {
					$event['start_date'] = $date;
				}

				// Termin-ID nur einmal (Seiten überschneiden sich nicht, aber sicher ist sicher).
				if ( '' !== $event['_termin'] && isset( $seen[ $event['_termin'] ] ) ) {
					continue;
				}
				$seen[ $event['_termin'] ] = true;
				$events[]                  = $event;
			}
		}

		if ( empty( $events ) ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}

		return $this->build_external_ids( $events );
	}

	/**
	 * @param DOMXPath   $xpath XPath.
	 * @param DOMElement $item  div.listviewItem.
	 * @return array
	 */
	private function parse_event( DOMXPath $xpath, DOMElement $item ) {
		$link   = $this->first( $xpath, './/h3/a', $item );
		$url    = $link ? $this->absolute_url( $link->getAttribute( 'href' ) ) : '';
		$series = '';
		$termin = '';
		if ( preg_match( '#/details/veranstaltung/([^/]+)/datum/(\d+)\.html$#', $url, $m ) ) {
			$series = $m[1];
			$termin = $m[2];
		}

		$subtitle = $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' ym-gbox-right ')]/p", $item );

		$event = array(
			'title'       => $this->clean_text( $this->first( $xpath, './/h3', $item ) ),
			'description' => $this->clean_text( $subtitle ), // kurzer Untertitel der Liste, sonst leer
			'start_date'  => '',
			'start_time'  => null,
			'end_date'    => null, // Die Liste enthält keine Endzeit (nur die Detailseite).
			'end_time'    => null,
			'all_day'     => false,
			'venue_name'  => '',
			'venue_address' => '', // Straße aus dem Block "Veranstaltungsort", sonst leer
			'city'        => '',
			'event_url'   => $url,
			'source_url'  => $url,
			'external_id' => null, // wird in build_external_ids() gesetzt
			'_series'     => $series,
			'_termin'     => $termin,
		);

		if ( '' === $series ) {
			$event['_error'] = 'Detail-Link fehlt oder hat unbekanntes Format';
			return $event;
		}

		// Uhrzeit: "11:00 Uhr" oder "ganztägig" (ausdrücklich gekennzeichnet).
		$time = $this->clean_text( $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' time-blue ')]", $item ) );
		if ( 'ganztägig' === mb_strtolower( $time ) ) {
			$event['all_day'] = true;
		} elseif ( preg_match( '/^(\d{1,2}):(\d{2})(?:\s*-\s*(\d{1,2}):(\d{2}))?\s*Uhr$/u', $time, $t ) ) {
			$event['start_time'] = sprintf( '%02d:%02d', $t[1], $t[2] );
			if ( ! empty( $t[3] ) ) {
				$event['end_time'] = sprintf( '%02d:%02d', $t[3], $t[4] );
			}
		} elseif ( '' !== $time ) {
			$event['_error'] = 'Zeitangabe nicht lesbar: ' . $time;
			return $event;
		}

		// Veranstaltungsort: der small-Block mit der Überschrift "Veranstaltungsort".
		$location = $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' event-location ')]//small[strong]", $item );
		if ( $location ) {
			$venue = $this->first( $xpath, './a', $location );
			$lines = array();
			foreach ( $location->childNodes as $child ) {
				$text = $this->clean_text( $child );
				if ( '' !== $text && 'Veranstaltungsort' !== $text ) {
					$lines[] = $text;
				}
			}
			$event['venue_name'] = $venue ? $this->clean_text( $venue ) : ( isset( $lines[0] ) ? $lines[0] : '' );
			foreach ( $lines as $line ) {
				if ( preg_match( '/^\d{5}\s+(.+)$/u', $line, $c ) ) {
					$event['city'] = $this->normalize_city( $c[1] );
				}
			}
			// Straße: nur bei genau dem Aufbau [Ortsname (Link), Straße, "PLZ Stadt"] – sonst keine Adresse, nichts raten.
			if ( $venue && 3 === count( $lines ) && $lines[0] === $event['venue_name']
				&& ! preg_match( '/^\d{5}\s/u', $lines[1] ) && preg_match( '/^\d{5}\s+\S/u', $lines[2] ) ) {
				$event['venue_address'] = $lines[1];
			}
		}

		return $event;
	}

	/**
	 * Ortsteilzusätze entfernen ("Bad Rodach-OT Elsa" -> "Bad Rodach", "Seßlach-StT Dietersdorf" -> "Seßlach"),
	 * Abkürzung vereinheitlichen ("Neustadt b. Coburg" -> "Neustadt bei Coburg").
	 *
	 * @param string $city Stadt aus der PLZ-Zeile.
	 * @return string
	 */
	private function normalize_city( $city ) {
		$city = preg_replace( '/-(?:OT|StT)\s.*$/u', '', $city );
		$city = preg_replace( '/\sb\.\s/u', ' bei ', $city );
		return trim( $city );
	}

	/**
	 * external_id je Termin: Serien-Slug + Datum + laufende Nummer der Serie an diesem Tag (nach Beginn),
	 * z. B. "pippi-langstrumpf-1_2026-10-11_2". Unabhängig von der Termin-ID der Detail-URL (docs/04-quellen.md).
	 *
	 * @param array[] $events Termine.
	 * @return array[]
	 */
	private function build_external_ids( array $events ) {
		$groups = array();
		foreach ( $events as $i => $event ) {
			if ( empty( $event['_error'] ) ) {
				$groups[ $event['_series'] . '_' . $event['start_date'] ][] = $i;
			}
		}

		foreach ( $groups as $prefix => $indexes ) {
			usort(
				$indexes,
				function ( $a, $b ) use ( $events ) {
					return strcmp( (string) $events[ $a ]['start_time'], (string) $events[ $b ]['start_time'] ) ?: $a - $b;
				}
			);
			foreach ( $indexes as $n => $i ) {
				$events[ $i ]['external_id'] = $prefix . '_' . ( $n + 1 );
			}
		}

		foreach ( $events as $i => $event ) {
			unset( $events[ $i ]['_series'], $events[ $i ]['_termin'] );
		}

		return array_values( $events );
	}
}
