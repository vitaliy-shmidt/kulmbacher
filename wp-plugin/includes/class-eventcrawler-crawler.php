<?php
/**
 * Crawler Core.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ablauf je Quelle: Adapter -> Validierung/Normalisierung -> Filter -> Matching -> INSERT/UPDATE.
 * Details: docs/03-crawler.md, Matching/Hashes: docs/02-datenbank.md.
 */
class Eventcrawler_Crawler {

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

	/**
	 * Registrierte Adapter in Crawl-Reihenfolge, Schlüssel = Slug.
	 *
	 * @return Eventcrawler_Source[]
	 */
	public static function get_sources() {
		$sources = apply_filters(
			'eventcrawler_sources',
			array(
				new Eventcrawler_Source_Burg_Rabenstein(),
				new Eventcrawler_Source_Bad_Steben(),
				new Eventcrawler_Source_Coburg(),
				new Eventcrawler_Source_Theater_Selb(),
				new Eventcrawler_Source_Das_Zentrum_Bayreuth(),
				new Eventcrawler_Source_Schuetzenhaus_Muenchberg(),
				new Eventcrawler_Source_Stadtsteinach(),
				new Eventcrawler_Source_Helmbrechts(),
				new Eventcrawler_Source_Kronach(),
				new Eventcrawler_Source_Bayreuth_Tourismus(),
			)
		);

		$by_slug = array();
		foreach ( $sources as $source ) {
			if ( $source instanceof Eventcrawler_Source ) {
				$by_slug[ $source->get_slug() ] = $source;
			}
		}
		return $by_slug;
	}

	/**
	 * Anzeigename zu einem Quellen-Slug (z. B. "burg-rabenstein" -> "Burg Rabenstein").
	 * Zentrale Zuordnung über das Adapter-Register; unbekannte Slugs (z. B. "dev-test") werden unverändert angezeigt.
	 *
	 * @param string $slug Wert der Spalte `source`.
	 * @return string
	 */
	public static function get_source_name( $slug ) {
		static $names = null;
		if ( null === $names ) {
			$names = array();
			foreach ( self::get_sources() as $source_slug => $source ) {
				$names[ $source_slug ] = $source->get_name();
			}
		}
		return isset( $names[ $slug ] ) ? $names[ $slug ] : (string) $slug;
	}

	/**
	 * Crawlt eine Quelle. Bei einem Fehler der Quelle wird nichts in der Datenbank verändert.
	 *
	 * @param Eventcrawler_Source $source Adapter.
	 * @return array|WP_Error Statistik: found, new, updated, unchanged, filtered, failed.
	 */
	public function run( Eventcrawler_Source $source ) {
		$run_started = current_time( 'mysql' );

		try {
			$items = $source->fetch_events();
		} catch ( Throwable $e ) {
			$items = new WP_Error( 'eventcrawler_exception', $e->getMessage() );
		}

		if ( is_wp_error( $items ) ) {
			$this->log( $source, 'Abbruch: ' . $items->get_error_message() );
			return $items;
		}

		$stats = array(
			'found'     => count( $items ),
			'new'       => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'filtered'  => 0,
			'failed'    => 0,
		);

		// Filterregeln einmal je Lauf laden (Option eventcrawler_filter_rules, nur aktive).
		$rules = Eventcrawler_Filter_Rules::get_active();
		if ( Eventcrawler_Filter_Rules::is_corrupt() ) {
			$this->log( $source, 'Filterregeln beschädigt (Option eventcrawler_filter_rules) – Crawl ohne Textfilter.' );
		}

		foreach ( $items as $item ) {
			$event = $this->normalize( $source, $item );
			if ( is_wp_error( $event ) ) {
				$stats['failed']++;
				$this->log( $source, 'Übersprungen: ' . $event->get_error_message() );
				continue;
			}

			if ( null !== Eventcrawler_Filter::get_exclusion_reason( $event, $source->get_allowed_cities(), $rules ) ) {
				$stats['filtered']++;
				continue;
			}

			$result = $this->save( $source, $event, $run_started );
			$stats[ $result ]++;
		}

		return $stats;
	}

