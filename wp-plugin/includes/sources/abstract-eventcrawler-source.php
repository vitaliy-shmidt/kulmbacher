<?php
/**
 * Basisklasse für Source Adapter.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein Adapter liest genau eine Quelle und liefert Events im internen Format (docs/03-crawler.md).
 * Keine Datenbank-, Filter-, Matching- oder Admin-Logik in Adaptern.
 */
abstract class Eventcrawler_Source {

	const HTTP_TIMEOUT = 20;

	/**
	 * Stabiler Wert für die Spalte `source`, z. B. "burg-rabenstein". Nie ändern, sobald Daten existieren.
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * @return string Anzeigename.
	 */
	abstract public function get_name();

	/**
	 * @return string Tatsächlich abgerufene URL.
	 */
	abstract public function get_url();

	/**
	 * Liefert die Events der Quelle.
	 *
	 * Jedes Element: title, description, start_date (Y-m-d), start_time (H:i|null), end_date (Y-m-d|null),
	 * end_time (H:i|null), all_day (bool), venue_name, city, event_url, source_url, external_id (string|null),
	 * optional venue_address (Straße/Hausnummer, nur aus einem strukturierten Adressfeld der Quelle, nie aus Fließtext).
	 * Fehlende Werte sind null – nie Ersatzwerte wie 00:00. Ungültige Einträge werden mit einer Begründung
	 * in '_error' geliefert, damit der Crawler sie als fehlerhaft zählt.
	 *
	 * @return array[]|WP_Error WP_Error, wenn die Quelle nicht gelesen werden konnte.
	 */
	abstract public function fetch_events();

	/**
	 * @return string[]|null Erlaubte Städte (Ortsregel, docs/05-filter.md) oder null = keine Beschränkung.
	 */
	public function get_allowed_cities() {
		return null;
	}

	/**
	 * True nur, wenn geprüft ist, dass jede Detail-URL genau einen Termin bezeichnet (Matching Priorität 2).
	 *
	 * @return bool
	 */
	public function has_unique_detail_urls() {
		return false;
	}

	/**
	 * Ruft eine Seite per wp_remote_get() ab.
	 *
	 * @param string   $url     URL.
	 * @param int|null $timeout Sekunden; Standard HTTP_TIMEOUT.
	 * @return string|WP_Error HTML oder Fehler.
	 */
	protected function fetch_html( $url, $timeout = null ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout ? (int) $timeout : self::HTTP_TIMEOUT,
				'redirection'         => 3,
				'user-agent'          => 'Eventcrawler/' . EVENTCRAWLER_VERSION . ' (WordPress; ' . home_url() . ')',
				'limit_response_size' => 5 * MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'eventcrawler_http', 'Quelle nicht erreichbar: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'eventcrawler_http', sprintf( 'Quelle antwortet mit HTTP %d.', $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'eventcrawler_http', 'Quelle liefert eine leere Antwort.' );
		}

