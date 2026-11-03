<?php
/**
 * Admin-Seiten "Übersicht", "Export" und "Hilfe".
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Übersicht (Startseite des Menüs), Export und Hilfe (docs/06-admin.md Abschnitte 16–18).
 * Nur lesend bzw. Formulare auf vorhandene Aktionen (Export: admin-post eventcrawler_export, unverändert).
 */
class Eventcrawler_Dashboard_Admin {

	const PAGE_EXPORT = 'eventcrawler-export';
	const PAGE_HELP   = 'eventcrawler-help';

	/**
	 * Übersicht = Top-Level-Seite (Eventcrawler_Admin::MENU_SLUG).
	 *
	 * @return string|false Hook.
	 */
	public static function register_overview() {
		return add_submenu_page( Eventcrawler_Admin::MENU_SLUG, 'Übersicht', 'Übersicht', Eventcrawler_Admin::CAPABILITY, Eventcrawler_Admin::MENU_SLUG, array( __CLASS__, 'render_overview' ) );
	}

	public static function register_export() {
		add_submenu_page( Eventcrawler_Admin::MENU_SLUG, 'Export', 'Export', Eventcrawler_Admin::CAPABILITY, self::PAGE_EXPORT, array( __CLASS__, 'render_export' ) );
	}

	public static function register_help() {
		add_submenu_page( Eventcrawler_Admin::MENU_SLUG, 'Hilfe', 'Hilfe', Eventcrawler_Admin::CAPABILITY, self::PAGE_HELP, array( __CLASS__, 'render_help' ) );
	}

	/**
	 * @param string $page Slug.
	 * @param array  $args Parameter.
	 * @return string
	 */
	private static function page_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ Übersicht */

	public static function render_overview() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$repository = new Eventcrawler_Repository();
		$today      = current_time( 'Y-m-d' );
		$by_source  = $repository->count_by_source();
		$total      = array_sum( $by_source );
		$future     = $repository->count_events( array( 'date_from' => $today ) );
		$manual     = isset( $by_source[ Eventcrawler_Manual_Events::SOURCE ] ) ? $by_source[ Eventcrawler_Manual_Events::SOURCE ] : 0;
		$sources    = Eventcrawler_Admin::source_options();
		$status     = Eventcrawler_Sources_Admin::all_status();
		$last_crawl = '';
		$problems   = array();
		foreach ( $sources as $slug => $label ) {
			if ( ! isset( $status[ $slug ] ) ) {
				continue;
			}
			if ( $status[ $slug ]['time'] > $last_crawl ) {
				$last_crawl = $status[ $slug ]['time'];
			}
			list( $level, $text ) = Eventcrawler_Sources_Admin::classify( $status[ $slug ] );
			if ( in_array( $level, array( 'error', 'warning' ), true ) ) {
				$problems[ $slug ] = array( $level, $label, $text, $status[ $slug ] );
			}
		}
		$last_import = get_option( Eventcrawler_Importer::LAST_IMPORT_OPTION );
		$mode        = Eventcrawler_Settings::mode();
		?>
		<div class="wrap eventcrawler-admin">
			<h1>Eventcrawler – Übersicht</h1>
			<?php Eventcrawler_Admin::render_help( 'Der Eventcrawler sammelt Veranstaltungen von Partner-Websites, aus Dateiimporten und aus Ihren eigenen Einträgen. Von hier aus erreichen Sie die wichtigsten Aufgaben.' ); ?>

			<div class="eventcrawler-grid">
				<?php
				self::stat_card( number_format_i18n( $total ), 'Veranstaltungen gespeichert', self::page_url( Eventcrawler_Admin::PAGE_SLUG ) );
				self::stat_card( number_format_i18n( $future ), 'davon ab heute', self::page_url( Eventcrawler_Admin::PAGE_SLUG ) );
				self::stat_card( number_format_i18n( $manual ), 'manuelle Veranstaltungen', self::page_url( Eventcrawler_Admin::PAGE_SLUG, array( 'source' => Eventcrawler_Manual_Events::SOURCE ) ) );
				self::stat_card( number_format_i18n( count( $sources ) ), 'aktive Quellen', self::page_url( Eventcrawler_Sources_Admin::PAGE_SLUG ) );
				?>
				<div class="eventcrawler-card">
					<span class="eventcrawler-label">Letzter Abruf</span>
					<span class="eventcrawler-number" style="font-size:1.25rem"><?php echo esc_html( $last_crawl ? Eventcrawler_Sources_Admin::human_time( $last_crawl ) : '—' ); ?></span>
					<span class="eventcrawler-label">Letzter Import:
						<?php echo esc_html( is_array( $last_import ) && ! empty( $last_import['time'] ) ? Eventcrawler_Sources_Admin::human_time( $last_import['time'] ) : '—' ); ?>
					</span>
				</div>
			</div>