	/**
	 * Validiert Pflichtfelder, bereinigt Werte und berechnet event_key und content_hash.
	 *
	 * @param Eventcrawler_Source $source Adapter.
	 * @param array               $item   Rohdaten des Adapters.
	 * @return array|WP_Error
	 */
	private function normalize( Eventcrawler_Source $source, array $item ) {
		$label = isset( $item['title'] ) ? $item['title'] : '(ohne Titel)';

		if ( ! empty( $item['_error'] ) ) {
			return new WP_Error( 'eventcrawler_invalid', $label . ': ' . $item['_error'] );
		}

		$event = array(
			'source'      => $source->get_slug(),
			'external_id' => isset( $item['external_id'] ) && '' !== (string) $item['external_id'] ? sanitize_text_field( (string) $item['external_id'] ) : null,
			'title'       => mb_substr( sanitize_text_field( isset( $item['title'] ) ? $item['title'] : '' ), 0, 255 ),
			'description' => sanitize_textarea_field( isset( $item['description'] ) ? $item['description'] : '' ),
			'start_date'  => self::normalize_date( isset( $item['start_date'] ) ? $item['start_date'] : null ),
			'start_time'  => self::normalize_time( isset( $item['start_time'] ) ? $item['start_time'] : null ),
			'end_date'    => self::normalize_date( isset( $item['end_date'] ) ? $item['end_date'] : null ),
			'end_time'    => self::normalize_time( isset( $item['end_time'] ) ? $item['end_time'] : null ),
			'all_day'     => empty( $item['all_day'] ) ? 0 : 1,
			'venue_name'  => mb_substr( sanitize_text_field( isset( $item['venue_name'] ) ? $item['venue_name'] : '' ), 0, 255 ),
			'city'        => mb_substr( sanitize_text_field( isset( $item['city'] ) ? $item['city'] : '' ), 0, 100 ),
			// Nur aus einem strukturierten Adressfeld der Quelle (docs/03-crawler.md), nie aus Fließtext. Leer = NULL.
			'venue_address' => self::null_if_empty( mb_substr( sanitize_text_field( isset( $item['venue_address'] ) ? $item['venue_address'] : '' ), 0, 255 ) ),
			'event_url'   => isset( $item['event_url'] ) ? esc_url_raw( $item['event_url'], array( 'http', 'https' ) ) : '',
			'source_url'  => isset( $item['source_url'] ) ? esc_url_raw( $item['source_url'], array( 'http', 'https' ) ) : '',
		);

		if ( '' === $event['title'] ) {
			return new WP_Error( 'eventcrawler_invalid', 'Titel fehlt' );
		}
		if ( ! is_string( $event['start_date'] ) ) {
			return new WP_Error( 'eventcrawler_invalid', $label . ': ungültiges oder fehlendes Startdatum' );
		}
		if ( '' === $event['city'] ) {
			return new WP_Error( 'eventcrawler_invalid', $label . ': Stadt fehlt' );
		}
		// false = Wert vorhanden, aber ungültig. Lieber überspringen als falsch speichern.
		if ( false === $event['start_time'] || false === $event['end_time'] || false === $event['end_date'] ) {
			return new WP_Error( 'eventcrawler_invalid', $label . ': ungültige Uhrzeit oder ungültiges Enddatum' );
		}
		if ( $source->has_unique_detail_urls() && '' === $event['source_url'] ) {
			return new WP_Error( 'eventcrawler_invalid', $label . ': Detail-URL fehlt' );
		}

		// Ganztägig: Uhrzeiten entfallen. Ganztag setzt nur der Adapter, nie wegen fehlender Uhrzeit.
		if ( $event['all_day'] ) {
			$event['start_time'] = null;
			$event['end_time']   = null;
		}

		// Endzeit ohne Enddatum: gleicher Tag (docs/02-datenbank.md, Regel end_date).
		if ( null !== $event['end_time'] && null === $event['end_date'] ) {
			$event['end_date'] = $event['start_date'];
		}

		// Ende vor Beginn (z. B. 22:00–01:00 ohne eindeutiges Folgedatum): nicht interpretieren, Ende verwerfen.
		// Ein Ende am Folgetag muss der Adapter ausdrücklich als end_date liefern.
		if ( null !== $event['end_date'] ) {
			$start = $event['start_date'] . ' ' . ( $event['start_time'] ? $event['start_time'] : '00:00:00' );
			$end   = $event['end_date'] . ' ' . ( $event['end_time'] ? $event['end_time'] : '23:59:59' );
			if ( $end <= $start ) {
				$this->log( $source, $label . ': Ende liegt nicht nach dem Beginn (' . $start . ' / ' . $end . ') – Endangaben verworfen.' );
				$event['end_date'] = null;
				$event['end_time'] = null;
			}
		}

		$event['event_key']    = self::event_key( $event );
		$event['content_hash'] = self::content_hash( $event );

		return $event;
	}

	/**
	 * Hilfsschlüssel für das Fallback-Matching (docs/02-datenbank.md Abschnitt 4).
	 *
	 * @param array $event Normalisiertes Event.
	 * @return string
	 */
	public static function event_key( array $event ) {
		return sha1(
			implode(
				'|',
				array(
					$event['source'],
					self::normalize_for_hash( $event['title'] ),
					self::normalize_for_hash( $event['city'] ),
					$event['start_date'],
				)
			)
		);
	}

