<?php
/**
 * Source Adapter: Kronach.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest den Veranstaltungskalender der Stadt Kronach (CMS „recon“). Die Termine kommen aus der Ergebnisliste der
 * Datumssuche (heute bis heute + 27 Tage, alle Seiten); jeder Termin hat dort eine eigene ID (ev[id]). Adresse und
 * Stadt stehen nur auf der Detailseite; eine Detailseite nennt unter „Weitere Termine“ die IDs der übrigen Termine
 * derselben Veranstaltung, deren Ort damit ohne eigenen Abruf feststeht.
 * Analyse und Felder: docs/04-quellen.md, Abschnitt 14.
 *
 * Ergebnisliste, je Termin a.events-list-link[href=…ev%5Bid%5D={ID}]:
 *   span.day / span.month / span.time   " 15" / " Okt" / " 19:30 Uhr" (Zeit kann fehlen; kein Jahr)
 *   div.slidecontent-title h2           Titel
 *   div.slidecontent-teaser             "Ort: Kreiskulturraum" (kann fehlen)
 *   ul.pagination                       "(15 Seiten)", Folgeseiten mit &page=1 …
 * Detailseite:
 *   div.events-address                  .events-address-title / -str / -ort ("96317 Kronach")
 *   div.event-collapse-body a.events-list-link   „Weitere Termine“ (IDs)
 */
class Eventcrawler_Source_Kronach extends Eventcrawler_Source {

	const LIST_URL  = 'https://www.kronach.de/tourismus-kultur/veranstaltungen/index.html';
	const DAYS      = 28; // heute + 27 Tage (2026-10-15: 8 Listenseiten, rund 90 Termine).
	const MAX_PAGES = 12; // Schutz vor ausufernder Laufzeit; die Liste ist nach Datum sortiert.
	const TIMEZONE  = 'Europe/Berlin';

	/**
	 * Monatskürzel der Quelle. Belegt (2026-10-15): Jan, Feb, Mai, Jul, Okt, Nov, Dez; die übrigen sind die
	 * gleichgebildeten Kürzel. Ein anderes Kürzel macht den Termin fehlerhaft, statt ein Datum zu raten.
	 */
	const MONTHS = array(
		'jan' => 'Januar',
		'feb' => 'Februar',
		'mär' => 'März',
		'apr' => 'April',
		'mai' => 'Mai',
		'jun' => 'Juni',
		'jul' => 'Juli',
		'aug' => 'August',
		'sep' => 'September',
		'okt' => 'Oktober',
		'nov' => 'November',
		'dez' => 'Dezember',
	);

	/**
	 * Geprüfte Orte ohne Adresse/PLZ auf der Detailseite (A88): normalisierter Ortsname => Stadt. Nur city wird gesetzt.
	 */
	const VENUE_CITIES = array(
		'innenstadt kronach' => 'Kronach',
	);

	public function get_slug() {
		return 'kronach';
	}

	public function get_name() {
		return 'Kronach';
	}

	public function get_url() {
		return self::LIST_URL;
	}

	/**
	 * Die Detail-URL enthält die Termin-ID (je Termin eine eigene ID, geprüft).
	 */
	public function has_unique_detail_urls() {
		return true;
	}

	public function fetch_events() {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( self::TIMEZONE ) );
		$url   = self::search_url( $today, $today->modify( '+' . ( self::DAYS - 1 ) . ' days' ) );

		$pages = array();
		$total = 1;
		for ( $p = 0; $p < $total && $p < self::MAX_PAGES; $p++ ) {
			if ( $p > 0 ) {
				sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
			}
			$html = $this->fetch_html( $p > 0 ? $url . '&page=' . $p : $url );
			if ( is_wp_error( $html ) ) {
				return $html; // Unvollständige Liste nicht verarbeiten.
			}
			if ( 0 === $p ) {
				$total = $this->parse_page_count( $html );
			}
			$pages[] = $html;
		}

