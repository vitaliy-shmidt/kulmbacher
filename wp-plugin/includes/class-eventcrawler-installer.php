<?php
/**
 * Anlage und Aktualisierung der Veranstaltungstabelle.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legt {$wpdb->prefix}eventcrawler_events per dbDelta() an bzw. migriert sie.
 *
 * Schema-Dokumentation: docs/02-datenbank.md
 */
class Eventcrawler_Installer {

	const DB_VERSION_OPTION = 'eventcrawler_db_version';

	/**
	 * Aktivierungs-Hook. Führt auch ausstehende Migrationen aus (z. B. Reaktivierung mit altem Schema).
	 */
	public static function activate() {
		self::upgrade();
		Eventcrawler_Filter_Rules::maybe_initialize();
	}

	/**
	 * Läuft bei plugins_loaded: Migration auch bei bereits aktiviertem Plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== EVENTCRAWLER_DB_VERSION ) {
			self::upgrade();
		}
	}

	/**
	 * Vollständiger Tabellenname mit dem Präfix der Installation.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'eventcrawler_events';
	}

	/**
	 * Migrationen (idempotent) -> dbDelta() -> Datenanpassungen -> Version speichern.
	 * Schlägt eine Umbenennung fehl, wird abgebrochen und die Version NICHT erhöht,
	 * damit dbDelta() keine leeren Ersatzspalten neben den alten Daten anlegt.
	 *
	 * @return bool
	 */
	public static function upgrade() {
		if ( ! self::migrate_to_2() ) {
			return false;
		}

		self::create_table(); // Version 3: ergänzt nur die Spalte venue_address (keine Umbenennung).
		self::backfill();

		update_option( self::DB_VERSION_OPTION, EVENTCRAWLER_DB_VERSION, false );
		return true;
	}

	/**
	 * Erstellt oder ergänzt die Tabelle (Schema-Version 3).
	 *
	 * dbDelta() verlangt: ein Feld pro Zeile, zwei Leerzeichen nach PRIMARY KEY, KEY statt INDEX.
	 * dbDelta() kann Spalten nicht umbenennen – das erledigt migrate_to_2() vorher.
	 * event_key ist bewusst NICHT unique (wiederkehrende Termine, siehe docs/02-datenbank.md).
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(50) NOT NULL,
  external_id varchar(191) DEFAULT NULL,
  event_key char(40) NOT NULL,
  title varchar(255) NOT NULL,
  description text,
  start_date date NOT NULL,
  start_time time DEFAULT NULL,
  end_date date DEFAULT NULL,
  end_time time DEFAULT NULL,
  all_day tinyint(1) unsigned NOT NULL DEFAULT 0,
  venue_name varchar(255) DEFAULT NULL,
  venue_address varchar(255) DEFAULT NULL,
  city varchar(100) NOT NULL,
  event_url varchar(2048) DEFAULT NULL,
  source_url varchar(2048) DEFAULT NULL,
  content_hash char(40) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_external (source,external_id),
  KEY source_url (source,source_url(191)),
  KEY source_event_key (source,event_key),
  KEY start_date (start_date,start_time),
  KEY city (city),
  KEY last_seen_at (last_seen_at)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Schema 1 -> 2: event_date -> start_date, venue -> venue_name (Daten bleiben erhalten),
	 * Index event_date -> start_date. Nur ausführen, was noch nicht geschehen ist.
	 *
	 * @return bool False bei einem DB-Fehler.
	 */
	private static function migrate_to_2() {
		global $wpdb;
		$table = self::table_name();

		// Frische Installation: Tabelle existiert noch nicht, dbDelta() legt Version 2 direkt an.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return true;
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );

		$statements = array();
		if ( in_array( 'event_date', $columns, true ) && ! in_array( 'start_date', $columns, true ) ) {
			$statements[] = "ALTER TABLE {$table} CHANGE event_date start_date date NOT NULL";
		}
		if ( in_array( 'venue', $columns, true ) && ! in_array( 'venue_name', $columns, true ) ) {
			$statements[] = "ALTER TABLE {$table} CHANGE venue venue_name varchar(255) DEFAULT NULL";
		}
		if ( in_array( 'event_date', $indexes, true ) ) {
			$statements[] = "ALTER TABLE {$table} DROP INDEX event_date";
		}

		foreach ( $statements as $sql ) {
			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nur Tabellenname.
				error_log( '[eventcrawler][migration] Fehlgeschlagen: ' . $sql . ' – ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}
		}

		return true;
	}

	/**
	 * Datenanpassung nach dbDelta() (idempotent, läuft bei jedem Upgrade):
	 * - event_url für Altdaten = source_url (bis Version 1 war source_url der einzige, öffentliche Link)
	 * - content_hash mit der aktuellen Formel neu berechnen (Version 2: Ende/Ganztag/event_url, Version 3:
	 *   venue_address), damit nur echte Inhaltsänderungen ein UPDATE auslösen. venue_address bleibt bei Altdaten NULL.
	 */
	private static function backfill() {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( "UPDATE {$table} SET event_url = source_url WHERE event_url IS NULL AND source_url IS NOT NULL AND source_url <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $row ) {
			$hash = Eventcrawler_Crawler::content_hash( $row );
			if ( $hash !== $row['content_hash'] ) {
				$wpdb->update( $table, array( 'content_hash' => $hash ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
			}
		}
	}
}