	/**
	 * @param mixed $value Datum Y-m-d, leer oder null.
	 * @return string|null|false Y-m-d, null (nicht vorhanden) oder false (ungültig).
	 */
	private static function normalize_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$date = DateTime::createFromFormat( '!Y-m-d', (string) $value );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : false;
	}

	/**
	 * @param mixed $value Uhrzeit H:i oder H:i:s, leer oder null.
	 * @return string|null|false HH:MM:00, null (nicht vorhanden) oder false (ungültig). Nie künstlich 00:00:00.
	 */
	private static function normalize_time( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', (string) $value, $m ) ) {
			return false;
		}
		return $m[1] . ':' . $m[2] . ':00';
	}

	/**
	 * Hash der Inhaltsfelder, deren Änderung ein UPDATE auslöst (docs/02-datenbank.md Abschnitt 5).
	 * Funktioniert mit normalisierten Events und mit Datenbankzeilen (Neuberechnung bei der Migration).
	 *
	 * @param array $event Normalisiertes Event oder Datenbankzeile.
	 * @return string
	 */
	public static function content_hash( array $event ) {
		$time = function ( $value ) {
			return empty( $value ) ? '' : substr( $value, 0, 5 );
		};

		return sha1(
			implode(
				'|',
				array(
					self::normalize_for_hash( $event['title'] ),
					self::normalize_for_hash( $event['description'] ),
					(string) $event['start_date'],
					$time( $event['start_time'] ),
					(string) $event['end_date'],
					$time( $event['end_time'] ),
					(int) $event['all_day'],
					self::normalize_for_hash( $event['venue_name'] ),
					self::normalize_for_hash( $event['city'] ),
					(string) $event['event_url'],
					(string) $event['source_url'],
					self::normalize_for_hash( isset( $event['venue_address'] ) ? $event['venue_address'] : '' ), // seit Schema-Version 3.
				)
			)
		);
	}

	/**
	 * @param string $value Wert.
	 * @return string|null
	 */
	private static function null_if_empty( $value ) {
		return '' === $value ? null : $value;
	}

	/**
	 * @param string|null $text Text.
	 * @return string
	 */
	private static function normalize_for_hash( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( (string) $text ) ) );
	}

	/**
	 * Matching nach Priorität und INSERT / UPDATE / nur last_seen_at.
	 *
	 * @param Eventcrawler_Source $source      Adapter.
	 * @param array               $event       Normalisiertes Event.
	 * @param string              $run_started Startzeit des Laufs (MySQL-Format).
	 * @return string Statistik-Schlüssel: new, updated, unchanged oder failed.
	 */
	private function save( Eventcrawler_Source $source, array $event, $run_started ) {
		$existing = $this->find_existing( $source, $event, $run_started );
		$now      = current_time( 'mysql' );

		if ( null === $existing ) {
			return false === $this->repository->insert( $event ) ? $this->db_failed( $source, $event ) : 'new';
		}

		if ( $existing['content_hash'] === $event['content_hash'] ) {
			$ok = $this->repository->update( $existing['id'], array( 'last_seen_at' => $now ) );
			return $ok ? 'unchanged' : $this->db_failed( $source, $event );
		}

		$event['updated_at']   = $now;
		$event['last_seen_at'] = $now;
		// Eine bestehende external_id nicht mit NULL überschreiben.
		if ( null === $event['external_id'] ) {
			unset( $event['external_id'] );
		}

		return $this->repository->update( $existing['id'], $event ) ? 'updated' : $this->db_failed( $source, $event );
	}

	/**
	 * Priorität 1: external_id, 2: eindeutige Detail-URL, 3: Fallback über event_key.
	 * Greift Priorität 1 oder 2, wird bei fehlendem Treffer NICHT auf eine niedrigere ausgewichen.
	 *
	 * @param Eventcrawler_Source $source      Adapter.
	 * @param array               $event       Normalisiertes Event.
	 * @param string              $run_started Startzeit des Laufs.
	 * @return array|null
	 */
	private function find_existing( Eventcrawler_Source $source, array $event, $run_started ) {
		if ( null !== $event['external_id'] ) {
			return $this->repository->find_by_external_id( $event['source'], $event['external_id'] );
		}

		if ( $source->has_unique_detail_urls() ) {
			return $this->repository->find_by_source_url( $event['source'], $event['source_url'] );
		}

		$candidates = $this->repository->find_fallback_candidates( $event['source'], $event['event_key'], $run_started );
		foreach ( $candidates as $candidate ) {
			if ( $candidate['start_time'] === $event['start_time'] ) {
				return $candidate;
			}
		}
		return empty( $candidates ) ? null : $candidates[0];
	}

	/**
	 * @param Eventcrawler_Source $source Adapter.
	 * @param array               $event  Event.
	 * @return string
	 */
	private function db_failed( Eventcrawler_Source $source, array $event ) {
		global $wpdb;
		$this->log( $source, 'DB-Fehler bei "' . $event['title'] . '": ' . $wpdb->last_error );
		return 'failed';
	}

	/**
	 * Technisches Protokoll über error_log(), nur mit WP_DEBUG.
	 *
	 * @param Eventcrawler_Source $source  Adapter.
	 * @param string              $message Meldung.
	 */
	private function log( Eventcrawler_Source $source, $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[eventcrawler][' . $source->get_slug() . '] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
