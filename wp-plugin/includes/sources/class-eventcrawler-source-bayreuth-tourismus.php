<?php
/**
 * Source Adapter: Bayreuth Tourismus.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest den Eventkalender von bayreuth-tourismus.de über die Regional-API, die das Kalender-Widget der Seite
 * (rbi-events) selbst abfragt. Ein API-Eintrag = ein Termin; die eventId gilt je Veranstaltung, der Termin ist
 * eventId + Datum. Analyse und Felder: docs/04-quellen.md, Abschnitt 8.
 *
 * Termine am Ort DAS ZENTRUM werden nicht übernommen (A91): DAS ZENTRUM ist als eigene Quelle angebunden. Die Termine
 * dieses Orts liefert die API über ihren Ortsfilter venueIds (stabile Venue-ID); kein Titel- oder Ähnlichkeitsabgleich.
 *
 * GET /event-management/events/search?page=0&size=1000&sort=…&districts=…&from=Y-m-d&to=Y-m-d[&venueIds=…]
 *   -> { values: [...], totalItems, totalPages, currentPage }
 *   eventId, detailLink, title, description (Kurztext)
 *   dateInfo.date.start / .end (Y-m-d), dateInfo.start / .end (H:i:s)
 *   venue.name, venue.room, venue.address.street / houseNumber / postalCode / city (venue kann null sein)
 */
class Eventcrawler_Source_Bayreuth_Tourismus extends Eventcrawler_Source {

	const API_URL   = 'https://api.region-bayreuth.de/event-management/events/search';
	const PAGE_URL  = 'https://www.bayreuth-tourismus.de/eventkalender/';
	const DAYS      = 28; // heute + 27 Tage (2026-10-15: 563 Termine, eine Seite).
	const PAGE_SIZE = 1000;
	const MAX_PAGES = 5;
	const TIMEZONE  = 'Europe/Berlin';

	/**
	 * Stadtteil-Filter aus der Widget-Konfiguration der Seite /eventkalender/ (filterBy.districts, Stand 2026-10-15).
	 */
	const DISTRICTS = array(
		'908d5a67-bed7-4658-ae58-6ae6a7f2f776',
		'f058da00-304c-4586-b907-b1f73fde8677',
		'd67fe93d-15fe-4fe5-a2eb-d65541cc5479',
		'c084fa11-5586-4add-ae7b-aeab6c03c78e',
	);

	/**
	 * Ort „DAS ZENTRUM - Internationales Jugendkulturzentrum Bayreuth e.V.“ in der Regionaldatenbank (einziger Ort
	 * dieses Hauses, belegt über /venue-management/venues/status/approved).
	 */
	const DAS_ZENTRUM_VENUE_ID   = 'e7cac5bf-eefe-4b23-af39-d06aba69e0bd';
	const DAS_ZENTRUM_VENUE_NAME = 'DAS ZENTRUM - Internationales Jugendkulturzentrum Bayreuth e.V.';

	/**
	 * @var int Anzahl der im letzten Lauf wegen DAS ZENTRUM nicht übernommenen Termine (nicht in "gefunden" enthalten).
	 */
	private $excluded = 0;

	public function get_slug() {
		return 'bayreuth-tourismus';
	}

	public function get_name() {
		return 'Bayreuth Tourismus';
	}

	public function get_url() {
		return self::API_URL;
	}

	/**
	 * Eine Detail-URL bezeichnet die Veranstaltung, nicht den Termin; Identifikation über external_id.
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	/**
	 * @return int Im letzten parse() ausgeschlossene DAS-ZENTRUM-Termine.
	 */
	public function get_excluded_count() {
		return $this->excluded;
	}

