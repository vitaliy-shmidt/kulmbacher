<?php
/**
 * Source Adapter: Stadtsteinach.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest den Veranstaltungskalender der Stadt Stadtsteinach (TYPO3 + calendarize): Listenseiten (10 Termine je Seite,
 * Folgeseite über den Link "nächste" mit cHash) und je Termin die Detailseite, weil nur dort der Veranstaltungsort steht.
 * Analyse und Felder: docs/04-quellen.md, Abschnitt 11.
 *
 * Liste, je Termin div.calendarize-item:
 *   span.event-date                    "15.10.2026"
 *   span.event-time1 / event-time3     "09:30" / "- 11:00 Uhr" bzw. "Uhr" (fehlen bei Terminen ohne Uhrzeit)
 *   p.event-list-title > a[href]       Titel, Detail-URL mit tx_calendarize_calendar[index]={Index-ID}
 * Detailseite:
 *   dl.meta dt "Veranstaltungsort:" + dd   Ortstext (ohne Adresse und Stadt)
 */
class Eventcrawler_Source_Stadtsteinach extends Eventcrawler_Source {

	const BASE      = 'https://www.stadtsteinach.de';
	const LIST_URL  = 'https://www.stadtsteinach.de/freizeit-kultur-tourismus/veranstaltungen';
	const MAX_PAGES = 20; // Schutz gegen Endlosschleifen (2026-10-15: 5 Seiten).

	// Die Quelle nennt keine Stadt. Nur wenn der Ortstext diesen Namen als ganzes Wort enthält, ist die Stadt belegt
	// (docs/04-quellen.md Abschnitt 11, A87); sonst wird der Termin übersprungen.
	const CITY = 'Stadtsteinach';

	// Ortstexte dieser Quelle ohne Stadtnamen bzw. mit anderer Stadt, deren Stadt extern belegt ist (docs/04-quellen.md
	// Abschnitt 11, A88). Schlüssel = Ortstext nach clean_text() in Kleinbuchstaben; nur exakte Treffer, nur diese Quelle.
	// Es wird ausschließlich die Stadt gesetzt; Ortstext und Adresse bleiben wie in der Quelle.
	const VENUE_CITIES = array(
		'bauernmarktscheune'               => 'Stadtsteinach', // Bauernmarkt e. V.: Knollenstraße 8, 95346 Stadtsteinach
		'steinachtalhalle'                 => 'Stadtsteinach', // Stadt Stadtsteinach: Badstraße 4, 95346 Stadtsteinach
		'festplatz in der knollenstraße'   => 'Stadtsteinach', // Stadtsteinacher Anzeiger 11/2024 (Weihnachtszauber); Knollenstraße 8, 95346 Stadtsteinach
		'burgruine nordeck'                => 'Stadtsteinach', // Frankenwald Tourismus: Am Hochofen, 95346 Stadtsteinach
		'pfarrzentrum st. hedwig kulmbach' => 'Kulmbach',      // Erzbistum Bamberg: Am Galgenberg 4, 95326 Kulmbach
	);

	public function get_slug() {
		return 'stadtsteinach';
	}

	public function get_name() {
		return 'Stadtsteinach';
	}

	public function get_url() {
		return self::LIST_URL;
	}

	/**
	 * Identifikation über die Index-ID (external_id, Priorität 1).
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$pages = array();
		$url   = self::LIST_URL;
		for ( $i = 0; $i < self::MAX_PAGES && $url; $i++ ) {
			if ( $i > 0 ) {
				sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
			}
			$html = $this->fetch_html( $url );
			if ( is_wp_error( $html ) ) {
				return $html; // Unvollständige Liste nicht verarbeiten.
			}
			$pages[] = $html;
			$url     = $this->next_page_url( $html );
		}

		$events = $this->parse_list( $pages );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$details = array();
		foreach ( $events as $event ) {
			if ( empty( $event['_error'] ) ) {
				sleep( 1 );
				$details[ $event['event_url'] ] = $this->fetch_html( $event['event_url'] );
			}
		}
		return $this->apply_details( $events, $details );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string[] $pages   HTML der Listenseiten.
	 * @param array    $details Detail-URL => HTML (oder WP_Error).
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages, array $details ) {
		$events = $this->parse_list( $pages );
		return is_wp_error( $events ) ? $events : $this->apply_details( $events, $details );
	}

	/**
	 * Link "nächste" der Seitennavigation oder null.
	 *
	 * @param string $html Listenseite.
	 * @return string|null
	 */
	public function next_page_url( $html ) {
		$xpath = $this->create_xpath( $html );
		foreach ( $xpath->query( "//ul[contains(concat(' ', normalize-space(@class), ' '), ' f3-widget-paginator ')]//a[@href]" ) as $link ) {
			if ( 'nächste' === $this->clean_text( $link ) ) {
				return $this->full_url( $link->getAttribute( 'href' ) );
			}
		}
		return null;
	}

