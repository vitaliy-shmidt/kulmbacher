<?php
/**
 * Source Adapter: Helmbrechts.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest die Termine der Stadt Helmbrechts (TYPO3: news + calendarize). Die Termine kommen aus den Monatsansichten
 * (aktueller und nächster Monat, ab heute), weil nur sie jeden Termin einzeln enthalten; Ort, Adresse, Stadt, Teaser
 * und Ende stehen auf der Detailseite der Veranstaltung (eine Detailseite je Veranstaltung, nicht je Termin).
 * Analyse und Felder: docs/04-quellen.md, Abschnitt 12.
 *
 * Monatsansicht, je Termin ul.events > li > a[href=/news-details-aktuell/{slug}]:
 *   div.caltitle                     Titel
 *   span.innerwrap (ohne caltime)    "15.10.2026"
 *   span.caltime                     "Beginn: 19:30 Uhr" ("00:00" = keine Uhrzeit)
 * Detailseite:
 *   .termindaten                     "Datum: 16.10.2026 … Ende: 16:00 Uhr" bzw. "Ende: 15.11.2026 15:00 Uhr"
 *   div.location strong "Veranstaltungsort:"   Name <br> "Straße[,] PLZ Stadt[ / Ortsteil]"
 *   div.teaser-text                  kurzer Teaser
 */
class Eventcrawler_Source_Helmbrechts extends Eventcrawler_Source {

	const BASE          = 'https://stadt-helmbrechts.de';
	const LIST_URL      = 'https://stadt-helmbrechts.de/termine-veranstaltungen';
	const MONTHS        = 2; // aktueller + nächster Monat (2026-10-15: 42 Detailseiten, ca. 65–70 s).
	const TIMEZONE      = 'Europe/Berlin';

	public function get_slug() {
		return 'helmbrechts';
	}

	public function get_name() {
		return 'Helmbrechts';
	}

	public function get_url() {
		return self::LIST_URL;
	}

	/**
	 * Eine Detail-URL bezeichnet die Veranstaltung, nicht den Termin; Identifikation über external_id.
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$today  = new DateTimeImmutable( 'today', new DateTimeZone( self::TIMEZONE ) );
		$months = array();
		for ( $i = 0; $i < self::MONTHS; $i++ ) {
			if ( $i > 0 ) {
				sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
			}
			$month = $today->modify( 'first day of this month' )->modify( '+' . $i . ' month' );
			$html  = $this->fetch_html( self::LIST_URL . '/monat/' . $month->format( 'Y' ) . '/' . $month->format( 'n' ) );
			if ( is_wp_error( $html ) ) {
				return $html; // Unvollständige Termine nicht verarbeiten.
			}
			$months[] = $html;
		}

		$events = $this->parse_months( $months, $today->format( 'Y-m-d' ) );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$details = array();
		foreach ( $events as $event ) {
			if ( empty( $event['_error'] ) && ! isset( $details[ $event['event_url'] ] ) ) {
				sleep( 1 );
				$details[ $event['event_url'] ] = $this->fetch_html( $event['event_url'] );
			}
		}
		return $this->apply_details( $events, $details );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string[] $months  HTML der Monatsansichten.
	 * @param string   $from    Erster Tag (Y-m-d); frühere Termine der Monatsansicht werden nicht übernommen.
	 * @param array    $details Detail-URL => HTML (oder WP_Error).
	 * @return array[]|WP_Error
	 */
	public function parse( array $months, $from, array $details ) {
		$events = $this->parse_months( $months, $from );
		return is_wp_error( $events ) ? $events : $this->apply_details( $events, $details );
	}

