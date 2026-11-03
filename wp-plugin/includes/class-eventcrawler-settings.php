<?php
/**
 * Einstellungen des Plugins (WPEM-Kompatibilität, SEO-Detailseiten).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eine Option `eventcrawler_settings` (Array) mit festen Defaults. Fehlende oder ungültige Werte fallen auf den Default
 * zurück; gespeichert wird nur über sanitize() (docs/06-admin.md „Einstellungen“).
 *
 * Grundsatz Parallelbetrieb (A103): Ohne ausdrückliche Freigabe übernimmt der Eventcrawler keine WPEM-Shortcodes und
 * keine WPEM-URLs; Detailseiten sind standardmäßig aus.
 */
class Eventcrawler_Settings {

	const OPTION = 'eventcrawler_settings';

	/**
	 * Option mit der ID der per Klick erstellten Frontend-Testseite (Entwurf).
	 */
	const TEST_PAGE_OPTION = 'eventcrawler_test_page_id';

	/**
	 * URL-Basen, die im Parallelbetrieb WPEM gehören (Rewrite von CPT und Taxonomie) und nie verwendet werden dürfen,
	 * solange WPEM aktiv ist.
	 */
	const WPEM_BASES = array( 'veranstaltung', 'veranstaltungsort' );

	/**
	 * Standardbasis der Detailseiten im Parallelbetrieb (docs/12-seo.md Abschnitt 2).
	 */
	const DEFAULT_BASE = 'eventcrawler-veranstaltung';

	const DEFAULTS = array(
		'wpem_aliases'          => 0,
		'seo_detail_pages'      => 0,
		'seo_structured_data'   => 1,
		'seo_sitemap'           => 1,
		'seo_base'              => self::DEFAULT_BASE,
		'seo_sitemap_past_days' => 60,
		'seo_title_pattern'     => '{title} am {date} in {city}',
	);

	/**
	 * @var array|null Cache je Request.
	 */
	private static $cache = null;

	/**
	 * @return array Alle Einstellungen, ergänzt um Defaults.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array(), false );
		}
		return self::$cache;
	}

	/**
	 * @param string $key Schlüssel aus DEFAULTS.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Speichert bereinigte Werte.
	 *
	 * @param array $values Rohwerte (z. B. aus dem Formular).
	 * @return array|WP_Error Gespeicherte Werte oder Fehler (dann wird nichts gespeichert).
	 */
	public static function save( array $values ) {
		$clean = self::sanitize( $values, true );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		update_option( self::OPTION, $clean, true );
		self::$cache = null;
		return $clean;
	}

	/**
	 * Zurücksetzen des Request-Caches (Tests).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * @param array $values Rohwerte.
	 * @param bool  $strict true: ungültige Werte sind Fehler (Formular). false: Default verwenden (gelesene Option).
	 * @return array|WP_Error
	 */
	public static function sanitize( array $values, $strict ) {
		$out = self::DEFAULTS;
		foreach ( array( 'wpem_aliases', 'seo_detail_pages', 'seo_structured_data', 'seo_sitemap' ) as $flag ) {
			if ( array_key_exists( $flag, $values ) ) {
				$out[ $flag ] = empty( $values[ $flag ] ) ? 0 : 1;
			} elseif ( $strict ) {
				$out[ $flag ] = 0; // nicht angehakte Checkbox fehlt im Formular
			}
		}

		if ( isset( $values['seo_base'] ) && is_scalar( $values['seo_base'] ) ) {
			$base = strtolower( trim( (string) $values['seo_base'], " \t\n\r\0\x0B/" ) );
			if ( preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base ) && strlen( $base ) <= 50 ) {
				$out['seo_base'] = $base;
			} elseif ( $strict ) {
				return new WP_Error( 'eventcrawler_settings', 'Die URL-Basis darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (z. B. „eventcrawler-veranstaltung“).' );
			}
		}

		if ( isset( $values['seo_sitemap_past_days'] ) && is_scalar( $values['seo_sitemap_past_days'] ) ) {
			$days = trim( (string) $values['seo_sitemap_past_days'] );
			if ( ctype_digit( $days ) && (int) $days <= 3650 ) {
				$out['seo_sitemap_past_days'] = (int) $days;
			} elseif ( $strict ) {
				return new WP_Error( 'eventcrawler_settings', 'Vergangene Veranstaltungen in der Sitemap: bitte eine Zahl von 0 bis 3650 Tage eingeben.' );
			}
		}

