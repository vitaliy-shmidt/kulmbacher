<?php
/**
 * XML-Export im bestehenden Minimalformat.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt <Root><Textabschnitt> mit h1 (Datum), h2 (Stadt), p (Veranstaltung). Format: docs/07-export.md.
 * Reine Leseoperation; keine HTTP-Abrufe, keine Änderungen an Events.
 */
class Eventcrawler_Exporter {

	/**
	 * Feste deutsche Namen, unabhängig von Server-Locale und WordPress-Sprache.
	 */
	const MONTHS = array(
		1  => 'Januar',
		2  => 'Februar',
		3  => 'März',
		4  => 'April',
		5  => 'Mai',
		6  => 'Juni',
		7  => 'Juli',
		8  => 'August',
		9  => 'September',
		10 => 'Oktober',
		11 => 'November',
		12 => 'Dezember',
	);

	/**
	 * Schlüssel = ISO-Wochentag (DateTime::format( 'N' )).
	 */
	const WEEKDAYS = array(
		1 => 'Montag',
		2 => 'Dienstag',
		3 => 'Mittwoch',
		4 => 'Donnerstag',
		5 => 'Freitag',
		6 => 'Samstag',
		7 => 'Sonntag',
	);

	/**
	 * @var Eventcrawler_Repository
	 */
	private $repository;

	/**
	 * Anzahl der Events, die mangels Stadt nicht exportiert wurden (nach build_xml()).
	 *
	 * @var int
	 */
	public $skipped = 0;

	/**
	 * Anzahl exportierter Events (nach build_xml()).
	 *
	 * @var int
	 */
	public $exported = 0;

	/**
	 * Anzahl der <p>-Zeilen (nach build_xml()); kleiner als $exported, wenn Termine zusammengefasst wurden.
	 *
	 * @var int
	 */
	public $lines = 0;

	/**
	 * @param Eventcrawler_Repository $repository Datenzugriff.
	 */
	public function __construct( Eventcrawler_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Dateiname mit dem heutigen Datum in der WordPress-Zeitzone.
	 *
	 * @return string
	 */
	public static function filename() {
		return 'veranstaltungen-export-' . wp_date( 'Y-m-d' ) . '.xml';
	}

	/**
	 * Erzeugt das XML aller gespeicherten Events bzw. der zum Filter passenden (Auswahl im Repository, nicht im XML).
	 *
	 * @param array $filters source, month, year (siehe Eventcrawler_Repository::where_clause()); leer = alle.
	 * @return string|WP_Error XML oder Fehler (keine Events, XMLWriter fehlt, Ergebnis nicht wohlgeformt).
	 */
	public function build_xml( array $filters = array() ) {
		if ( ! class_exists( 'XMLWriter' ) ) {
			return new WP_Error( 'eventcrawler_export_xmlwriter', 'Die PHP-Erweiterung XMLWriter ist nicht verfügbar.' );
		}

		$events         = $this->repository->get_events_for_export( $filters );
		$domain_labels  = Eventcrawler_Domain_Mappings::get_lookup(); // einmal je Export, nicht je Event
		$this->skipped  = 0;
		$this->exported = 0;

		$xml = new XMLWriter();
		$xml->openMemory();
		// Äußere Form wie der in InDesign bewährte Export von WP Kulmbacher Land (docs/07-export.md Abschnitt 11):
		// ein Element je Zeile, LF, keine Einrückung, kein Zeilenumbruch nach </Root>.
		$xml->setIndent( true );
		$xml->setIndentString( '' );
		$xml->startDocument( '1.0', 'UTF-8' );
		$xml->startElement( 'Root' );
		$xml->startElement( 'Textabschnitt' );

		$current_date = null;
		$current_city = null;
		$this->lines  = 0;

		foreach ( self::group_times( $events, $domain_labels ) as $line ) {
			$event = $line['event'];
			$city  = trim( (string) $event['city'] );
			$h1    = self::format_date( $event['start_date'] );

			// Ohne Stadt kein <h2> – nicht unter einem erfundenen Ort einsortieren.
			if ( '' === $city || null === $h1 ) {
				$this->skipped += $line['count'];
				continue;
			}

			if ( $event['start_date'] !== $current_date ) {
				$xml->writeElement( 'h1', $h1 );
				$current_date = $event['start_date'];
				$current_city = null;
			}
			if ( $city !== $current_city ) {
				$xml->writeElement( 'h2', $city );
				$current_city = $city;
			}

			$xml->writeElement( 'p', self::format_times( $line['times'] ) . $line['body'] );
			$this->exported += $line['count'];
			$this->lines++;
		}

		$xml->endElement(); // Textabschnitt.
		$xml->endElement(); // Root.
		$xml->endDocument();
		$output = $xml->outputMemory();
		if ( "\n" === substr( $output, -1 ) ) {
			$output = substr( $output, 0, -1 );
		}

		if ( 0 === $this->exported ) {
			return new WP_Error( 'eventcrawler_export_empty', 'Keine Veranstaltungen für den Export vorhanden.' );
		}

		if ( ! self::is_well_formed( $output ) ) {
			return new WP_Error( 'eventcrawler_export_invalid', 'Die XML-Datei konnte nicht fehlerfrei erzeugt werden.' );
		}

		return $output;
	}

	/**
	 * "2026-09-04" -> "04. September Freitag".
	 *
	 * @param string $ymd Datum Y-m-d.
	 * @return string|null Null bei ungültigem Datum.
	 */
	public static function format_date( $ymd ) {
		$date = DateTime::createFromFormat( '!Y-m-d', (string) $ymd );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $ymd ) {
			return null;
		}

		return $date->format( 'd' ) . '. ' . self::MONTHS[ (int) $date->format( 'n' ) ] . ' ' . self::WEEKDAYS[ (int) $date->format( 'N' ) ];
	}

