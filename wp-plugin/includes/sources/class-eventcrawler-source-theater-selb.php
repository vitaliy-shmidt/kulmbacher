<?php
/**
 * Source Adapter: Theater Selb (Rosenthal-Theater Selb).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest den Spielplan von theater-selb.de (WordPress, Theme-Widget "gmk-theater") und je Termin die Detailseite.
 * Jede Aufführung ist ein eigener WordPress-Beitrag; die Liste enthält alle kommenden Termine (keine Paginierung).
 * Analyse und Selektoren: docs/04-quellen.md, Abschnitt 4.
 *
 * Liste /spielplan/, je Termin:
 *   div.gmk-theater-single
 *     div.gmk-theater-single-date            "Samstag, 17. Oktober 2026"
 *     a.gmk-theater-single-title[href]       Titel, Detailseite
 *     div.gmk-theater-single-infos > p       span "Genre: " / "Beginn: " / "Veranstalter: " + span Wert
 * Detailseite:
 *   article#post-{ID}                        WordPress-Post-ID (je Termin, bleibt bei Verschiebung gleich)
 *   div.single-post-main-text                Untertitel (-> description)
 *   div.single-play-infos-overview-item      p "Datum: " / "Beginn: " / "Adresse: " / … + span Wert
 */
class Eventcrawler_Source_Theater_Selb extends Eventcrawler_Source {

	const LIST_URL = 'https://www.theater-selb.de/spielplan/';

	// Spielstätte laut Seitenfooter: "Rosenthal-Theater Selb, Hohenberger Str. 9, 95100 Selb". Wird nur gesetzt, wenn
	// die Adresse des Termins genau diese Straße ist (alle 30 Termine am 2026-10-15); sonst nichts raten.
	const VENUE        = 'Rosenthal-Theater Selb';
	const CITY         = 'Selb';
	const VENUE_STREET = 'hohenbergerstr9'; // normalisiert, siehe normalize_street().

	public function get_slug() {
		return 'theater-selb';
	}

	public function get_name() {
		return 'Theater Selb';
	}

	public function get_url() {
		return self::LIST_URL;
	}

	/**
	 * Identifikation über die Post-ID (external_id, Priorität 1).
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	public function fetch_events() {
		$html = $this->fetch_html( self::LIST_URL );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		$events = $this->parse_list( $html );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		// Je Termin eine Detailseite (Adresse, Untertitel, Post-ID), nacheinander mit Pause.
		$details = array();
		foreach ( $events as $event ) {
			if ( empty( $event['_error'] ) ) {
				sleep( 1 ); // Höflich: kurze Pause zwischen den Seiten.
				$details[ $event['event_url'] ] = $this->fetch_html( $event['event_url'] );
			}
		}

		return $this->apply_details( $events, $details );
	}

	/**
	 * Öffentlich, damit der Parser mit lokal gespeichertem HTML getestet werden kann.
	 *
	 * @param string $list_html HTML der Liste /spielplan/.
	 * @param array  $details   Detail-URL => HTML (oder WP_Error).
	 * @return array[]|WP_Error
	 */
	public function parse( $list_html, array $details ) {
		$events = $this->parse_list( $list_html );
		return is_wp_error( $events ) ? $events : $this->apply_details( $events, $details );
	}

