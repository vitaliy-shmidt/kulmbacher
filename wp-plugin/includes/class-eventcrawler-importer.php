<?php
/**
 * Dateiimport (CSV/XML) direkt in {prefix}eventcrawler_events.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest CSV- und XML-Dateien, ordnet Spalten den Eventcrawler-Feldern zu, prüft jede Zeile mit denselben Regeln wie
 * manuelle Termine und schreibt erst nach einem Probelauf (docs/11-import.md).
 *
 * - Ziel ist ausschließlich die Eventcrawler-Tabelle; kein wpem_event, keine WordPress-Posts.
 * - Jede Importdatei gehört zu einer vom Admin festgelegten Importquelle (`source`, z. B. „kulturamt-import“), die
 *   weder ein Crawler-Slug noch „manual“ sein darf. So können sich Import, Crawler und manuelle Termine nicht
 *   gegenseitig überschreiben.
 * - Matching nur deterministisch: source + external_id; ohne external_id der event_key (source, Titel, Stadt, Datum)
 *   wie beim Crawler, nur unter Zeilen derselben Quelle ohne external_id. Kein unscharfer Abgleich.
 * - Fehlt ein Termin in einer späteren Datei, wird er NICHT gelöscht.
 */
class Eventcrawler_Importer {

	/**
	 * Maximale Dateigröße in Byte (2 MB ≈ 10.000 Termine) und maximale Anzahl Datenzeilen.
	 */
	const MAX_BYTES = 2097152;
	const MAX_ROWS  = 10000;

	/**
	 * Option: Importquellen (Slug => Bezeichnung, letzter Import) und letzter Import (für die Übersicht).
	 */
	const SOURCES_OPTION     = 'eventcrawler_import_sources';
	const LAST_IMPORT_OPTION = 'eventcrawler_last_import';

	/**
	 * Zielfelder mit Bezeichnung (Reihenfolge = Anzeige im Mapping).
	 */
	const FIELDS = array(
		'external_id'   => 'Externe ID',
		'title'         => 'Titel',
		'description'   => 'Beschreibung',
		'start_date'    => 'Beginn – Datum',
		'start_time'    => 'Beginn – Uhrzeit',
		'end_date'      => 'Ende – Datum',
		'end_time'      => 'Ende – Uhrzeit',
		'all_day'       => 'Ganztägig',
		'venue_name'    => 'Veranstaltungsort',
		'venue_address' => 'Adresse',
		'city'          => 'Stadt',
		'event_url'     => 'Webseiten-Link',
		'source_url'    => 'Herkunfts-URL',
	);

	/**
	 * Pflichtfelder einer Zeile.
	 */
	const REQUIRED = array( 'title', 'start_date', 'city' );

	/**
	 * Erkennbare Trennzeichen (Schlüssel für Formulare/URLs).
	 */
	const DELIMITERS = array(
		'semicolon' => array( ';', 'Semikolon (;)' ),
		'comma'     => array( ',', 'Komma (,)' ),
		'tab'       => array( "\t", 'Tabulator' ),
	);

	/**
	 * Vorschläge für das CSV-Mapping: nur exakt bekannte Spaltennamen (klein geschrieben, ohne Leerzeichen am Rand).
	 * Keine Teilstrings, keine Ähnlichkeit – der Admin bestätigt die Zuordnung.
	 */
	const HEADER_SUGGESTIONS = array(
		'external_id'       => 'external_id',
		'externe id'        => 'external_id',
		'title'             => 'title',
		'titel'             => 'title',
		'description'       => 'description',
		'beschreibung'      => 'description',
		'start_date'        => 'start_date',
		'datum'             => 'start_date',
		'startdatum'        => 'start_date',
		'start_time'        => 'start_time',
		'beginn'            => 'start_time',
		'uhrzeit'           => 'start_time',
		'end_date'          => 'end_date',
		'enddatum'          => 'end_date',
		'end_time'          => 'end_time',
		'ende'              => 'end_time',
		'endzeit'           => 'end_time',
		'all_day'           => 'all_day',
		'ganztägig'         => 'all_day',
		'ganztaegig'        => 'all_day',
		'venue_name'        => 'venue_name',
		'ort'               => 'venue_name',
		'veranstaltungsort' => 'venue_name',
		'venue_address'     => 'venue_address',
		'adresse'           => 'venue_address',
		'city'              => 'city',
		'stadt'             => 'city',
		'event_url'         => 'event_url',
		'url'               => 'event_url',
		'link'              => 'event_url',
		'webseiten-link'    => 'event_url',
		'source_url'        => 'source_url',
	);

