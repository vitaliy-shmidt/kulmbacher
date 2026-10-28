<?php
/**
 * Datenbankzugriff auf die Veranstaltungstabelle.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kapselt alle $wpdb-Zugriffe auf {$wpdb->prefix}eventcrawler_events.
 *
 * Enthält bewusst keine Matching-Logik; die gehört später in den Crawler (docs/03-crawler.md).
 */
class Eventcrawler_Repository {

	/**
	 * Spalten, die über insert()/update() geschrieben werden dürfen, mit wpdb-Format.
	 */
	const COLUMNS = array(
		'source'       => '%s',
		'external_id'  => '%s',
		'event_key'    => '%s',
		'title'        => '%s',
		'description'  => '%s',
		'start_date'   => '%s',
		'start_time'   => '%s',
		'end_date'     => '%s',
		'end_time'     => '%s',
		'all_day'      => '%d',
		'venue_name'   => '%s',
		'venue_address' => '%s',
		'city'         => '%s',
		'event_url'    => '%s',
		'source_url'   => '%s',
		'content_hash' => '%s',
		'created_at'   => '%s',
		'updated_at'   => '%s',
		'last_seen_at' => '%s',
	);

	/**
	 * Erlaubte Sortierfelder (Whitelist gegen SQL-Injection über ORDER BY).
	 */
	const SORTABLE = array( 'start_date', 'title', 'city', 'source', 'last_seen_at' );

	/**
	 * Spalten für das öffentliche Frontend (docs/10-frontend.md): keine internen Felder wie external_id, event_key,
	 * content_hash, source_url oder Zeitstempel.
	 */
	const PUBLIC_COLUMNS = 'id, source, title, description, start_date, start_time, end_date, end_time, all_day, venue_name, venue_address, city, event_url';

	/**
	 * Reihenfolge im Frontend: Tag, dann Termine ohne Uhrzeit zuerst (wie die Tagesliste von WPEM), dann Uhrzeit, Titel.
	 */
	const PUBLIC_ORDER = 'start_date ASC, start_time IS NOT NULL ASC, start_time ASC, title ASC, id ASC';

