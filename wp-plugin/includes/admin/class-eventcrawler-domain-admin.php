<?php
/**
 * Admin-Seite "Link-Bezeichnungen": Domain -> Ausgabetext für "Info:" im XML-Export.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü Eventcrawler > Link-Bezeichnungen (docs/06-admin.md Abschnitt 10, docs/07-export.md Abschnitt 13).
 * Schreibende Aktionen nur per POST an admin-post.php mit manage_options und eigenem Nonce je Aktion.
 */
class Eventcrawler_Domain_Admin {

	const PAGE_SLUG  = 'eventcrawler-domains';
	const NONCE_NAME = 'eventcrawler_domain_nonce';
	const ACTIONS    = array( 'add', 'update', 'toggle', 'delete' );

	public static function init() {
		foreach ( self::ACTIONS as $op ) {
			add_action( 'admin_post_eventcrawler_domain_' . $op, array( __CLASS__, 'handle_' . $op ) );
		}
	}

	public static function register_menu() {
		add_submenu_page(
			Eventcrawler_Admin::PAGE_SLUG,
			'Link-Bezeichnungen',
			'Link-Bezeichnungen',
			Eventcrawler_Admin::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_add() {
		self::check( 'add' );
		$result = Eventcrawler_Domain_Mappings::add( self::post( 'domain' ), self::post( 'label' ), '1' === self::post( 'enabled' ) );
		self::redirect( is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', 'Link-Bezeichnung gespeichert.' ) );
	}

	public static function handle_update() {
		self::check( 'update' );
		$id     = self::post( 'mapping_id' );
		$result = Eventcrawler_Domain_Mappings::update( $id, self::post( 'domain' ), self::post( 'label' ), '1' === self::post( 'enabled' ) );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'error', $result->get_error_message() ), array( 'edit' => $id ) );
		}
		self::redirect( array( 'success', 'Link-Bezeichnung aktualisiert.' ) );
	}

	public static function handle_toggle() {
		self::check( 'toggle' );
		$enabled = Eventcrawler_Domain_Mappings::toggle( self::post( 'mapping_id' ) );
		if ( null === $enabled ) {
			self::redirect( array( 'error', 'Link-Bezeichnung nicht gefunden.' ) );
		}
		self::redirect( array( 'success', $enabled ? 'Link-Bezeichnung aktiviert.' : 'Link-Bezeichnung deaktiviert. Im XML steht wieder die Domain.' ) );
	}

	public static function handle_delete() {
		self::check( 'delete' );
		$deleted = Eventcrawler_Domain_Mappings::delete( self::post( 'mapping_id' ) );
		self::redirect( $deleted ? array( 'success', 'Link-Bezeichnung gelöscht. Im XML steht wieder die Domain.' ) : array( 'error', 'Link-Bezeichnung nicht gefunden.' ) );
	}