		$events = $this->parse_list( $pages, $today );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$details = array();
		$covered = array();
		foreach ( $events as $event ) {
			$id = $event['external_id'];
			if ( ! empty( $event['_error'] ) || isset( $covered[ $id ] ) ) {
				continue;
			}
			sleep( 1 );
			$details[ $id ] = $this->fetch_html( $event['event_url'] );
			if ( ! is_wp_error( $details[ $id ] ) ) {
				foreach ( $this->parse_detail( $details[ $id ] )['siblings'] as $sibling ) {
					$covered[ $sibling ] = true;
				}
			}
			$covered[ $id ] = true;
		}
		return $this->apply_details( $events, $details );
	}

	/**
	 * @param DateTimeInterface $from Erster Tag.
	 * @param DateTimeInterface $to   Letzter Tag.
	 * @return string URL der Datumssuche (wie das Suchformular der Seite).
	 */
	public static function search_url( DateTimeInterface $from, DateTimeInterface $to ) {
		return self::LIST_URL . '?ev%5Bstart%5D=' . $from->format( 'd.m.Y' ) . '&ev%5Bend%5D=' . $to->format( 'd.m.Y' ) . '&ev%5Bsearch%5D=';
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string[]          $pages   HTML der Listenseiten.
	 * @param DateTimeInterface $from    Erster Tag der Suche (Bezug für das fehlende Jahr).
	 * @param array             $details Termin-ID => HTML (oder WP_Error).
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages, DateTimeInterface $from, array $details ) {
		$events = $this->parse_list( $pages, $from );
		return is_wp_error( $events ) ? $events : $this->apply_details( $events, $details );
	}

	/**
	 * @param string $html Erste Listenseite.
	 * @return int Anzahl Seiten laut Pagination ("(15 Seiten)"), 1 ohne Pagination.
	 */
	public function parse_page_count( $html ) {
		$xpath = $this->create_xpath( $html );
		foreach ( $xpath->query( "//ul[contains(concat(' ', normalize-space(@class), ' '), ' pagination ')]//a" ) as $a ) {
			if ( preg_match( '/^\((\d+) Seiten\)$/u', $this->clean_text( $a ), $m ) ) {
				return max( 1, (int) $m[1] );
			}
		}
		return 1;
	}

	/**
	 * @param string[]          $pages HTML der Listenseiten.
	 * @param DateTimeInterface $from  Erster Tag der Suche.
	 * @return array[]|WP_Error
	 */
	public function parse_list( array $pages, DateTimeInterface $from ) {
		$events = array();
		$seen   = array();
		$found  = 0;
		foreach ( $pages as $html ) {
			$xpath = $this->create_xpath( $html );
			$links = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' event-resultlist ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' events-list-link ')]" );
			$found += $links->length;
			foreach ( $links as $link ) {
				$event = $this->parse_entry( $xpath, $link, $from );
				if ( null !== $event['external_id'] ) {
					if ( isset( $seen[ $event['external_id'] ] ) ) {
						continue; // Gleicher Termin am Seitenübergang nur einmal.
					}
					$seen[ $event['external_id'] ] = true;
				}
				$events[] = $event;
			}
		}

		// Keine Termine in der Ergebnisliste = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( 0 === $found ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}
		return $events;
	}

	/**
	 * @param DOMXPath          $xpath XPath.
	 * @param DOMElement        $link  a.events-list-link.
	 * @param DateTimeInterface $from  Erster Tag der Suche.
	 * @return array
	 */
	private function parse_entry( DOMXPath $xpath, DOMElement $link, DateTimeInterface $from ) {
		$id    = preg_match( '/[?&]ev(?:%5B|\[)id(?:%5D|\])=(\d+)/i', $link->getAttribute( 'href' ), $m ) ? $m[1] : null;
		$url   = null !== $id ? self::LIST_URL . '?ev%5Bid%5D=' . $id : '';
		$venue = $this->clean_text( $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' slidecontent-teaser ')]", $link ) );

		$event = array(
			'title'         => $this->clean_text( $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' slidecontent-title ')]", $link ) ),
			'description'   => '', // Die Quelle hat keinen Teaser; der Langtext der Detailseite wird nicht übernommen.
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null, // Die Quelle nennt kein Ende.
			'end_time'      => null,
			'all_day'       => false, // Die Quelle kennzeichnet keine Ganztagstermine.
			'venue_name'    => preg_match( '/^Ort:\s*(.+)$/u', $venue, $v ) ? trim( $v[1] ) : '',
			'venue_address' => '',
			'city'          => '',
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => $id,
		);

		if ( null === $id ) {
			$event['_error'] = 'Termin-ID fehlt im Link';
			return $event;
		}

		$day   = $this->clean_text( $this->first( $xpath, ".//span[@class='day']", $link ) );
		$month = mb_strtolower( $this->clean_text( $this->first( $xpath, ".//span[@class='month']", $link ) ) );
		$date  = ( preg_match( '/^\d{1,2}$/', $day ) && isset( self::MONTHS[ $month ] ) )
			? $this->resolve_german_date( (int) $day . '. ' . self::MONTHS[ $month ], $from )
			: null;
		if ( null === $date ) {
			$event['_error'] = 'Datum nicht lesbar: ' . $day . ' ' . $month;
			return $event;
		}
		$event['start_date'] = $date;

		$time = $this->clean_text( $this->first( $xpath, ".//span[@class='time']", $link ) );
		if ( '' !== $time ) {
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})\s*Uhr$/u', $time, $t ) || (int) $t[1] > 23 || (int) $t[2] > 59 ) {
				$event['_error'] = 'Uhrzeit nicht lesbar: ' . $time;
				return $event;
			}
			$event['start_time'] = sprintf( '%02d:%02d', $t[1], $t[2] );
		}

		if ( '' === $event['venue_name'] ) {
			$event['_error'] = 'Veranstaltungsort fehlt';
		}
		return $event;
	}

	/**
	 * Ergänzt Adresse und Stadt aus der Detailseite des Termins oder eines Termins derselben Veranstaltung.
	 *
	 * @param array[] $events  Termine.
	 * @param array   $details Termin-ID => HTML (oder WP_Error).
	 * @return array[]
	 */
	private function apply_details( array $events, array $details ) {
		$parsed  = array();
		$sources = array(); // Termin-ID => ID der Detailseite, die ihn nennt.
		foreach ( $details as $id => $html ) {
			$parsed[ $id ] = is_wp_error( $html ) ? $html : $this->parse_detail( $html );
			if ( ! is_wp_error( $parsed[ $id ] ) ) {
				foreach ( $parsed[ $id ]['siblings'] as $sibling ) {
					if ( ! isset( $sources[ $sibling ] ) ) {
						$sources[ $sibling ] = $id;
					}
				}
			}
		}

		foreach ( $events as $i => $event ) {
			if ( ! empty( $event['_error'] ) ) {
				continue;
			}
			$id = $event['external_id'];
			if ( isset( $parsed[ $id ] ) ) {
				$detail = $parsed[ $id ];
			} elseif ( isset( $sources[ $id ] ) ) {
				$detail = $parsed[ $sources[ $id ] ];
			} else {
				$detail = new WP_Error( 'eventcrawler_http', 'nicht abgerufen' );
			}
			if ( is_wp_error( $detail ) ) {
				$events[ $i ]['_error'] = 'Detailseite: ' . $detail->get_error_message();
				continue;
			}
			if ( $detail['venue'] !== $event['venue_name'] ) {
				$events[ $i ]['_error'] = 'Ort der Detailseite weicht ab: ' . $detail['venue'];
				continue;
			}

			$events[ $i ]['venue_address'] = $detail['street'];
			$events[ $i ]['city']          = $detail['city'];
			if ( '' === $detail['city'] ) {
				$key = mb_strtolower( $event['venue_name'] );
				if ( isset( self::VENUE_CITIES[ $key ] ) ) {
					$events[ $i ]['city'] = self::VENUE_CITIES[ $key ];
				} else {
					$events[ $i ]['_error'] = 'Stadt fehlt (Ort: ' . $event['venue_name'] . ')';
				}
			}
		}
		return $events;
	}

	/**
	 * @param string $html Detailseite.
	 * @return array|WP_Error venue, street, city, siblings (Termin-IDs unter „Weitere Termine“).
	 */
	private function parse_detail( $html ) {
		$xpath = $this->create_xpath( $html );
		$block = $this->first( $xpath, "//div[contains(concat(' ', normalize-space(@class), ' '), ' events-address ')]", $xpath->document );
		if ( ! $block ) {
			return new WP_Error( 'eventcrawler_structure', 'Block "Wo?" fehlt' );
		}
		$field  = function ( $class ) use ( $xpath, $block ) {
			return $this->clean_text( $this->first( $xpath, "./div[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]", $block ) );
		};
		$detail = array(
			'venue'    => $field( 'events-address-title' ),
			'street'   => $field( 'events-address-str' ),
			'city'     => '',
			'siblings' => array(),
		);
		if ( preg_match( '/^\d{5}\s+(.+)$/u', $field( 'events-address-ort' ), $m ) ) {
			$detail['city'] = trim( $m[1] );
		}
		if ( '' === $detail['city'] ) {
			$detail['street'] = ''; // Straße ohne PLZ/Ort nicht übernehmen.
		}

		$links = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' event-collapse-body ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' events-list-link ')]" );
		foreach ( $links as $a ) {
			if ( preg_match( '/[?&]ev(?:%5B|\[)id(?:%5D|\])=(\d+)/i', $a->getAttribute( 'href' ), $s ) ) {
				$detail['siblings'][] = $s[1];
			}
		}
		return $detail;
	}
}