	/**
	 * @param string[] $pages HTML der Listenseiten.
	 * @return array[]|WP_Error
	 */
	public function parse_list( array $pages ) {
		$events = array();
		$seen   = array();
		foreach ( $pages as $html ) {
			$xpath = $this->create_xpath( $html );
			foreach ( $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' calendarize-item ')]" ) as $item ) {
				$event = $this->parse_item( $xpath, $item );
				if ( null !== $event['external_id'] && isset( $seen[ $event['external_id'] ] ) ) {
					continue; // Seiten können sich überschneiden.
				}
				$seen[ (string) $event['external_id'] ] = true;
				$events[]                               = $event;
			}
		}

		// Keine Termine = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( empty( $events ) ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}
		return $events;
	}

	/**
	 * @param DOMXPath   $xpath XPath.
	 * @param DOMElement $item  div.calendarize-item.
	 * @return array
	 */
	private function parse_item( DOMXPath $xpath, DOMElement $item ) {
		$link = $this->first( $xpath, ".//p[contains(concat(' ', normalize-space(@class), ' '), ' event-list-title ')]/a", $item );
		$url  = $link ? $this->full_url( $link->getAttribute( 'href' ) ) : '';

		$event = array(
			'title'         => $this->clean_text( $link ),
			'description'   => '', // Kein Kurztext in der Quelle; der Langtext der Detailseite wird nicht übernommen.
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => false, // Die Quelle kennzeichnet keine Ganztagstermine.
			'venue_name'    => '',
			'venue_address' => '', // Nicht vorhanden.
			'city'          => '',
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => preg_match( '/tx_calendarize_calendar%5Bindex%5D=(\d+)/', $url, $m ) ? $m[1] : null,
		);

		if ( null === $event['external_id'] ) {
			$event['_error'] = 'Detail-Link mit Index-ID fehlt';
			return $event;
		}

		$date_text = $this->clean_text( $this->first( $xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' event-date ')]", $item ) );
		$date      = DateTime::createFromFormat( '!d.m.Y', $date_text );
		if ( ! $date || $date->format( 'd.m.Y' ) !== $date_text ) {
			$event['_error'] = 'Datum nicht lesbar: ' . $date_text;
			return $event;
		}
		$event['start_date'] = $date->format( 'Y-m-d' );

		// Uhrzeit: event-time1 "09:30", event-time3 "- 11:00 Uhr" (Ende) oder "Uhr". Beide fehlen = keine Uhrzeit.
		$time1 = $this->clean_text( $this->first( $xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' event-time1 ')]", $item ) );
		$time3 = $this->clean_text( $this->first( $xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' event-time3 ')]", $item ) );
		if ( '' !== $time1 || '' !== $time3 ) {
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time1, $s ) || ! preg_match( '/^(?:-\s*(\d{1,2}):(\d{2})\s*)?Uhr$/u', $time3, $e ) ) {
				$event['_error'] = 'Uhrzeit nicht lesbar: ' . trim( $time1 . ' ' . $time3 );
				return $event;
			}
			$event['start_time'] = sprintf( '%02d:%02d', $s[1], $s[2] );
			if ( ! empty( $e[1] ) ) {
				$event['end_time'] = sprintf( '%02d:%02d', $e[1], $e[2] );
			}
		}

		return $event;
	}

	/**
	 * Ergänzt Ort und Stadt aus den Detailseiten.
	 *
	 * @param array[] $events  Termine der Liste.
	 * @param array   $details Detail-URL => HTML (oder WP_Error).
	 * @return array[]
	 */
	private function apply_details( array $events, array $details ) {
		foreach ( $events as $i => $event ) {
			if ( ! empty( $event['_error'] ) ) {
				continue;
			}
			$html = isset( $details[ $event['event_url'] ] ) ? $details[ $event['event_url'] ] : new WP_Error( 'eventcrawler_http', 'nicht abgerufen' );
			if ( is_wp_error( $html ) ) {
				$events[ $i ]['_error'] = 'Detailseite: ' . $html->get_error_message();
				continue;
			}

			$xpath = $this->create_xpath( $html );
			$meta  = $xpath->query( "//dl[contains(concat(' ', normalize-space(@class), ' '), ' meta ')]" )->item( 0 );
			if ( ! $meta ) {
				$events[ $i ]['_error'] = 'Detailseite ohne Angaben-Block (dl.meta)';
				continue;
			}
			foreach ( $xpath->query( './dt', $meta ) as $dt ) {
				if ( 'Veranstaltungsort:' === $this->clean_text( $dt ) ) {
					$dd = $this->first( $xpath, 'following-sibling::dd[1]', $dt );
					$events[ $i ]['venue_name'] = $this->clean_text( $dd );
				}
			}

			$key = mb_strtolower( $events[ $i ]['venue_name'] );
			if ( isset( self::VENUE_CITIES[ $key ] ) ) {
				$events[ $i ]['city'] = self::VENUE_CITIES[ $key ];
			} elseif ( preg_match( '/(?<![\p{L}\p{N}])' . self::CITY . '(?![\p{L}\p{N}])/u', $events[ $i ]['venue_name'] ) ) {
				$events[ $i ]['city'] = self::CITY;
			} else {
				$events[ $i ]['_error'] = 'Stadt nicht belegt (Ort: ' . ( '' === $events[ $i ]['venue_name'] ? 'fehlt' : $events[ $i ]['venue_name'] ) . ')';
			}
		}
		return $events;
	}

	/**
	 * Relativer Link -> volle URL mit Query (die Detail-URLs unterscheiden sich nur in der Query).
	 *
	 * @param string $href Link aus dem HTML (Entities bereits dekodiert).
	 * @return string
	 */
	private function full_url( $href ) {
		$href = trim( $href );
		if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
			return self::BASE . $href;
		}
		return 0 === strpos( $href, self::BASE . '/' ) ? $href : '';
	}
}