	/**
	 * @param string $html HTML der Liste.
	 * @return array[]|WP_Error
	 */
	public function parse_list( $html ) {
		$xpath = $this->create_xpath( $html );
		$items = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' gmk-theater-single ')]" );

		// Keine Container = Seitenstruktur geändert. Nicht als "0 Events" werten.
		if ( ! $items || 0 === $items->length ) {
			return new WP_Error( 'eventcrawler_structure', 'Keine Veranstaltungen gefunden – die Seitenstruktur hat sich vermutlich geändert.' );
		}

		$events = array();
		$seen   = array();
		foreach ( $items as $item ) {
			$event = $this->parse_item( $xpath, $item );
			if ( '' !== $event['event_url'] && isset( $seen[ $event['event_url'] ] ) ) {
				continue;
			}
			$seen[ $event['event_url'] ] = true;
			$events[]                    = $event;
		}
		return $events;
	}

	/**
	 * @param DOMXPath   $xpath XPath.
	 * @param DOMElement $item  div.gmk-theater-single.
	 * @return array
	 */
	private function parse_item( DOMXPath $xpath, DOMElement $item ) {
		$link = $this->first( $xpath, ".//a[contains(concat(' ', normalize-space(@class), ' '), ' gmk-theater-single-title ')]", $item );
		$url  = $link ? $this->absolute_url( $link->getAttribute( 'href' ) ) : '';

		$event = array(
			'title'         => $this->clean_text( $link ),
			'description'   => '', // Untertitel der Detailseite
			'start_date'    => '',
			'start_time'    => null,
			'end_date'      => null, // Die Quelle nennt keine Endzeit.
			'end_time'      => null,
			'all_day'       => false, // Die Quelle kennzeichnet keine Ganztagstermine.
			'venue_name'    => '',
			'venue_address' => '',
			'city'          => '',
			'event_url'     => $url,
			'source_url'    => $url,
			'external_id'   => null, // Post-ID der Detailseite
		);

		if ( '' === $url ) {
			$event['_error'] = 'Detail-Link fehlt';
			return $event;
		}

		// "Samstag, 17. Oktober 2026": Jahr steht in der Quelle. Referenz 1. Januar dieses Jahres -> kein Jahreswechsel,
		// der Wochentag wird von resolve_german_date() geprüft.
		$date_text = $this->clean_text( $this->first( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' gmk-theater-single-date ')]", $item ) );
		if ( preg_match( '/^(.+?)\s+(\d{4})$/u', $date_text, $m ) ) {
			$event['start_date'] = (string) $this->resolve_german_date( $m[1], new DateTimeImmutable( $m[2] . '-01-01' ) );
		}
		if ( '' === $event['start_date'] ) {
			$event['_error'] = 'Datum nicht lesbar: ' . $date_text;
			return $event;
		}

		// Nur die ausdrücklich als "Beginn" bezeichnete Uhrzeit (kein Einlass).
		$infos = $this->label_values( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' gmk-theater-single-infos ')]/p", 'span[1]', 'span[2]', $item );
		if ( isset( $infos['Beginn'] ) && '' !== $infos['Beginn'] ) {
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $infos['Beginn'], $t ) ) {
				$event['_error'] = 'Beginn nicht lesbar: ' . $infos['Beginn'];
				return $event;
			}
			$event['start_time'] = sprintf( '%02d:%02d', $t[1], $t[2] );
		}

		return $event;
	}

	/**
	 * Ergänzt die Termine um die Daten der Detailseiten.
	 *
	 * @param array[] $events  Termine aus der Liste.
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
			$events[ $i ] = $this->parse_detail( $event, $html );
		}
		return $events;
	}

	/**
	 * @param array  $event Termin aus der Liste.
	 * @param string $html  HTML der Detailseite.
	 * @return array
	 */
	private function parse_detail( array $event, $html ) {
		$xpath   = $this->create_xpath( $html );
		$article = $xpath->query( '//article[starts-with(@id, "post-")]' )->item( 0 );
		if ( ! $article || ! preg_match( '/^post-(\d+)$/', $article->getAttribute( 'id' ), $m ) ) {
			$event['_error'] = 'Post-ID auf der Detailseite nicht gefunden';
			return $event;
		}
		$event['external_id'] = $m[1];

		$root  = $xpath->document->documentElement;
		$infos = $this->label_values( $xpath, "//div[contains(concat(' ', normalize-space(@class), ' '), ' single-play-infos-overview-item ')]", 'p[1]', 'span[1]', $root );

		// Liste und Detailseite müssen dasselbe Datum und denselben Beginn nennen, sonst nicht raten.
		$date = isset( $infos['Datum'] ) ? DateTime::createFromFormat( '!d.m.Y', $infos['Datum'] ) : false;
		if ( ! $date || $date->format( 'Y-m-d' ) !== $event['start_date'] ) {
			$event['_error'] = 'Datum der Detailseite passt nicht zur Liste: ' . ( isset( $infos['Datum'] ) ? $infos['Datum'] : '(fehlt)' );
			return $event;
		}
		$time = isset( $infos['Beginn'] ) && preg_match( '/^(\d{1,2}):(\d{2})$/', $infos['Beginn'], $t ) ? sprintf( '%02d:%02d', $t[1], $t[2] ) : null;
		if ( $time !== $event['start_time'] ) {
			$event['_error'] = 'Beginn der Detailseite passt nicht zur Liste: ' . ( isset( $infos['Beginn'] ) ? $infos['Beginn'] : '(fehlt)' );
			return $event;
		}

		$event['description'] = $this->clean_text( $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' single-post-main-text ')]" )->item( 0 ) );

		// Adresse: Straße unverändert als venue_address; eine angehängte "PLZ Stadt" wird abgetrennt.
		$address = isset( $infos['Adresse'] ) ? $infos['Adresse'] : '';
		$city    = '';
		if ( preg_match( '/^(.*[^\s,])\s*,?\s+\d{5}\s+(\S.*)$/u', $address, $a ) ) {
			$address = $a[1];
			$city    = $a[2];
		}
		if ( '' === $address ) {
			$event['_error'] = 'Adresse fehlt';
			return $event;
		}
		$event['venue_address'] = $address;

		if ( self::VENUE_STREET === $this->normalize_street( $address ) && ( '' === $city || self::CITY === $city ) ) {
			$event['venue_name'] = self::VENUE;
			$event['city']       = self::CITY;
		} elseif ( '' !== $city ) {
			$event['city'] = $city; // anderer Ort mit Stadtangabe: Spielstätte unbekannt, nicht raten.
		} else {
			$event['_error'] = 'Adresse ist nicht die des Rosenthal-Theaters und nennt keine Stadt: ' . $address;
		}

		return $event;
	}

	/**
	 * Paare "Bezeichnung: " => Wert aus wiederholten Blöcken (Bezeichnung ohne Doppelpunkt).
	 *
	 * @param DOMXPath $xpath       XPath.
	 * @param string   $block_query Blöcke.
	 * @param string   $label_query Bezeichnung relativ zum Block.
	 * @param string   $value_query Wert relativ zum Block.
	 * @param DOMNode  $context     Kontext.
	 * @return string[]
	 */
	private function label_values( DOMXPath $xpath, $block_query, $label_query, $value_query, DOMNode $context ) {
		$values = array();
		foreach ( $xpath->query( $block_query, $context ) as $block ) {
			$label = rtrim( $this->clean_text( $this->first( $xpath, $label_query, $block ) ), ': ' );
			if ( '' !== $label && ! isset( $values[ $label ] ) ) {
				$values[ $label ] = $this->clean_text( $this->first( $xpath, $value_query, $block ) );
			}
		}
		return $values;
	}

	/**
	 * Vergleichsform einer Straße: "Hohenberger Straße 9", "Hohenbergerstrasse 9", "Hohenberger Str. 9" -> "hohenbergerstr9".
	 *
	 * @param string $street Straße.
	 * @return string
	 */
	private function normalize_street( $street ) {
		$street = str_replace( array( 'straße', 'strasse' ), 'str', mb_strtolower( $street ) );
		return preg_replace( '/[\s.]+/u', '', $street );
	}
}
