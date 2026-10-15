<?php
/**
 * Source Adapter: Burg Rabenstein (Ahorntal).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest die Übersichtsseite (TYPO3 + calendarize). Analyse und Selektoren: docs/04-quellen.md, Abschnitt 3.
 *
 * Pro Termin ein Container:
 *   div.single-news.event[data-startDate="TT.MM.JJJJ"]
 *     h3.event-headline                    Titel
 *     span.date                            "Wochentag - TT.MM.JJJJ HH:MM[ - HH:MM]"
 *     div.content > p (1.)                 Teaser, endet auf "[...]"
 *     div.content > p > strong "Preis:"    Preis (wird nicht gespeichert)
 *     a.stretched-link[href]               Detailseite, je Termin eindeutig
 */
class Eventcrawler_Source_Burg_Rabenstein extends Eventcrawler_Source {

	const VENUE = 'Burg Rabenstein';
	const CITY  = 'Ahorntal';

	public function get_slug() {
		return 'burg-rabenstein';
	}

	public function get_name() {
		return 'Burg Rabenstein';
	}

	public function get_url() {
		return 'https://www.burg-rabenstein.de/events/alle-events-im-ueberblick';
	}

	/**
	 * Geprüft am 2026-09-24: 124 Termine, 124 verschiedene Detail-URLs.
	 */
	public function has_unique_detail_urls() {
		return true;
	}

	public function fetch_events() {
		$html = $this->fetch_html( $this->get_url() );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return $this->parse( $html );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string $html HTML der Übersichtsseite.
	 * @return array[]|WP_Error
	 */
	public function parse( $html ) {
		$xpath      = $this->create_xpath( $html );
		$containers = $xpath->query(
			"//div[contains(concat(' ', normalize-space(@class), ' '), ' single-news ')"
			. " and contains(concat(' ', normalize-space(@class), ' '), ' event ')]"
		);

		// Keine Container = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( ! $containers || 0 === $containers->length ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}

		$events = array();
		foreach ( $containers as $container ) {
			$events[] = $this->parse_event( $xpath, $container );
		}

		return $events;
	}

	/**
	 * @param DOMXPath   $xpath     XPath.
	 * @param DOMElement $container Event-Container.
	 * @return array
	 */
	private function parse_event( DOMXPath $xpath, DOMElement $container ) {
		$link       = $this->first( $xpath, ".//a[contains(concat(' ', normalize-space(@class), ' '), ' stretched-link ')]", $container );
		$detail_url = $link ? $this->absolute_url( $link->getAttribute( 'href' ) ) : '';

		$event = array(
			'title'       => $this->clean_text( $this->first( $xpath, ".//h3[contains(concat(' ', normalize-space(@class), ' '), ' event-headline ')]", $container ) ),
			'description' => $this->parse_teaser( $xpath, $container ),
			'start_date'  => '',
			'start_time'  => null,
			'end_date'    => null,
			'end_time'    => null,
			'all_day'     => false, // Die Quelle kennzeichnet keine ganztägigen Termine (geprüft 2026-09-24).
			'venue_name'  => self::VENUE,
			'city'        => self::CITY,
			'event_url'   => $detail_url, // Öffentliche Detailseite.
			'source_url'  => $detail_url, // Dieselbe Seite; Grundlage des Matchings (Priorität 2).
			'external_id' => null, // Quelle hat keine unabhängige ID (ICS-UID = "calendarize-" + URL-Slug).
		);

		// Datum aus den strukturierten Attributen.
		$date     = DateTime::createFromFormat( '!d.m.Y', $container->getAttribute( 'data-startdate' ) );
		$end_attr = $container->getAttribute( 'data-enddate' );
		$end_date = '' === $end_attr ? null : DateTime::createFromFormat( '!d.m.Y', $end_attr );
		if ( ! $date ) {
			$event['_error'] = 'data-startDate fehlt oder ist ungültig';
			return $event;
		}
		if ( false === $end_date || ( $end_date && $end_date < $date ) ) {
			$event['_error'] = 'data-endDate ungültig: ' . $end_attr;
			return $event;
		}

		// Uhrzeit aus dem sichtbaren Text. Dessen Datum muss zum Attribut passen, sonst lieber überspringen.
		$date_text = $this->clean_text( $this->first( $xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' date ')]", $container ) );
		if ( ! preg_match( '/(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}):(\d{2}))?(?:\s*-\s*(\d{1,2}):(\d{2}))?/u', $date_text, $m ) ) {
			$event['_error'] = 'Datumszeile nicht lesbar: ' . $date_text;
			return $event;
		}
		if ( $m[1] !== $date->format( 'd.m.Y' ) ) {
			$event['_error'] = 'Datum im Text weicht von data-startDate ab: ' . $date_text;
			return $event;
		}

		$event['start_date'] = $date->format( 'Y-m-d' );
		if ( ! empty( $m[2] ) ) {
			$event['start_time'] = sprintf( '%02d:%02d', $m[2], $m[3] );
		}
		if ( ! empty( $m[4] ) ) {
			// "18:00 - 22:00": Ende laut data-endDate (derzeit immer = Startdatum).
			$event['end_time'] = sprintf( '%02d:%02d', $m[4], $m[5] );
			$event['end_date'] = $end_date ? $end_date->format( 'Y-m-d' ) : $event['start_date'];
		} elseif ( $end_date && $end_date > $date ) {
			// Echt mehrtägig laut Quelle, aber ohne Endzeit (kam bei der Prüfung nicht vor).
			$event['end_date'] = $end_date->format( 'Y-m-d' );
		}
		// Ohne Endzeit und mit data-endDate = Startdatum: keine Endinformation -> end_date bleibt NULL.

		return $event;
	}

	/**
	 * Erster Absatz ohne "Preis:"; das abschließende "[...]" der Kürzung wird entfernt.
	 *
	 * @param DOMXPath   $xpath     XPath.
	 * @param DOMElement $container Event-Container.
	 * @return string
	 */
	private function parse_teaser( DOMXPath $xpath, DOMElement $container ) {
		$paragraphs = $xpath->query( ".//div[contains(concat(' ', normalize-space(@class), ' '), ' content ')]/p", $container );
		foreach ( $paragraphs as $p ) {
			$text = $this->clean_text( $p );
			if ( '' === $text || 0 === strpos( $text, 'Preis:' ) ) {
				continue;
			}
			return trim( preg_replace( '/\s*\[\.\.\.\]$/u', '', $text ) );
		}
		return '';
	}
}