		if ( isset( $values['seo_title_pattern'] ) && is_scalar( $values['seo_title_pattern'] ) ) {
			$pattern = trim( sanitize_text_field( (string) $values['seo_title_pattern'] ) );
			if ( '' !== $pattern && false !== strpos( $pattern, '{title}' ) && mb_strlen( $pattern ) <= 200 ) {
				$out['seo_title_pattern'] = $pattern;
			} elseif ( $strict ) {
				return new WP_Error( 'eventcrawler_settings', 'Das Seitentitel-Muster muss {title} enthalten.' );
			}
		}

		if ( $strict ) {
			$conflict = self::base_conflict( $out['seo_base'] );
			if ( $conflict ) {
				return new WP_Error( 'eventcrawler_settings', $conflict );
			}
		}

		return $out;
	}

	/**
	 * Prüft, ob eine URL-Basis mit WPEM oder einer vorhandenen Seite kollidiert.
	 *
	 * @param string $base URL-Basis.
	 * @return string Fehlermeldung oder "".
	 */
	public static function base_conflict( $base ) {
		if ( in_array( $base, self::WPEM_BASES, true ) && self::wpem_active() ) {
			return 'Die URL-Basis „' . $base . '“ gehört im Parallelbetrieb zu WP Kulmbacher Land und kann nicht verwendet werden, solange das Plugin aktiv ist.';
		}
		$page = get_page_by_path( $base, OBJECT, array( 'page', 'post' ) );
		if ( $page && 'trash' !== $page->post_status ) {
			return 'Unter /' . $base . '/ gibt es bereits eine Seite („' . $page->post_title . '“). Bitte eine andere URL-Basis wählen.';
		}
		return '';
	}

	/**
	 * Ist WP Kulmbacher Land (WPEM) aktiv? Erkennung über die Konstante bzw. Klasse des Plugins, nicht über die Daten
	 * (die bleiben nach dem Deaktivieren in der Datenbank).
	 *
	 * @return bool
	 */
	public static function wpem_active() {
		/**
		 * Erkennung „WPEM aktiv“ übersteuern (nur für Tests/Sonderfälle).
		 *
		 * @param bool $active Erkannt über Konstante/Klasse.
		 */
		return (bool) apply_filters( 'eventcrawler_wpem_active', defined( 'WPEM_VERSION' ) || class_exists( 'WPEM_Frontend', false ) );
	}

	/**
	 * Dürfen die Alias-Shortcodes [events_list]/[events_calendar] registriert werden? Nur mit ausdrücklicher Freigabe
	 * UND ohne aktives WPEM (A103). Das Deaktivieren von WPEM allein schaltet sie nicht ein.
	 *
	 * @return bool
	 */
	public static function aliases_enabled() {
		return 1 === (int) self::get( 'wpem_aliases' ) && ! self::wpem_active();
	}

	/**
	 * Aktueller Betriebsmodus für die Anzeige.
	 *
	 * @return string parallel|aliases|standalone
	 */
	public static function mode() {
		if ( self::wpem_active() ) {
			return 'parallel';
		}
		return self::aliases_enabled() ? 'aliases' : 'standalone';
	}

	/**
	 * Erstellt die Frontend-Testseite als Entwurf (nur nach Admin-Klick). Vorhandene Seiten werden nie verändert; existiert
	 * die zuvor erstellte Testseite noch, wird keine zweite angelegt.
	 *
	 * @return int|WP_Error Seiten-ID.
	 */
	public static function create_test_page() {
		$existing = self::test_page();
		if ( $existing ) {
			return new WP_Error( 'eventcrawler_test_page_exists', 'Die Testseite existiert bereits.' );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Eventcrawler Test',
				'post_content' => "[eventcrawler_calendar]\n\n[eventcrawler_events]",
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_option( self::TEST_PAGE_OPTION, (int) $id, false );
		return (int) $id;
	}

	/**
	 * @return WP_Post|null Die per Klick erstellte Testseite, falls sie noch existiert (nicht im Papierkorb).
	 */
	public static function test_page() {
		$id = (int) get_option( self::TEST_PAGE_OPTION, 0 );
		if ( ! $id ) {
			return null;
		}
		$page = get_post( $id );
		return ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) ? $page : null;
	}
}
