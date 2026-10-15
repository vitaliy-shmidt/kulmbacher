<?php
/**
 * Source Adapter: DAS ZENTRUM Bayreuth.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest die öffentliche REST-API des Kalender-Plugins "The Events Calendar" auf das-zentrum.de (WordPress).
 * Ein Eintrag der API = ein Termin mit eigener Post-ID; Ort mit Adresse ist strukturiert enthalten, Detailseiten
 * werden nicht abgerufen. Analyse und Felder: docs/04-quellen.md, Abschnitt 5.
 *
 * GET /wp-json/tribe/events/v1/events?per_page=50&page=n  ->  { events: [...], total, total_pages }
 *   id                     Post-ID des Termins (-> external_id)
 *   title, url             Titel (HTML-Entities), öffentliche Detailseite
 *   start_date, end_date   "Y-m-d H:i:s" Ortszeit (timezone), Ende = Beginn bedeutet: keine Endangabe
 *   all_day                bool
 *   venue                  id, venue (Name), address, zip, city
 */
class Eventcrawler_Source_Das_Zentrum_Bayreuth extends Eventcrawler_Source {

	const API_URL   = 'https://www.das-zentrum.de/wp-json/tribe/events/v1/events';
	const PER_PAGE  = 50;
	const MAX_PAGES = 10; // Schutz gegen Endlosschleifen (2026-10-15: 2 Seiten).
	const TIMEZONE  = 'Europe/Berlin';

	// Der Ort "DAS ZENTRUM – Kleinkunstbühne" (Ort-ID 60) hat in der Quelle keine Adresse. Er ist ein Raum des Hauses
	// in Bayreuth (belegt in docs/04-quellen.md Abschnitt 5). Nur für genau diese Ort-ID wird die Stadt gesetzt,
	// eine Straße wird nicht ergänzt.
	const KLEINKUNSTBUEHNE_VENUE_ID = 60;
	const KLEINKUNSTBUEHNE_CITY     = 'Bayreuth';

	public function get_slug() {
		return 'das-zentrum-bayreuth';
	}

	public function get_name() {
		return 'DAS ZENTRUM Bayreuth';
	}

	public function get_url() {
		return self::API_URL;
	}

	/**
	 * Identifikation über die Post-ID (external_id, Priorität 1).
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$pages = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			if ( $page > 1 ) {
				sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
			}
			$body = $this->fetch_html( self::API_URL . '?per_page=' . self::PER_PAGE . '&page=' . $page );
			if ( is_wp_error( $body ) ) {
				return $body; // Unvollständige Daten nicht verarbeiten.
			}
			$pages[] = $body;

			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || ! isset( $data['total_pages'] ) || $page >= (int) $data['total_pages'] ) {
				break;
			}
		}

		return $this->parse( $pages );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeicherten API-Antworten getestet werden kann.
	 *
	 * @param string[] $pages JSON-Antworten der API.
	 * @return array[]|WP_Error
	 */
	public function parse( array $pages ) {
		$events = array();
		$seen   = array();

		foreach ( $pages as $body ) {
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
				return new WP_Error( 'eventcrawler_structure', 'Antwort der Veranstaltungs-API nicht lesbar – die Schnittstelle hat sich vermutlich geändert.' );
			}
			foreach ( $data['events'] as $item ) {
				$event = $this->parse_event( is_array( $item ) ? $item : array() );
				if ( null !== $event['external_id'] && isset( $seen[ $event['external_id'] ] ) ) {
					continue; // Seiten können sich bei Änderungen während des Abrufs überschneiden.
				}
				$seen[ (string) $event['external_id'] ] = true;
				$events[]                               = $event;
			}
		}

		if ( empty( $events ) ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Schnittstelle hat sich vermutlich geändert.' );
		}

		return $events;
	}

	/**
	 * @param array $item Eintrag aus "events".
	 * @return array
	 */
	private function parse_event( array $item ) {
		$url   = isset( $item['url'] ) ? $this->absolute_url( (string) $item['url'] ) : '';
		$venue = isset( $item['venue'] ) && is_array( $item['venue'] ) ? $item['venue'] : array();
		$text  = function ( $key ) use ( $venue ) {
			return isset( $venue[ $key ] ) && is_scalar( $venue[ $key ] ) ? $this->clean_text( (string) $venue[ $key ] ) : '';
		};

		$event = array(
			'title'         => isset( $item['title'] ) ? $this->clean_text( (string) $item['title'] ) : '',
			'description'   => '', // Die Quelle hat keinen Kurztext (excerpt leer), nur langen Werbetext.
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => ! empty( $item['all_day'] ),
			'venue_name'    => $text( 'venue' ),
			'venue_address' => $text( 'address' ),
			'city'          => $text( 'city' ),
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => isset( $item['id'] ) && (int) $item['id'] > 0 ? (string) (int) $item['id'] : null,
		);

		if ( null === $event['external_id'] ) {
			$event['_error'] = 'ID fehlt';
			return $event;
		}
		if ( ! isset( $item['timezone'] ) || self::TIMEZONE !== $item['timezone'] ) {
			$event['_error'] = 'unerwartete Zeitzone: ' . ( isset( $item['timezone'] ) ? (string) $item['timezone'] : '(fehlt)' );
			return $event;
		}

		$start = $this->split_datetime( isset( $item['start_date'] ) ? $item['start_date'] : '' );
		if ( null === $start ) {
			$event['_error'] = 'Beginn nicht lesbar';
			return $event;
		}
		$event['start_date'] = $start[0];
		$event['start_time'] = $event['all_day'] ? null : $start[1];

		// Ende nur, wenn es nach dem Beginn liegt; Ende = Beginn ist in dieser Quelle die fehlende Endangabe.
		$end = $this->split_datetime( isset( $item['end_date'] ) ? $item['end_date'] : '' );
		if ( null !== $end && $end[0] . ' ' . $end[1] > $start[0] . ' ' . $start[1] ) {
			if ( $event['all_day'] ) {
				$event['end_date'] = $end[0] > $start[0] ? $end[0] : null;
			} else {
				$event['end_date'] = $end[0];
				$event['end_time'] = $end[1];
			}
		}

		if ( '' === $event['city'] && '' === $event['venue_address']
			&& isset( $venue['id'] ) && self::KLEINKUNSTBUEHNE_VENUE_ID === (int) $venue['id'] ) {
			$event['city'] = self::KLEINKUNSTBUEHNE_CITY;
		}
		if ( '' === $event['city'] ) {
			$event['_error'] = 'Ort ohne Stadtangabe: ' . ( '' === $event['venue_name'] ? '(kein Ort)' : $event['venue_name'] );
		}

		return $event;
	}

	/**
	 * "2026-10-15 19:30:00" -> array( '2026-10-15', '19:30' ).
	 *
	 * @param mixed $value Datum mit Uhrzeit.
	 * @return string[]|null
	 */
	private function split_datetime( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}$/', $value, $m ) ) {
			return null;
		}
		return array( $m[1], $m[2] );
	}
}
