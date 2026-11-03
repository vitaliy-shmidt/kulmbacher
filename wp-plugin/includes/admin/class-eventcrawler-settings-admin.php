<?php
/**
 * Admin-Seite "Einstellungen": WPEM-Kompatibilität (Parallelbetrieb), Frontend-Testseite.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü "Eventcrawler → Einstellungen" (docs/06-admin.md Abschnitt 14).
 * Speichern und Testseite nur per POST an admin-post.php mit manage_options und eigenem Nonce.
 */
class Eventcrawler_Settings_Admin {

	const PAGE_SLUG        = 'eventcrawler-settings';
	const ACTION_SAVE      = 'eventcrawler_save_settings';
	const ACTION_TEST_PAGE = 'eventcrawler_create_test_page';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_TEST_PAGE, array( __CLASS__, 'handle_test_page' ) );
	}

	public static function register_menu() {
		add_submenu_page( Eventcrawler_Admin::MENU_SLUG, 'Einstellungen', 'Einstellungen', Eventcrawler_Admin::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * @return string URL der Seite.
	 */
	public static function url() {
		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
	}

	public static function handle_save() {
		self::check( self::ACTION_SAVE );
		$input = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- in check() geprüft.
		foreach ( array_keys( Eventcrawler_Settings::DEFAULTS ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			}
		}
		// phpcs:enable
		$before = Eventcrawler_Settings::all();
		$saved  = Eventcrawler_Settings::save( $input );
		if ( is_wp_error( $saved ) ) {
			self::redirect( 'error', $saved->get_error_message() );
		}
		do_action( 'eventcrawler_settings_saved', $saved, $before );
		self::redirect( 'success', 'Einstellungen gespeichert.' );
	}

	public static function handle_test_page() {
		self::check( self::ACTION_TEST_PAGE );
		$result = Eventcrawler_Settings::create_test_page();
		if ( is_wp_error( $result ) ) {
			self::redirect( 'warning', $result->get_error_message() );
		}
		self::redirect( 'success', 'Die Testseite „Eventcrawler Test“ wurde als Entwurf angelegt. Sie ist nicht veröffentlicht.' );
	}

	/**
	 * POST, Capability, Nonce.
	 *
	 * @param string $action Aktion (= Nonce-Aktion).
	 */
	private static function check( $action ) {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'eventcrawler' ), 405 );
		}
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		check_admin_referer( $action, 'eventcrawler_settings_nonce' );
	}

	/**
	 * @param string $type    success|warning|error.
	 * @param string $message Meldung.
	 */
	private static function redirect( $type, $message ) {
		set_transient( 'eventcrawler_settings_notice_' . get_current_user_id(), array( $type, $message ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Abschnitt „SEO“ (docs/12-seo.md Abschnitt 8).
	 *
	 * @param array $settings Aktuelle Werte.
	 */
	private static function render_seo_section( array $settings ) {
		$example = array(
			'title'         => 'Ninotschka',
			'start_date'    => '2026-10-24',
			'start_time'    => '20:00:00',
			'end_date'      => null,
			'end_time'      => null,
			'all_day'       => 0,
			'venue_name'    => 'Kreiskulturraum',
			'venue_address' => 'Siechenanger 1',
			'city'          => 'Kronach',
		);
		?>
		<div class="eventcrawler-card" id="seo">
			<h2>SEO – Detailseiten für Suchmaschinen</h2>
			<p>Jede Veranstaltung kann eine eigene Seite bekommen (Titel, Datum, Uhrzeit, Ort, Adresse, Stadt, Link zur Veranstaltungsseite). Die Seiten von WP Kulmbacher Land bleiben davon unberührt; es gibt keine Weiterleitungen.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Detailseiten</th>
					<td><label><input type="checkbox" name="seo_detail_pages" value="1" <?php checked( 1, (int) $settings['seo_detail_pages'] ); ?>> Event-Detailseiten aktivieren</label>
					<p class="description">Standard: aus. Erst einschalten, wenn die Testseite geprüft ist. Danach verlinken die Titel in der Veranstaltungsliste auf die Detailseite.</p></td>
				</tr>
				<tr>
					<th scope="row">Strukturierte Daten</th>
					<td><label><input type="checkbox" name="seo_structured_data" value="1" <?php checked( 1, (int) $settings['seo_structured_data'] ); ?>> Event Structured Data (JSON-LD) ausgeben</label></td>
				</tr>
				<tr>
					<th scope="row">Sitemap</th>
					<td><label><input type="checkbox" name="seo_sitemap" value="1" <?php checked( 1, (int) $settings['seo_sitemap'] ); ?>> Event-Seiten in die WordPress-Sitemap aufnehmen</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="ec-seo-base">URL-Basis</label></th>
					<td><code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code><input type="text" id="ec-seo-base" name="seo_base" class="regular-text" maxlength="50" value="<?php echo esc_attr( $settings['seo_base'] ); ?>"><code>/titel-datum/</code>
					<p class="description">Im Parallelbetrieb nicht „veranstaltung“ (gehört WP Kulmbacher Land). Eine Änderung ändert alle Detail-URLs.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ec-seo-days">Vergangene Veranstaltungen in der Sitemap</label></th>
					<td><input type="number" id="ec-seo-days" name="seo_sitemap_past_days" min="0" max="3650" step="1" class="small-text" value="<?php echo (int) $settings['seo_sitemap_past_days']; ?>"> Tage
					<p class="description">Vergangene Veranstaltungen bleiben erreichbar; in der Sitemap stehen nur die der letzten Tage.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ec-seo-title">Seitentitel-Muster</label></th>
					<td><input type="text" id="ec-seo-title" name="seo_title_pattern" class="regular-text" maxlength="200" value="<?php echo esc_attr( $settings['seo_title_pattern'] ); ?>">
					<p class="description">Platzhalter: {title}, {date}, {time}, {venue}, {city}, {site}. Fehlt ein Wert, entfällt der Platzhalter samt dem Wort davor. Beispiel: „<?php echo esc_html( Eventcrawler_Seo::title( $example, $settings['seo_title_pattern'] ) ); ?>“</p></td>
				</tr>
			</table>
			<?php if ( Eventcrawler_Seo::enabled() ) : ?>
				<p class="eventcrawler-icon-ok">Detailseiten sind aktiv, z. B. <code><?php echo esc_html( user_trailingslashit( home_url( '/' . $settings['seo_base'] . '/ninotschka-2026-10-24' ) ) ); ?></code></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		$settings  = Eventcrawler_Settings::all();
		$notice    = get_transient( 'eventcrawler_settings_notice_' . get_current_user_id() );
		$test_page = Eventcrawler_Settings::test_page();
		$mode      = Eventcrawler_Settings::mode();
		if ( is_array( $notice ) ) {
			delete_transient( 'eventcrawler_settings_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap eventcrawler-admin">
			<h1>Einstellungen</h1>
			<?php Eventcrawler_Admin::render_help( 'Hier legen Sie fest, wie der Eventcrawler mit dem bisherigen Veranstaltungs-Plugin „WP Kulmbacher Land“ zusammenarbeitet und ob Veranstaltungen eigene Seiten für Suchmaschinen bekommen. Solange Sie nichts ändern, bleiben alle bestehenden Seiten unverändert.' ); ?>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE, 'eventcrawler_settings_nonce' ); ?>

				<div class="eventcrawler-card" id="wpem">
					<h2>WPEM-Kompatibilität</h2>
					<p><strong>Aktueller Modus:</strong>
						<?php
						if ( 'parallel' === $mode ) {
							echo '<span class="eventcrawler-status eventcrawler-status--ok">Parallelbetrieb</span>';
						} elseif ( 'aliases' === $mode ) {
							echo '<span class="eventcrawler-status eventcrawler-status--warning">WPEM-Shortcodes übernommen</span>';
						} else {
							echo '<span class="eventcrawler-status eventcrawler-status--ok">Eigenständig</span>';
						}
						?>
					</p>
					<?php if ( 'parallel' === $mode ) : ?>
						<ul class="eventcrawler-checklist">
							<li>wp-events-manager („WP Kulmbacher Land“) bleibt unverändert aktiv</li>
							<li>bestehende WPEM-Shortcodes werden nicht ersetzt</li>
							<li>der Eventcrawler verwendet eigene Shortcodes</li>
						</ul>
					<?php elseif ( 'aliases' === $mode ) : ?>
						<p>WP Kulmbacher Land ist nicht aktiv. Seiten mit <code>[events_list]</code> und <code>[events_calendar]</code> zeigen jetzt die Veranstaltungen des Eventcrawlers.</p>
					<?php else : ?>
						<p>WP Kulmbacher Land ist nicht aktiv. <code>[events_list]</code> und <code>[events_calendar]</code> werden nicht vom Eventcrawler bedient, solange die Übernahme unten nicht eingeschaltet ist.</p>
					<?php endif; ?>

					<p>Eigene Shortcodes des Eventcrawlers (funktionieren immer):</p>
					<p><code>[eventcrawler_events]</code> &nbsp; <code>[eventcrawler_calendar]</code></p>

					<p>
						<label>
							<input type="checkbox" name="wpem_aliases" value="1" <?php checked( 1, (int) $settings['wpem_aliases'] ); ?>>
							WPEM-Shortcodes übernehmen
						</label>
					</p>
					<p class="description">Nur wirksam, wenn WP Kulmbacher Land <strong>deaktiviert</strong> ist. Dann zeigen bestehende Seiten mit <code>[events_list]</code>/<code>[events_calendar]</code> die Veranstaltungen des Eventcrawlers. Standard: aus. Das Deaktivieren von WP Kulmbacher Land allein schaltet die Übernahme nicht ein.</p>
					<?php if ( 1 === (int) $settings['wpem_aliases'] && Eventcrawler_Settings::wpem_active() ) : ?>
						<p class="eventcrawler-hint">Die Übernahme ist eingeschaltet, wartet aber, bis WP Kulmbacher Land deaktiviert wird.</p>
					<?php endif; ?>
				</div>

				<?php self::render_seo_section( $settings ); ?>
				<?php do_action( 'eventcrawler_settings_sections', $settings ); ?>

				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>

			<div class="eventcrawler-card" id="testseite">
				<h2>Frontend-Testseite</h2>
				<p>Legt eine neue Seite „Eventcrawler Test“ mit Kalender und Veranstaltungsliste an – als <strong>Entwurf</strong>, also nicht öffentlich. Bestehende Seiten werden nicht verändert.</p>
				<?php if ( $test_page ) : ?>
					<p>
						Testseite vorhanden (Status: <?php echo esc_html( 'publish' === $test_page->post_status ? 'veröffentlicht' : 'Entwurf' ); ?>).
						<a class="button" href="<?php echo esc_url( get_preview_post_link( $test_page ) ); ?>" target="_blank" rel="noopener noreferrer">Vorschau ansehen</a>
						<a class="button-link" href="<?php echo esc_url( get_edit_post_link( $test_page->ID, 'url' ) ); ?>">Seite bearbeiten</a>
					</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST_PAGE ); ?>">
						<?php wp_nonce_field( self::ACTION_TEST_PAGE, 'eventcrawler_settings_nonce' ); ?>
						<button type="submit" class="button">Eventcrawler-Testseite erstellen</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