		return self::to_utf8( $body, (string) wp_remote_retrieve_header( $response, 'content-type' ) );
	}

	/**
	 * Stellt sicher, dass der Body UTF-8 ist (create_xpath() liest immer als UTF-8, docs/03-crawler.md Abschnitt 3).
	 * Gültiges UTF-8 bleibt unverändert, auch wenn der Header etwas anderes behauptet (sonst entstünde "CafÃ©").
	 * Ungültiges UTF-8 wird nur umgewandelt, wenn der Content-Type einen bekannten Zeichensatz nennt; sonst wird die
	 * Quelle abgebrochen, statt falsch dekodierte Zeichen zu speichern.
	 *
	 * @param string $body         Antwort.
	 * @param string $content_type Header Content-Type, z. B. "text/html; charset=iso-8859-1".
	 * @return string|WP_Error
	 */
	protected static function to_utf8( $body, $content_type ) {
		if ( mb_check_encoding( $body, 'UTF-8' ) ) {
			return $body;
		}

		if ( preg_match( '/charset\s*=\s*"?([\w.:-]+)/i', $content_type, $m )
			&& ! in_array( strtolower( $m[1] ), array( 'utf-8', 'utf8' ), true )
			&& in_array( strtolower( $m[1] ), array_map( 'strtolower', mb_list_encodings() ), true ) ) {
			return mb_convert_encoding( $body, 'UTF-8', $m[1] );
		}

		return new WP_Error( 'eventcrawler_charset', 'Quelle liefert kein gültiges UTF-8 und keinen passenden Zeichensatz im Header.' );
	}

	/**
	 * Parst HTML (UTF-8) robust; libxml-Warnungen werden unterdrückt und verworfen.
	 *
	 * @param string $html HTML.
	 * @return DOMXPath
	 */
	protected function create_xpath( $html ) {
		$previous = libxml_use_internal_errors( true );

		$dom = new DOMDocument();
		// Die XML-Deklaration zwingt libxml zu UTF-8 statt ISO-8859-1.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $dom );
	}

	/**
	 * Klartext aus einem Knoten bzw. String: Entities, geschützte Leerzeichen, Zeilenumbrüche, Whitespace.
	 *
	 * @param DOMNode|string|null $value Knoten oder Text.
	 * @return string
	 */
	protected function clean_text( $value ) {
		if ( null === $value ) {
			return '';
		}
		$text = $value instanceof DOMNode ? $value->textContent : $value;
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Deutsche Datumsangabe ohne Jahr ("Donnerstag, 24. September") in Y-m-d umwandeln, unabhängig von der Server-Locale.
	 * Das Jahr kommt aus einem Referenzdatum (erster Tag der abgerufenen Seite): gleiches Jahr, oder das Folgejahr,
	 * wenn der Monat vor dem Referenzmonat liegt (Jahreswechsel). Ein angegebener Wochentag muss passen.
	 *
	 * @param string            $text      z. B. "Donnerstag, 24. September" oder "24. September".
	 * @param DateTimeInterface $reference Frühestes mögliches Datum der Seite.
	 * @return string|null Y-m-d oder null, wenn nicht eindeutig lesbar.
	 */
	protected function resolve_german_date( $text, DateTimeInterface $reference ) {
		$months   = array( 'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12 );
		$weekdays = array( 'montag' => 1, 'dienstag' => 2, 'mittwoch' => 3, 'donnerstag' => 4, 'freitag' => 5, 'samstag' => 6, 'sonntag' => 7 );

		if ( ! preg_match( '/^(?:(\p{L}+),\s*)?(\d{1,2})\.\s*(\p{L}+)$/u', trim( $text ), $m ) ) {
			return null;
		}
		$month = mb_strtolower( $m[3] );
		if ( ! isset( $months[ $month ] ) ) {
			return null;
		}
		$year = (int) $reference->format( 'Y' );
		if ( $months[ $month ] < (int) $reference->format( 'n' ) ) {
			$year++;
		}
		if ( ! checkdate( $months[ $month ], (int) $m[2], $year ) ) {
			return null;
		}
		$date = sprintf( '%04d-%02d-%02d', $year, $months[ $month ], (int) $m[2] );

		if ( '' !== $m[1] ) {
			$weekday = mb_strtolower( $m[1] );
			if ( ! isset( $weekdays[ $weekday ] ) || (int) ( new DateTime( $date ) )->format( 'N' ) !== $weekdays[ $weekday ] ) {
				return null; // Wochentag passt nicht zum errechneten Jahr -> nicht raten.
			}
		}
		return $date;
	}

	/**
	 * Erster Treffer eines relativen XPath-Ausdrucks oder null.
	 *
	 * @param DOMXPath $xpath   XPath.
	 * @param string   $query   Ausdruck.
	 * @param DOMNode  $context Kontextknoten.
	 * @return DOMNode|null
	 */
	protected function first( DOMXPath $xpath, $query, DOMNode $context ) {
		$nodes = $xpath->query( $query, $context );
		return ( $nodes && $nodes->length ) ? $nodes->item( 0 ) : null;
	}

	/**
	 * Macht eine (relative) URL absolut zur Basis der Quelle und entfernt Query und Fragment.
	 *
	 * @param string $href Link aus dem HTML.
	 * @return string
	 */
	protected function absolute_url( $href ) {
		$href = trim( $href );
		if ( '' === $href ) {
			return '';
		}
		if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
			$base = wp_parse_url( $this->get_url() );
			$href = $base['scheme'] . '://' . $base['host'] . $href;
		}
		$parts = wp_parse_url( $href );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
	}
}
