<?php
/**
 * Admin-Bereich: Menü, Veranstaltungsliste mit Filtern, Crawler-Start, Export, Löschaktionen.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-Seite "Importierte Veranstaltungen" (docs/06-admin.md).
 */
class Eventcrawler_Admin {

	const CAPABILITY = 'manage_options';
	const PAGE_SLUG  = 'eventcrawler';

	/**
	 * GET-Parameter der Listenfilter (sichtbar in der URL).
	 */
	const LIST_FIELDS = array(
		'source' => 'source',
		'month'  => 'month',
		'year'   => 'year',
		'search' => 's',
	);

	/**
	 * POST-Felder des Exportformulars (eigene Namen, keine Übernahme aus Liste oder Crawler).
	 */
	const EXPORT_FIELDS = array(
		'source' => 'eventcrawler_export_source',
		'month'  => 'eventcrawler_export_month',
		'year'   => 'eventcrawler_export_year',
	);

	/**
	 * @var Eventcrawler_List_Table|null
	 */
	private static $list_table;

	/**
	 * Validierte Listenfilter des aktuellen Requests.
	 *
	 * @var array
	 */
	private static $list_filters = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'wp_ajax_eventcrawler_crawl_source', array( __CLASS__, 'handle_crawl_source' ) ); // nur angemeldet
		add_action( 'wp_ajax_eventcrawler_get_event_details', array( __CLASS__, 'handle_event_details' ) ); // nur angemeldet
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_eventcrawler_export', array( __CLASS__, 'handle_export' ) );
		Eventcrawler_Filter_Admin::init();
		Eventcrawler_Domain_Admin::init();
		Eventcrawler_Event_Admin::init();
		Eventcrawler_Wpem_Admin::init();
	}

	/**
	 * Quellen für Auswahlfelder: Slug => Anzeigename, direkt aus dem Adapter-Register.
	 *
	 * @return string[]
	 */
	public static function source_options() {
		$options = array();
		foreach ( Eventcrawler_Crawler::get_sources() as $slug => $source ) {
			$options[ $slug ] = $source->get_name();
		}
		return $options;
	}

	/**
	 * Quellen für Listen- und Exportfilter: das Register plus "Manuell" (manuelle Termine werden nie gecrawlt,
	 * erscheinen deshalb nicht im Crawler-Dropdown).
	 *
	 * @return string[]
	 */
	public static function filter_source_options() {
		return self::source_options() + array( Eventcrawler_Manual_Events::SOURCE => Eventcrawler_Manual_Events::NAME );
	}

	/**
	 * Liest und validiert Filterwerte (Quelle, Monat, Jahr, optional Suche).
	 * Leere Werte = kein Filter. Ungültige Werte werden verworfen und in $errors gemeldet.
	 *
	 * @param array    $input  Rohdaten ($_GET oder $_POST).
	 * @param string[] $fields Filtername => Feldname im Request.
	 * @param string[] $errors Wird mit Fehlermeldungen befüllt.
	 * @return array source (string), month (int), year (int), search (string).
	 */
	public static function read_filters( array $input, array $fields, &$errors = array() ) {
		$errors  = array();
		$filters = array(
			'source' => '',
			'month'  => 0,
			'year'   => 0,
			'search' => '',
		);
		$raw     = function ( $key ) use ( $input, $fields ) {
			return ( isset( $fields[ $key ], $input[ $fields[ $key ] ] ) && is_scalar( $input[ $fields[ $key ] ] ) )
				? trim( sanitize_text_field( wp_unslash( (string) $input[ $fields[ $key ] ] ) ) )
				: '';
		};

		$source = $raw( 'source' );
		if ( '' !== $source ) {
			if ( isset( self::filter_source_options()[ $source ] ) ) {
				$filters['source'] = $source;
			} else {
				$errors[] = 'Unbekannte Quelle.';
			}
		}

		$month = $raw( 'month' );
		if ( '' !== $month ) {
			if ( ctype_digit( $month ) && (int) $month >= 1 && (int) $month <= 12 ) {
				$filters['month'] = (int) $month;
			} else {
				$errors[] = 'Ungültiger Monat.';
			}
		}

		$year = $raw( 'year' );
		if ( '' !== $year ) {
			if ( ctype_digit( $year ) && (int) $year >= 2000 && (int) $year <= 2100 ) {
				$filters['year'] = (int) $year;
			} else {
				$errors[] = 'Ungültiges Jahr.';
			}
		}

		$filters['search'] = mb_substr( $raw( 'search' ), 0, 100 );

		return $filters;
	}

	/**
	 * Aktive Listenfilter als URL-Parameter (für Lösch-Links und Redirects, damit die Ansicht erhalten bleibt).
	 *
	 * @return array
	 */
	public static function list_query_args() {
		$args = array();
		foreach ( self::LIST_FIELDS as $key => $field ) {
			if ( ! empty( self::$list_filters[ $key ] ) ) {
				$args[ $field ] = self::$list_filters[ $key ];
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Ansichtsparameter.
		foreach ( array( 'orderby', 'order' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_key( wp_unslash( $_GET[ $key ] ) );
			}
		}
		if ( ! empty( $_GET['paged'] ) ) {
			$args['paged'] = absint( $_GET['paged'] );
		}
		// phpcs:enable
		return $args;
	}

	/**
	 * "XML exportieren": nur POST, manage_options, Nonce. Liefert die Datei direkt als Download
	 * oder leitet bei einem Fehler mit Meldung zur Liste zurück (kein kaputter Download).
	 */
	public static function handle_export() {
		self::check_post_request( 'eventcrawler_export', 'eventcrawler_export_nonce' );

		$errors  = array();
		$filters = self::read_filters( $_POST, self::EXPORT_FIELDS, $errors ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- oben geprüft.
		if ( $errors ) {
			self::redirect_with_notices( array( array( 'type' => 'error', 'message' => 'Export abgebrochen: ' . implode( ' ', $errors ) ) ) );
		}
		unset( $filters['search'] ); // Keine Freitextsuche im Export.

		// Eventuelle Ausgaben (z. B. PHP-Hinweise) während der Erzeugung abfangen, damit sie nie in der Datei landen.
		ob_start();
		$exporter = new Eventcrawler_Exporter( new Eventcrawler_Repository() );
		$xml      = $exporter->build_xml( $filters );
		ob_end_clean();

		if ( is_wp_error( $xml ) ) {
			if ( 'eventcrawler_export_empty' === $xml->get_error_code() ) {
				$filtered = $filters['source'] || $filters['month'] || $filters['year'];
				$message  = $filtered ? 'Keine Veranstaltungen für den gewählten Exportfilter vorhanden.' : $xml->get_error_message();
				self::redirect_with_notices( array( array( 'type' => 'warning', 'message' => $message ) ) );
			}
			self::redirect_with_notices( array( array( 'type' => 'error', 'message' => $xml->get_error_message() ) ) );
		}

		if ( $exporter->skipped > 0 ) {
			error_log( sprintf( '[eventcrawler][export] %d Veranstaltung(en) ohne Stadt oder mit ungültigem Datum nicht exportiert.', $exporter->skipped ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Eventcrawler_Exporter::filename() . '"' );
		header( 'Content-Length: ' . strlen( $xml ) );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- von XMLWriter escaptes XML.
		exit;
	}

	/**
	 * AJAX "eventcrawler_crawl_source": crawlt GENAU EINE registrierte Quelle in diesem Request und antwortet mit JSON.
	 * "Alle Quellen" ruft der Browser nacheinander je Quelle auf (assets/eventcrawler-admin.js). Nur angemeldete
	 * Benutzer (wp_ajax_ ohne nopriv), manage_options, Nonce "eventcrawler_crawl_source", Source-ID aus dem Register.
	 */
	public static function handle_crawl_source() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_send_json_error( array( 'message' => 'Nur POST.' ), 405 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}
		check_ajax_referer( 'eventcrawler_crawl_source', 'nonce' ); // ungültig: HTTP 403, Antwort "-1"

		$sources = Eventcrawler_Crawler::get_sources();
		$slug    = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! isset( $sources[ $slug ] ) ) {
			wp_send_json_error( array( 'source' => $slug, 'message' => 'Unbekannte Quelle.' ), 400 );
		}
		$source = $sources[ $slug ];

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- gilt jetzt je Quelle.
		}

		$started = microtime( true );
		try {
			$result = ( new Eventcrawler_Crawler( new Eventcrawler_Repository() ) )->run( $source );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'eventcrawler_exception', $e->getMessage() );
		}
		$data = array(
			'source'   => $slug,
			'label'    => $source->get_name(),
			'duration' => round( microtime( true ) - $started, 1 ),
		);

		if ( is_wp_error( $result ) ) {
			// Quellenfehler (z. B. HTTP 500): Daten dieser Quelle bleiben unverändert.
			$data['message'] = $result->get_error_message() . ' Vorhandene Veranstaltungen dieser Quelle wurden nicht verändert.';
			$data['stats']   = null;
			wp_send_json_error( $data );
		}

		$data['stats'] = $result; // found, new, updated, unchanged, filtered, failed (= fehlerhafte Events)
		wp_send_json_success( $data );
	}

	/**
	 * AJAX "eventcrawler_get_event_details": liefert die gespeicherten Werte EINES Events für das Detail-Modal der Liste
	 * (docs/06-admin.md Abschnitt 11). Nur lesend. Nur angemeldet (wp_ajax_ ohne nopriv), nur POST, manage_options,
	 * Nonce "eventcrawler_event_details", event_id als positive Ganzzahl.
	 *
	 * Antwort: {"success":true,"data":{"id":123,"fields":[["id","123"],["source","coburg"],…,["end_time",null]]}}.
	 * Werte unverändert aus der Datenbank (SELECT *, Strings bzw. null), Spalten in Tabellenreihenfolge.
	 */
	public static function handle_event_details() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_send_json_error( array( 'message' => 'Nur POST.' ), 405 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}
		if ( ! check_ajax_referer( 'eventcrawler_event_details', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Sicherheitsprüfung fehlgeschlagen.' ), 403 );
		}

		$raw = isset( $_POST['event_id'] ) && is_scalar( $_POST['event_id'] ) ? trim( (string) wp_unslash( $_POST['event_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nur Ziffern erlaubt (unten).
		if ( '' === $raw || ! ctype_digit( $raw ) || strlen( $raw ) > 19 || 0 === (int) $raw ) {
			wp_send_json_error( array( 'message' => 'Ungültige Event-ID.' ), 400 );
		}

		$event = ( new Eventcrawler_Repository() )->get_by_id( (int) $raw );
		if ( ! $event ) {
			wp_send_json_error( array( 'message' => 'Event nicht gefunden.' ), 404 );
		}

		$fields = array();
		foreach ( $event as $column => $value ) {
			$fields[] = array( (string) $column, null === $value ? null : (string) $value );
		}
		wp_send_json_success(
			array(
				'id'     => (int) $event['id'],
				'fields' => $fields,
			)
		);
	}

	/**
	 * Lädt das Crawl-Skript nur auf der eigenen Seite und übergibt Quellen (aus dem Register), AJAX-URL und Nonce.
	 * Dasselbe Skript steuert das Detail-Modal der Liste (Nonce in window.eventcrawlerDetails).
	 *
	 * @param string $hook_suffix Aktuelle Admin-Seite.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'eventcrawler-admin', plugins_url( 'assets/eventcrawler-admin.js', EVENTCRAWLER_PATH . 'eventcrawler.php' ), array(), EVENTCRAWLER_VERSION, true );
		wp_enqueue_style( 'eventcrawler-admin', plugins_url( 'assets/eventcrawler-admin.css', EVENTCRAWLER_PATH . 'eventcrawler.php' ), array(), EVENTCRAWLER_VERSION );

		$sources = array();
		foreach ( self::source_options() as $slug => $label ) {
			$sources[] = array(
				'id'    => $slug,
				'label' => $label,
			);
		}
		wp_add_inline_script(
			'eventcrawler-admin',
			'window.eventcrawlerCrawl = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'eventcrawler_crawl_source' ),
					'sources' => $sources,
				)
			) . ';' . "
" . 'window.eventcrawlerDetails = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'eventcrawler_event_details' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Gemeinsame Absicherung der admin-post-Aktionen: POST, Capability, Nonce.
	 *
	 * @param string $action Nonce-Aktion.
	 * @param string $field  Nonce-Feldname.
	 */
	private static function check_post_request( $action, $field ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'eventcrawler' ), 405 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		check_admin_referer( $action, $field );
	}

	/**
	 * Speichert Meldungen für die Listenseite und leitet dorthin um.
	 *
	 * @param array[] $notices Liste aus array( 'type' => success|warning|error, 'message' => string ).
	 * @param array   $args    Zusätzliche Listenparameter (z. B. source => manual).
	 */
	public static function redirect_with_notices( array $notices, array $args = array() ) {
		set_transient( self::notice_key(), $notices, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), array_map( 'rawurlencode', $args ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return string Transient-Schlüssel für die Meldungen des aktuellen Benutzers.
	 */
	private static function notice_key() {
		return 'eventcrawler_notices_' . get_current_user_id();
	}

	public static function register_menu() {
		// Menü bewusst "Eventcrawler": "Veranstaltungen" gehört einem anderen Plugin (manuelle Verwaltung).
		$hook = add_menu_page(
			'Importierte Veranstaltungen',
			'Eventcrawler',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-calendar-alt',
			26
		);

		add_action( 'load-' . $hook, array( __CLASS__, 'load_page' ) );

		// Untermenü: erster Eintrag = dieselbe Seite mit eigenem Namen, danach manuelle Termine, "Filterregeln",
		// "Link-Bezeichnungen" und – nur solange WPEM-Daten existieren – die WPEM-Übernahme.
		add_submenu_page( self::PAGE_SLUG, 'Importierte Veranstaltungen', 'Importierte Veranstaltungen', self::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
		Eventcrawler_Event_Admin::register_menu();
		Eventcrawler_Filter_Admin::register_menu();
		Eventcrawler_Domain_Admin::register_menu();
		Eventcrawler_Wpem_Admin::register_menu();
	}

	/**
	 * Läuft vor der Ausgabe der Seite: Filter lesen, Liste vorbereiten, Löschaktionen verarbeiten (mit Redirect).
	 */
	public static function load_page() {
		require_once EVENTCRAWLER_PATH . 'includes/admin/class-eventcrawler-list-table.php';

		// Ungültige Filterwerte in der URL werden ignoriert (reine Ansicht).
		self::$list_filters = self::read_filters( $_GET, self::LIST_FIELDS ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::$list_table   = new Eventcrawler_List_Table( new Eventcrawler_Repository(), self::$list_filters );

		if ( 'delete' === self::$list_table->current_action() ) {
			self::handle_delete();
		}
	}

	/**
	 * Einzel- und Bulk-Löschen. Beendet den Request immer mit einem Redirect (Filter und Seite bleiben erhalten).
	 */
	private static function handle_delete() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}

		$repository = new Eventcrawler_Repository();

		// phpcs:disable WordPress.Security.NonceVerification -- Nonces werden direkt darunter geprüft.
		if ( isset( $_POST['ids'] ) || isset( $_POST['_wpnonce'] ) ) {
			// Bulk-Aktion aus dem Formular der WP_List_Table.
			check_admin_referer( 'bulk-events' );
			$ids     = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
			$deleted = $repository->delete_many( $ids );
		} else {
			// Einzelnes Löschen über den Link in der Spalte "Aktionen".
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			check_admin_referer( 'eventcrawler_delete_' . $id );
			$deleted = ( $id && $repository->delete( $id ) ) ? 1 : 0;
		}
		// phpcs:enable

		$args = self::list_query_args();
		unset( $args['paged'] ); // Nach dem Löschen kann die Seite leer sein.
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'    => self::PAGE_SLUG,
						'deleted' => $deleted,
					),
					array_map( 'rawurlencode', $args )
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}

		self::$list_table->prepare_items();

		$sources        = self::source_options();
		$filter_sources = self::filter_source_options();
		$years          = ( new Eventcrawler_Repository() )->get_available_years();
		$f       = self::$list_filters;
		if ( $f['year'] && ! in_array( $f['year'], $years, true ) ) {
			$years[] = $f['year']; // gewähltes Jahr ohne Daten trotzdem anzeigen
			sort( $years );
		}
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Importierte Veranstaltungen</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Eventcrawler_Manual_Events::PAGE_SLUG ) ); ?>" class="page-title-action">Veranstaltung hinzufügen</a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<?php // Crawler: kein Formular, das Skript ruft je Quelle admin-ajax.php auf (Nonce kommt aus window.eventcrawlerCrawl). ?>
			<div class="eventcrawler-crawl">
				<p>
					<strong>Crawler:</strong>
					<label for="eventcrawler-crawl-source">Quelle</label>
					<select id="eventcrawler-crawl-source">
						<option value="all">Alle Quellen</option>
						<?php self::render_options( $sources, '' ); ?>
					</select>
					<button type="button" id="eventcrawler-crawl-start" class="button button-primary" disabled>Crawler starten</button>
					<noscript><span class="description">Der Crawler benötigt JavaScript.</span></noscript>
				</p>
				<div id="eventcrawler-crawl-progress" class="notice inline" hidden></div>
			</div>

			<?php // Export- und Filterformulare haben eigene Feldnamen, IDs und Nonce-Felder (keine Kollision mit _wpnonce der Liste). ?>

			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="eventcrawler-export">
				<input type="hidden" name="action" value="eventcrawler_export">
				<?php wp_nonce_field( 'eventcrawler_export', 'eventcrawler_export_nonce' ); ?>
				<p>
					<strong>XML-Export:</strong>
					<?php self::render_period_fields( 'eventcrawler-export', self::EXPORT_FIELDS, $filter_sources, $years, array() ); ?>
					<button type="submit" class="button">XML exportieren</button>
				</p>
			</form>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="eventcrawler-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p>
					<strong>Liste filtern:</strong>
					<?php self::render_period_fields( 'eventcrawler-filter', self::LIST_FIELDS, $filter_sources, $years, $f ); ?>
					<label for="eventcrawler-filter-search">Suche</label>
					<input type="search" name="s" id="eventcrawler-filter-search" value="<?php echo esc_attr( $f['search'] ); ?>">
					<button type="submit" class="button">Filtern</button>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Zurücksetzen</a>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), array_map( 'rawurlencode', self::list_query_args() ) ), admin_url( 'admin.php' ) ) ); ?>">
				<?php self::$list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Auswahlfelder Quelle, Monat, Jahr (für Export und Listenfilter; eigene IDs je Formular).
	 *
	 * @param string   $id_prefix Präfix der HTML-IDs.
	 * @param string[] $fields    Filtername => Feldname.
	 * @param string[] $sources   Slug => Name.
	 * @param int[]    $years     Verfügbare Jahre.
	 * @param array    $selected  Aktuelle Werte.
	 */
	private static function render_period_fields( $id_prefix, array $fields, array $sources, array $years, array $selected ) {
		$selected = wp_parse_args( $selected, array( 'source' => '', 'month' => 0, 'year' => 0 ) );
		?>
		<label for="<?php echo esc_attr( $id_prefix . '-source' ); ?>">Quelle</label>
		<select name="<?php echo esc_attr( $fields['source'] ); ?>" id="<?php echo esc_attr( $id_prefix . '-source' ); ?>">
			<option value="">Alle Quellen</option>
			<?php self::render_options( $sources, $selected['source'] ); ?>
		</select>
		<label for="<?php echo esc_attr( $id_prefix . '-month' ); ?>">Monat</label>
		<select name="<?php echo esc_attr( $fields['month'] ); ?>" id="<?php echo esc_attr( $id_prefix . '-month' ); ?>">
			<option value="">Alle Monate</option>
			<?php self::render_options( Eventcrawler_Exporter::MONTHS, $selected['month'] ); ?>
		</select>
		<label for="<?php echo esc_attr( $id_prefix . '-year' ); ?>">Jahr</label>
		<select name="<?php echo esc_attr( $fields['year'] ); ?>" id="<?php echo esc_attr( $id_prefix . '-year' ); ?>">
			<option value="">Alle Jahre</option>
			<?php self::render_options( array_combine( $years, $years ) ?: array(), $selected['year'] ); ?>
		</select>
		<?php
	}

	/**
	 * @param array      $options  Wert => Beschriftung.
	 * @param string|int $selected Gewählter Wert.
	 */
	private static function render_options( array $options, $selected ) {
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $selected, (string) $value, false ),
				esc_html( $label )
			);
		}
	}

	private static function render_notices() {
		$notices = get_transient( self::notice_key() );
		if ( is_array( $notices ) ) {
			delete_transient( self::notice_key() );
			foreach ( $notices as $notice ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $notice['type'] ),
					esc_html( $notice['message'] )
				);
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige einer Zahl.
		if ( ! isset( $_GET['deleted'] ) ) {
			return;
		}

		$deleted = absint( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 0 === $deleted ) {
			$class   = 'notice-warning';
			$message = 'Es wurde keine Veranstaltung gelöscht.';
		} else {
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %d: Anzahl gelöschter Veranstaltungen */
				_n( '%d Veranstaltung gelöscht.', '%d Veranstaltungen gelöscht.', $deleted, 'eventcrawler' ),
				$deleted
			);
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