	/**
	 * POST, manage_options und Nonce "eventcrawler_domain_{op}" prüfen.
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
		check_admin_referer( 'eventcrawler_domain_' . $op, self::NONCE_NAME );
	}

	/**
	 * @param string $key POST-Feld.
	 * @return string Unbereinigter Text (Bereinigung in Eventcrawler_Domain_Mappings), ohne Slashes.
	 */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce in check(), Bereinigung in validate().
	}

	/**
	 * Meldung merken und zur Seite zurückleiten.
	 *
	 * @param array $notice array( type, message ).
	 * @param array $args   Zusätzliche URL-Parameter.
	 */
	private static function redirect( array $notice, array $args = array() ) {
		set_transient( 'eventcrawler_domain_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), array_map( 'rawurlencode', $args ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$mappings = Eventcrawler_Domain_Mappings::get_all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Ansicht.
		$edit = isset( $_GET['edit'] ) ? Eventcrawler_Domain_Mappings::get( sanitize_key( wp_unslash( $_GET['edit'] ) ) ) : null;
		?>
		<div class="wrap">
			<h1>Link-Bezeichnungen</h1>
			<?php self::render_notice(); ?>
			<?php if ( Eventcrawler_Domain_Mappings::is_corrupt() ) : ?>
				<div class="notice notice-error"><p>Die gespeicherten Link-Bezeichnungen sind beschädigt (kein gültiges Format). Im XML steht derzeit überall die Domain. Neu angelegte Einträge ersetzen den beschädigten Wert.</p></div>
			<?php endif; ?>
			<p class="description">
				Im XML-Export steht nach „Info:“ nur die Domain des Veranstaltungslinks, z. B. „pages.et4.de“. Hier
				lässt sich für eine Domain ein verständlicher Text festlegen, z. B. „Bad Steben“. Das gilt für
				<strong>alle Quellen</strong> mit dieser Domain und <strong>nur</strong> für die Ausgabe im XML; die
				gespeicherten Links bleiben vollständig. Ohne aktiven Eintrag steht im XML die Domain.
			</p>

			<h2><?php echo $edit ? 'Link-Bezeichnung bearbeiten' : 'Link-Bezeichnung hinzufügen'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo $edit ? 'eventcrawler_domain_update' : 'eventcrawler_domain_add'; ?>">
				<?php self::nonce_input( $edit ? 'update' : 'add' ); ?>
				<?php if ( $edit ) : ?>
					<input type="hidden" name="mapping_id" value="<?php echo esc_attr( $edit['id'] ); ?>">
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eventcrawler-domain-domain">Domain</label></th>
						<td>
							<input type="text" name="domain" id="eventcrawler-domain-domain" class="regular-text" maxlength="2048" value="<?php echo esc_attr( $edit ? $edit['domain'] : '' ); ?>" placeholder="z. B. pages.et4.de" required>
							<p class="description">Domain oder ganzer Link; gespeichert wird nur die Domain (ohne https://, Pfad und Parameter).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eventcrawler-domain-label">Ausgabe im XML</label></th>
						<td>
							<input type="text" name="label" id="eventcrawler-domain-label" class="regular-text" maxlength="<?php echo (int) Eventcrawler_Domain_Mappings::MAX_LABEL_LENGTH; ?>" value="<?php echo esc_attr( $edit ? $edit['label'] : '' ); ?>" placeholder="z. B. Bad Steben" required>
							<p class="description">Freier Text, z. B. „Bad Steben“, „Tourismus Bad Steben“ oder „www.bad-steben.de“ (höchstens <?php echo (int) Eventcrawler_Domain_Mappings::MAX_LABEL_LENGTH; ?> Zeichen, ohne HTML).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Status</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $edit ? $edit['enabled'] : true ); ?>> aktiv</label></td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary">Speichern</button>
					<?php if ( $edit ) : ?>
						<a class="button-link" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ); ?>">Abbrechen</a>
					<?php endif; ?>
				</p>
			</form>

			<h2>Link-Bezeichnungen (<?php echo (int) count( $mappings ); ?>)</h2>
			<table class="widefat striped">
				<thead><tr><th>Domain</th><th>Ausgabe im XML</th><th>Status</th><th>Aktionen</th></tr></thead>
				<tbody>
				<?php if ( empty( $mappings ) ) : ?>
					<tr><td colspan="4">Keine Link-Bezeichnungen vorhanden. Im XML steht überall die Domain.</td></tr>
				<?php endif; ?>
				<?php foreach ( $mappings as $mapping ) : ?>
					<tr>
						<td><code><?php echo esc_html( $mapping['domain'] ); ?></code></td>
						<td><?php echo esc_html( $mapping['label'] ); ?></td>
						<td><?php echo $mapping['enabled'] ? 'Aktiv' : '<em>Inaktiv</em> (Domain wird ausgegeben)'; ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'edit' => $mapping['id'] ), admin_url( 'admin.php' ) ) ); ?>">Bearbeiten</a> |
							<?php self::row_button( 'toggle', $mapping['id'], $mapping['enabled'] ? 'Deaktivieren' : 'Aktivieren' ); ?> |
							<?php self::row_button( 'delete', $mapping['id'], 'Löschen', 'Diese Link-Bezeichnung wirklich löschen? Im XML steht dann wieder die Domain.' ); ?>
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
		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::NONCE_NAME ), esc_attr( wp_create_nonce( 'eventcrawler_domain_' . $op ) ) );
	}

	/**
	 * Kleines POST-Formular für eine Zeilenaktion.
	 *
	 * @param string      $op      toggle|delete.
	 * @param string      $id      Zuordnungs-ID.
	 * @param string      $label   Beschriftung.
	 * @param string|null $confirm Sicherheitsabfrage.
	 */
	private static function row_button( $op, $id, $label, $confirm = null ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"<?php echo $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( 'eventcrawler_domain_' . $op ); ?>">
			<input type="hidden" name="mapping_id" value="<?php echo esc_attr( $id ); ?>">
			<?php self::nonce_input( $op ); ?>
			<button type="submit" class="button-link<?php echo 'delete' === $op ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_notice() {
		$key    = 'eventcrawler_domain_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && 2 === count( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}
}
