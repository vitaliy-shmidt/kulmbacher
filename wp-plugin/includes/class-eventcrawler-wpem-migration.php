<?php
/**
 * Einmalige Übernahme der Termine aus „WP Kulmbacher Land“ (WPEM).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest den WPEM-Bestand nur lesend direkt aus wp_posts/wp_postmeta/wp_terms (funktioniert auch bei deaktiviertem WPEM)
 * und legt veröffentlichte Termine als manuelle Termine an. Keine Synchronisation, in WPEM wird nichts verändert.
 * Mapping, Dubletten- und Idempotenzregeln: docs/10-frontend.md Abschnitt 10.
 */
class Eventcrawler_Wpem_Migration {

	const POST_TYPE = 'wpem_event';
	const TAXONOMY  = 'wpem_venue';
	const ID_PREFIX = 'wpem-';

	const META_KEYS = array( '_wpem_start_date', '_wpem_start_time', '_wpem_end_date', '_wpem_end_time', '_wpem_all_day', '_wpem_website_url' );

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
	 * Gibt es WPEM-Termine in der Datenbank (unabhängig davon, ob WPEM aktiv ist)?
	 *
	 * @return bool
	 */
	public static function has_wpem_data() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", self::POST_TYPE ) );
	}

	/**
	 * Probelauf: ordnet jeden WPEM-Termin einem Ergebnis zu, ohne etwas zu speichern.
	 *
	 * @return array counts (Status => Anzahl) und items (je Termin: post_id, title, status, reason, warnings, match_id, event).
	 *               Status: new, migrated, duplicate, invalid, not_published.
	 */
	public function analyze() {
		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit') ORDER BY ID ASC",
				self::POST_TYPE
			),
			ARRAY_A
		);

		$meta     = $this->load_meta();
		$venues   = $this->load_venues();
		$migrated = $this->load_migrated_ids();
		$items    = array();
		$counts   = array_fill_keys( array( 'new', 'migrated', 'duplicate', 'invalid', 'not_published' ), 0 );

		$prepared = array();
		foreach ( $posts as $post ) {
			$id   = (int) $post['ID'];
			$item = array(
				'post_id'  => $id,
				'title'    => self::decode( $post['post_title'] ),
				'status'   => 'new',
				'reason'   => '',
				'warnings' => array(),
				'match_id' => 0,
				'event'    => null,
			);

			if ( 'publish' !== $post['post_status'] ) {
				$item['status'] = 'not_published';
				$item['reason'] = 'Status „' . $post['post_status'] . '“ (nur veröffentlichte Termine werden übernommen)';
			} elseif ( isset( $migrated[ self::ID_PREFIX . $id ] ) ) {
				$item['status']   = 'migrated';
				$item['match_id'] = $migrated[ self::ID_PREFIX . $id ];
			} else {
				$result = Eventcrawler_Manual_Events::prepare(
					self::map( $post, isset( $meta[ $id ] ) ? $meta[ $id ] : array(), isset( $venues[ $id ] ) ? $venues[ $id ] : null ),
					false
				);
				$item['warnings'] = $result['warnings'];
				if ( $result['event'] ) {
					$item['event'] = $result['event'];
					$prepared[]    = count( $items );
				} else {
					$item['status'] = 'invalid';
					$item['reason'] = implode( ' ', $result['errors'] );
				}
			}
			$items[] = $item;
		}

		// Dubletten zu vorhandenen Terminen (Crawler oder schon manuell erfasst), nur exakte Regeln.
		if ( $prepared ) {
			$dates = array();
			foreach ( $prepared as $index ) {
				$dates[] = $items[ $index ]['event']['start_date'];
			}
			$lookup = $this->load_duplicate_lookup( min( $dates ), max( $dates ) );
			foreach ( $prepared as $index ) {
				$match = self::find_duplicate( $items[ $index ]['event'], $lookup );
				if ( $match ) {
					$items[ $index ]['status']   = 'duplicate';
					$items[ $index ]['match_id'] = $match['id'];
					$items[ $index ]['reason']   = $match['reason'];
				}
			}
		}

		foreach ( $items as $item ) {
			$counts[ $item['status'] ]++;
		}

		return array(
			'counts' => $counts,
			'items'  => $items,
		);
	}

	/**
	 * Übernimmt alle Termine mit Status "new". Mehrfaches Ausführen legt nichts doppelt an (external_id wpem-{ID}).
	 *
	 * @return array counts aus analyze() plus inserted und failed.
	 */
	public function run() {
		$analysis = $this->analyze();
		$inserted = 0;
		$failed   = 0;

		foreach ( $analysis['items'] as $item ) {
			if ( 'new' !== $item['status'] ) {
				continue;
			}
			$result = Eventcrawler_Manual_Events::save( $this->repository, $item['event'] );
			if ( is_wp_error( $result ) ) {
				$failed++;
			} else {
				$inserted++;
			}
		}

		return array_merge(
			$analysis['counts'],
			array(
				'inserted' => $inserted,
				'failed'   => $failed,
			)
		);
	}

	/**
	 * WPEM-Rohdaten eines Termins → Eingabe für Eventcrawler_Manual_Events::prepare().
	 *
	 * @param array      $post  ID, post_title, post_content.
	 * @param array      $meta  Meta-Key => Wert.
	 * @param array|null $venue name, city, address (erster Ort nach Name) oder null.
	 * @return array
	 */
	public static function map( array $post, array $meta, $venue ) {
		$get = function ( $key ) use ( $meta ) {
			return isset( $meta[ $key ] ) ? trim( (string) $meta[ $key ] ) : '';
		};

		$city       = '';
		$venue_name = '';
		$address    = '';
		if ( $venue ) {
			$name = trim( self::decode( $venue['name'] ) );
			// Wie das <h2> im XML-Export von WPEM: Stadt des Orts, sonst sein Name.
			$city       = '' !== trim( (string) $venue['city'] ) ? trim( self::decode( $venue['city'] ) ) : $name;
			$venue_name = mb_strtolower( $name ) === mb_strtolower( $city ) ? '' : $name;
			$address    = trim( preg_replace( '/\s*\R\s*/u', ', ', trim( self::decode( (string) $venue['address'] ) ) ) );
		}

		return array(
			'external_id'   => self::ID_PREFIX . (int) $post['ID'],
			'title'         => self::decode( $post['post_title'] ),
			'description'   => self::decode( wp_strip_all_tags( strip_shortcodes( (string) $post['post_content'] ) ) ),
			'start_date'    => self::german_date( $get( '_wpem_start_date' ) ),
			'start_time'    => self::time( $get( '_wpem_start_time' ) ),
			'end_date'      => self::german_date( $get( '_wpem_end_date' ) ),
			'end_time'      => self::time( $get( '_wpem_end_time' ) ),
			'all_day'       => '1' === $get( '_wpem_all_day' ) ? 1 : 0,
			'venue_name'    => $venue_name,
			'venue_address' => $address,
			'city'          => $city,
			'event_url'     => $get( '_wpem_website_url' ),
		);
	}

	/**
	 * "TT.MM.JJJJ" (auch einstellig) → "JJJJ-MM-TT"; anderes bleibt unverändert und wird von prepare() als ungültig gemeldet.
	 *
	 * @param string $value WPEM-Datum.
	 * @return string
	 */
	public static function german_date( $value ) {
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		}
		return $value;
	}

	/**
	 * "9:30" → "09:30"; anderes bleibt unverändert (prepare() prüft).
	 *
	 * @param string $value WPEM-Uhrzeit.
	 * @return string
	 */
	private static function time( $value ) {
		return preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ? sprintf( '%02d:%s', $m[1], $m[2] ) : $value;
	}

	/**
	 * @param string $text Text aus der Datenbank (Titel und Termnamen speichert WordPress teils mit Entities).
	 * @return string
	 */
	private static function decode( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * @return array Post-ID => array( Meta-Key => Wert ).
	 */
	private function load_meta() {
		global $wpdb;
		$keys = implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND pm.meta_key IN ({$keys}) ORDER BY pm.meta_id ASC",
				array_merge( array( self::POST_TYPE ), self::META_KEYS )
			),
			ARRAY_A
		);

		$meta = array();
		foreach ( $rows as $row ) {
			// Wie get_post_meta( …, true ): der erste Wert zählt.
			if ( ! isset( $meta[ (int) $row['post_id'] ][ $row['meta_key'] ] ) ) {
				$meta[ (int) $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
			}
		}
		return $meta;
	}

	/**
	 * Erster Ort je Termin, nach Name sortiert wie wp_get_post_terms() in WPEM.
	 *
	 * @return array Post-ID => array( name, city, address ).
	 */
	private function load_venues() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, t.term_id, t.name FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s ORDER BY t.name ASC, t.term_id ASC",
				self::TAXONOMY
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}

		$term_meta = array();
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.term_id, tm.meta_key, tm.meta_value FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
				WHERE tt.taxonomy = %s AND tm.meta_key IN ('wpem_venue_address', 'wpem_venue_city') ORDER BY tm.meta_id ASC",
				self::TAXONOMY
			),
			ARRAY_A
		);
		foreach ( $meta_rows as $row ) {
			if ( ! isset( $term_meta[ (int) $row['term_id'] ][ $row['meta_key'] ] ) ) {
				$term_meta[ (int) $row['term_id'] ][ $row['meta_key'] ] = $row['meta_value'];
			}
		}

		$venues = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row['object_id'];
			if ( isset( $venues[ $post_id ] ) ) {
				continue;
			}
			$term_id            = (int) $row['term_id'];
			$venues[ $post_id ] = array(
				'name'    => $row['name'],
				'city'    => isset( $term_meta[ $term_id ]['wpem_venue_city'] ) ? $term_meta[ $term_id ]['wpem_venue_city'] : '',
				'address' => isset( $term_meta[ $term_id ]['wpem_venue_address'] ) ? $term_meta[ $term_id ]['wpem_venue_address'] : '',
			);
		}
		return $venues;
	}

	/**
	 * @return array external_id (wpem-{ID}) => Eventcrawler-ID der bereits übernommenen Termine.
	 */
	private function load_migrated_ids() {
		global $wpdb;
		$table = Eventcrawler_Installer::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, external_id FROM {$table} WHERE source = %s AND external_id LIKE %s",
				Eventcrawler_Manual_Events::SOURCE,
				$wpdb->esc_like( self::ID_PREFIX ) . '%'
			),
			ARRAY_A
		);
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ $row['external_id'] ] = (int) $row['id'];
		}
		return $ids;
	}

	/**
	 * Vorhandene Termine im Zeitraum (alle Quellen außer bereits übernommenen WPEM-Terminen) als Nachschlagetabelle.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array url => id, title => id (Schlüssel enthalten das Datum).
	 */
	private function load_duplicate_lookup( $from, $to ) {
		global $wpdb;
		$table = Eventcrawler_Installer::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, city, start_date, event_url FROM {$table}
				WHERE start_date >= %s AND start_date <= %s AND NOT ( source = %s AND external_id IS NOT NULL AND external_id LIKE %s ) ORDER BY id ASC",
				$from,
				$to,
				Eventcrawler_Manual_Events::SOURCE,
				$wpdb->esc_like( self::ID_PREFIX ) . '%'
			),
			ARRAY_A
		);

		$lookup = array(
			'url'   => array(),
			'title' => array(),
		);
		foreach ( $rows as $row ) {
			$url = self::normalize_url( (string) $row['event_url'] );
			if ( '' !== $url && ! isset( $lookup['url'][ $row['start_date'] . '|' . $url ] ) ) {
				$lookup['url'][ $row['start_date'] . '|' . $url ] = (int) $row['id'];
			}
			$key = $row['start_date'] . '|' . self::normalize_text( $row['title'] ) . '|' . self::normalize_text( $row['city'] );
			if ( ! isset( $lookup['title'][ $key ] ) ) {
				$lookup['title'][ $key ] = (int) $row['id'];
			}
		}
		return $lookup;
	}

	/**
	 * Exakte Dublettenregeln (docs/10-frontend.md Abschnitt 10): gleiches Datum und gleiche URL oder gleicher Titel + Stadt.
	 *
	 * @param array $event  Normalisierter WPEM-Termin.
	 * @param array $lookup Ergebnis von load_duplicate_lookup().
	 * @return array|null id, reason.
	 */
	private static function find_duplicate( array $event, array $lookup ) {
		$url = self::normalize_url( (string) $event['event_url'] );
		if ( '' !== $url && isset( $lookup['url'][ $event['start_date'] . '|' . $url ] ) ) {
			return array(
				'id'     => $lookup['url'][ $event['start_date'] . '|' . $url ],
				'reason' => 'gleiches Datum und gleicher Webseiten-Link',
			);
		}
		$key = $event['start_date'] . '|' . self::normalize_text( $event['title'] ) . '|' . self::normalize_text( $event['city'] );
		if ( isset( $lookup['title'][ $key ] ) ) {
			return array(
				'id'     => $lookup['title'][ $key ],
				'reason' => 'gleiches Datum, gleicher Titel und gleiche Stadt',
			);
		}
		return null;
	}

	/**
	 * Schema und Host klein, ohne #Fragment; Pfad und Query exakt.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		$url = trim( preg_replace( '/#.*$/s', '', $url ) );
		if ( ! preg_match( '#^(https?)://([^/?]+)(.*)$#i', $url, $m ) ) {
			return '';
		}
		return strtolower( $m[1] ) . '://' . strtolower( $m[2] ) . $m[3];
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( (string) $text ) ) );
	}
}