	/**
	 * @param string[] $months HTML der Monatsansichten.
	 * @param string   $from   Erster Tag (Y-m-d).
	 * @return array[]|WP_Error
	 */
	public function parse_months( array $months, $from ) {
		$events = array();
		$seen   = array();
		$found  = 0;
		foreach ( $months as $html ) {
			$xpath = $this->create_xpath( $html );
			$links = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' calendarize ')]//ul[contains(concat(' ', normalize-space(@class), ' '), ' events ')]/li/a" );
			$found += $links->length;
			foreach ( $links as $link ) {
				$event = $this->parse_entry( $xpath, $link );
				if ( '' !== $event['start_date'] && $event['start_date'] < $from ) {
					continue; // Vergangene Tage des laufenden Monats.
				}
				// Dieselbe Monatsansicht kann Tage des Nachbarmonats zeigen: gleicher Termin nur einmal.
				$key = $event['event_url'] . '|' . $event['start_date'] . '|' . $event['start_time'] . '|' . $event['title'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$events[]     = $event;
			}
		}

		// Keine Termine in der Monatsansicht = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( 0 === $found ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}

		return $this->number_events( $events );
	}

	/**
	 * @param DOMXPath   $xpath XPath.
	 * @param DOMElement $link  a in ul.events > li.
	 * @return array
	 */
	private function parse_entry( DOMXPath $xpath, DOMElement $link ) {
		$href = trim( $link->getAttribute( 'href' ) );
		$url  = ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) ? $this->absolute_url( $href ) : '';

		$event = array(
			'title'         => $this->clean_text( $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' caltitle ')]", $link ) ),
			'description'   => '',
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => false, // Die Quelle kennzeichnet keine Ganztagstermine.
			'venue_name'    => '',
			'venue_address' => '',
			'city'          => '',
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => null, // wird in number_events() gesetzt
			'_slug'         => preg_match( '#^https://stadt-helmbrechts\.de/news-details-aktuell/([a-z0-9-]+)$#', $url, $m ) ? $m[1] : '',
		);

		if ( '' === $event['_slug'] ) {
			$event['_error'] = 'Detail-Link fehlt oder hat unbekanntes Format';
			return $event;
		}

		$date_text = $this->clean_text( $this->first( $xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' innerwrap ') and not(contains(concat(' ', normalize-space(@class), ' '), ' caltime '))]", $link ) );
		$date      = DateTime::createFromFormat( '!d.m.Y', $date_text );
		if ( ! $date || $date->format( 'd.m.Y' ) !== $date_text ) {
			$event['_error'] = 'Datum nicht lesbar: ' . $date_text;
			return $event;
		}
		$event['start_date'] = $date->format( 'Y-m-d' );

		$time_text = $this->clean_text( $this->first( $xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' caltime ')]", $link ) );
		if ( '' !== $time_text ) {
			if ( ! preg_match( '/^Beginn:\s*(\d{1,2})[:.](\d{2})\s*Uhr$/u', $time_text, $t ) ) {
				$event['_error'] = 'Beginn nicht lesbar: ' . $time_text;
				return $event;
			}
			// "00:00" ist in dieser Quelle der Platzhalter für "keine Uhrzeit" (docs/04-quellen.md Abschnitt 12).
			if ( 0 !== (int) $t[1] || 0 !== (int) $t[2] ) {
				$event['start_time'] = sprintf( '%02d:%02d', $t[1], $t[2] );
			}
		}

		return $event;
	}

	/**
	 * external_id je Termin: Slug + Datum + laufende Nummer der Veranstaltung an diesem Tag (nach Beginn).
	 *
	 * @param array[] $events Termine.
	 * @return array[]
	 */
	private function number_events( array $events ) {
		$groups = array();
		foreach ( $events as $i => $event ) {
			if ( empty( $event['_error'] ) ) {
				$groups[ $event['_slug'] . '_' . $event['start_date'] ][] = $i;
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
		return $events;
	}

	/**
	 * Ergänzt Ort, Adresse, Stadt, Teaser und (nur für den Termin mit dem "Datum" der Detailseite) das Ende.
	 *
	 * @param array[] $events  Termine.
	 * @param array   $details Detail-URL => HTML (oder WP_Error).
	 * @return array[]
	 */
	private function apply_details( array $events, array $details ) {
		$parsed = array();
		foreach ( $events as $i => $event ) {
			unset( $events[ $i ]['_slug'] );
			if ( ! empty( $event['_error'] ) ) {
				continue;
			}
			$url = $event['event_url'];
			if ( ! isset( $parsed[ $url ] ) ) {
				$html           = isset( $details[ $url ] ) ? $details[ $url ] : new WP_Error( 'eventcrawler_http', 'nicht abgerufen' );
				$parsed[ $url ] = is_wp_error( $html ) ? $html : $this->parse_detail( $html );
			}
			$detail = $parsed[ $url ];
			if ( is_wp_error( $detail ) ) {
				$events[ $i ]['_error'] = 'Detailseite: ' . $detail->get_error_message();
				continue;
			}

			$events[ $i ]['description']   = $detail['teaser'];
			$events[ $i ]['venue_name']    = $detail['venue'];
			$events[ $i ]['venue_address'] = $detail['street'];
			$events[ $i ]['city']          = $detail['city'];
			if ( $detail['date'] === $event['start_date'] ) {
				$events[ $i ]['end_date'] = $detail['end_date'];
				$events[ $i ]['end_time'] = $detail['end_time'];
			}
			if ( '' === $detail['city'] ) {
				$events[ $i ]['_error'] = '' === $detail['venue'] ? 'Veranstaltungsort fehlt' : 'Stadt fehlt (Ort: ' . $detail['venue'] . ')';
			}
		}
		return $events;
	}

	/**
	 * @param string $html Detailseite.
	 * @return array|WP_Error teaser, venue, street, city, date (Y-m-d|''), end_date, end_time.
	 */
	private function parse_detail( $html ) {
		$xpath = $this->create_xpath( $html );
		$data  = $this->first( $xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' termindaten ')]", $xpath->document );
		if ( ! $data ) {
			return new WP_Error( 'eventcrawler_structure', 'Block "Daten zur Veranstaltung" fehlt' );
		}
		$text   = $this->clean_text( $data );
		$detail = array(
			'teaser'   => $this->clean_text( $this->first( $xpath, "//div[contains(concat(' ', normalize-space(@class), ' '), ' teaser-text ')]", $xpath->document ) ),
			'venue'    => '',
			'street'   => '',
			'city'     => '',
			'date'     => '',
			'end_date' => null,
			'end_time' => null,
		);

		if ( preg_match( '/Datum:\s*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			$detail['date'] = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		}
		// "Ende: 16:00 Uhr" (gleicher Tag) oder "Ende: 15.11.2026 15:00 Uhr".
		if ( '' !== $detail['date'] && preg_match( '/Ende:\s*(?:(\d{2})\.(\d{2})\.(\d{4})\s+)?(\d{1,2})[:.](\d{2})\s*Uhr/u', $text, $e ) ) {
			$detail['end_date'] = empty( $e[1] ) ? $detail['date'] : sprintf( '%04d-%02d-%02d', $e[3], $e[2], $e[1] );
			$detail['end_time'] = sprintf( '%02d:%02d', $e[4], $e[5] );
		}

		foreach ( $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' location ')]" ) as $block ) {
			if ( 'Veranstaltungsort:' !== $this->clean_text( $this->first( $xpath, './strong', $block ) ) ) {
				continue;
			}
			$lines = array();
			foreach ( $block->childNodes as $node ) {
				if ( XML_TEXT_NODE === $node->nodeType ) {
					$line = $this->clean_text( $node );
					if ( '' !== $line ) {
						$lines[] = $line;
					}
				}
			}
			$detail['venue'] = isset( $lines[0] ) ? $lines[0] : '';
			// "Luitpoldstraße 21, 95233 Helmbrechts" bzw. "Am Kirchspitz 21 95233 Helmbrechts / Ort".
			if ( isset( $lines[1] ) && preg_match( '/^(.*?)[,\s]+(\d{5})\s+([^\/]+?)(?:\s*\/.*)?$/u', $lines[1], $a ) ) {
				$detail['street'] = trim( $a[1], " ,\t" );
				$detail['city']   = trim( $a[3] );
			}
			break;
		}
		return $detail;
	}
}
