<?php
/**
 * Admin: manuelle Veranstaltung anlegen, bearbeiten, duplizieren.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü "Eventcrawler → Veranstaltung hinzufügen" (docs/06-admin.md Abschnitt 12).
 * Bearbeitet werden nur Termine mit source = manual; gecrawlte Termine bleiben unverändert (docs/10-frontend.md).
 */
class Eventcrawler_Event_Admin {

	const ACTION = 'eventcrawler_save_event';
	const NONCE  = 'eventcrawler_save_event';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	public static function register_menu() {
		$hook = add_submenu_page(
			Eventcrawler_Admin::PAGE_SLUG,
			'Veranstaltung hinzufügen',
			'Veranstaltung hinzufügen',
			Eventcrawler_Manual_Events::CAPABILITY,
			Eventcrawler_Manual_Events::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load_page' ) );
		}
	}

	/**
	 * Vor jeder Ausgabe: Bearbeiten/Duplizieren nur für manuelle Termine (sonst HTTP 403 statt einer Seite mit Meldung).
	 */
	public static function load_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		$source_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['copy'] ) ? absint( $_GET['copy'] ) : 0 );
		// phpcs:enable
		if ( ! $source_id ) {
			return;
		}
		$row = ( new Eventcrawler_Repository() )->get_by_id( $source_id );
		if ( ! $row || Eventcrawler_Manual_Events::SOURCE !== $row['source'] ) {
			wp_die( esc_html( 'Nur manuelle Veranstaltungen können bearbeitet oder dupliziert werden. Gecrawlte Termine ändert nur der Crawler.' ), 403 );
		}
	}

	/**
	 * Speichern: nur POST, manage_options, Nonce. Bei Fehlern zurück zum Formular mit den eingegebenen Werten.
	 */
	public static function handle_save() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'eventcrawler' ), 405 );
		}
		if ( ! current_user_can( Eventcrawler_Manual_Events::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		check_admin_referer( self::NONCE, 'eventcrawler_event_nonce' );

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$input = array();
		foreach ( array( 'title', 'description', 'start_date', 'start_time', 'end_date', 'end_time', 'venue_name', 'venue_address', 'city', 'event_url' ) as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ? wp_unslash( (string) $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- prepare() bereinigt jedes Feld.
		}
		$input['all_day'] = empty( $_POST['all_day'] ) ? 0 : 1;

		$result = Eventcrawler_Manual_Events::prepare( $input, true );
		if ( ! $result['event'] ) {
			self::back_to_form( $id, $input, $result['errors'] );
		}

		$saved = Eventcrawler_Manual_Events::save( new Eventcrawler_Repository(), $result['event'], $id );
		if ( is_wp_error( $saved ) ) {
			self::back_to_form( $id, $input, array( $saved->get_error_message() ) );
		}

		Eventcrawler_Admin::redirect_with_notices(
			array(
				array(
					'type'    => 'success',
					'message' => sprintf( 'Veranstaltung „%s“ am %s gespeichert.', $result['event']['title'], mysql2date( 'd.m.Y', $result['event']['start_date'] ) ),
				),
			),
			array( 'source' => Eventcrawler_Manual_Events::SOURCE )
		);
	}

	/**
	 * @param int      $id     Event-ID (0 = neu).
	 * @param array    $input  Eingaben.
	 * @param string[] $errors Fehlermeldungen.
	 */
	private static function back_to_form( $id, array $input, array $errors ) {
		set_transient( self::form_key(), array( 'values' => $input, 'errors' => $errors ), 5 * MINUTE_IN_SECONDS );
		$args = array( 'page' => Eventcrawler_Manual_Events::PAGE_SLUG );
		if ( $id ) {
			$args['id'] = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return string Transient für Formularwerte nach einem Fehler (je Benutzer).
	 */
	private static function form_key() {
		return 'eventcrawler_event_form_' . get_current_user_id();
	}

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Manual_Events::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}

		$repository = new Eventcrawler_Repository();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$copy_id = isset( $_GET['copy'] ) ? absint( $_GET['copy'] ) : 0;
		// phpcs:enable

		$values = array_fill_keys( array( 'title', 'description', 'start_date', 'start_time', 'end_date', 'end_time', 'venue_name', 'venue_address', 'city', 'event_url' ), '' );
		$values['all_day'] = 0;
		$origin            = '';

		$source_id = $id ? $id : $copy_id;
		if ( $source_id ) {
			$row = $repository->get_by_id( $source_id );
			if ( ! $row || Eventcrawler_Manual_Events::SOURCE !== $row['source'] ) {
				wp_die( esc_html( 'Nur manuelle Veranstaltungen können bearbeitet oder dupliziert werden. Gecrawlte Termine ändert nur der Crawler.' ), 403 );
			}
			$values = Eventcrawler_Manual_Events::to_form_values( $row );
			if ( $id && $row['external_id'] && 0 === strpos( $row['external_id'], Eventcrawler_Wpem_Migration::ID_PREFIX ) ) {
				$origin = 'Übernommen aus WP Kulmbacher Land (Beitrag ' . substr( $row['external_id'], strlen( Eventcrawler_Wpem_Migration::ID_PREFIX ) ) . ').';
			}
		}

		$errors = array();
		$stored = get_transient( self::form_key() );
		if ( is_array( $stored ) ) {
			delete_transient( self::form_key() );
			$values = wp_parse_args( $stored['values'], $values );
			$errors = $stored['errors'];
		}

		$title = $id ? 'Veranstaltung bearbeiten' : ( $copy_id ? 'Veranstaltung duplizieren' : 'Veranstaltung hinzufügen' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p class="description">Manuelle Veranstaltung (Quelle „Manuell“). Sie erscheint wie gecrawlte Termine in der Liste, im XML-Export und im Frontend. Felder mit * sind Pflicht.</p>
			<?php if ( $origin ) : ?>
				<p class="description"><?php echo esc_html( $origin ); ?></p>
			<?php endif; ?>
			<?php foreach ( $errors as $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( self::NONCE, 'eventcrawler_event_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ec-title">Titel *</label></th>
						<td><input type="text" class="large-text" id="ec-title" name="title" maxlength="255" required value="<?php echo esc_attr( $values['title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-description">Beschreibung</label></th>
						<td><textarea class="large-text" rows="4" id="ec-description" name="description"><?php echo esc_textarea( $values['description'] ); ?></textarea>
						<p class="description">Klartext, ohne HTML. Erscheint im XML nach dem Titel.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-start-date">Beginn *</label></th>
						<td>
							<input type="date" id="ec-start-date" name="start_date" required value="<?php echo esc_attr( $values['start_date'] ); ?>">
							<label for="ec-start-time" class="screen-reader-text">Beginn – Uhrzeit</label>
							<input type="time" id="ec-start-time" name="start_time" value="<?php echo esc_attr( $values['start_time'] ); ?>">
							<p class="description">Uhrzeit leer lassen, wenn sie nicht bekannt ist (es wird keine 00:00 gespeichert).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-end-date">Ende</label></th>
						<td>
							<input type="date" id="ec-end-date" name="end_date" value="<?php echo esc_attr( $values['end_date'] ); ?>">
							<label for="ec-end-time" class="screen-reader-text">Ende – Uhrzeit</label>
							<input type="time" id="ec-end-time" name="end_time" value="<?php echo esc_attr( $values['end_time'] ); ?>">
							<p class="description">Optional. Nur Uhrzeit = Ende am selben Tag. Das Ende muss nach dem Beginn liegen.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Ganztägig</th>
						<td><label><input type="checkbox" name="all_day" value="1" <?php checked( ! empty( $values['all_day'] ) ); ?>> Ganztägige Veranstaltung (Uhrzeiten werden nicht gespeichert)</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-venue">Veranstaltungsort</label></th>
						<td><input type="text" class="regular-text" id="ec-venue" name="venue_name" maxlength="255" list="ec-venues" value="<?php echo esc_attr( $values['venue_name'] ); ?>">
						<?php self::render_datalist( 'ec-venues', $repository->get_distinct_values( 'venue_name' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-address">Adresse</label></th>
						<td><input type="text" class="regular-text" id="ec-address" name="venue_address" maxlength="255" value="<?php echo esc_attr( $values['venue_address'] ); ?>">
						<p class="description">Straße und Hausnummer, ohne PLZ und Stadt.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-city">Stadt *</label></th>
						<td><input type="text" class="regular-text" id="ec-city" name="city" maxlength="100" required list="ec-cities" value="<?php echo esc_attr( $values['city'] ); ?>">
						<?php self::render_datalist( 'ec-cities', $repository->get_distinct_values( 'city' ) ); ?>
						<p class="description">Überschrift (&lt;h2&gt;) im XML-Export.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ec-url">Webseiten-Link</label></th>
						<td><input type="url" class="large-text" id="ec-url" name="event_url" maxlength="2048" placeholder="https://" value="<?php echo esc_attr( $values['event_url'] ); ?>"></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary">Speichern</button>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Eventcrawler_Admin::PAGE_SLUG, 'source' => Eventcrawler_Manual_Events::SOURCE ), admin_url( 'admin.php' ) ) ); ?>">Abbrechen</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string   $id     HTML-ID.
	 * @param string[] $values Vorschläge.
	 */
	private static function render_datalist( $id, array $values ) {
		echo '<datalist id="' . esc_attr( $id ) . '">';
		foreach ( $values as $value ) {
			echo '<option value="' . esc_attr( $value ) . '"></option>';
		}
		echo '</datalist>';
	}
}