	/**
	 * Für Importe gesperrte Quellen-Slugs (zusätzlich zu allen Crawler-Slugs).
	 */
	const RESERVED_SOURCES = array( 'manual', 'dev-test', 'all' );

	/**
	 * @var Eventcrawler_Repository
	 */
	private $repository;

	/**
	 * @param Eventcrawler_Repository $repository Datenzugriff.
	 */
	public function __construct( Eventcrawler_Repository $repository ) {
		$this->repository = $repository;
	}

	/* ------------------------------------------------------------------ Importquellen */

	/**
	 * @return array Slug => array( label, last_import, stats ).
	 */
	public static function import_sources() {
		$sources = get_option( self::SOURCES_OPTION, array() );
		if ( ! is_array( $sources ) ) {
			return array();
		}
		$valid = array();
		foreach ( $sources as $slug => $data ) {
			if ( is_string( $slug ) && is_array( $data ) && isset( $data['label'] ) && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				$valid[ $slug ] = $data;
			}
		}
		return $valid;
	}

	/**
	 * Anzeigename einer beliebigen Quelle: Importquelle, sonst Crawler-Register bzw. „Manuell“.
	 *
	 * @param string $slug Quelle.
	 * @return string
	 */
	public static function source_name( $slug ) {
		$imports = self::import_sources();
		if ( isset( $imports[ $slug ] ) ) {
			return (string) $imports[ $slug ]['label'];
		}
		return Eventcrawler_Crawler::get_source_name( $slug );
	}

