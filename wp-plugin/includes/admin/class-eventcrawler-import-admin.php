<?php
/**
 * Admin-Seite "Import": CSV-/XML-Dateien in Schritten importieren.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü "Eventcrawler → Import" (docs/11-import.md Abschnitt 6).
 *
 * Ablauf: Datei auswählen → Format erkennen → Felder zuordnen → Vorschau/Probelauf → Import durchführen → Ergebnis.
 * Die hochgeladene Datei liegt bis zum Import in einem Transient des Benutzers (Datenbank, nicht öffentlich erreichbar,
 * 1 Stunde). Alle schreibenden Schritte: POST an admin-post.php, manage_options, eigener Nonce je Aktion.
 */
class Eventcrawler_Import_Admin {

	const PAGE_SLUG = 'eventcrawler-import';
	const NONCE     = 'eventcrawler_import_nonce';
	const ACTIONS   = array( 'upload', 'map', 'run' );

	/**
	 * Höchstens so viele Zeilen in Vorschau und Fehlerliste anzeigen (gezählt wird immer alles).
	 */
	const PREVIEW_ROWS = 20;
	const ERROR_ROWS   = 200;

	const ACTION_LABELS = array(
		'new'       => 'Neu',
		'update'    => 'Aktualisierung',
		'unchanged' => 'Unverändert',
		'invalid'   => 'Fehlerhaft',
	);

	public static function init() {
		foreach ( self::ACTIONS as $op ) {
			add_action( 'admin_post_eventcrawler_import_' . $op, array( __CLASS__, 'handle_' . $op ) );
		}
	}