	/**
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * @var string
	 */
	private $table;

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = Eventcrawler_Installer::table_name();
	}

	/**
	 * @param int $id Event-ID.
	 * @return array|null
	 */
	public function get_by_id( $id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);
	}

	/**
	 * @param string $source      Quellen-Slug.
	 * @param string $external_id Externe ID der Quelle.
	 * @return array|null
	 */
	public function find_by_external_id( $source, $external_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source = %s AND external_id = %s LIMIT 1",
				$source,
				$external_id
			),
			ARRAY_A
		);
	}

	/**
	 * Erwartet eine bereits normalisierte URL (Normalisierung ist Aufgabe des Crawlers).
	 *
	 * @param string $source     Quellen-Slug.
	 * @param string $source_url Detail-URL.
	 * @return array|null
	 */
	public function find_by_source_url( $source, $source_url ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source = %s AND source_url = %s LIMIT 1",
				$source,
				$source_url
			),
			ARRAY_A
		);
	}

	/**
	 * Fallback-Matching (Priorität 3): Datensätze mit gleichem event_key, die im laufenden Crawl
	 * noch nicht zugeordnet wurden.
	 *
	 * @param string $source      Quellen-Slug.
	 * @param string $event_key   Hilfsschlüssel.
	 * @param string $run_started Startzeit des Laufs (MySQL-Format).
	 * @return array[]
	 */
	public function find_fallback_candidates( $source, $event_key, $run_started ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE source = %s AND event_key = %s AND last_seen_at < %s ORDER BY id ASC",
				$source,
				$event_key,
				$run_started
			),
			ARRAY_A
		);
	}

	/**
	 * Liste für die Admin-Tabelle.
	 *
	 * @param array $args {
	 *     @type string $orderby  Eines von self::SORTABLE. Standard: start_date.
	 *     @type string $order    ASC|DESC. Standard: ASC.
	 *     @type int    $per_page Standard: 50.
	 *     @type int    $page     1-basiert. Standard: 1.
	 *     @type array  $filters  Siehe where_clause().
	 * }
	 * @return array[]
	 */
	public function get_events( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'orderby'  => 'start_date',
				'order'    => 'ASC',
				'per_page' => 50,
				'page'     => 1,
				'filters'  => array(),
			)
		);
		list( $where, $params ) = $this->where_clause( $args['filters'] );

		$orderby  = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'start_date';
		$order    = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// Innerhalb eines Tages immer chronologisch; id als stabiler letzter Sortierschlüssel.
		$order_sql = "{$orderby} {$order}";
		if ( 'start_date' !== $orderby ) {
			$order_sql .= ', start_date ASC';
		}
		$order_sql .= ', start_time ASC, id ASC';

		$params[] = $per_page;
		$params[] = $offset;

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
	}

	/**
	 * Events für den XML-Export, ohne Paginierung, in stabiler Reihenfolge:
	 * Datum, Stadt, Uhrzeit (Events ohne Uhrzeit zuletzt), Titel, id.
	 *
	 * @param array $filters Siehe where_clause() (der Export nutzt source, month, year).
	 * @return array[]
	 */
	public function get_events_for_export( array $filters = array() ) {
		list( $where, $params ) = $this->where_clause( $filters );
		$sql = "SELECT * FROM {$this->table} {$where}
			ORDER BY start_date ASC, city ASC, start_time IS NULL ASC, start_time ASC, title ASC, id ASC";

		return $this->wpdb->get_results( $params ? $this->wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );
	}

	/**
	 * @param array $filters Siehe where_clause().
	 * @return int
	 */
	public function count_events( array $filters = array() ) {
		list( $where, $params ) = $this->where_clause( $filters );
		$sql = "SELECT COUNT(*) FROM {$this->table} {$where}";

		return (int) $this->wpdb->get_var( $params ? $this->wpdb->prepare( $sql, $params ) : $sql );
	}

	/**
	 * Seite der öffentlichen Liste (Frontend), nur PUBLIC_COLUMNS.
	 *
	 * @param array $filters  Siehe where_clause().
	 * @param int   $per_page Einträge je Seite.
	 * @param int   $page     1-basiert.
	 * @return array[]
	 */
	public function get_public_events( array $filters, $per_page, $page ) {
		list( $where, $params ) = $this->where_clause( $filters );
		$per_page = max( 1, (int) $per_page );
		$params[] = $per_page;
		$params[] = ( max( 1, (int) $page ) - 1 ) * $per_page;

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT ' . self::PUBLIC_COLUMNS . " FROM {$this->table} {$where} ORDER BY " . self::PUBLIC_ORDER . ' LIMIT %d OFFSET %d',
				$params
			),
			ARRAY_A
		);
	}

	/**
	 * Termine eines Zeitraums für den Kalender: nur die Spalten, die das Monatsraster braucht.
	 *
	 * @param array $filters Siehe where_clause() (date_from/date_to = Monat).
	 * @return array[]
	 */
	public function get_calendar_events( array $filters ) {
		list( $where, $params ) = $this->where_clause( $filters );
		$sql = "SELECT id, title, start_date, start_time, all_day FROM {$this->table} {$where} ORDER BY " . self::PUBLIC_ORDER;

		return $this->wpdb->get_results( $params ? $this->wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );
	}

	/**
	 * Städte mit Terminen (für die Stadtauswahl im Frontend), alphabetisch.
	 *
	 * @param array $filters Siehe where_clause().
	 * @return string[]
	 */
	public function get_cities( array $filters = array() ) {
		list( $where, $params ) = $this->where_clause( $filters );
		$sql = "SELECT DISTINCT city FROM {$this->table} {$where} ORDER BY city ASC";

		return $this->wpdb->get_col( $params ? $this->wpdb->prepare( $sql, $params ) : $sql );
	}

	/**
	 * Werte einer Textspalte der manuellen Termine (Vorschläge im Formular), alphabetisch, ohne leere Werte.
	 *
	 * @param string $column venue_name oder city.
	 * @return string[]
	 */
	public function get_distinct_values( $column ) {
		if ( ! in_array( $column, array( 'venue_name', 'city' ), true ) ) {
			return array();
		}
		return $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT {$column} FROM {$this->table} WHERE source = %s AND {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} ASC LIMIT 500",
				Eventcrawler_Manual_Events::SOURCE
			)
		);
	}

	/**
	 * Jahre, in denen Events beginnen, aufsteigend (für die Jahr-Auswahl).
	 *
	 * @return int[]
	 */
	public function get_available_years() {
		return array_map( 'intval', $this->wpdb->get_col( "SELECT DISTINCT YEAR(start_date) FROM {$this->table} ORDER BY 1 ASC" ) );
	}

	/**
	 * Gemeinsame Filterbedingungen für Liste, Zählung und Export. Erwartet bereits validierte Werte
	 * (Eventcrawler_Admin::read_filters()); leere Werte bedeuten "kein Filter".
	 *
	 * @param array $filters {
	 *     @type string $source Quellen-Slug.
	 *     @type int    $month  1–12; ohne Jahr: dieser Monat in allen Jahren.
	 *     @type int    $year   z. B. 2026; mit Monat: genau dieser Monat.
	 *     @type string   $search    Teilstring in Titel, Beschreibung, Ort, Stadt (Collation: ohne Groß-/Kleinschreibung).
	 *     @type string[] $sources   Frontend: nur diese Quellen-Slugs.
	 *     @type string   $date_from Frontend: start_date ab diesem Tag (Y-m-d).
	 *     @type string   $date_to   Frontend: start_date bis einschließlich diesem Tag (Y-m-d).
	 *     @type string   $city      Frontend: exakt diese Stadt.
	 * }
	 * @return array array( 'WHERE …' oder '', Parameter für prepare() ).
	 */
	private function where_clause( array $filters ) {
		$conditions = array();
		$params     = array();

		if ( ! empty( $filters['source'] ) ) {
			$conditions[] = 'source = %s';
			$params[]     = $filters['source'];
		}

		if ( ! empty( $filters['sources'] ) && is_array( $filters['sources'] ) ) {
			$conditions[] = 'source IN (' . implode( ',', array_fill( 0, count( $filters['sources'] ), '%s' ) ) . ')';
			$params       = array_merge( $params, array_values( $filters['sources'] ) );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'start_date >= %s';
			$params[]     = $filters['date_from'];
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'start_date <= %s';
			$params[]     = $filters['date_to'];
		}

		if ( isset( $filters['city'] ) && '' !== $filters['city'] ) {
			$conditions[] = 'city = %s';
			$params[]     = $filters['city'];
		}

		$month = empty( $filters['month'] ) ? 0 : (int) $filters['month'];
		$year  = empty( $filters['year'] ) ? 0 : (int) $filters['year'];
		if ( $year ) {
			// Datumsbereich statt YEAR()/MONTH(), damit der Index auf start_date greift.
			$from         = $month ? sprintf( '%04d-%02d-01', $year, $month ) : sprintf( '%04d-01-01', $year );
			$to           = $month ? gmdate( 'Y-m-d', strtotime( $from . ' +1 month' ) ) : sprintf( '%04d-01-01', $year + 1 );
			$conditions[] = 'start_date >= %s AND start_date < %s';
			$params[]     = $from;
			$params[]     = $to;
		} elseif ( $month ) {
			$conditions[] = 'MONTH(start_date) = %d';
			$params[]     = $month;
		}

		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like         = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
			$conditions[] = '(title LIKE %s OR description LIKE %s OR venue_name LIKE %s OR venue_address LIKE %s OR city LIKE %s)';
			array_push( $params, $like, $like, $like, $like, $like );
		}

		return array( $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '', $params );
	}

	/**
	 * Fügt ein Event ein. created_at, updated_at und last_seen_at werden auf "jetzt" gesetzt, falls nicht übergeben.
	 *
	 * @param array $data Spalte => Wert (nur Spalten aus self::COLUMNS).
	 * @return int|false Neue ID oder false bei Fehler.
	 */
	public function insert( array $data ) {
		$now  = current_time( 'mysql' );
		$data = wp_parse_args(
			$data,
			array(
				'created_at'   => $now,
				'updated_at'   => $now,
				'last_seen_at' => $now,
			)
		);
		$data = $this->filter_columns( $data );

		$result = $this->wpdb->insert( $this->table, $data, $this->formats( $data ) );

		return false === $result ? false : (int) $this->wpdb->insert_id;
	}

	/**
	 * Aktualisiert nur die übergebenen Spalten. Zeitstempel setzt der Aufrufer selbst,
	 * weil "nur last_seen_at" und "inhaltliches Update" unterschiedlich behandelt werden.
	 *
	 * @param int   $id   Event-ID.
	 * @param array $data Spalte => Wert.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		$data = $this->filter_columns( $data );
		if ( empty( $data ) ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->table,
			$data,
			array( 'id' => (int) $id ),
			$this->formats( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * @param int $id Event-ID.
	 * @return bool True, wenn ein Datensatz gelöscht wurde.
	 */
	public function delete( $id ) {
		$result = $this->wpdb->delete( $this->table, array( 'id' => (int) $id ), array( '%d' ) );
		return $result > 0;
	}

	/**
	 * @param int[] $ids Event-IDs.
	 * @return int Anzahl gelöschter Datensätze.
	 */
	public function delete_many( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$result = $this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM {$this->table} WHERE id IN ({$placeholders})", $ids )
		);

		return (int) $result;
	}

	/**
	 * @param array $data Rohdaten.
	 * @return array Nur erlaubte Spalten.
	 */
	private function filter_columns( array $data ) {
		return array_intersect_key( $data, self::COLUMNS );
	}

	/**
	 * @param array $data Gefilterte Daten.
	 * @return string[] wpdb-Formate in derselben Reihenfolge.
	 */
	private function formats( array $data ) {
		// Reihenfolge MUSS der von $data entsprechen: wpdb ordnet Formate nach Position zu.
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMNS[ $column ];
		}
		return $formats;
	}
}