	/**
	 * Fasst die sortierten Termine zu <p>-Zeilen zusammen (docs/07-export.md Abschnitt 12).
	 *
	 * Mehrere Termine ergeben nur dann EINE Zeile, wenn alles außer der Startzeit übereinstimmt: Quelle, Datum, Stadt,
	 * Titel, Beschreibung, Veranstaltungsort, Adresse und die fertige Zeile ohne Uhrzeit (inkl. sichtbarer URL).
	 * Nur Termine mit Uhrzeit, nie ganztägige oder solche ohne Uhrzeit. Der Vergleich ist exakt (kein Fuzzy-Matching).
	 * Die Datensätze in der Datenbank bleiben getrennt.
	 *
	 * @param array[]  $events        Datensätze in Export-Reihenfolge (Repository::get_events_for_export()).
	 * @param string[] $domain_labels Aktive Link-Bezeichnungen, siehe format_body().
	 * @return array[] Je Zeile: event (erster Termin), times (H:i), body (Zeile ohne Uhrzeit), count (Anzahl Termine).
	 */
	public static function group_times( array $events, array $domain_labels = array() ) {
		$lines = array();
		$index = array();

		foreach ( $events as $event ) {
			$body = self::format_body( $event, $domain_labels );
			$time = empty( $event['start_time'] ) ? null : substr( $event['start_time'], 0, 5 );

			if ( null !== $time && empty( $event['all_day'] ) ) {
				$key = array();
				foreach ( array( 'source', 'start_date', 'city', 'title', 'description', 'venue_name', 'venue_address' ) as $field ) {
					$key[] = self::clean( isset( $event[ $field ] ) ? $event[ $field ] : '' );
				}
				$key[] = $body;
				$key   = implode( "\x1F", $key );

				if ( isset( $index[ $key ] ) ) {
					$lines[ $index[ $key ] ]['times'][] = $time;
					$lines[ $index[ $key ] ]['count']++;
					continue;
				}
				$index[ $key ] = count( $lines );
			}

			$lines[] = array(
				'event' => $event,
				'times' => null === $time ? array() : array( $time ),
				'body'  => $body,
				'count' => 1,
			);
		}

		return $lines;
	}

	/**
	 * Uhrzeiten-Präfix: "18:30 Uhr ", "18:30 Uhr & 20:00 Uhr ", "18:30 Uhr, 20:00 Uhr & 21:30 Uhr ".
	 * Chronologisch sortiert, doppelte Uhrzeiten nur einmal. Leer ohne Uhrzeit.
	 *
	 * @param string[] $times Uhrzeiten H:i.
	 * @return string
	 */
	public static function format_times( array $times ) {
		$times = array_values( array_unique( $times ) );
		if ( ! $times ) {
			return '';
		}
		sort( $times, SORT_STRING );
		$labels = array();
		foreach ( $times as $time ) {
			$labels[] = $time . ' Uhr';
		}
		$last = array_pop( $labels );
		return ( $labels ? implode( ', ', $labels ) . ' & ' : '' ) . $last . ' ';
	}