			<h2>Schnellaktionen</h2>
			<div class="eventcrawler-actions">
				<a class="button" href="<?php echo esc_url( self::page_url( Eventcrawler_Manual_Events::PAGE_SLUG ) ); ?>">Veranstaltung hinzufügen</a>
				<a class="button" href="<?php echo esc_url( self::page_url( Eventcrawler_Import_Admin::PAGE_SLUG ) ); ?>">Datei importieren</a>
				<a class="button" href="<?php echo esc_url( self::page_url( self::PAGE_EXPORT ) ); ?>">XML exportieren</a>
			</div>
			<?php Eventcrawler_Sources_Admin::render_crawl_controls( false ); ?>

			<h2>Quellen</h2>
			<?php if ( ! $problems ) : ?>
				<p class="eventcrawler-icon-ok">Keine Auffälligkeiten beim letzten Abruf. <a href="<?php echo esc_url( self::page_url( Eventcrawler_Sources_Admin::PAGE_SLUG ) ); ?>">Alle Quellen ansehen</a></p>
			<?php else : ?>
				<div class="eventcrawler-grid">
					<?php foreach ( $problems as $slug => $p ) : ?>
						<div class="eventcrawler-card eventcrawler-source-card eventcrawler-source-card--<?php echo esc_attr( $p[0] ); ?>">
							<h3 class="eventcrawler-icon-<?php echo esc_attr( $p[0] ); ?>"><?php echo esc_html( $p[1] ); ?></h3>
							<p><?php echo esc_html( $p[2] ); ?></p>
							<p class="eventcrawler-label">Zuletzt abgerufen: <?php echo esc_html( Eventcrawler_Sources_Admin::human_time( $p[3]['time'] ) ); ?></p>
							<?php if ( ! empty( $p[3]['message'] ) ) : ?>
								<details class="eventcrawler-tech"><summary>Technische Details</summary><code><?php echo esc_html( $p[3]['message'] ); ?></code></details>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<p><a href="<?php echo esc_url( self::page_url( Eventcrawler_Sources_Admin::PAGE_SLUG ) ); ?>">Alle Quellen ansehen</a></p>
			<?php endif; ?>

