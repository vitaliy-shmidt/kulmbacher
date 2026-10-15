<?php
/**
 * Source Adapter: Schützenhaus Münchberg.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest die Programmseite des Schützenhauses Münchberg (WordPress, Elementor-Post-Loop "Ele Custom Skin").
 * Alle kommenden Termine stehen auf einer Seite; Detailseiten werden nicht abgerufen.
 * Analyse und Felder: docs/04-quellen.md, Abschnitt 9.
 *
 * Je Eintrag:
 *   article.ecs-post-loop#post-{ID}             WordPress-Post-ID
 *     .elementor-heading-title mit <span>        Kategorie-Begriffe (ignoriert, fehlt bei manchen Einträgen)
 *     .elementor-heading-title                   Titel, Datum ("Fr, 16.10.26" bzw. "Di, 27.10. & Mi, 28.10.26"),
 *                                                "Beginn: 19:00 Uhr", "Einlass: 18:00 Uhr" (ignoriert)
 *     .elementor-widget-text-editor              Kurztext; Text "AUSVERKAUFT" ist ein Status (nicht übernommen)
 *     a.elementor-button "Mehr Infos"            Detailseite (fehlt bei ausverkauften Einträgen)
 */
class Eventcrawler_Source_Schuetzenhaus_Muenchberg extends Eventcrawler_Source {

	const LIST_URL = 'https://schuetzenhaus-muenchberg.de/veranstaltungen/';

	// Programm des Hauses: Seite und Termin-Template nennen für jeden Termin dieses Haus (docs/04-quellen.md Abschnitt 9).
	const VENUE   = 'Schützenhaus Münchberg';
	const ADDRESS = 'Hofer Straße 69';
	const CITY    = 'Münchberg';

	public function get_slug() {
		return 'schuetzenhaus-muenchberg';
	}

	public function get_name() {
		return 'Schützenhaus Münchberg';
	}

	public function get_url() {
		return self::LIST_URL;
	}

	/**
	 * Identifikation über external_id (Post-ID + laufende Nummer, Priorität 1).
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$html = $this->fetch_html( self::LIST_URL );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		return $this->parse( $html );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string $html HTML der Programmseite.
	 * @return array[]|WP_Error
	 */
	public function parse( $html ) {
		$xpath = $this->create_xpath( $html );
		$items = $xpath->query( "//article[contains(concat(' ', normalize-space(@class), ' '), ' ecs-post-loop ')]" );

		// Keine Einträge = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( ! $items || 0 === $items->length ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}

		$events = array();
		$seen   = array();
		foreach ( $items as $item ) {
			foreach ( $this->parse_item( $xpath, $item ) as $event ) {
				if ( null !== $event['external_id'] && isset( $seen[ $event['external_id'] ] ) ) {
					continue;
				}
				$seen[ (string) $event['external_id'] ] = true;
				$events[]                               = $event;
			}
		}
		return $events;
	}