	/**
	 * Veranstaltungszeile eines einzelnen Termins: "[HH:MM Uhr ]" + format_body().
	 *
	 * @param array    $event         Datensatz.
	 * @param string[] $domain_labels Aktive Link-Bezeichnungen, siehe format_body().
	 * @return string
	 */
	public static function format_line( array $event, array $domain_labels = array() ) {
		$times = empty( $event['start_time'] ) ? array() : array( substr( $event['start_time'], 0, 5 ) );
		return self::format_times( $times ) . self::format_body( $event, $domain_labels );
	}

	/**
	 * Zeile ohne Uhrzeit: "Titel[, Beschreibung][, Veranstaltungsort][, Adresse][. Info: Domain]".
	 *
	 * Endet ein Bestandteil bereits mit ".", "!" oder "?", folgt nur ein Leerzeichen statt ", " bzw. ". ".
	 * Redundanzregeln (07-export.md Abschnitte 6 und 12), exakt bzw. als ganze Wortfolge, ohne Fuzzy-Matching:
	 * - Beginnt die Beschreibung mit dem Titel, wird der Titel nicht wiederholt.
	 * - Veranstaltungsort bzw. Adresse entfallen, wenn sie nur die Stadt wiederholen (die steht im <h2>) oder als ganze
	 *   Wortfolge schon im Titel oder in der Beschreibung stehen.
	 * - Ein Bestandteil, der wortgleich einem vorherigen entspricht, entfällt.
	 * Der Titel bleibt immer, Beschreibungen werden nicht gekürzt. Rückgabe ist Klartext; das Escaping übernimmt XMLWriter.
	 *
	 * @param array    $event         Datensatz.
	 * @param string[] $domain_labels Aktive Link-Bezeichnungen (Domain => Ausgabetext, Eventcrawler_Domain_Mappings::get_lookup()).
	 *                                Leer = überall die Domain.
	 * @return string
	 */
	public static function format_body( array $event, array $domain_labels = array() ) {
		$title       = self::clean( isset( $event['title'] ) ? $event['title'] : '' );
		$description = self::clean( isset( $event['description'] ) ? $event['description'] : '' );
		$venue       = self::clean( isset( $event['venue_name'] ) ? $event['venue_name'] : '' );
		$address     = self::clean( isset( $event['venue_address'] ) ? $event['venue_address'] : '' );
		$city        = self::clean( isset( $event['city'] ) ? $event['city'] : '' );

		// Ort steht schon in der Zeile ("Führung im Haus Marteau" + "Haus Marteau") oder ist nur die Stadt -> nicht
		// wiederholen. Die Stadt wird nie aus Eigennamen entfernt ("Tourist-Information Coburg" bleibt).
		$venue   = self::is_redundant( $venue, $city, $title, $description ) ? '' : $venue;
		$address = self::is_redundant( $address, $city, $title, $description ) ? '' : $address;

		// Beschreibung wiederholt den Titel am Anfang: "Whisky-Dinner" + "Whisky-Dinner mit …" -> "Whisky-Dinner mit …".
		$first = $title;
		$rest  = self::strip_leading_phrase( $description, $title );
		if ( null !== $rest ) {
			$first      .= ( '' === $rest || preg_match( '/^[,.;:!?…]/u', $rest ) ? '' : ' ' ) . $rest;
			$description = '';
		}

		$parts = array();
		foreach ( array( $first, $description, $venue, $address ) as $value ) {
			if ( '' !== $value && ! in_array( mb_strtolower( $value ), array_map( 'mb_strtolower', $parts ), true ) ) {
				$parts[] = $value;
			}
		}

		$line = '';
		foreach ( $parts as $part ) {
			$line .= ( '' === $line ? '' : ( self::ends_sentence( $line ) ? ' ' : ', ' ) ) . $part;
		}

		// Öffentlicher Link bevorzugt, sonst die Herkunftsseite. Endzeit wird bewusst NICHT exportiert (07-export.md).
		$url = trim( (string) ( isset( $event['event_url'] ) ? $event['event_url'] : '' ) );
		if ( '' === $url ) {
			$url = trim( (string) ( isset( $event['source_url'] ) ? $event['source_url'] : '' ) );
		}
		if ( '' !== $url ) {
			$display = self::display_url( $url );
			// Aktive Link-Bezeichnung aus dem Backend (docs/07-export.md Abschnitt 13), nur für einen erkannten Hostnamen.
			if ( preg_match( '#^https?://#i', $url ) && isset( $domain_labels[ $display ] ) ) {
				$display = $domain_labels[ $display ];
			}
			/**
			 * Sichtbare URL im XML. Standard: die aktive Link-Bezeichnung der Domain, sonst nur der Hostname
			 * (Kundenwunsch), z. B. "pages.et4.de". Die gespeicherte URL bleibt vollständig. Wer das XML in
			 * WP Kulmbacher Land importiert, braucht die volle URL (docs/09-wpem-integration.md):
			 * add_filter( 'eventcrawler_export_display_url', function ( $display, $url ) { return $url; }, 10, 2 );
			 *
			 * @param string $display Sichtbare URL bzw. Link-Bezeichnung.
			 * @param string $url     Gespeicherte, vollständige URL.
			 */
			$display = (string) apply_filters( 'eventcrawler_export_display_url', $display, $url );
			$line   .= ( self::ends_sentence( $line ) ? ' ' : '. ' ) . 'Info: ' . $display;
		}

		return $line;
	}

