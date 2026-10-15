<?php
/**
 * Admin-Seite "Filterregeln": Verwaltung der textbasierten Ausschlussbegriffe.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü Eventcrawler > Filterregeln (docs/05-filter.md, docs/06-admin.md).
 * Schreibende Aktionen nur per POST an admin-post.php mit manage_options und eigenem Nonce je Aktion.
 */
class Eventcrawler_Filter_Admin {

	const PAGE_SLUG  = 'eventcrawler-filter';
	const NONCE_NAME = 'eventcrawler_filter_nonce';
	const ACTIONS    = array( 'add', 'update', 'toggle', 'delete' );

	public static function init() {
		foreach ( self::ACTIONS as $op ) {
			add_action( 'admin_post_eventcrawler_filter_' . $op, array( __CLASS__, 'handle_' . $op ) );
		}
	}

	public static function register_menu() {
		add_submenu_page(
			Eventcrawler_Admin::PAGE_SLUG,
			'Filterregeln',
			'Filterregeln',
			Eventcrawler_Admin::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_add() {
		self::check( 'add' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- in check() geprüft.
		$result = Eventcrawler_Filter_Rules::add( self::post( 'term' ), self::post( 'category' ), self::post( 'match' ) );
		// phpcs:enable
		self::redirect( is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', 'Filterregel hinzugefügt.' ) );
	}

	public static function handle_update() {
		self::check( 'update' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- in check() geprüft.
		$id     = self::post( 'rule_id' );
		$result = Eventcrawler_Filter_Rules::update( $id, self::post( 'term' ), self::post( 'category' ), self::post( 'match' ), '1' === self::post( 'enabled' ) );
		// phpcs:enable
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'error', $result->get_error_message() ), array( 'edit' => $id ) );
		}
		self::redirect( array( 'success', 'Filterregel aktualisiert.' ) );
	}

	public static function handle_toggle() {
		self::check( 'toggle' );
		$enabled = Eventcrawler_Filter_Rules::toggle( self::post( 'rule_id' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( null === $enabled ) {
			self::redirect( array( 'error', 'Filterregel nicht gefunden.' ) );
		}
		self::redirect( array( 'success', $enabled ? 'Filterregel aktiviert.' : 'Filterregel deaktiviert.' ) );
	}

	public static function handle_delete() {
		self::check( 'delete' );
		$deleted = Eventcrawler_Filter_Rules::delete( self::post( 'rule_id' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::redirect( $deleted ? array( 'success', 'Filterregel gelöscht.' ) : array( 'error', 'Filterregel nicht gefunden.' ) );
	}

	/**
	 * POST, manage_options und Nonce "eventcrawler_filter_{op}" prüfen.
	 *
	 * @param string $op Aktion.
	 */
	private static function check( $op ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'eventcrawler' ), 405 );
		}
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		check_admin_referer( 'eventcrawler_filter_' . $op, self::NONCE_NAME );
	}

	/**
	 * @param string $key POST-Feld.
	 * @return string Unbereinigter Text (Bereinigung in Eventcrawler_Filter_Rules), ohne Slashes.
	 */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Meldung merken und zur Filterseite zurückleiten.
	 *
	 * @param array $notice array( type, message ).
	 * @param array $args   Zusätzliche URL-Parameter.
	 */
	private static function redirect( array $notice, array $args = array() ) {
		set_transient( 'eventcrawler_filter_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$rules = Eventcrawler_Filter_Rules::get_all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Ansicht.
		$edit = isset( $_GET['edit'] ) ? Eventcrawler_Filter_Rules::get( sanitize_key( wp_unslash( $_GET['edit'] ) ) ) : null;
		$test = isset( $_GET['test_text'] ) ? sanitize_text_field( wp_unslash( $_GET['test_text'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap">
			<h1>Filterregeln</h1>
			<?php self::render_notice(); ?>
			<?php if ( Eventcrawler_Filter_Rules::is_corrupt() ) : ?>
				<div class="notice notice-error"><p>Die gespeicherten Filterregeln sind beschädigt (kein gültiges Format). Derzeit ist <strong>keine</strong> Regel aktiv. Neu angelegte Regeln ersetzen den beschädigten Wert.</p></div>
			<?php endif; ?>
			<p class="description">
				Ausschlussbegriffe gelten für <strong>alle Quellen</strong>. Geprüft werden Titel und Beschreibung, ohne
				Unterscheidung von Groß- und Kleinschreibung. Änderungen wirken beim <strong>nächsten Crawl</strong>;
				bereits gespeicherte Veranstaltungen werden nicht gelöscht oder verändert. Ortsregeln (z. B. nur
				Nürnberg/Fürth) werden hier nicht verwaltet.
			</p>

			<h2><?php echo $edit ? 'Filterregel bearbeiten' : 'Begriff hinzufügen'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo $edit ? 'eventcrawler_filter_update' : 'eventcrawler_filter_add'; ?>">
				<?php self::nonce_input( $edit ? 'update' : 'add' ); ?>
				<?php if ( $edit ) : ?>
					<input type="hidden" name="rule_id" value="<?php echo esc_attr( $edit['id'] ); ?>">
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eventcrawler-filter-term">Begriff</label></th>
						<td><input type="text" name="term" id="eventcrawler-filter-term" class="regular-text" maxlength="<?php echo (int) Eventcrawler_Filter_Rules::MAX_LENGTH; ?>" value="<?php echo esc_attr( $edit ? $edit['term'] : '' ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="eventcrawler-filter-category">Kategorie</label></th>
						<td><select name="category" id="eventcrawler-filter-category"><?php self::options( Eventcrawler_Filter_Rules::CATEGORIES, $edit ? $edit['category'] : 'stammtisch' ); ?></select></td>
					</tr>
					<tr>
						<th scope="row"><label for="eventcrawler-filter-match">Vergleich</label></th>
						<td>
							<select name="match" id="eventcrawler-filter-match"><?php self::options( Eventcrawler_Filter_Rules::MATCH_MODES, $edit ? $edit['match'] : 'contains' ); ?></select>
							<p class="description">„ganzes Wort“ für kurze Begriffe und Abkürzungen (z. B. SPD: trifft „SPD-Ortsverein“, nicht „SPDX“).</p>
						</td>
					</tr>
					<?php if ( $edit ) : ?>
						<tr>
							<th scope="row">Status</th>
							<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $edit['enabled'] ); ?>> aktiv</label></td>
						</tr>
					<?php endif; ?>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php echo $edit ? 'Änderungen speichern' : 'Begriff hinzufügen'; ?></button>
					<?php if ( $edit ) : ?>
						<a class="button-link" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ); ?>">Abbrechen</a>
					<?php endif; ?>
				</p>
			</form>

			<h2>Text prüfen</h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label for="eventcrawler-filter-test" class="screen-reader-text">Text prüfen</label>
				<input type="text" name="test_text" id="eventcrawler-filter-test" class="regular-text" value="<?php echo esc_attr( $test ); ?>" placeholder="z. B. Titel einer Veranstaltung">
				<button type="submit" class="button">Prüfen</button>
				<?php if ( '' !== $test ) : ?>
					<?php $hit = Eventcrawler_Filter::find_matching_rule( $test, Eventcrawler_Filter_Rules::get_active() ); ?>
					<p id="eventcrawler-filter-test-result"><strong>
						<?php
						echo esc_html(
							$hit ? sprintf( 'Würde gefiltert: %1$s (Regel „%2$s“)', Eventcrawler_Filter_Rules::CATEGORIES[ $hit['category'] ], $hit['term'] ) : 'Würde importiert (keine aktive Regel trifft zu).'
						);
						?>
					</strong></p>
				<?php endif; ?>
			</form>

			<h2>Regeln (<?php echo (int) count( $rules ); ?>)</h2>
			<table class="widefat striped">
				<thead><tr><th>Begriff</th><th>Kategorie</th><th>Vergleich</th><th>Status</th><th>Aktionen</th></tr></thead>
				<tbody>
				<?php if ( empty( $rules ) ) : ?>
					<tr><td colspan="5">Keine Filterregeln vorhanden.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $rule ) : ?>
					<tr>
						<td><?php echo esc_html( $rule['term'] ); ?></td>
						<td><?php echo esc_html( Eventcrawler_Filter_Rules::CATEGORIES[ $rule['category'] ] ); ?></td>
						<td><?php echo esc_html( Eventcrawler_Filter_Rules::MATCH_MODES[ $rule['match'] ] ); ?></td>
						<td><?php echo $rule['enabled'] ? 'Aktiv' : '<em>Inaktiv</em>'; ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'edit' => $rule['id'] ), admin_url( 'admin.php' ) ) ); ?>">Bearbeiten</a> |
							<?php self::row_button( 'toggle', $rule['id'], $rule['enabled'] ? 'Deaktivieren' : 'Aktivieren' ); ?> |
							<?php self::row_button( 'delete', $rule['id'], 'Löschen', 'Diese Filterregel wirklich löschen?' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Nonce als verstecktes Feld OHNE id-Attribut (mehrere Formulare pro Seite, keine doppelten IDs).
	 *
	 * @param string $op Aktion.
	 */
	private static function nonce_input( $op ) {
		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::NONCE_NAME ), esc_attr( wp_create_nonce( 'eventcrawler_filter_' . $op ) ) );
	}

	/**
	 * Kleines POST-Formular für eine Zeilenaktion.
	 *
	 * @param string      $op      toggle|delete.
	 * @param string      $id      Regel-ID.
	 * @param string      $label   Beschriftung.
	 * @param string|null $confirm Sicherheitsabfrage.
	 */
	private static function row_button( $op, $id, $label, $confirm = null ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"<?php echo $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( 'eventcrawler_filter_' . $op ); ?>">
			<input type="hidden" name="rule_id" value="<?php echo esc_attr( $id ); ?>">
			<?php self::nonce_input( $op ); ?>
			<button type="submit" class="button-link<?php echo 'delete' === $op ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * @param array  $options  Wert => Beschriftung.
	 * @param string $selected Gewählter Wert.
	 */
	private static function options( array $options, $selected ) {
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
		}
	}

	private static function render_notice() {
		$key    = 'eventcrawler_filter_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && 2 === count( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}
}