	/**
	 * Prüft einen Quellen-Slug für einen Import.
	 *
	 * @param string $slug Slug.
	 * @return string Fehlermeldung oder "".
	 */
	public static function validate_source( $slug ) {
		if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) || strlen( $slug ) > 50 ) {
			return 'Die Kennung der Importquelle darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (max. 50 Zeichen).';
		}
		if ( in_array( $slug, self::RESERVED_SOURCES, true ) ) {
			return 'Die Kennung „' . $slug . '“ ist reserviert (manuelle Termine bzw. Testdaten).';
		}
		if ( isset( Eventcrawler_Crawler::get_sources()[ $slug ] ) ) {
			return 'Die Kennung „' . $slug . '“ gehört einer Crawler-Quelle. Importierte Termine dürfen gecrawlte nicht überschreiben.';
		}
		return '';
	}

	/**
	 * Kennung aus einer Bezeichnung („Kulturamt Kulmbach“ → „kulturamt-kulmbach“), deterministisch.
	 *
	 * @param string $label Bezeichnung.
	 * @return string
	 */
	public static function slug_from_label( $label ) {
		$label = strtr( mb_strtolower( (string) $label ), array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$slug  = trim( preg_replace( '/[^a-z0-9]+/', '-', remove_accents( $label ) ), '-' );
		return substr( $slug, 0, 50 );
	}

	/* ------------------------------------------------------------------ Datei lesen */

	/**
	 * Prüft eine hochgeladene Datei ($_FILES-Eintrag) und liefert Inhalt und Format.
	 *
	 * @param array $file          Eintrag aus $_FILES.
	 * @param bool  $require_upload true: is_uploaded_file() verlangen (Tests: false).
	 * @return array|WP_Error name, format (csv|xml), content.
	 */
	public static function read_upload( array $file, $require_upload = true ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new WP_Error( 'eventcrawler_import_size', self::size_message() );
		}
		if ( UPLOAD_ERR_NO_FILE === $error || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'eventcrawler_import_nofile', 'Bitte eine Datei auswählen.' );
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'eventcrawler_import_upload', 'Die Datei konnte nicht hochgeladen werden. Bitte erneut versuchen.' );
		}
		if ( $require_upload && ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'eventcrawler_import_upload', 'Die Datei konnte nicht hochgeladen werden. Bitte erneut versuchen.' );
		}
		$name = isset( $file['name'] ) ? sanitize_file_name( wp_basename( (string) $file['name'] ) ) : '';
		$size = (int) @filesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'eventcrawler_import_size', self::size_message() );
		}
		$content = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return self::read_content( $content, $name );
	}

	/**
	 * Prüft Dateiinhalt und erkennt das Format. Der vom Browser gemeldete MIME-Typ wird nicht verwendet; maßgeblich
	 * sind Endung und Inhalt.
	 *
	 * @param string $content Dateiinhalt.
	 * @param string $name    Dateiname.
	 * @return array|WP_Error name, format, content (ohne BOM).
	 */
	public static function read_content( $content, $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'xml', 'txt' ), true ) ) {
			return new WP_Error( 'eventcrawler_import_type', 'Bitte eine CSV- oder XML-Datei hochladen (Endung .csv, .txt oder .xml). Excel-Dateien bitte vorher als „CSV UTF-8“ speichern.' );
		}
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new WP_Error( 'eventcrawler_import_size', self::size_message() );
		}
		if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
			$content = substr( $content, 3 ); // UTF-8-BOM (z. B. Excel „CSV UTF-8“)
		}
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'eventcrawler_import_empty', 'Die Datei ist leer.' );
		}
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return new WP_Error( 'eventcrawler_import_encoding', 'Die Datei ist nicht UTF-8-kodiert. Bitte in der Tabellenkalkulation als „CSV UTF-8“ speichern bzw. die XML-Datei als UTF-8 exportieren.' );
		}
		if ( 0 === strncmp( "\x50\x4B\x03\x04", $content, 4 ) ) {
			return new WP_Error( 'eventcrawler_import_type', 'Das ist eine Excel- bzw. ZIP-Datei. Bitte als „CSV UTF-8“ speichern und erneut hochladen.' );
		}
		$format = '<' === substr( ltrim( $content ), 0, 1 ) ? 'xml' : 'csv';
		if ( 'xml' === $ext && 'xml' !== $format ) {
			return new WP_Error( 'eventcrawler_import_type', 'Die Datei endet auf .xml, enthält aber kein XML.' );
		}
		return array(
			'name'    => $name,
			'format'  => $format,
			'content' => $content,
		);
	}

	/**
	 * @return string Verständliche Meldung zur Größenbegrenzung.
	 */
	public static function size_message() {
		return sprintf( 'Die Datei ist zu groß. Erlaubt sind höchstens %s. Bitte die Datei aufteilen (z. B. je Monat).', size_format( self::max_bytes() ) );
	}

	/**
	 * @return int Wirksame Grenze: Plugin-Limit oder kleineres Server-Limit.
	 */
	public static function max_bytes() {
		$server = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		return $server > 0 ? min( self::MAX_BYTES, $server ) : self::MAX_BYTES;
	}

	/* ------------------------------------------------------------------ CSV */

	/**
	 * Liest eine CSV-Datei. Ohne vorgegebenes Trennzeichen wird es deterministisch erkannt: ein Kandidat (; , Tab)
	 * gilt nur, wenn die Kopfzeile damit mindestens 2 Spalten hat und JEDE Datenzeile genau so viele Spalten. Passt
	 * genau ein Kandidat, wird er verwendet; sonst muss der Admin wählen.
	 *
	 * @param string      $content   Inhalt (UTF-8, ohne BOM).
	 * @param string|null $delimiter Schlüssel aus DELIMITERS oder null (erkennen).
	 * @return array|WP_Error delimiter (Schlüssel oder null), candidates (Schlüssel[]), header (string[]), rows
	 *                        (array( line, cells )), errors (Zeilenfehler bei vorgegebenem Trennzeichen).
	 */
	public static function parse_csv( $content, $delimiter = null ) {
		$result = array(
			'delimiter'  => null,
			'candidates' => array(),
			'header'     => array(),
			'rows'       => array(),
			'errors'     => array(),
		);

		if ( null === $delimiter ) {
			foreach ( self::DELIMITERS as $key => $def ) {
				$records = self::csv_records( $content, $def[0] );
				if ( count( $records ) < 1 || count( $records[0]['cells'] ) < 2 ) {
					continue;
				}
				$width = count( $records[0]['cells'] );
				$ok    = true;
				foreach ( array_slice( $records, 1 ) as $record ) {
					if ( count( $record['cells'] ) !== $width ) {
						$ok = false;
						break;
					}
				}
				if ( $ok ) {
					$result['candidates'][] = $key;
				}
			}
			if ( 1 !== count( $result['candidates'] ) ) {
				return $result; // Admin muss wählen (keiner oder mehrere passen).
			}
			$delimiter = $result['candidates'][0];
		}

		if ( ! isset( self::DELIMITERS[ $delimiter ] ) ) {
			return new WP_Error( 'eventcrawler_import_delimiter', 'Unbekanntes Trennzeichen.' );
		}
		$records = self::csv_records( $content, self::DELIMITERS[ $delimiter ][0] );
		if ( ! $records ) {
			return new WP_Error( 'eventcrawler_import_empty', 'Die Datei enthält keine Zeilen.' );
		}
		$header = array_map( 'trim', $records[0]['cells'] );
		if ( count( $header ) < 2 ) {
			return new WP_Error( 'eventcrawler_import_header', 'Die erste Zeile muss die Spaltennamen enthalten (mindestens zwei Spalten). Stimmt das Trennzeichen?' );
		}
		$rows = array_slice( $records, 1 );
		if ( ! $rows ) {
			return new WP_Error( 'eventcrawler_import_empty', 'Die Datei enthält nur die Kopfzeile, aber keine Veranstaltungen.' );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			return new WP_Error( 'eventcrawler_import_rows', sprintf( 'Die Datei enthält %d Zeilen; erlaubt sind höchstens %d. Bitte aufteilen.', count( $rows ), self::MAX_ROWS ) );
		}
		$result['delimiter'] = $delimiter;
		$result['header']    = $header;
		foreach ( $rows as $row ) {
			if ( count( $row['cells'] ) !== count( $header ) ) {
				$result['errors'][ $row['line'] ] = array( sprintf( 'Die Zeile hat %d statt %d Spalten.', count( $row['cells'] ), count( $header ) ) );
				continue;
			}
			$result['rows'][] = $row;
		}
		return $result;
	}

	/**
	 * Zerlegt CSV-Text in Datensätze (RFC 4180: Anführungszeichen, "" als Escape, Zeilenumbrüche in Feldern).
	 * Leere Zeilen werden übersprungen. "line" ist die Zeilennummer wie in der Tabellenkalkulation (Kopfzeile = 1).
	 *
	 * @param string $content   Inhalt.
	 * @param string $delimiter Ein Zeichen.
	 * @return array[] array( line, cells ).
	 */
	private static function csv_records( $content, $delimiter ) {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$records = array();
		$number  = 0;
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$number++;
			if ( array( null ) === $cells ) {
				continue; // leere Zeile
			}
			$cells = array_map(
				function ( $cell ) {
					return null === $cell ? '' : (string) $cell;
				},
				$cells
			);
			if ( '' === trim( implode( '', $cells ) ) ) {
				continue; // nur Trennzeichen
			}
			$records[] = array(
				'line'  => $number,
				'cells' => $cells,
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $records;
	}

	/**
	 * Vorschläge Spalte → Feld nur bei exakt bekanntem Spaltennamen; jedes Feld höchstens einmal.
	 *
	 * @param string[] $header Spaltennamen.
	 * @return string[] Spaltenindex => Feld ('' = nicht zuordnen).
	 */
	public static function suggest_mapping( array $header ) {
		$mapping = array();
		$used    = array();
		foreach ( $header as $index => $name ) {
			$key   = mb_strtolower( trim( (string) $name ) );
			$field = isset( self::HEADER_SUGGESTIONS[ $key ] ) ? self::HEADER_SUGGESTIONS[ $key ] : '';
			if ( '' !== $field && ! isset( $used[ $field ] ) ) {
				$used[ $field ]    = true;
				$mapping[ $index ] = $field;
			} else {
				$mapping[ $index ] = '';
			}
		}
		return $mapping;
	}

	/**
	 * Prüft eine Zuordnung Spalte → Feld.
	 *
	 * @param array    $mapping Spaltenindex => Feld.
	 * @param string[] $header  Spaltennamen.
	 * @return string[] Fehlermeldungen.
	 */
	public static function validate_mapping( array $mapping, array $header ) {
		$errors = array();
		$seen   = array();
		foreach ( $mapping as $index => $field ) {
			if ( '' === $field ) {
				continue;
			}
			if ( ! isset( self::FIELDS[ $field ] ) || ! isset( $header[ $index ] ) ) {
				$errors[] = 'Ungültige Zuordnung.';
				continue;
			}
			if ( isset( $seen[ $field ] ) ) {
				$errors[] = sprintf( 'Das Feld „%s“ ist mehreren Spalten zugeordnet („%s“ und „%s“).', self::FIELDS[ $field ], $header[ $seen[ $field ] ], $header[ $index ] );
			}
			$seen[ $field ] = $index;
		}
		foreach ( self::REQUIRED as $field ) {
			if ( ! isset( $seen[ $field ] ) ) {
				$errors[] = sprintf( 'Bitte eine Spalte für „%s“ zuordnen (Pflichtfeld).', self::FIELDS[ $field ] );
			}
		}
		return $errors;
	}

	/**
	 * CSV-Zeilen mit Zuordnung → Werte je Feld.
	 *
	 * @param array[] $rows    Ergebnis parse_csv()['rows'].
	 * @param array   $mapping Spaltenindex => Feld.
	 * @return array[] array( line, values ).
	 */
	public static function map_rows( array $rows, array $mapping ) {
		$out = array();
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $mapping as $index => $field ) {
				if ( '' !== $field && isset( $row['cells'][ $index ] ) ) {
					$values[ $field ] = $row['cells'][ $index ];
				}
			}
			$out[] = array(
				'line'   => $row['line'],
				'values' => $values,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ XML */

	/**
	 * Liest das neutrale Eventcrawler-Importformat (docs/11-import.md Abschnitt 3):
	 * <events source="…" generated_at="…"><event><title>…</title>…</event></events>.
	 *
	 * Sicherheit: Dateien mit DOCTYPE/ENTITY werden abgelehnt (keine DTD-Auswertung, kein XXE, keine Entity-Expansion);
	 * libxml lädt nichts aus dem Netz (LIBXML_NONET), Entities werden nicht ersetzt (kein LIBXML_NOENT).
	 *
	 * @param string $content Inhalt (UTF-8, ohne BOM).
	 * @return array|WP_Error source, generated_at, rows (array( line, values )), unknown (string[]), errors.
	 */
	public static function parse_xml( $content ) {
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $content ) ) {
			return new WP_Error( 'eventcrawler_import_xml_dtd', 'Die XML-Datei enthält eine DOCTYPE- bzw. ENTITY-Angabe. Aus Sicherheitsgründen werden nur XML-Dateien ohne DOCTYPE angenommen.' );
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$dom = new DOMDocument();
		$ok  = $dom->loadXML( $content, LIBXML_NONET | LIBXML_NOCDATA | ( defined( 'LIBXML_BIGLINES' ) ? LIBXML_BIGLINES : 0 ) );
		$xml_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok || null !== $dom->doctype ) {
			$first = $xml_errors ? $xml_errors[0] : null;
			return new WP_Error(
				'eventcrawler_import_xml',
				$first ? sprintf( 'Die XML-Datei ist nicht gültig (Zeile %d: %s).', (int) $first->line, trim( $first->message ) ) : 'Die XML-Datei ist nicht gültig.'
			);
		}

		$root = $dom->documentElement;
		if ( ! $root || 'events' !== $root->nodeName ) {
			return new WP_Error( 'eventcrawler_import_xml_root', 'Unbekanntes XML-Format: Das Wurzelelement muss <events> heißen (Eventcrawler-Importformat, siehe Hilfe).' );
		}

		$result = array(
			'source'       => trim( $root->getAttribute( 'source' ) ),
			'generated_at' => trim( $root->getAttribute( 'generated_at' ) ),
			'rows'         => array(),
			'unknown'      => array(),
			'errors'       => array(),
		);

		foreach ( $root->childNodes as $node ) {
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}
			if ( 'event' !== $node->nodeName ) {
				$result['unknown'][ $node->nodeName ] = true;
				continue;
			}
			$line   = (int) $node->getLineNo();
			$values = array();
			$errors = array();
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				$name = $child->nodeName;
				if ( ! isset( self::FIELDS[ $name ] ) ) {
					$result['unknown'][ $name ] = true;
					continue;
				}
				if ( isset( $values[ $name ] ) ) {
					$errors[] = sprintf( 'Das Element <%s> kommt mehrfach vor.', $name );
					continue;
				}
				$values[ $name ] = $child->textContent;
			}
			if ( $errors ) {
				$result['errors'][ $line ] = $errors;
				continue;
			}
			$result['rows'][] = array(
				'line'   => $line,
				'values' => $values,
			);
			if ( count( $result['rows'] ) > self::MAX_ROWS ) {
				return new WP_Error( 'eventcrawler_import_rows', sprintf( 'Die Datei enthält mehr als %d Veranstaltungen. Bitte aufteilen.', self::MAX_ROWS ) );
			}
		}
		$result['unknown'] = array_keys( $result['unknown'] );

		if ( ! $result['rows'] && ! $result['errors'] ) {
			return new WP_Error( 'eventcrawler_import_empty', 'Die XML-Datei enthält keine <event>-Einträge.' );
		}
		return $result;
	}

	/* ------------------------------------------------------------------ Zeilen prüfen */

	/**
	 * Prüft und normalisiert eine Zeile zu einer Datenbankzeile der Importquelle.
	 *
	 * @param array  $values Feld => Rohwert.
	 * @param string $source Importquelle.
	 * @return array event (oder null), errors (string[]), warnings (string[]).
	 */
	public static function prepare_row( array $values, $source ) {
		$v = array();
		foreach ( self::FIELDS as $field => $label ) {
			$raw = isset( $values[ $field ] ) && is_scalar( $values[ $field ] ) ? trim( (string) $values[ $field ] ) : '';
			// Ein Feld, das genau „NULL“ enthält (z. B. aus einem Datenbankexport), gilt als leer.
			$v[ $field ] = 0 === strcasecmp( $raw, 'NULL' ) ? '' : $raw;
		}

		$errors = array();
		if ( '' === $v['title'] ) {
			$errors[] = 'Titel fehlt';
		}
		if ( '' === $v['start_date'] ) {
			$errors[] = 'Startdatum fehlt';
		}
		if ( '' === $v['city'] ) {
			$errors[] = 'Stadt fehlt';
		}

		$start_date = '' === $v['start_date'] ? '' : self::parse_date( $v['start_date'] );
		if ( false === $start_date ) {
			$errors[] = sprintf( 'Ungültiges Datum „%s“', $v['start_date'] );
		}
		$end_date = '' === $v['end_date'] ? '' : self::parse_date( $v['end_date'] );
		if ( false === $end_date ) {
			$errors[] = sprintf( 'Ungültiges Enddatum „%s“', $v['end_date'] );
		}
		$start_time = '' === $v['start_time'] ? '' : self::parse_time( $v['start_time'] );
		if ( false === $start_time ) {
			$errors[] = sprintf( 'Ungültige Uhrzeit „%s“', $v['start_time'] );
		}
		$end_time = '' === $v['end_time'] ? '' : self::parse_time( $v['end_time'] );
		if ( false === $end_time ) {
			$errors[] = sprintf( 'Ungültige Endzeit „%s“', $v['end_time'] );
		}
		$all_day = self::parse_flag( $v['all_day'] );
		if ( null === $all_day ) {
			$errors[] = sprintf( 'Ungültiger Wert für „Ganztägig“: „%s“ (erlaubt: 1/0, ja/nein)', $v['all_day'] );
		}
		if ( mb_strlen( $v['external_id'] ) > 191 ) {
			$errors[] = 'Externe ID ist länger als 191 Zeichen';
		}
		if ( $errors ) {
			return array(
				'event'    => null,
				'errors'   => $errors,
				'warnings' => array(),
			);
		}

		$prepared = Eventcrawler_Manual_Events::prepare(
			array(
				'external_id'   => $v['external_id'],
				'title'         => $v['title'],
				'description'   => $v['description'],
				'start_date'    => $start_date,
				'start_time'    => $start_time,
				'end_date'      => $end_date,
				'end_time'      => $end_time,
				'all_day'       => $all_day,
				'venue_name'    => $v['venue_name'],
				'venue_address' => $v['venue_address'],
				'city'          => $v['city'],
				'event_url'     => $v['event_url'],
			),
			false // wie beim Crawler: ungültiges Ende / Link wird verworfen und als Hinweis gemeldet
		);
		if ( ! $prepared['event'] ) {
			return array(
				'event'    => null,
				'errors'   => array_map(
					function ( $message ) {
						return rtrim( $message, '.' );
					},
					$prepared['errors']
				),
				'warnings' => $prepared['warnings'],
			);
		}

		$event    = $prepared['event'];
		$warnings = $prepared['warnings'];
		if ( '' === $event['title'] ) {
			return array(
				'event'    => null,
				'errors'   => array( 'Titel fehlt' ),
				'warnings' => $warnings,
			);
		}

		$event['source']     = $source;
		$event['source_url'] = null;
		if ( '' !== $v['source_url'] ) {
			$url = esc_url_raw( $v['source_url'], array( 'http', 'https' ) );
			if ( '' !== $url && preg_match( '#^https?://[^/\s]+#i', $url ) && strlen( $url ) <= 2048 ) {
				$event['source_url'] = $url;
			} else {
				$warnings[] = 'Herkunfts-URL verworfen (kein http/https): ' . $v['source_url'];
			}
		}
		// event_key und content_hash hängen von source/source_url ab → für die Importquelle neu berechnen.
		$event['event_key']    = Eventcrawler_Crawler::event_key( $event );
		$event['content_hash'] = Eventcrawler_Crawler::content_hash( $event );

		return array(
			'event'    => $event,
			'errors'   => array(),
			'warnings' => $warnings,
		);
	}

	/**
	 * „2026-11-15“ oder „15.11.2026“ (auch einstellig) → Y-m-d; sonst false. Kein Raten anderer Formate.
	 *
	 * @param string $value Datum.
	 * @return string|false
	 */
	public static function parse_date( $value ) {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m ) ) {
			list( , $d, $mo, $y ) = $m;
		} else {
			return false;
		}
		if ( ! checkdate( (int) $mo, (int) $d, (int) $y ) || (int) $y < 2000 || (int) $y > 2100 ) {
			return false;
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/**
	 * „20:00“, „9:30“, „20.00“, „20:00:00“, optional mit „ Uhr“ → HH:MM; sonst false. Nie 00:00 erfinden.
	 *
	 * @param string $value Uhrzeit.
	 * @return string|false
	 */
	public static function parse_time( $value ) {
		$value = preg_replace( '/\s*uhr$/i', '', $value );
		if ( ! preg_match( '/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?$/', $value, $m ) || (int) $m[1] > 23 || (int) $m[2] > 59 || ( isset( $m[3] ) && (int) $m[3] > 59 ) ) {
			return false;
		}
		return sprintf( '%02d:%02d', $m[1], $m[2] );
	}

	/**
	 * Ganztägig: leer/0/nein/false/no → 0; 1/ja/true/yes/x → 1; sonst null (Fehler).
	 *
	 * @param string $value Wert.
	 * @return int|null
	 */
	public static function parse_flag( $value ) {
		$value = mb_strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( '', '0', 'nein', 'false', 'no' ), true ) ) {
			return 0;
		}
		if ( in_array( $value, array( '1', 'ja', 'true', 'yes', 'x' ), true ) ) {
			return 1;
		}
		return null;
	}

	/* ------------------------------------------------------------------ Probelauf und Import */

	/**
	 * Probelauf: prüft alle Zeilen und ordnet sie zu (neu, aktualisieren, unverändert, fehlerhaft). Schreibt nichts.
	 *
	 * @param array[]  $rows        array( line, values ).
	 * @param string   $source      Importquelle (bereits geprüft).
	 * @param array    $file_errors Fehler aus dem Einlesen (Zeile => Meldungen), z. B. falsche Spaltenzahl.
	 * @return array counts (read, new, update, unchanged, invalid), items (je Zeile: line, action, event, match_id,
	 *               errors, warnings), errors (Zeile => Meldungen, sortiert).
	 */
	public function analyze( array $rows, $source, array $file_errors = array() ) {
		$items  = array();
		$errors = $file_errors;

		foreach ( $rows as $row ) {
			$prepared = self::prepare_row( $row['values'], $source );
			$items[]  = array(
				'line'     => (int) $row['line'],
				'action'   => $prepared['event'] ? 'pending' : 'invalid',
				'event'    => $prepared['event'],
				'match_id' => 0,
				'errors'   => $prepared['errors'],
				'warnings' => $prepared['warnings'],
			);
		}

		// Doppelte external_id in der Datei: unklar, welche Zeile gilt → alle betroffenen Zeilen überspringen.
		$by_external = array();
		foreach ( $items as $index => $item ) {
			if ( $item['event'] && null !== $item['event']['external_id'] ) {
				$by_external[ $item['event']['external_id'] ][] = $index;
			}
		}
		foreach ( $by_external as $external_id => $indexes ) {
			if ( count( $indexes ) < 2 ) {
				continue;
			}
			$lines = array();
			foreach ( $indexes as $index ) {
				$lines[] = $items[ $index ]['line'];
			}
			foreach ( $indexes as $index ) {
				$items[ $index ]['action']   = 'invalid';
				$items[ $index ]['errors'][] = sprintf( 'Externe ID „%s“ kommt mehrfach in der Datei vor (Zeilen %s)', $external_id, implode( ', ', $lines ) );
			}
		}

		// Vorhandene Termine der Quelle einmal laden (Index source_external), keine Abfrage je Zeile.
		list( $by_id, $by_key ) = $this->load_existing( $source );
		$used = array();
		foreach ( $items as $index => $item ) {
			if ( 'pending' !== $item['action'] ) {
				continue;
			}
			$event = $item['event'];
			$match = null;
			if ( null !== $event['external_id'] ) {
				$match = isset( $by_id[ $event['external_id'] ] ) ? $by_id[ $event['external_id'] ] : null;
			} elseif ( isset( $by_key[ $event['event_key'] ] ) ) {
				// Wie Crawler-Priorität 3: noch nicht zugeordnete Kandidaten, gleiche Uhrzeit bevorzugt, sonst kleinste ID.
				$candidates = array_values(
					array_filter(
						$by_key[ $event['event_key'] ],
						function ( $row ) use ( $used ) {
							return ! isset( $used[ $row['id'] ] );
						}
					)
				);
				foreach ( $candidates as $candidate ) {
					if ( (string) $candidate['start_time'] === (string) $event['start_time'] ) {
						$match = $candidate;
						break;
					}
				}
				if ( ! $match && $candidates ) {
					$match = $candidates[0];
				}
			}

			if ( $match ) {
				$used[ $match['id'] ]          = true;
				$items[ $index ]['match_id'] = (int) $match['id'];
				$items[ $index ]['action']   = $match['content_hash'] === $event['content_hash'] ? 'unchanged' : 'update';
			} else {
				$items[ $index ]['action'] = 'new';
			}
		}

		$counts = array(
			'read'      => count( $rows ) + count( $file_errors ),
			'new'       => 0,
			'update'    => 0,
			'unchanged' => 0,
			'invalid'   => count( $file_errors ),
		);
		foreach ( $items as $item ) {
			$counts[ $item['action'] ]++;
			if ( $item['errors'] ) {
				$errors[ $item['line'] ] = isset( $errors[ $item['line'] ] ) ? array_merge( $errors[ $item['line'] ], $item['errors'] ) : $item['errors'];
			}
		}
		ksort( $errors );

		return array(
			'counts' => $counts,
			'items'  => $items,
			'errors' => $errors,
		);
	}

	/**
	 * Führt den Import aus (vorher immer analyze()). Neue Termine werden eingefügt, geänderte aktualisiert, unveränderte
	 * nicht angefasst, fehlerhafte übersprungen. Nichts wird gelöscht.
	 *
	 * @param array[] $rows        array( line, values ).
	 * @param string  $source      Importquelle.
	 * @param string  $label       Bezeichnung der Importquelle.
	 * @param array   $file_errors Fehler aus dem Einlesen.
	 * @param string  $file_name   Dateiname (für die Übersicht).
	 * @return array counts (wie analyze, plus inserted, updated, failed) und errors.
	 */
	public function run( array $rows, $source, $label, array $file_errors = array(), $file_name = '' ) {
		$analysis = $this->analyze( $rows, $source, $file_errors );
		$now      = current_time( 'mysql' );
		$stats    = array(
			'inserted' => 0,
			'updated'  => 0,
			'failed'   => 0,
		);

		foreach ( $analysis['items'] as $item ) {
			if ( 'new' === $item['action'] ) {
				$id = $this->repository->insert( $item['event'] );
				$id ? $stats['inserted']++ : $stats['failed']++;
			} elseif ( 'update' === $item['action'] ) {
				$data = $item['event'];
				unset( $data['source'] );
				if ( null === $data['external_id'] ) {
					unset( $data['external_id'] ); // eine gespeicherte external_id nie mit NULL überschreiben
				}
				$data['updated_at']   = $now;
				$data['last_seen_at'] = $now;
				$this->repository->update( $item['match_id'], $data ) ? $stats['updated']++ : $stats['failed']++;
			}
		}

		$counts  = array_merge( $analysis['counts'], $stats );
		$sources = self::import_sources();
		$sources[ $source ] = array(
			'label'       => $label,
			'last_import' => $now,
			'counts'      => $counts,
		);
		update_option( self::SOURCES_OPTION, $sources, false );
		update_option(
			self::LAST_IMPORT_OPTION,
			array(
				'time'   => $now,
				'source' => $source,
				'file'   => $file_name,
				'counts' => $counts,
			),
			false
		);

		return array(
			'counts' => $counts,
			'errors' => $analysis['errors'],
		);
	}

	/**
	 * Vorhandene Termine einer Importquelle (nur die für das Matching nötigen Spalten).
	 *
	 * @param string $source Quelle.
	 * @return array array( external_id => Zeile ), array( event_key => Zeilen ohne external_id, nach id ).
	 */
	private function load_existing( $source ) {
		global $wpdb;
		$table = Eventcrawler_Installer::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, external_id, event_key, start_time, content_hash FROM {$table} WHERE source = %s ORDER BY id ASC", $source ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$by_id  = array();
		$by_key = array();
		foreach ( $rows as $row ) {
			if ( null !== $row['external_id'] ) {
				$by_id[ $row['external_id'] ] = $row;
			} else {
				$row['start_time']            = null === $row['start_time'] ? null : (string) $row['start_time'];
				$by_key[ $row['event_key'] ][] = $row;
			}
		}
		return array( $by_id, $by_key );
	}
}