	public static function register_menu() {
		add_submenu_page( Eventcrawler_Admin::MENU_SLUG, 'Import', 'Import', Eventcrawler_Admin::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * @param array $args Zusätzliche Parameter.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ Handler */

	/**
	 * Schritt 1: Datei hochladen, prüfen, zwischenspeichern.
	 */
	public static function handle_upload() {
		self::check( 'upload' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Datei wird in read_upload() geprüft, Nonce in check().
		$file = isset( $_FILES['import_file'] ) && is_array( $_FILES['import_file'] ) ? $_FILES['import_file'] : array();
		$read = Eventcrawler_Importer::read_upload( $file );
		if ( is_wp_error( $read ) ) {
			self::redirect( array(), array( 'error', $read->get_error_message() ) );
		}
		$token = wp_generate_password( 20, false );
		$state = array(
			'name'    => $read['name'],
			'format'  => $read['format'],
			'content' => $read['content'],
		);
		if ( ! set_transient( self::state_key( $token ), $state, HOUR_IN_SECONDS ) ) {
			self::redirect( array(), array( 'error', 'Die Datei konnte nicht zwischengespeichert werden. Bitte eine kleinere Datei verwenden.' ) );
		}
		self::redirect( array( 'step' => 'map', 'token' => $token ) );
	}

	/**
	 * Schritt 2: Zuordnung und Importquelle speichern → Probelauf.
	 */
	public static function handle_map() {
		self::check( 'map' );
		$token = self::post_text( 'token', 40 );
		$state = self::load_state( $token );
		if ( ! $state ) {
			self::redirect( array(), array( 'error', 'Die hochgeladene Datei ist nicht mehr vorhanden (nach einer Stunde wird sie verworfen). Bitte erneut hochladen.' ) );
		}

		$errors = array();
		$source = self::read_source_choice( $errors );
		$config = array(
			'source'    => $source ? $source[0] : '',
			'label'     => $source ? $source[1] : '',
			'delimiter' => null,
			'mapping'   => array(),
		);

		if ( 'csv' === $state['format'] ) {
			$delimiter = self::post_text( 'delimiter', 20 );
			$parsed    = isset( Eventcrawler_Importer::DELIMITERS[ $delimiter ] ) ? Eventcrawler_Importer::parse_csv( $state['content'], $delimiter ) : new WP_Error( 'x', 'Bitte ein Trennzeichen wählen.' );
			if ( is_wp_error( $parsed ) ) {
				$errors[] = $parsed->get_error_message();
			} else {
				$mapping = array();
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in check() geprüft.
				$raw = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				foreach ( array_keys( $parsed['header'] ) as $index ) {
					$field             = isset( $raw[ $index ] ) && is_scalar( $raw[ $index ] ) ? sanitize_key( (string) $raw[ $index ] ) : '';
					$mapping[ $index ] = isset( Eventcrawler_Importer::FIELDS[ $field ] ) ? $field : '';
				}
				$errors              = array_merge( $errors, Eventcrawler_Importer::validate_mapping( $mapping, $parsed['header'] ) );
				$config['delimiter'] = $delimiter;
				$config['mapping']   = $mapping;
			}
		}

		$state['config'] = $config;
		set_transient( self::state_key( $token ), $state, HOUR_IN_SECONDS );
		if ( $errors ) {
			$args = array( 'step' => 'map', 'token' => $token );
			if ( $config['delimiter'] ) {
				$args['delimiter'] = $config['delimiter'];
			}
			self::redirect( $args, array( 'error', implode( ' ', array_unique( $errors ) ) ) );
		}
		self::redirect( array( 'step' => 'preview', 'token' => $token ) );
	}

	/**
	 * Schritt 3: Import durchführen (nach dem Probelauf).
	 */
	public static function handle_run() {
		self::check( 'run' );
		$token = self::post_text( 'token', 40 );
		$state = self::load_state( $token );
		if ( ! $state || empty( $state['config']['source'] ) ) {
			self::redirect( array(), array( 'error', 'Der Import konnte nicht gestartet werden: Die Datei ist nicht mehr vorhanden. Bitte erneut hochladen.' ) );
		}
		$prepared = self::prepare_rows( $state );
		if ( is_wp_error( $prepared ) ) {
			self::redirect( array(), array( 'error', $prepared->get_error_message() ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$importer = new Eventcrawler_Importer( new Eventcrawler_Repository() );
		$result   = $importer->run( $prepared['rows'], $state['config']['source'], $state['config']['label'], $prepared['errors'], $state['name'] );

		delete_transient( self::state_key( $token ) );
		set_transient(
			self::result_key(),
			array(
				'name'   => $state['name'],
				'label'  => $state['config']['label'],
				'source' => $state['config']['source'],
				'counts' => $result['counts'],
				'errors' => array_slice( $result['errors'], 0, self::ERROR_ROWS, true ),
				'total_errors' => count( $result['errors'] ),
			),
			HOUR_IN_SECONDS
		);
		self::redirect( array( 'step' => 'done' ) );
	}

	/* ------------------------------------------------------------------ Hilfen */

	/**
	 * @param string $op Aktion.
	 */
	private static function check( $op ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'eventcrawler' ), 405 );
		}
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		check_admin_referer( 'eventcrawler_import_' . $op, self::NONCE );
	}

	/**
	 * @param array      $args   URL-Parameter.
	 * @param array|null $notice array( Typ, Text ).
	 */
	private static function redirect( array $args, $notice = null ) {
		if ( $notice ) {
			set_transient( 'eventcrawler_import_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * @param string $token Zufallstoken.
	 * @return string Transient-Schlüssel (je Benutzer, damit niemand fremde Dateien laden kann).
	 */
	private static function state_key( $token ) {
		return 'eventcrawler_import_' . get_current_user_id() . '_' . md5( (string) $token );
	}

	/**
	 * @return string
	 */
	private static function result_key() {
		return 'eventcrawler_import_result_' . get_current_user_id();
	}

	/**
	 * @param string $token Token.
	 * @return array|null
	 */
	private static function load_state( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', (string) $token ) ) {
			return null;
		}
		$state = get_transient( self::state_key( $token ) );
		return is_array( $state ) && isset( $state['content'], $state['format'] ) ? $state : null;
	}

	/**
	 * @param string $key Feld.
	 * @param int    $max Länge.
	 * @return string
	 */
	private static function post_text( $key, $max ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- in check() geprüft.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ), 0, $max ) : '';
	}

	/**
	 * @param string $key Feld.
	 * @param int    $max Länge.
	 * @return string
	 */
	private static function get_text( $key, $max ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) ), 0, $max ) : '';
	}