	/**
	 * Sichtbare URL: bei "http://" bzw. "https://" nur der Hostname in Kleinbuchstaben ("www." bleibt; Pfad, Query,
	 * Fragment, Port und Zugangsdaten entfallen). Andere Werte (mailto:, ftp://, ohne Schema) und URLs ohne
	 * erkennbaren Host bleiben unverändert.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function display_url( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? strtolower( $host ) : $url;
	}

	/**
	 * Ort bzw. Adresse ist überflüssig, wenn er nur die Stadt ist oder als ganze Wortfolge schon in Titel oder
	 * Beschreibung steht (Groß-/Kleinschreibung egal, kein Fuzzy-Matching).
	 *
	 * @param string $value       Ort oder Adresse (bereinigt).
	 * @param string $city        Stadt (bereinigt).
	 * @param string $title       Titel (bereinigt).
	 * @param string $description Beschreibung (bereinigt).
	 * @return bool
	 */
	private static function is_redundant( $value, $city, $title, $description ) {
		return '' !== $value && ( mb_strtolower( $value ) === mb_strtolower( $city )
			|| self::contains_phrase( $title, $value ) || self::contains_phrase( $description, $value ) );
	}

	/**
	 * Klartext: HTML entfernen, Entities dekodieren (einmalig), unsichtbare Zeichen (Zero-Width-Space, BOM) entfernen,
	 * Whitespace inkl. Zeilenumbrüchen zusammenfassen, Kommata/Semikolons/Doppelpunkte am Rand entfernen.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xE2\x80\x8B", "\xEF\xBB\xBF" ), '', $text );
		$text = preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $text ) );
		return trim( $text, " ,;:\t\n\r\0\x0B" );
	}

	/**
	 * Beginnt $text mit $phrase (Groß-/Kleinschreibung egal, danach Wortgrenze), liefert den Rest ohne führende
	 * Leerzeichen, sonst null.
	 *
	 * @param string $text   Text.
	 * @param string $phrase Gesuchter Anfang.
	 * @return string|null
	 */
	private static function strip_leading_phrase( $text, $phrase ) {
		if ( '' === $text || '' === $phrase ) {
			return null;
		}
		if ( ! preg_match( '/^' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])(.*)$/iu', $text, $m ) ) {
			return null;
		}
		return ltrim( $m[1] );
	}

	/**
	 * Kommt $phrase als ganzes Wort bzw. ganze Wortfolge in $text vor (Groß-/Kleinschreibung egal)?
	 *
	 * @param string $text   Text.
	 * @param string $phrase Wortfolge.
	 * @return bool
	 */
	private static function contains_phrase( $text, $phrase ) {
		return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu', $text );
	}

	/**
	 * @param string $text Text.
	 * @return bool
	 */
	private static function ends_sentence( $text ) {
		return (bool) preg_match( '/[.!?…]$/u', $text );
	}

	/**
	 * Kontrolle vor dem Download: Das XML muss sich fehlerfrei wieder einlesen lassen.
	 *
	 * @param string $xml XML.
	 * @return bool
	 */
	private static function is_well_formed( $xml ) {
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$ok       = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return (bool) $ok;
	}
}