	/**
	 * Ein Eintrag ergibt je genanntem Tag einen Termin.
	 *
	 * @param DOMXPath   $xpath XPath.
	 * @param DOMElement $item  article.ecs-post-loop.
	 * @return array[]
	 */
	private function parse_item( DOMXPath $xpath, DOMElement $item ) {
		$base = array(
			'title'         => '',
			'description'   => '',
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null, // Die Quelle nennt kein Ende.
			'end_time'      => null,
			'all_day'       => false,
			'venue_name'    => self::VENUE,
			'venue_address' => self::ADDRESS,
			'city'          => self::CITY,
			'event_url'     => self::LIST_URL, // ohne "Mehr Infos" (ausverkauft): öffentliche Programmseite
			'source_url'    => self::LIST_URL,
			'external_id'   => null,
		);

		if ( ! preg_match( '/^post-(\d+)$/', $item->getAttribute( 'id' ), $m ) ) {
			$base['_error'] = 'Post-ID fehlt';
			return array( $base );
		}
		$post_id   = $m[1];
		$date_text = '';
		$time_text = null;

		foreach ( $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-heading-title ')]", $item ) as $heading ) {
			if ( $xpath->query( './/span', $heading )->length ) {
				continue; // Kategorie-Begriffe.
			}
			$text = $this->clean_text( $heading );
			if ( preg_match( '/^Beginn:/u', $text ) ) {
				$time_text = $text;
			} elseif ( preg_match( '/^Einlass:/u', $text ) ) {
				continue; // Einlass ist nicht der Beginn.
			} elseif ( preg_match( '/^\p{L}{2},\s*\d{1,2}\.\d{1,2}\./u', $text ) ) {
				$date_text = $text;
			} elseif ( '' === $base['title'] && '' !== $text ) {
				$base['title'] = $text;
			}
		}

		foreach ( $xpath->query( ".//div[contains(concat(' ', normalize-space(@class), ' '), ' elementor-widget-text-editor ')]", $item ) as $block ) {
			$text = $this->clean_text( $block );
			if ( '' !== $text && 'AUSVERKAUFT' !== mb_strtoupper( $text ) ) {
				$base['description'] = $text; // Kurztext der Liste.
				break;
			}
		}

		foreach ( $xpath->query( ".//a[contains(concat(' ', normalize-space(@class), ' '), ' elementor-button ')]", $item ) as $link ) {
			if ( 'Mehr Infos' === $this->clean_text( $link ) ) {
				$url = $this->absolute_url( $link->getAttribute( 'href' ) );
				if ( '' !== $url ) {
					$base['event_url']  = $url;
					$base['source_url'] = $url;
				}
				break;
			}
		}

		if ( null !== $time_text ) {
			if ( ! preg_match( '/^Beginn:\s*(\d{1,2})[:.](\d{2})\s*Uhr$/u', $time_text, $t ) ) {
				$base['external_id'] = $post_id . '-1';
				$base['_error']      = 'Beginn nicht lesbar: ' . $time_text;
				return array( $base );
			}
			$base['start_time'] = sprintf( '%02d:%02d', $t[1], $t[2] );
		}

		$dates = $this->parse_dates( $date_text );
		if ( empty( $dates ) ) {
			$base['external_id'] = $post_id . '-1';
			$base['_error']      = 'Datum nicht lesbar oder Wochentag passt nicht: ' . ( '' === $date_text ? '(fehlt)' : $date_text );
			return array( $base );
		}

		$events = array();
		foreach ( $dates as $n => $date ) {
			$event                = $base;
			$event['start_date']  = $date;
			$event['external_id'] = $post_id . '-' . ( $n + 1 );
			$events[]             = $event;
		}
		return $events;
	}

	/**
	 * "Fr, 16.10.26" oder "Di, 27.10. & Mi, 28.10.26" -> array( 'Y-m-d', ... ).
	 * Das zweistellige Jahr steht am letzten Tag und gilt für alle Tage ohne eigenes Jahr; jeder Wochentag muss passen,
	 * sonst wird nichts zurückgegeben (z. B. Jahreswechsel innerhalb eines Eintrags oder Tippfehler der Quelle).
	 *
	 * @param string $text Datumszeile.
	 * @return string[] Leer, wenn nicht eindeutig lesbar.
	 */
	private function parse_dates( $text ) {
		$weekdays = array( 'mo' => 1, 'di' => 2, 'mi' => 3, 'do' => 4, 'fr' => 5, 'sa' => 6, 'so' => 7 );
		$parts    = array();
		foreach ( preg_split( '/\s*&\s*/u', trim( $text ) ) as $part ) {
			if ( ! preg_match( '/^(\p{L}{2}),\s*(\d{1,2})\.(\d{1,2})\.(\d{2})?$/u', $part, $m ) ) {
				return array();
			}
			$parts[] = $m;
		}
		$last = end( $parts );
		if ( empty( $last[4] ) ) {
			return array();
		}

		$dates = array();
		foreach ( $parts as $m ) {
			$year    = 2000 + (int) ( empty( $m[4] ) ? $last[4] : $m[4] );
			$weekday = mb_strtolower( $m[1] );
			if ( ! isset( $weekdays[ $weekday ] ) || ! checkdate( (int) $m[3], (int) $m[2], $year ) ) {
				return array();
			}
			$date = sprintf( '%04d-%02d-%02d', $year, $m[3], $m[2] );
			if ( (int) ( new DateTime( $date ) )->format( 'N' ) !== $weekdays[ $weekday ] ) {
				return array();
			}
			$dates[] = $date;
		}
		return $dates;
	}
}