			<h2>Website</h2>
			<div class="eventcrawler-card">
				<p><strong>WPEM-Kompatibilität:</strong>
					<?php echo esc_html( 'parallel' === $mode ? 'Parallelbetrieb – WP Kulmbacher Land bleibt aktiv, seine Seiten bleiben unverändert.' : ( 'aliases' === $mode ? 'Die bisherigen Shortcodes zeigen Eventcrawler-Veranstaltungen.' : 'Eigenständig – der Eventcrawler nutzt nur eigene Shortcodes.' ) ); ?>
					<a href="<?php echo esc_url( self::page_url( Eventcrawler_Settings_Admin::PAGE_SLUG ) ); ?>">Einstellungen</a>
				</p>
				<?php do_action( 'eventcrawler_overview_website' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string $number Zahl (formatiert).
	 * @param string $label  Beschriftung.
	 * @param string $url    Link.
	 */
	private static function stat_card( $number, $label, $url ) {
		printf(
			'<a class="eventcrawler-card eventcrawler-stat" href="%1$s"><span class="eventcrawler-number">%2$s</span><span class="eventcrawler-label">%3$s</span></a>',
			esc_url( $url ),
			esc_html( $number ),
			esc_html( $label )
		);
	}

	/* ------------------------------------------------------------------ Export */

	public static function render_export() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$years = ( new Eventcrawler_Repository() )->get_available_years();
		?>
		<div class="wrap eventcrawler-admin">
			<h1>Export</h1>
			<?php Eventcrawler_Admin::render_help( 'Der XML-Export erzeugt die Datei für InDesign im bewährten Format: Tage als Überschrift, darunter die Orte und je Veranstaltung eine Zeile. Sie können alle Veranstaltungen exportieren oder nach Quelle, Monat und Jahr eingrenzen.' ); ?>
			<?php Eventcrawler_Admin::render_notices(); ?>
			<div class="eventcrawler-card">
				<h2>XML exportieren</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eventcrawler-export">
					<input type="hidden" name="action" value="eventcrawler_export">
					<?php wp_nonce_field( 'eventcrawler_export', 'eventcrawler_export_nonce' ); ?>
					<p><?php Eventcrawler_Admin::render_period_fields( 'eventcrawler-export', Eventcrawler_Admin::EXPORT_FIELDS, Eventcrawler_Admin::filter_source_options(), $years, array() ); ?></p>
					<p><button type="submit" class="button button-primary">XML exportieren</button></p>
				</form>
				<p class="description">Dateiname: <code><?php echo esc_html( Eventcrawler_Exporter::filename() ); ?></code>. Die Datei ist UTF-8-kodiert. Veranstaltungen ohne Stadt werden nicht exportiert.</p>
			</div>
			<div class="eventcrawler-card">
				<h2>Link-Bezeichnungen</h2>
				<p>Im XML steht nach „Info:“ die Domain des Veranstaltungslinks. Unter <a href="<?php echo esc_url( self::page_url( Eventcrawler_Domain_Admin::PAGE_SLUG ) ); ?>">Link-Bezeichnungen</a> legen Sie dafür verständliche Namen fest (z. B. „Bad Steben“ statt „pages.et4.de“).</p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ Hilfe */

	public static function render_help() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$faq = array(
			'Was passiert, wenn eine Quelle nicht erreichbar ist?' => 'Der Abruf dieser Quelle wird abgebrochen und unter „Quellen“ als fehlgeschlagen angezeigt. Die bisher gespeicherten Veranstaltungen dieser Quelle bleiben unverändert erhalten, die anderen Quellen werden normal abgerufen. Versuchen Sie es später erneut; die technische Fehlermeldung finden Sie unter „Technische Details“.',
			'Was bedeutet „gefiltert“?'                            => 'Eine Veranstaltung wurde in der Quelle gefunden, aber wegen einer Filterregel nicht gespeichert – z. B. Stammtische oder politische Termine. Die Regeln verwalten Sie unter „Filterregeln“. Neue Regeln löschen keine bereits gespeicherten Veranstaltungen.',
			'Was bedeutet „unverändert“?'                          => 'Die Veranstaltung ist bereits gespeichert und hat sich in der Quelle bzw. Datei nicht geändert. Es wird nichts neu angelegt und nichts überschrieben.',
			'Was bedeutet „übersprungen“ bzw. „fehlerhaft“?'       => 'Der Eintrag war unvollständig oder ungültig (z. B. ohne Datum oder ohne Stadt) und wurde deshalb nicht gespeichert. Es wird nie etwas erfunden.',
			'Werden fehlende Termine automatisch gelöscht?'        => 'Nein. Verschwindet ein Termin aus einer Quelle oder fehlt er in einer neuen Importdatei, bleibt er gespeichert. Löschen ist immer eine bewusste Aktion in der Liste „Veranstaltungen“. Ein gelöschter Termin, den die Quelle weiterhin anbietet, wird beim nächsten Abruf wieder angelegt.',
			'Was passiert beim Import?'                           => 'Sie laden eine CSV- oder XML-Datei hoch, ordnen die Spalten zu und sehen im Probelauf, was neu angelegt, aktualisiert oder übersprungen würde. Erst mit „Import durchführen“ wird gespeichert. Importieren Sie dieselbe Datei erneut, entstehen keine doppelten Termine.',
			'Was ist der Parallelbetrieb mit WPEM?'               => 'Das bisherige Plugin „WP Kulmbacher Land“ bleibt aktiv, alle bestehenden Seiten zeigen weiterhin dessen Veranstaltungen. Der Eventcrawler verwendet nur eigene Shortcodes und eigene Seiten, z. B. die Testseite. Erst wenn Sie es unter „Einstellungen“ ausdrücklich einschalten und WP Kulmbacher Land deaktiviert ist, übernimmt der Eventcrawler die bisherigen Shortcodes.',
			'Kann ich gecrawlte Veranstaltungen bearbeiten?'      => 'Nein, nur manuelle Veranstaltungen. Gecrawlte und importierte Termine gehören ihrer Quelle; eine Änderung würde beim nächsten Abruf bzw. Import überschrieben. Sie können sie aber löschen.',
		);
		?>
		<div class="wrap eventcrawler-admin">
			<h1>Hilfe</h1>
			<?php Eventcrawler_Admin::render_help( 'Kurze Anleitung für die tägliche Arbeit mit dem Eventcrawler.' ); ?>

			<div class="eventcrawler-card">
				<h2>Erste Schritte</h2>
				<ol class="eventcrawler-steps">
					<li><strong>Quellen prüfen:</strong> Unter <a href="<?php echo esc_url( self::page_url( Eventcrawler_Sources_Admin::PAGE_SLUG ) ); ?>">Quellen</a> sehen Sie, welche Websites angebunden sind und wann sie zuletzt abgerufen wurden.</li>
					<li><strong>Veranstaltungen abrufen:</strong> „Alle Quellen abrufen“ holt die aktuellen Termine. Das dauert einige Minuten; lassen Sie das Fenster offen.</li>
					<li><strong>Veranstaltungen kontrollieren:</strong> Unter <a href="<?php echo esc_url( self::page_url( Eventcrawler_Admin::PAGE_SLUG ) ); ?>">Veranstaltungen</a> filtern Sie nach Quelle, Monat oder Suchbegriff. Unerwünschte Termine können Sie löschen; eigene Termine legen Sie mit „Veranstaltung hinzufügen“ an.</li>
					<li><strong>Frontend-Testseite prüfen:</strong> Unter <a href="<?php echo esc_url( self::page_url( Eventcrawler_Settings_Admin::PAGE_SLUG ) ); ?>">Einstellungen</a> eine Testseite erstellen (Entwurf) und in der Vorschau ansehen.</li>
					<li><strong>Export erstellen:</strong> Unter <a href="<?php echo esc_url( self::page_url( self::PAGE_EXPORT ) ); ?>">Export</a> die XML-Datei für InDesign herunterladen.</li>
				</ol>
			</div>

			<div class="eventcrawler-card eventcrawler-faq">
				<h2>Häufige Fragen</h2>
				<?php foreach ( $faq as $question => $answer ) : ?>
					<details>
						<summary><?php echo esc_html( $question ); ?></summary>
						<p><?php echo esc_html( $answer ); ?></p>
					</details>
				<?php endforeach; ?>
			</div>

			<div class="eventcrawler-card">
				<h2>Shortcodes für Seiten</h2>
				<table class="widefat striped eventcrawler-table-compact">
					<tbody>
						<tr><td><code>[eventcrawler_events]</code></td><td>Veranstaltungsliste mit Suche, Stadt- und Datumsfilter</td></tr>
						<tr><td><code>[eventcrawler_events view="day"]</code></td><td>Tagesansicht mit „Vorheriger/Nächster Tag“</td></tr>
						<tr><td><code>[eventcrawler_calendar]</code></td><td>Monatskalender</td></tr>
						<tr><td><code>[eventcrawler_events sources="manual"]</code></td><td>nur bestimmte Quellen (Kennungen kommagetrennt)</td></tr>
					</tbody>
				</table>
			</div>

			<div class="eventcrawler-card">
				<h2>Begriffe</h2>
				<dl>
					<dt><strong>Abrufen</strong></dt><dd>Termine einer Partner-Website holen und speichern.</dd>
					<dt><strong>Quelle</strong></dt><dd>Herkunft einer Veranstaltung: eine Website, ein Dateiimport oder „Manuell“.</dd>
					<dt><strong>Filterregel</strong></dt><dd>Begriff, bei dem eine abgerufene Veranstaltung nicht gespeichert wird.</dd>
					<dt><strong>Link-Bezeichnung</strong></dt><dd>Verständlicher Name statt einer Domain im XML-Export.</dd>
				</dl>
			</div>
		</div>
		<?php
	}
}
