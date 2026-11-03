<?php
/**
 * Admin-Seite "Quellen": Status je Quelle, Abruf einzeln oder für alle Quellen.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü "Eventcrawler → Quellen" (docs/06-admin.md Abschnitt 16).
 *
 * Der Status je Quelle (letzter Abruf, Ergebnis, Fehlermeldung) wird beim AJAX-Abruf in der Option
 * `eventcrawler_source_status` gespeichert – keine Log-Tabelle, nur der jeweils letzte Lauf. Crawler und Adapter
 * bleiben unverändert; geschrieben wird ausschließlich in Eventcrawler_Admin::handle_crawl_source().
 */
class Eventcrawler_Sources_Admin {

	const PAGE_SLUG     = 'eventcrawler-sources';
	const STATUS_OPTION = 'eventcrawler_source_status';

	/**
	 * Nach so vielen Tagen ohne Abruf gilt eine Quelle als „länger nicht abgerufen“ (Hinweis, gelb).
	 */
	const STALE_DAYS = 7;

	public static function register_menu() {
		return add_submenu_page( Eventcrawler_Admin::MENU_SLUG, 'Quellen', 'Quellen', Eventcrawler_Admin::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Speichert das Ergebnis des letzten Abrufs einer Quelle.
	 *
	 * @param string          $slug     Quelle.
	 * @param array|WP_Error  $result   Statistik oder Fehler.
	 * @param float           $duration Sekunden.
	 */
	public static function record_status( $slug, $result, $duration ) {
		$all          = self::all_status();
		$all[ $slug ] = array(
			'time'     => current_time( 'mysql' ),
			'ok'       => ! is_wp_error( $result ),
			'stats'    => is_wp_error( $result ) ? null : array_map( 'intval', (array) $result ),
			'message'  => is_wp_error( $result ) ? $result->get_error_message() : '',
			'duration' => round( (float) $duration, 1 ),
		);
		update_option( self::STATUS_OPTION, $all, false );
	}

	/**
	 * @return array Slug => Status.
	 */
	public static function all_status() {
		$all = get_option( self::STATUS_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Bewertung einer Quelle für die Anzeige.
	 *
	 * @param array|null $status Gespeicherter Status.
	 * @return array level (ok|warning|error|muted), text.
	 */
	public static function classify( $status ) {
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return array( 'muted', 'Noch nicht über diese Oberfläche abgerufen' );
		}
		if ( empty( $status['ok'] ) ) {
			return array( 'error', 'Letzter Abruf fehlgeschlagen. Vorhandene Veranstaltungen bleiben erhalten.' );
		}
		$age = time() - (int) strtotime( get_gmt_from_date( $status['time'] ) . ' UTC' );
		if ( $age > self::STALE_DAYS * DAY_IN_SECONDS ) {
			return array( 'warning', sprintf( 'Seit mehr als %d Tagen nicht abgerufen.', self::STALE_DAYS ) );
		}
		return array( 'ok', 'Letzter Abruf erfolgreich' );
	}

	/**
	 * „heute 08:10“, „gestern 17:30“ oder „03.11.2026 08:10“ (Ortszeit der Installation).
	 *
	 * @param string $mysql Datum/Zeit.
	 * @return string
	 */
	public static function human_time( $mysql ) {
		$day = substr( (string) $mysql, 0, 10 );
		if ( current_time( 'Y-m-d' ) === $day ) {
			return 'heute ' . mysql2date( 'H:i', $mysql );
		}
		if ( wp_date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ) === $day ) {
			return 'gestern ' . mysql2date( 'H:i', $mysql );
		}
		return mysql2date( 'd.m.Y H:i', $mysql );
	}

	/**
	 * Abrufbereich (Button + Fortschritt) für assets/eventcrawler-admin.js.
	 *
	 * @param bool $with_select Auswahlfeld der Quellen anzeigen.
	 */
	public static function render_crawl_controls( $with_select ) {
		?>
		<div class="eventcrawler-crawl">
			<p class="eventcrawler-actions">
				<?php if ( $with_select ) : ?>
					<label for="eventcrawler-crawl-source">Quelle</label>
					<select id="eventcrawler-crawl-source">
						<option value="all">Alle Quellen</option>
						<?php foreach ( Eventcrawler_Admin::source_options() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" id="eventcrawler-crawl-start" class="button button-primary" disabled>Abrufen starten</button>
				<?php else : ?>
					<button type="button" id="eventcrawler-crawl-start" class="button button-primary" data-source="all" disabled>Alle Quellen abrufen</button>
				<?php endif; ?>
				<noscript><span class="description">Das Abrufen benötigt JavaScript.</span></noscript>
			</p>
			<div id="eventcrawler-crawl-progress" class="notice inline" hidden></div>
		</div>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$counts  = ( new Eventcrawler_Repository() )->count_by_source();
		$status  = self::all_status();
		$imports = Eventcrawler_Importer::import_sources();
		?>
		<div class="wrap eventcrawler-admin">
			<h1>Quellen</h1>
			<?php Eventcrawler_Admin::render_help( 'Quellen sind die Websites, von denen der Eventcrawler Veranstaltungen abruft. „Abrufen“ holt die aktuellen Termine einer Quelle; neue werden angelegt, geänderte aktualisiert. Ist eine Quelle nicht erreichbar, bleiben ihre gespeicherten Veranstaltungen unverändert. Termine, die in der Quelle fehlen, werden nicht automatisch gelöscht.' ); ?>
			<?php self::render_crawl_controls( false ); ?>

			<div class="eventcrawler-grid">
				<?php foreach ( Eventcrawler_Admin::source_options() as $slug => $label ) : ?>
					<?php
					$s               = isset( $status[ $slug ] ) ? $status[ $slug ] : null;
					list( $level, $text ) = self::classify( $s );
					?>
					<div class="eventcrawler-card eventcrawler-source-card eventcrawler-source-card--<?php echo esc_attr( $level ); ?>">
						<h3 class="eventcrawler-icon-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $label ); ?></h3>
						<p><strong><?php echo esc_html( self::count_label( isset( $counts[ $slug ] ) ? $counts[ $slug ] : 0 ) ); ?></strong></p>
						<p><?php echo esc_html( $text ); ?></p>
						<?php if ( $s ) : ?>
							<p class="eventcrawler-label">Zuletzt abgerufen: <?php echo esc_html( self::human_time( $s['time'] ) ); ?></p>
							<?php if ( ! empty( $s['ok'] ) && is_array( $s['stats'] ) ) : ?>
								<p class="eventcrawler-label"><?php echo esc_html( self::stats_text( $s['stats'] ) ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
						<p><button type="button" class="button eventcrawler-crawl-one" data-source="<?php echo esc_attr( $slug ); ?>" disabled>Abrufen</button></p>
						<?php if ( $s && ( '' !== $s['message'] || isset( $s['duration'] ) ) ) : ?>
							<details class="eventcrawler-tech">
								<summary>Technische Details</summary>
								<p>Kennung: <code><?php echo esc_html( $slug ); ?></code><br>
								Dauer: <?php echo esc_html( number_format_i18n( (float) $s['duration'], 1 ) ); ?> s
								<?php if ( '' !== $s['message'] ) : ?>
									<br>Meldung: <code><?php echo esc_html( $s['message'] ); ?></code>
								<?php endif; ?></p>
							</details>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<h2>Weitere Herkunft</h2>
			<div class="eventcrawler-grid">
				<div class="eventcrawler-card eventcrawler-source-card eventcrawler-source-card--muted">
					<h3><?php echo esc_html( Eventcrawler_Manual_Events::NAME ); ?></h3>
					<p><strong><?php echo esc_html( self::count_label( isset( $counts[ Eventcrawler_Manual_Events::SOURCE ] ) ? $counts[ Eventcrawler_Manual_Events::SOURCE ] : 0 ) ); ?></strong></p>
					<p>Von der Redaktion angelegt; werden nicht abgerufen.</p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Eventcrawler_Manual_Events::PAGE_SLUG ) ); ?>">Veranstaltung hinzufügen</a></p>
				</div>
				<?php foreach ( $imports as $slug => $data ) : ?>
					<div class="eventcrawler-card eventcrawler-source-card eventcrawler-source-card--muted">
						<h3><?php echo esc_html( $data['label'] ); ?> <span class="eventcrawler-label">(Import)</span></h3>
						<p><strong><?php echo esc_html( self::count_label( isset( $counts[ $slug ] ) ? $counts[ $slug ] : 0 ) ); ?></strong></p>
						<?php if ( ! empty( $data['last_import'] ) ) : ?>
							<p class="eventcrawler-label">Letzter Import: <?php echo esc_html( self::human_time( $data['last_import'] ) ); ?></p>
						<?php endif; ?>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Eventcrawler_Import_Admin::PAGE_SLUG ) ); ?>">Datei importieren</a></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param int $count Anzahl.
	 * @return string
	 */
	public static function count_label( $count ) {
		return 1 === (int) $count ? '1 Veranstaltung' : number_format_i18n( (int) $count ) . ' Veranstaltungen';
	}

	/**
	 * @param array $stats found, new, updated, unchanged, filtered, failed.
	 * @return string
	 */
	public static function stats_text( array $stats ) {
		$get = function ( $key ) use ( $stats ) {
			return isset( $stats[ $key ] ) ? (int) $stats[ $key ] : 0;
		};
		return sprintf(
			'Gefunden %d · neu %d · aktualisiert %d · unverändert %d · gefiltert %d · übersprungen %d',
			$get( 'found' ),
			$get( 'new' ),
			$get( 'updated' ),
			$get( 'unchanged' ),
			$get( 'filtered' ),
			$get( 'failed' )
		);
	}
}