	/**
	 * Importquelle aus dem Formular: vorhandene Quelle oder neue (Bezeichnung + Kennung).
	 *
	 * @param string[] $errors Fehler.
	 * @return array|null array( slug, label ).
	 */
	private static function read_source_choice( array &$errors ) {
		$existing = Eventcrawler_Importer::import_sources();
		$choice   = self::post_text( 'source_choice', 60 );
		if ( '' !== $choice && '__new' !== $choice ) {
			if ( isset( $existing[ $choice ] ) ) {
				return array( $choice, (string) $existing[ $choice ]['label'] );
			}
			$errors[] = 'Unbekannte Importquelle.';
			return null;
		}
		$label = self::post_text( 'source_label', 100 );
		$slug  = self::post_text( 'source_slug', 50 );
		if ( '' === $label ) {
			$errors[] = 'Bitte eine Bezeichnung für die neue Importquelle eingeben (z. B. „Kulturamt Kulmbach“).';
			return null;
		}
		if ( '' === $slug ) {
			$slug = Eventcrawler_Importer::slug_from_label( $label );
		}
		$problem = Eventcrawler_Importer::validate_source( $slug );
		if ( $problem ) {
			$errors[] = $problem;
			return null;
		}
		return array( $slug, $label );
	}

	/**
	 * Zeilen einer zwischengespeicherten Datei mit ihrer Zuordnung.
	 *
	 * @param array $state Transient.
	 * @return array|WP_Error rows, errors.
	 */
	private static function prepare_rows( array $state ) {
		if ( 'xml' === $state['format'] ) {
			$parsed = Eventcrawler_Importer::parse_xml( $state['content'] );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			return array(
				'rows'   => $parsed['rows'],
				'errors' => $parsed['errors'],
			);
		}
		$config = isset( $state['config'] ) ? $state['config'] : array();
		if ( empty( $config['delimiter'] ) ) {
			return new WP_Error( 'eventcrawler_import_config', 'Bitte zuerst die Felder zuordnen.' );
		}
		$parsed = Eventcrawler_Importer::parse_csv( $state['content'], $config['delimiter'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return array(
			'rows'   => Eventcrawler_Importer::map_rows( $parsed['rows'], $config['mapping'] ),
			'errors' => $parsed['errors'],
		);
	}

	/* ------------------------------------------------------------------ Ausgabe */

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$step   = self::get_text( 'step', 10 );
		$token  = self::get_text( 'token', 40 );
		$notice = get_transient( 'eventcrawler_import_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'eventcrawler_import_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap eventcrawler-admin">
			<h1>Import</h1>
			<?php Eventcrawler_Admin::render_help( 'Hier können Sie Veranstaltungen aus CSV- oder XML-Dateien übernehmen. Vor dem Import können Sie alle Änderungen in einem Probelauf prüfen. Ein Import löscht nie Veranstaltungen: Fehlt ein Termin in einer späteren Datei, bleibt er erhalten.' ); ?>
			<?php self::render_steps( $step ); ?>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>
			<?php
			if ( 'done' === $step ) {
				self::render_result();
			} elseif ( in_array( $step, array( 'map', 'preview' ), true ) ) {
				$state = self::load_state( $token );
				if ( ! $state ) {
					echo '<div class="notice notice-warning"><p>' . esc_html( 'Die hochgeladene Datei ist nicht mehr vorhanden. Bitte erneut hochladen.' ) . '</p></div>';
					self::render_upload();
				} elseif ( 'map' === $step ) {
					self::render_map( $state, $token );
				} else {
					self::render_preview( $state, $token );
				}
			} else {
				self::render_upload();
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param string $step Aktueller Schritt.
	 */
	private static function render_steps( $step ) {
		$steps   = array(
			''        => '1. Datei auswählen',
			'map'     => '2. Felder zuordnen',
			'preview' => '3. Vorschau und Probelauf',
			'done'    => '4. Ergebnis',
		);
		$current = isset( $steps[ $step ] ) ? $step : '';
		echo '<p class="eventcrawler-import-steps">';
		$parts = array();
		foreach ( $steps as $key => $label ) {
			$parts[] = $key === $current ? '<strong>' . esc_html( $label ) . '</strong>' : '<span class="eventcrawler-label">' . esc_html( $label ) . '</span>';
		}
		echo implode( ' &rarr; ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Teile escaped.
		echo '</p>';
	}

	private static function render_upload() {
		$last = get_option( Eventcrawler_Importer::LAST_IMPORT_OPTION );
		?>
		<div class="eventcrawler-card">
			<h2>Datei auswählen</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="eventcrawler_import_upload">
				<?php wp_nonce_field( 'eventcrawler_import_upload', self::NONCE ); ?>
				<p>
					<label for="eventcrawler-import-file">CSV- oder XML-Datei</label><br>
					<input type="file" id="eventcrawler-import-file" name="import_file" accept=".csv,.txt,.xml,text/csv,text/xml,application/xml" required>
				</p>
				<p class="description">
					Höchstens <?php echo esc_html( size_format( Eventcrawler_Importer::max_bytes() ) ); ?> und <?php echo esc_html( number_format_i18n( Eventcrawler_Importer::MAX_ROWS ) ); ?> Zeilen. Zeichensatz UTF-8 (in Excel: „Speichern unter → CSV UTF-8“). Trennzeichen Semikolon, Komma oder Tabulator; die erste Zeile enthält die Spaltennamen.
				</p>
				<?php submit_button( 'Datei prüfen', 'primary', 'submit', false ); ?>
			</form>
		</div>

		<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
			<div class="eventcrawler-card">
				<h2>Letzter Import</h2>
				<p>
					<?php echo esc_html( mysql2date( 'd.m.Y H:i', $last['time'] ) ); ?> –
					<?php echo esc_html( Eventcrawler_Importer::source_name( $last['source'] ) ); ?>
					<?php if ( ! empty( $last['file'] ) ) : ?>
						(<?php echo esc_html( $last['file'] ); ?>)
					<?php endif; ?>
				</p>
				<?php self::render_counts( $last['counts'] ); ?>
			</div>
		<?php endif; ?>

		<div class="eventcrawler-card">
			<h2>Welche Dateien gehen?</h2>
			<p><strong>CSV:</strong> beliebige Spaltennamen – Sie ordnen im nächsten Schritt jede Spalte einem Feld zu. Pflicht sind Titel, Datum und Stadt. Datum als <code>15.11.2026</code> oder <code>2026-11-15</code>, Uhrzeit als <code>20:00</code>.</p>
			<p><strong>XML:</strong> das Eventcrawler-Importformat:</p>
			<details class="eventcrawler-tech">
				<summary>Beispiel anzeigen</summary>
				<pre><code><?php echo esc_html( self::xml_example() ); ?></code></pre>
			</details>
		</div>
		<?php
	}

	/**
	 * @return string Beispiel des Importformats (docs/11-import.md Abschnitt 3).
	 */
	public static function xml_example() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<events source="kulturamt-import" generated_at="2026-11-03T10:00:00Z">' . "\n"
			. "  <event>\n"
			. "    <external_id>12345</external_id>\n"
			. "    <title>Beispielveranstaltung</title>\n"
			. "    <description></description>\n"
			. "    <start_date>2026-11-15</start_date>\n"
			. "    <start_time>20:00</start_time>\n"
			. "    <end_date>2026-11-15</end_date>\n"
			. "    <end_time>22:00</end_time>\n"
			. "    <all_day>0</all_day>\n"
			. "    <venue_name>DAS ZENTRUM</venue_name>\n"
			. "    <venue_address>Äußere Badstraße 7a</venue_address>\n"
			. "    <city>Bayreuth</city>\n"
			. "    <event_url>https://example.org/event</event_url>\n"
			. "    <source_url>https://example.org/</source_url>\n"
			. "  </event>\n"
			. '</events>';
	}

	/**
	 * @param array  $state Transient.
	 * @param string $token Token.
	 */
	private static function render_map( array $state, $token ) {
		$config   = isset( $state['config'] ) ? $state['config'] : array();
		$existing = Eventcrawler_Importer::import_sources();
		$xml_source = '';
		?>
		<div class="eventcrawler-card">
			<h2>Datei: <?php echo esc_html( $state['name'] ); ?></h2>
			<p>Erkanntes Format: <strong><?php echo esc_html( 'xml' === $state['format'] ? 'XML (Eventcrawler-Importformat)' : 'CSV' ); ?></strong></p>
		<?php
		if ( 'xml' === $state['format'] ) {
			$parsed = Eventcrawler_Importer::parse_xml( $state['content'] );
			if ( is_wp_error( $parsed ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $parsed->get_error_message() ) . '</p></div>';
				echo '<p><a class="button" href="' . esc_url( self::url() ) . '">Andere Datei wählen</a></p></div>';
				return;
			}
			$xml_source = '' === Eventcrawler_Importer::validate_source( $parsed['source'] ) ? $parsed['source'] : '';
			echo '<p>' . esc_html( sprintf( '%d Veranstaltungen gefunden.', count( $parsed['rows'] ) + count( $parsed['errors'] ) ) ) . ' Die Felder sind durch die Elementnamen festgelegt.</p>';
			if ( '' !== $parsed['source'] ) {
				echo '<p>Quelle laut Datei: <code>' . esc_html( $parsed['source'] ) . '</code>';
				if ( '' === $xml_source ) {
					echo ' – ' . esc_html( Eventcrawler_Importer::validate_source( $parsed['source'] ) . ' Bitte unten eine Importquelle festlegen.' );
				}
				echo '</p>';
			}
			if ( $parsed['unknown'] ) {
				echo '<p class="eventcrawler-hint">Nicht übernommen (unbekannte Elemente): ' . esc_html( implode( ', ', $parsed['unknown'] ) ) . '</p>';
			}
			echo '</div>';
			self::open_map_form( $token );
		} else {
			$requested = self::get_text( 'delimiter', 20 );
			$detected  = Eventcrawler_Importer::parse_csv( $state['content'] );
			$delimiter = isset( Eventcrawler_Importer::DELIMITERS[ $requested ] ) ? $requested : ( ! empty( $config['delimiter'] ) ? $config['delimiter'] : $detected['delimiter'] );
			?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="step" value="map">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<p>
					<label for="eventcrawler-import-delimiter">Trennzeichen</label>
					<select id="eventcrawler-import-delimiter" name="delimiter">
						<?php if ( null === $delimiter ) : ?>
							<option value="">– bitte wählen –</option>
						<?php endif; ?>
						<?php foreach ( Eventcrawler_Importer::DELIMITERS as $key => $def ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $delimiter, $key ); ?>><?php echo esc_html( $def[1] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button">Anwenden</button>
					<?php if ( null !== $detected['delimiter'] ) : ?>
						<span class="description">Automatisch erkannt: <?php echo esc_html( Eventcrawler_Importer::DELIMITERS[ $detected['delimiter'] ][1] ); ?></span>
					<?php elseif ( count( $detected['candidates'] ) > 1 ) : ?>
						<span class="eventcrawler-hint">Das Trennzeichen ist nicht eindeutig. Bitte wählen.</span>
					<?php else : ?>
						<span class="eventcrawler-hint">Das Trennzeichen konnte nicht erkannt werden (unterschiedlich viele Spalten je Zeile). Bitte wählen.</span>
					<?php endif; ?>
				</p>
			</form>
			</div>
			<?php
			if ( null === $delimiter ) {
				return;
			}
			$parsed = Eventcrawler_Importer::parse_csv( $state['content'], $delimiter );
			if ( is_wp_error( $parsed ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $parsed->get_error_message() ) . '</p></div>';
				return;
			}
			$mapping = ! empty( $config['mapping'] ) && $config['delimiter'] === $delimiter && count( $config['mapping'] ) === count( $parsed['header'] ) ? $config['mapping'] : Eventcrawler_Importer::suggest_mapping( $parsed['header'] );
			self::open_map_form( $token );
			echo '<input type="hidden" name="delimiter" value="' . esc_attr( $delimiter ) . '">';
			?>
			<div class="eventcrawler-card">
				<h2>Felder zuordnen</h2>
				<p>Ordnen Sie jeder Spalte das passende Feld zu. Vorschläge gibt es nur für bekannte Spaltennamen – bitte prüfen. Pflicht: Titel, Beginn – Datum, Stadt.</p>
				<table class="widefat striped eventcrawler-table-compact">
					<thead><tr><th>CSV-Spalte</th><th>Beispielwerte</th><th>Eventcrawler-Feld</th></tr></thead>
					<tbody>
					<?php foreach ( $parsed['header'] as $index => $name ) : ?>
						<?php
						$samples = array();
						foreach ( array_slice( $parsed['rows'], 0, 3 ) as $row ) {
							$samples[] = mb_strimwidth( (string) $row['cells'][ $index ], 0, 40, '…' );
						}
						?>
						<tr>
							<td><label for="eventcrawler-map-<?php echo (int) $index; ?>"><strong><?php echo esc_html( '' === $name ? '(ohne Namen)' : $name ); ?></strong></label></td>
							<td class="eventcrawler-label"><?php echo esc_html( implode( ' · ', array_filter( $samples, 'strlen' ) ) ); ?></td>
							<td>
								<select id="eventcrawler-map-<?php echo (int) $index; ?>" name="mapping[<?php echo (int) $index; ?>]">
									<option value="">– nicht übernehmen –</option>
									<?php foreach ( Eventcrawler_Importer::FIELDS as $field => $label ) : ?>
										<option value="<?php echo esc_attr( $field ); ?>" <?php selected( isset( $mapping[ $index ] ) ? $mapping[ $index ] : '', $field ); ?>><?php echo esc_html( $label . ( in_array( $field, Eventcrawler_Importer::REQUIRED, true ) ? ' *' : '' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $parsed['errors'] ) : ?>
					<p class="eventcrawler-hint"><?php echo esc_html( sprintf( '%d Zeile(n) haben eine abweichende Spaltenzahl und werden als fehlerhaft gemeldet.', count( $parsed['errors'] ) ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}
		self::render_source_fields( $existing, $config, $xml_source );
		?>
			<p>
				<?php submit_button( 'Weiter zum Probelauf', 'primary', 'submit', false ); ?>
				<a class="button-link" href="<?php echo esc_url( self::url() ); ?>">Abbrechen</a>
			</p>
		</form>
		<?php
	}

	/**
	 * @param string $token Token.
	 */
	private static function open_map_form( $token ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="eventcrawler_import_map">';
		echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'eventcrawler_import_map', self::NONCE );
	}

	/**
	 * Auswahl der Importquelle: vorhandene oder neue.
	 *
	 * @param array  $existing   Vorhandene Importquellen.
	 * @param array  $config     Gespeicherte Auswahl.
	 * @param string $xml_source Gültige Quelle aus der XML-Datei.
	 */
	private static function render_source_fields( array $existing, array $config, $xml_source ) {
		$selected = ! empty( $config['source'] ) ? $config['source'] : $xml_source;
		$is_new   = '' === $selected || ! isset( $existing[ $selected ] );
		?>
		<div class="eventcrawler-card">
			<h2>Importquelle</h2>
			<p>Unter dieser Quelle werden die Termine gespeichert. Importieren Sie dieselbe Datei später erneut, wählen Sie wieder dieselbe Quelle – dann werden Termine aktualisiert statt doppelt angelegt.</p>
			<?php if ( $existing ) : ?>
				<p>
					<label for="eventcrawler-import-source">Quelle</label>
					<select id="eventcrawler-import-source" name="source_choice">
						<?php foreach ( $existing as $slug => $data ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>><?php echo esc_html( $data['label'] . ' (' . $slug . ')' ); ?></option>
						<?php endforeach; ?>
						<option value="__new" <?php selected( $is_new ); ?>>Neue Importquelle …</option>
					</select>
				</p>
			<?php else : ?>
				<input type="hidden" name="source_choice" value="__new">
			<?php endif; ?>
			<p>
				<label for="eventcrawler-import-label">Bezeichnung der neuen Quelle</label><br>
				<input type="text" class="regular-text" id="eventcrawler-import-label" name="source_label" maxlength="100" value="<?php echo esc_attr( $is_new && ! empty( $config['label'] ) ? $config['label'] : '' ); ?>" placeholder="z. B. Kulturamt Kulmbach">
			</p>
			<details class="eventcrawler-tech" <?php echo ( $is_new && '' !== $selected ) ? 'open' : ''; ?>>
				<summary>Erweitert: Kennung festlegen</summary>
				<p>
					<label for="eventcrawler-import-slug">Kennung (source)</label><br>
					<input type="text" class="regular-text" id="eventcrawler-import-slug" name="source_slug" maxlength="50" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?php echo esc_attr( $is_new ? $selected : '' ); ?>">
				</p>
				<p class="description">Leer lassen: wird aus der Bezeichnung gebildet. Nur Kleinbuchstaben, Ziffern, Bindestriche; nicht „manual“ und keine Crawler-Quelle.</p>
			</details>
		</div>
		<?php
	}

	/**
	 * @param array  $state Transient.
	 * @param string $token Token.
	 */
	private static function render_preview( array $state, $token ) {
		if ( empty( $state['config']['source'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( 'Bitte zuerst Felder und Importquelle festlegen.' ) . '</p></div>';
			self::render_map( $state, $token );
			return;
		}
		$prepared = self::prepare_rows( $state );
		if ( is_wp_error( $prepared ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $prepared->get_error_message() ) . '</p></div>';
			return;
		}
		$importer = new Eventcrawler_Importer( new Eventcrawler_Repository() );
		$analysis = $importer->analyze( $prepared['rows'], $state['config']['source'], $prepared['errors'] );
		$counts   = $analysis['counts'];
		$preview  = array_slice(
			array_values(
				array_filter(
					$analysis['items'],
					function ( $item ) {
						return 'invalid' !== $item['action'];
					}
				)
			),
			0,
			self::PREVIEW_ROWS
		);
		?>
		<div class="eventcrawler-card">
			<h2>Probelauf: <?php echo esc_html( $state['name'] ); ?></h2>
			<p>Importquelle: <strong><?php echo esc_html( $state['config']['label'] ); ?></strong> <span class="eventcrawler-label">(<?php echo esc_html( $state['config']['source'] ); ?>)</span>. Es wurde noch nichts gespeichert.</p>
			<?php self::render_counts( $counts ); ?>
		</div>

		<?php if ( $preview ) : ?>
			<div class="eventcrawler-card">
				<h2>Import-Vorschau</h2>
				<p class="description"><?php echo esc_html( sprintf( 'Die ersten %d gültigen Zeilen, so wie sie gespeichert würden.', count( $preview ) ) ); ?></p>
				<table class="widefat striped eventcrawler-table-compact">
					<thead><tr><th>Zeile</th><th>Aktion</th><th>Datum</th><th>Uhrzeit</th><th>Titel</th><th>Ort</th><th>Stadt</th></tr></thead>
					<tbody>
					<?php foreach ( $preview as $item ) : ?>
						<?php $event = $item['event']; ?>
						<tr>
							<td><?php echo (int) $item['line']; ?></td>
							<td><?php echo esc_html( self::ACTION_LABELS[ $item['action'] ] ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd.m.Y', $event['start_date'] ) ); ?></td>
							<td><?php echo esc_html( Eventcrawler_Frontend::format_time( $event ) ); ?></td>
							<td><?php echo esc_html( $event['title'] ); ?>
								<?php if ( $item['warnings'] ) : ?>
									<br><span class="eventcrawler-label"><?php echo esc_html( implode( ' ', $item['warnings'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $event['venue_name'] ); ?></td>
							<td><?php echo esc_html( $event['city'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php self::render_errors( $analysis['errors'] ); ?>

		<div class="eventcrawler-actions">
			<?php if ( $counts['new'] + $counts['update'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="eventcrawler_import_run">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( 'eventcrawler_import_run', self::NONCE ); ?>
					<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( sprintf( 'Jetzt %d neue Veranstaltungen anlegen und %d aktualisieren?', $counts['new'], $counts['update'] ) ); ?>');">Import durchführen</button>
				</form>
			<?php else : ?>
				<p><strong>Nichts zu importieren:</strong> keine neuen oder geänderten Veranstaltungen.</p>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( self::url( array( 'step' => 'map', 'token' => $token ) ) ); ?>">Zuordnung ändern</a>
			<a class="button-link" href="<?php echo esc_url( self::url() ); ?>">Abbrechen</a>
		</div>
		<?php
	}

	private static function render_result() {
		$result = get_transient( self::result_key() );
		if ( ! is_array( $result ) ) {
			echo '<p>' . esc_html( 'Kein Importergebnis vorhanden.' ) . '</p>';
			self::render_upload();
			return;
		}
		$counts = $result['counts'];
		?>
		<div class="notice notice-<?php echo $counts['failed'] ? 'warning' : 'success'; ?>">
			<p><?php echo esc_html( sprintf( 'Import abgeschlossen: %d neu angelegt, %d aktualisiert, %d unverändert, %d fehlerhaft übersprungen.', $counts['inserted'], $counts['updated'], $counts['unchanged'], $counts['invalid'] ) ); ?>
			<?php if ( $counts['failed'] ) : ?>
				<?php echo esc_html( sprintf( ' %d konnten nicht gespeichert werden.', $counts['failed'] ) ); ?>
			<?php endif; ?></p>
		</div>
		<div class="eventcrawler-card">
			<h2>Ergebnis: <?php echo esc_html( $result['name'] ); ?></h2>
			<p>Importquelle: <strong><?php echo esc_html( $result['label'] ); ?></strong></p>
			<?php self::render_counts( $counts ); ?>
			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Eventcrawler_Admin::PAGE_SLUG, 'source' => $result['source'] ), admin_url( 'admin.php' ) ) ); ?>">Importierte Veranstaltungen ansehen</a>
				<a class="button-link" href="<?php echo esc_url( self::url() ); ?>">Weitere Datei importieren</a>
			</p>
		</div>
		<?php
		self::render_errors( $result['errors'], $result['total_errors'] );
	}

	/**
	 * @param array $counts read, new, update, unchanged, invalid.
	 */
	private static function render_counts( array $counts ) {
		$rows = array(
			'read'      => 'Zeilen gelesen',
			'new'       => 'Neu',
			'update'    => 'Aktualisierung',
			'unchanged' => 'Unverändert',
			'invalid'   => 'Fehlerhaft',
		);
		echo '<table class="widefat striped eventcrawler-table-compact" style="max-width:24rem"><tbody>';
		foreach ( $rows as $key => $label ) {
			printf( '<tr><td>%s</td><td><strong>%d</strong></td></tr>', esc_html( $label ), isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0 );
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array    $errors Zeile => Meldungen.
	 * @param int|null $total  Gesamtzahl (falls gekürzt).
	 */
	private static function render_errors( array $errors, $total = null ) {
		if ( ! $errors ) {
			return;
		}
		$total = null === $total ? count( $errors ) : (int) $total;
		echo '<div class="eventcrawler-card"><h2>' . esc_html( sprintf( 'Fehlerhafte Zeilen (%d)', $total ) ) . '</h2>';
		echo '<p class="description">Diese Zeilen werden nicht übernommen. Bitte in der Datei korrigieren und erneut importieren.</p>';
		echo '<ul class="eventcrawler-errors">';
		foreach ( array_slice( $errors, 0, self::ERROR_ROWS, true ) as $line => $messages ) {
			echo '<li><strong>' . esc_html( sprintf( 'Zeile %d:', $line ) ) . '</strong> ' . esc_html( implode( '; ', $messages ) ) . '</li>';
		}
		echo '</ul>';
		if ( $total > self::ERROR_ROWS ) {
			echo '<p class="description">' . esc_html( sprintf( '… und %d weitere.', $total - self::ERROR_ROWS ) ) . '</p>';
		}
		echo '</div>';
	}
}
