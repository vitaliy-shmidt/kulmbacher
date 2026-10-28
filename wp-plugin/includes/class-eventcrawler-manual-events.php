<?php
/**
 * Manuell gepflegte Veranstaltungen (source = manual).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalisierung und Speicherung redaktioneller Termine in derselben Tabelle wie die gecrawlten (docs/10-frontend.md).
 * Gleiche Regeln wie der Crawler (docs/02-datenbank.md Abschnitt 2): fehlende Werte NULL, nie künstlich 00:00,
 * Endzeit ohne Enddatum = gleicher Tag, Ganztag ohne Uhrzeiten. Kein Filter durch Filterregeln: die Redaktion entscheidet.
 */
class Eventcrawler_Manual_Events {

	const SOURCE     = 'manual';
	const NAME       = 'Manuell';
	const CAPABILITY = 'manage_options';
	const PAGE_SLUG  = 'eventcrawler-event';

	/**
	 * Bearbeiten-Link im Backend (auch für das Frontend, daher nicht in der nur im Admin geladenen Klasse).
	 *
	 * @param int $id Event-ID.
	 * @return string
	 */
	public static function edit_url( $id ) {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'id'   => (int) $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Prüft und normalisiert Eingaben zu einer Datenbankzeile.
	 *
	 * @param array $input  title, description, start_date (Y-m-d), start_time (H:i), end_date, end_time, all_day,
	 *                      venue_name, venue_address, city, event_url, optional external_id.
	 * @param bool  $strict true (Formular): ungültiges Ende bzw. ungültiger Link sind Fehler.
	 *                      false (Übernahme): sie werden verworfen und als Hinweis gemeldet – wie beim Crawler.
	 * @return array event (Zeile oder null bei Fehlern), errors (string[]), warnings (string[]).
	 */
	public static function prepare( array $input, $strict = true ) {
		$errors   = array();
		$warnings = array();
		$text     = function ( $key, $max ) use ( $input ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
			return mb_substr( $value, 0, $max );
		};
		$raw      = function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		};

		$event = array(
			'source'        => self::SOURCE,
			'external_id'   => '' === $text( 'external_id', 191 ) ? null : $text( 'external_id', 191 ),
			'title'         => $text( 'title', 255 ),
			'description'   => isset( $input['description'] ) && is_scalar( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
			'start_date'    => Eventcrawler_Crawler::normalize_date( $raw( 'start_date' ) ),
			'start_time'    => Eventcrawler_Crawler::normalize_time( $raw( 'start_time' ) ),
			'end_date'      => Eventcrawler_Crawler::normalize_date( $raw( 'end_date' ) ),
			'end_time'      => Eventcrawler_Crawler::normalize_time( $raw( 'end_time' ) ),
			'all_day'       => empty( $input['all_day'] ) ? 0 : 1,
			'venue_name'    => $text( 'venue_name', 255 ),
			'venue_address' => $text( 'venue_address', 255 ),
			'city'          => $text( 'city', 100 ),
			'event_url'     => $raw( 'event_url' ),
			'source_url'    => null, // Herkunft ist die Redaktion; im XML gäbe es sonst einen falschen "Info:"-Fallback.
		);

		if ( '' === $event['title'] ) {
			$errors[] = 'Titel fehlt.';
		}
		if ( null === $event['start_date'] ) {
			$errors[] = 'Beginn – Datum fehlt.';
		} elseif ( false === $event['start_date'] ) {
			$errors[] = 'Beginn – Datum ist ungültig.';
		}
		if ( false === $event['start_time'] ) {
			$errors[] = 'Beginn – Uhrzeit ist ungültig (HH:MM).';
		}
		if ( false === $event['end_date'] ) {
			$errors[] = 'Ende – Datum ist ungültig.';
		}
		if ( false === $event['end_time'] ) {
			$errors[] = 'Ende – Uhrzeit ist ungültig (HH:MM).';
		}
		if ( '' === $event['city'] ) {
			$errors[] = 'Stadt fehlt.';
		}

		if ( '' !== $event['event_url'] ) {
			$url = esc_url_raw( $event['event_url'], array( 'http', 'https' ) );
			if ( '' === $url || ! preg_match( '#^https?://[^/\s]+#i', $url ) || strlen( $url ) > 2048 ) {
				if ( $strict ) {
					$errors[] = 'Webseiten-Link muss mit http:// oder https:// beginnen.';
				} else {
					$warnings[] = 'Webseiten-Link verworfen (kein http/https): ' . $event['event_url'];
				}
				$url = '';
			}
			$event['event_url'] = $url;
		}

		if ( $errors ) {
			return array(
				'event'    => null,
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		if ( $event['all_day'] ) {
			$event['start_time'] = null;
			$event['end_time']   = null;
		}
		if ( null !== $event['end_time'] && null === $event['end_date'] ) {
			$event['end_date'] = $event['start_date'];
		}
		if ( null !== $event['end_date'] ) {
			$start = $event['start_date'] . ' ' . ( $event['start_time'] ? $event['start_time'] : '00:00:00' );
			$end   = $event['end_date'] . ' ' . ( $event['end_time'] ? $event['end_time'] : '23:59:59' );
			if ( $end <= $start ) {
				if ( $strict ) {
					return array(
						'event'    => null,
						'errors'   => array( 'Das Ende liegt nicht nach dem Beginn.' ),
						'warnings' => $warnings,
					);
				}
				$warnings[]        = 'Ende verworfen (liegt nicht nach dem Beginn).';
				$event['end_date'] = null;
				$event['end_time'] = null;
			}
		}

		foreach ( array( 'description', 'venue_name', 'venue_address', 'event_url' ) as $field ) {
			if ( '' === $event[ $field ] ) {
				$event[ $field ] = null;
			}
		}

		$event['event_key']    = Eventcrawler_Crawler::event_key( $event );
		$event['content_hash'] = Eventcrawler_Crawler::content_hash( $event );

		return array(
			'event'    => $event,
			'errors'   => array(),
			'warnings' => $warnings,
		);
	}

	/**
	 * Formularwerte einer gespeicherten Zeile (Y-m-d, H:i), z. B. zum Bearbeiten oder Duplizieren.
	 *
	 * @param array $row Datenbankzeile.
	 * @return array
	 */
	public static function to_form_values( array $row ) {
		$values = array();
		foreach ( array( 'title', 'description', 'start_date', 'end_date', 'venue_name', 'venue_address', 'city', 'event_url' ) as $field ) {
			$values[ $field ] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
		}
		$values['start_time'] = empty( $row['start_time'] ) ? '' : substr( $row['start_time'], 0, 5 );
		$values['end_time']   = empty( $row['end_time'] ) ? '' : substr( $row['end_time'], 0, 5 );
		$values['all_day']    = empty( $row['all_day'] ) ? 0 : 1;
		return $values;
	}

	/**
	 * Legt einen neuen manuellen Termin an bzw. aktualisiert einen bestehenden (nur source = manual).
	 *
	 * @param Eventcrawler_Repository $repository Datenzugriff.
	 * @param array                   $event      Ergebnis von prepare().
	 * @param int                     $id         0 = neu.
	 * @return int|WP_Error ID.
	 */
	public static function save( Eventcrawler_Repository $repository, array $event, $id = 0 ) {
		$id = (int) $id;
		if ( 0 === $id ) {
			$new_id = $repository->insert( $event );
			return $new_id ? $new_id : new WP_Error( 'eventcrawler_db', 'Die Veranstaltung konnte nicht gespeichert werden.' );
		}

		$existing = $repository->get_by_id( $id );
		if ( ! $existing || self::SOURCE !== $existing['source'] ) {
			return new WP_Error( 'eventcrawler_not_manual', 'Nur manuelle Veranstaltungen können bearbeitet werden.' );
		}

		unset( $event['external_id'] ); // Herkunft (z. B. wpem-123) bleibt erhalten.
		$now                   = current_time( 'mysql' );
		$event['updated_at']   = $now;
		$event['last_seen_at'] = $now;

		return $repository->update( $id, $event ) ? $id : new WP_Error( 'eventcrawler_db', 'Die Veranstaltung konnte nicht gespeichert werden.' );
	}
}