	public function fetch_events() {
		$today = new DateTimeImmutable( 'today', new DateTimeZone( self::TIMEZONE ) );
		$url   = self::search_url( $today, $today->modify( '+' . ( self::DAYS - 1 ) . ' days' ) );

		$pages = $this->fetch_pages( $url );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}
		sleep( 1 ); // Höflich: kurze Pause zwischen den Abfragen.
		$zentrum = $this->fetch_pages( $url . '&venueIds=' . self::DAS_ZENTRUM_VENUE_ID );
		if ( is_wp_error( $zentrum ) ) {
			return $zentrum; // Ohne Ortsliste könnten DAS-ZENTRUM-Termine doppelt gespeichert werden.
		}
		return $this->parse( $pages, $zentrum );
	}

	/**
	 * @param DateTimeInterface $from Erster Tag.
	 * @param DateTimeInterface $to   Letzter Tag.
	 * @return string Such-URL wie das Widget (Stadtteile, chronologisch).
	 */
	public static function search_url( DateTimeInterface $from, DateTimeInterface $to ) {
		$query = 'page={page}&size=' . self::PAGE_SIZE . '&sort=eventDateTimes.date:asc';
		foreach ( self::DISTRICTS as $district ) {
			$query .= '&districts=' . $district;
		}
		return self::API_URL . '?' . $query . '&from=' . $from->format( 'Y-m-d' ) . '&to=' . $to->format( 'Y-m-d' );
	}

	/**
	 * Alle Seiten einer Suche (Seiten ab 0). Bricht die Folge ab, ist die ganze Suche ein Fehler.
	 *
	 * @param string $url Such-URL mit Platzhalter {page}.
	 * @return string[]|WP_Error
	 */
	private function fetch_pages( $url ) {
		$pages = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			if ( $page > 0 ) {
				sleep( 1 );
			}
			$body = $this->fetch_html( str_replace( '{page}', (string) $page, $url ) );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$pages[] = $body;
			$data    = json_decode( $body, true );
			if ( ! is_array( $data ) || ! isset( $data['totalPages'] ) || $page + 1 >= (int) $data['totalPages'] ) {
				break;
			}
		}
		return $pages;
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeicherten API-Antworten getestet werden kann.
	 *
	 * @param string[] $pages   JSON-Antworten der Suche.
	 * @param string[] $zentrum JSON-Antworten derselben Suche mit venueIds = DAS ZENTRUM.
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages, array $zentrum ) {
		$this->excluded = 0;

		$items = $this->read_values( $pages );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$zentrum_items = $this->read_values( $zentrum );
		if ( is_wp_error( $zentrum_items ) ) {
			return $zentrum_items;
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Schnittstelle hat sich vermutlich geändert.' );
		}
		$zentrum_ids = array();
		foreach ( $zentrum_items as $item ) {
			if ( isset( $item['eventId'] ) && is_string( $item['eventId'] ) ) {
				$zentrum_ids[ $item['eventId'] ] = true;
			}
		}

		$events = array();
		$seen   = array();
		foreach ( $items as $item ) {
			$id = isset( $item['eventId'] ) && is_string( $item['eventId'] ) ? $item['eventId'] : '';
			if ( isset( $zentrum_ids[ $id ] ) ) {
				$this->excluded++;
				continue; // A91: Ort DAS ZENTRUM -> Quelle das-zentrum-bayreuth.
			}
			$event = $this->parse_event( $item );
			if ( empty( $event['_error'] ) && self::DAS_ZENTRUM_VENUE_NAME === $event['venue_name'] ) {
				// Name von DAS ZENTRUM, aber nicht im Ortsfilter: widersprüchlich -> lieber nicht speichern.
				$event['_error'] = 'Ort DAS ZENTRUM ohne Treffer im Ortsfilter';
			}
			$key = $id . '|' . $event['start_date'] . '|' . (string) $event['start_time'];
			if ( isset( $seen[ $key ] ) ) {
				continue; // Seiten können sich bei Änderungen während des Abrufs überschneiden.
			}
			$seen[ $key ] = true;
			$events[]     = $event;
		}

		return $this->number_events( $events );
	}

	/**
	 * @param string[] $pages JSON-Antworten.
	 * @return array[]|WP_Error Alle values.
	 */
	private function read_values( array $pages ) {
		$values = array();
		foreach ( $pages as $body ) {
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || ! isset( $data['values'] ) || ! is_array( $data['values'] ) ) {
				return new WP_Error( 'eventcrawler_structure', 'Antwort der Veranstaltungs-API nicht lesbar – die Schnittstelle hat sich vermutlich geändert.' );
			}
			foreach ( $data['values'] as $item ) {
				$values[] = is_array( $item ) ? $item : array();
			}
		}
		return $values;
	}

	/**
	 * @param array $item Eintrag aus "values".
	 * @return array
	 */
	private function parse_event( array $item ) {
		$url   = isset( $item['detailLink'] ) && is_string( $item['detailLink'] ) ? $this->absolute_url( $item['detailLink'] ) : '';
		$date  = isset( $item['dateInfo'] ) && is_array( $item['dateInfo'] ) ? $item['dateInfo'] : array();
		$days  = isset( $date['date'] ) && is_array( $date['date'] ) ? $date['date'] : array();
		$venue = isset( $item['venue'] ) && is_array( $item['venue'] ) ? $item['venue'] : array();
		$addr  = isset( $venue['address'] ) && is_array( $venue['address'] ) ? $venue['address'] : array();
		$text  = function ( array $from, $key ) {
			return isset( $from[ $key ] ) && is_scalar( $from[ $key ] ) ? $this->clean_text( (string) $from[ $key ] ) : '';
		};

		$event = array(
			'title'         => $text( $item, 'title' ),
			'description'   => $text( $item, 'description' ), // Kurztext der Datenbank (höchstens ca. 120 Zeichen).
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => false, // Die Quelle kennzeichnet keine Ganztagstermine.
			'venue_name'    => $text( $venue, 'name' ),
			'venue_address' => trim( $text( $addr, 'street' ) . ' ' . $text( $addr, 'houseNumber' ) ),
			'city'          => $text( $addr, 'city' ),
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => null, // wird in number_events() gesetzt
			'_id'           => isset( $item['eventId'] ) && is_string( $item['eventId'] ) && preg_match( '/^[0-9a-f-]{36}$/', $item['eventId'] ) ? $item['eventId'] : '',
		);

		if ( '' === $event['_id'] ) {
			$event['_error'] = 'eventId fehlt';
			return $event;
		}

		$start = isset( $days['start'] ) ? $this->date_value( $days['start'] ) : null;
		if ( null === $start ) {
			$event['_error'] = 'Datum nicht lesbar';
			return $event;
		}
		$event['start_date'] = $start;

		$time = isset( $date['start'] ) ? $this->time_value( $date['start'] ) : null;
		if ( false === $time ) {
			$event['_error'] = 'Beginn nicht lesbar';
			return $event;
		}
		$event['start_time'] = $time;

		// Ende wie geliefert (Datum + Uhrzeit); liegt es nicht nach dem Beginn, verwirft es der Crawler.
		$end_time = isset( $date['end'] ) ? $this->time_value( $date['end'] ) : null;
		$end_date = isset( $days['end'] ) ? $this->date_value( $days['end'] ) : null;
		if ( is_string( $end_time ) && null !== $time ) {
			$event['end_date'] = null !== $end_date ? $end_date : $start;
			$event['end_time'] = $end_time;
		}

		if ( '' === $event['venue_name'] ) {
			$event['_error'] = 'Veranstaltungsort fehlt';
		} elseif ( '' === $event['city'] ) {
			$event['_error'] = 'Stadt fehlt (Ort: ' . $event['venue_name'] . ')';
		}
		return $event;
	}

	/**
	 * @param mixed $value "2026-10-15".
	 * @return string|null
	 */
	private function date_value( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * @param mixed $value "19:30:00", leer oder null.
	 * @return string|null|false H:i, null (keine Angabe) oder false (unlesbar).
	 */
	private function time_value( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m ) ) {
			return false;
		}
		return $m[1] . ':' . $m[2];
	}

	/**
	 * external_id je Termin: eventId + Datum + laufende Nummer der Veranstaltung an diesem Tag (nach Beginn).
	 *
	 * @param array[] $events Termine.
	 * @return array[]
	 */
	private function number_events( array $events ) {
		$groups = array();
		foreach ( $events as $i => $event ) {
			if ( empty( $event['_error'] ) ) {
				$groups[ $event['_id'] . '_' . $event['start_date'] ][] = $i;
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
			unset( $events[ $i ]['_id'] );
		}
		return $events;
	}
}
