<?php
/**
 * Öffentliche Detailseiten je Termin mit Title, Description, Canonical, Robots, JSON-LD und Sitemap.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO-Detailseiten (docs/12-seo.md). Standardmäßig AUS (Einstellung „Event-Detailseiten aktivieren“).
 *
 * - URL: /{basis}/{slug}/, Basis im Parallelbetrieb „eventcrawler-veranstaltung“ – nie WPEMs „veranstaltung“, solange
 *   WPEM aktiv ist (Eventcrawler_Settings::base_conflict()). Keine Redirects von WPEM-URLs, keine Canonicals auf
 *   WPEM-Seiten.
 * - Eine Seite lädt genau einen Termin (Index slug). Unbekannter Slug = echte WordPress-404.
 * - Vergangene Termine bleiben erreichbar (200) mit Hinweis.
 * - Nur vorhandene Daten; keine erfundenen Texte, kein Status, keine Angebote, keine Bilder.
 */
class Eventcrawler_Seo {

	const QUERY_VAR   = 'eventcrawler_event';
	const STATE_OPTION = 'eventcrawler_rewrite_state';

	/**
	 * Parameter der Listen-/Kalenderansicht, die keine eigenen Suchmaschinen-Seiten erzeugen sollen.
	 */
	const FILTER_PARAMS = array( 'date', 'ec_search', 'ec_city', 'ec_page', 'ec_month' );

	/**
	 * Shortcodes, auf deren Seiten Filter-URLs noindex bekommen.
	 */
	const LIST_SHORTCODES = array( 'eventcrawler_events', 'eventcrawler_calendar', 'events_list', 'events_calendar' );

	/**
	 * @var array|null|false Termin der aktuellen Detailseite (false = noch nicht geladen).
	 */
	private static $event = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ), 20 );
		add_action( 'init', array( __CLASS__, 'register_sitemap' ), 30 );
		add_filter( 'wp_robots', array( __CLASS__, 'list_page_robots' ) );
		add_action( 'eventcrawler_settings_saved', array( __CLASS__, 'settings_saved' ), 10, 2 );

		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'posts_pre_query', array( __CLASS__, 'posts_pre_query' ), 10, 2 );
		add_action( 'parse_query', array( __CLASS__, 'parse_query' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'pre_handle_404' ), 10, 2 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 99 );
		add_filter( 'wp_title', array( __CLASS__, 'document_title' ), 99 );
		add_filter( 'wp_robots', array( __CLASS__, 'detail_robots' ), 99 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ) );
		// Verbreitete SEO-Plugins: auf der Detailseite dieselben Werte statt ihrer Startseiten-Annahmen.
		add_filter( 'wpseo_canonical', array( __CLASS__, 'plugin_canonical' ), 99 );
		add_filter( 'wpseo_title', array( __CLASS__, 'plugin_title' ), 99 );
		add_filter( 'wpseo_metadesc', array( __CLASS__, 'plugin_description' ), 99 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'plugin_canonical' ), 99 );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'plugin_title' ), 99 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'plugin_description' ), 99 );
	}

	/**
	 * @return bool Detailseiten eingeschaltet.
	 */
	public static function enabled() {
		return 1 === (int) Eventcrawler_Settings::get( 'seo_detail_pages' );
	}

	/**
	 * @return string URL-Basis (ohne Schrägstriche).
	 */
	public static function base() {
		return (string) Eventcrawler_Settings::get( 'seo_base' );
	}

	/**
	 * Öffentliche URL eines Termins oder "" (Detailseiten aus / kein Slug).
	 *
	 * @param array $event Zeile mit slug.
	 * @return string
	 */
	public static function url( array $event ) {
		if ( ! self::enabled() || empty( $event['slug'] ) ) {
			return '';
		}
		return user_trailingslashit( home_url( '/' . self::base() . '/' . $event['slug'] ) );
	}

	/* ------------------------------------------------------------------ Rewrite */

	/**
	 * Rewrite-Regel nur bei aktivierten Detailseiten. Ändert sich Basis oder Schalter, werden die Regeln einmal neu
	 * erzeugt (Option eventcrawler_rewrite_state); sonst kein flush je Request.
	 */
	public static function register_rewrite() {
		$state = self::enabled() ? 'on:' . self::base() : 'off';
		if ( self::enabled() ) {
			add_rewrite_rule( '^' . preg_quote( self::base(), '#' ) . '/([a-z0-9-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
		if ( get_option( self::STATE_OPTION ) !== $state ) {
			flush_rewrite_rules( false );
			update_option( self::STATE_OPTION, $state, true );
		}
	}

	/**
	 * Nach dem Speichern der Einstellungen beim nächsten Request neu erzeugen (dann mit der neuen Basis).
	 *
	 * @param array $saved  Neue Werte.
	 * @param array $before Alte Werte.
	 */
	public static function settings_saved( $saved, $before ) {
		if ( $saved['seo_detail_pages'] !== $before['seo_detail_pages'] || $saved['seo_base'] !== $before['seo_base'] ) {
			delete_option( self::STATE_OPTION );
		}
	}

	/**
	 * Deaktivierung: Regeln beim nächsten Request ohne Eventcrawler neu erzeugen lassen.
	 */
	public static function deactivate() {
		delete_option( self::STATE_OPTION );
		delete_option( 'rewrite_rules' );
	}

	/**
	 * @param string[] $vars Query-Variablen.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* ------------------------------------------------------------------ Request */

	/**
	 * @return string Angefragter Slug oder "".
	 */
	private static function requested_slug() {
		$slug = (string) get_query_var( self::QUERY_VAR );
		return preg_match( '/^[a-z0-9-]{1,191}$/', $slug ) ? $slug : '';
	}

	/**
	 * Termin der aktuellen Detailseite (genau eine Abfrage je Request).
	 *
	 * @return array|null
	 */
	public static function current_event() {
		if ( false === self::$event ) {
			$slug        = self::requested_slug();
			self::$event = '' === $slug ? null : ( new Eventcrawler_Repository() )->get_public_by_slug( $slug );
		}
		return self::$event;
	}

	/**
	 * Für Tests: Zustand zurücksetzen.
	 */
	public static function reset() {
		self::$event = false;
	}

	/**
	 * @return bool Ist der aktuelle Request eine (gültige) Detailseite?
	 */
	public static function is_detail() {
		return self::enabled() && '' !== self::requested_slug() && null !== self::current_event();
	}

	/**
	 * Hauptabfrage auf der Detailseite ist keine Startseite/Blogübersicht.
	 *
	 * @param WP_Query $query Abfrage.
	 */
	public static function parse_query( $query ) {
		if ( $query->is_main_query() && '' !== (string) $query->get( self::QUERY_VAR ) ) {
			$query->is_home       = false;
			$query->is_front_page = false;
			$query->is_archive    = false;
		}
	}

	/**
	 * Keine Beitragsabfrage für die Detailseite (der Termin kommt aus der eigenen Tabelle).
	 *
	 * @param array|null $posts Vorgabe.
	 * @param WP_Query   $query Abfrage.
	 * @return array|null
	 */
	public static function posts_pre_query( $posts, $query ) {
		if ( $query->is_main_query() && '' !== (string) $query->get( self::QUERY_VAR ) ) {
			return array();
		}
		return $posts;
	}

	/**
	 * Gefundener Termin: kein 404 (Status 200). Unbekannter Slug: WordPress setzt die echte 404.
	 *
	 * @param bool     $preempt Vorgabe.
	 * @param WP_Query $query   Abfrage.
	 * @return bool
	 */
	public static function pre_handle_404( $preempt, $query ) {
		if ( '' === (string) $query->get( self::QUERY_VAR ) ) {
			return $preempt;
		}
		if ( null === self::current_event() ) {
			$query->set_404();
			status_header( 404 );
			nocache_headers();
			return true;
		}
		status_header( 200 );
		return true;
	}

	/**
	 * @param string $template Vorlage.
	 * @return string
	 */
	public static function template_include( $template ) {
		return self::is_detail() ? EVENTCRAWLER_PATH . 'templates/eventcrawler-event.php' : $template;
	}

	/**
	 * Keine „Weiterleitung auf die richtige URL“ durch WordPress für die Detailseite.
	 *
	 * @param string|false $url Ziel.
	 * @return string|false
	 */
	public static function redirect_canonical( $url ) {
		return '' !== self::requested_slug() ? false : $url;
	}

	/**
	 * @param string[] $classes Klassen.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::is_detail() ) {
			$classes   = array_diff( $classes, array( 'home', 'blog' ) );
			$classes[] = 'eventcrawler-event-page';
		}
		return $classes;
	}

	/* ------------------------------------------------------------------ Kopfbereich */

	/**
	 * Seitentitel nach Muster (Einstellung), nur aus vorhandenen Werten.
	 *
	 * @param array  $event   Termin.
	 * @param string $pattern Muster mit {title}, {date}, {time}, {venue}, {city}, {site}.
	 * @return string
	 */
	public static function title( array $event, $pattern = null ) {
		$pattern = null === $pattern ? (string) Eventcrawler_Settings::get( 'seo_title_pattern' ) : $pattern;
		$values  = array(
			'title' => trim( (string) $event['title'] ),
			'date'  => self::date_short( $event['start_date'] ),
			'time'  => self::time_text( $event ),
			'venue' => trim( (string) $event['venue_name'] ),
			'city'  => trim( (string) $event['city'] ),
			'site'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		// Leerer Platzhalter: samt dem Wort davor entfernen („ in {city}“), damit kein „in“ ohne Ort stehen bleibt.
		foreach ( $values as $key => $value ) {
			if ( '' === $value ) {
				$pattern = preg_replace( '/\s*[^\s{}]*\s*\{' . $key . '\}/u', '', $pattern );
			}
		}
		$title = $pattern;
		foreach ( $values as $key => $value ) {
			$title = str_replace( '{' . $key . '}', $value, $title );
		}
		return trim( preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Meta-Description nur aus vorhandenen Daten, max. 160 Zeichen:
	 * "{Titel} am {Datum}[ um {Uhrzeit}] in {Stadt}.[ Ort: {Ort}[, {Adresse}].]"
	 *
	 * @param array $event Termin.
	 * @return string
	 */
	public static function description( array $event ) {
		$text = trim( (string) $event['title'] ) . ' am ' . self::date_short( $event['start_date'] );
		$time = self::time_text( $event );
		if ( '' !== $time && 'ganztägig' !== $time ) {
			$text .= ' um ' . $time;
		}
		$text .= ' in ' . trim( (string) $event['city'] ) . '.';
		$place = array_filter( array( trim( (string) $event['venue_name'] ), trim( (string) $event['venue_address'] ) ), 'strlen' );
		if ( $place ) {
			$text .= ' Ort: ' . implode( ', ', $place ) . '.';
		}
		return self::shorten( $text, 160 );
	}

	/**
	 * <title> der Detailseite. Escaped, weil WordPress den Rückgabewert von pre_get_document_title/wp_title
	 * unverändert ausgibt.
	 *
	 * @param string $title Vorgabe.
	 * @return string
	 */
	public static function document_title( $title ) {
		$event = self::is_detail() ? self::current_event() : null;
		return $event ? esc_html( self::title( $event ) ) : $title;
	}

	/**
	 * Kopfbereich der Detailseite: Description, Canonical, JSON-LD.
	 */
	public static function head() {
		$event = self::is_detail() ? self::current_event() : null;
		if ( ! $event ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( self::description( $event ) ) . '" />' . "\n";
		echo '<link rel="canonical" href="' . esc_url( self::url( $event ) ) . '" />' . "\n";
		if ( 1 === (int) Eventcrawler_Settings::get( 'seo_structured_data' ) && self::indexable( $event ) ) {
			echo '<script type="application/ld+json">' . self::json_ld_string( $event ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode mit JSON_HEX_TAG.
		}
	}

	/**
	 * Robots der Detailseite: index, follow nur mit ausreichenden Daten; sonst noindex, follow. Eine nicht öffentliche
	 * Website (Einstellung „Suchmaschinen davon abhalten“) bleibt noindex.
	 *
	 * @param array $robots Direktiven.
	 * @return array
	 */
	public static function detail_robots( $robots ) {
		$event = self::is_detail() ? self::current_event() : null;
		if ( ! $event ) {
			return $robots;
		}
		if ( ! self::indexable( $event ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		} elseif ( empty( $robots['noindex'] ) ) {
			$robots['index']  = true;
			$robots['follow'] = true;
		}
		return $robots;
	}

	/**
	 * Listen-/Kalenderseiten mit Filter-Parametern (?date=, ?ec_search= …) erzeugen keine eigenen Suchmaschinen-Seiten:
	 * noindex, follow. Die Seite ohne Parameter bleibt unverändert (Canonical setzt WordPress auf die Seite selbst).
	 *
	 * @param array $robots Direktiven.
	 * @return array
	 */
	public static function list_page_robots( $robots ) {
		if ( ! is_singular() || ! self::has_filter_params() ) {
			return $robots;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return $robots;
		}
		foreach ( self::LIST_SHORTCODES as $shortcode ) {
			if ( has_shortcode( $post->post_content, $shortcode ) ) {
				unset( $robots['index'] );
				$robots['noindex'] = true;
				$robots['follow']  = true;
				break;
			}
		}
		return $robots;
	}

	/**
	 * @return bool Enthält die Anfrage Filter-Parameter der Liste?
	 */
	public static function has_filter_params() {
		foreach ( self::FILTER_PARAMS as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Erkennung.
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $value Vorgabe.
	 * @return string
	 */
	public static function plugin_canonical( $value ) {
		$event = self::is_detail() ? self::current_event() : null;
		return $event ? self::url( $event ) : $value;
	}

	/**
	 * @param string $value Vorgabe.
	 * @return string
	 */
	public static function plugin_title( $value ) {
		$event = self::is_detail() ? self::current_event() : null;
		return $event ? self::title( $event ) : $value;
	}

	/**
	 * @param string $value Vorgabe.
	 * @return string
	 */
	public static function plugin_description( $value ) {
		$event = self::is_detail() ? self::current_event() : null;
		return $event ? self::description( $event ) : $value;
	}

	/* ------------------------------------------------------------------ Daten */

	/**
	 * Ausreichende Daten für Indexierung, Sitemap und JSON-LD: Titel, Datum, Stadt (Pflicht) und Ort oder Adresse.
	 * Muss Eventcrawler_Repository::INDEXABLE_SQL entsprechen.
	 *
	 * @param array $event Termin.
	 * @return bool
	 */
	public static function indexable( array $event ) {
		return '' !== trim( (string) $event['title'] ) && ! empty( $event['start_date'] ) && '' !== trim( (string) $event['city'] )
			&& ( '' !== trim( (string) $event['venue_name'] ) || '' !== trim( (string) $event['venue_address'] ) );
	}

	/**
	 * @param array $event Termin.
	 * @return bool Liegt der Termin (bzw. sein Ende) vor heute?
	 */
	public static function is_past( array $event ) {
		$last = ! empty( $event['end_date'] ) ? $event['end_date'] : $event['start_date'];
		return $last < current_time( 'Y-m-d' );
	}

	/**
	 * Beschreibung öffentlich? Gleiche Regel wie in der Liste (A100): Standard nur manuelle Termine.
	 *
	 * @param array $event Termin.
	 * @return string Beschreibung oder "".
	 */
	public static function public_description( array $event ) {
		$show = (bool) apply_filters( 'eventcrawler_frontend_show_description', Eventcrawler_Manual_Events::SOURCE === $event['source'], $event );
		return $show ? trim( (string) $event['description'] ) : '';
	}

	/**
	 * schema.org/Event nur mit vorhandenen Daten. Kein eventStatus, keine offers, kein Bild (docs/12-seo.md Abschnitt 5).
	 *
	 * @param array $event Termin.
	 * @return array
	 */
	public static function json_ld( array $event ) {
		$address = array( '@type' => 'PostalAddress' );
		if ( '' !== trim( (string) $event['venue_address'] ) ) {
			$address['streetAddress'] = trim( (string) $event['venue_address'] );
		}
		$address['addressLocality'] = trim( (string) $event['city'] );
		$address['addressCountry']  = 'DE';

		$place = array( '@type' => 'Place' );
		if ( '' !== trim( (string) $event['venue_name'] ) ) {
			$place['name'] = trim( (string) $event['venue_name'] );
		}
		$place['address'] = $address;

		$data = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Event',
			'name'      => trim( (string) $event['title'] ),
			'startDate' => self::iso( $event['start_date'], empty( $event['all_day'] ) ? $event['start_time'] : null ),
		);
		if ( ! empty( $event['end_date'] ) ) {
			$data['endDate'] = self::iso( $event['end_date'], empty( $event['all_day'] ) ? $event['end_time'] : null );
		}
		$data['location'] = $place;
		$description      = self::public_description( $event );
		if ( '' !== $description ) {
			$data['description'] = self::shorten( $description, 500 );
		}
		$url = self::url( $event );
		if ( '' !== $url ) {
			$data['url'] = $url;
		}
		return $data;
	}

	/**
	 * @param array $event Termin.
	 * @return string JSON, sicher innerhalb von <script> (</script> und & maskiert).
	 */
	public static function json_ld_string( array $event ) {
		return (string) wp_json_encode( self::json_ld( $event ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	}

	/**
	 * ISO 8601: mit Uhrzeit als Datum+Zeit in der Zeitzone der Website ("2026-10-24T20:00:00+02:00"), ohne Uhrzeit nur
	 * das Datum – nie eine erfundene 00:00.
	 *
	 * @param string      $date Y-m-d.
	 * @param string|null $time H:i:s oder null.
	 * @return string
	 */
	public static function iso( $date, $time ) {
		if ( empty( $time ) ) {
			return (string) $date;
		}
		$dt = date_create_immutable( $date . ' ' . $time, wp_timezone() );
		return $dt ? $dt->format( 'c' ) : (string) $date;
	}

	/**
	 * @param string $ymd Datum.
	 * @return string "24.10.2026".
	 */
	private static function date_short( $ymd ) {
		$d = DateTime::createFromFormat( '!Y-m-d', (string) $ymd );
		return $d ? $d->format( 'd.m.Y' ) : (string) $ymd;
	}

	/**
	 * @param array $event Termin.
	 * @return string "20:00 Uhr", "20:00–22:00 Uhr", "ganztägig" oder "".
	 */
	private static function time_text( array $event ) {
		return Eventcrawler_Frontend::format_time( $event );
	}

	/**
	 * Kürzt an einer Wortgrenze mit „…“.
	 *
	 * @param string $text Text.
	 * @param int    $max  Zeichen.
	 * @return string
	 */
	private static function shorten( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $space && $space > $max / 2 ? mb_substr( $cut, 0, $space ) : $cut, ' ,.;:' ) . '…';
	}

	/**
	 * Sachliche SEO-Hinweise für das Admin (keine Punktzahl).
	 *
	 * @param array $event Termin (Datenbankzeile).
	 * @return array[] array( ok, label ).
	 */
	public static function checks( array $event ) {
		$enabled = self::enabled();
		$items   = array(
			array(
				'ok'    => $enabled,
				'label' => $enabled ? 'Detailseite aktiv' : 'Detailseiten sind ausgeschaltet (Einstellungen → SEO)',
			),
			array(
				'ok'    => '' !== trim( (string) $event['title'] ),
				'label' => '' !== trim( (string) $event['title'] ) ? 'Titel vorhanden' : 'Titel fehlt',
			),
			array(
				'ok'    => ! empty( $event['start_date'] ),
				'label' => 'Datum vorhanden',
			),
			array(
				'ok'    => ! empty( $event['start_time'] ) || ! empty( $event['all_day'] ),
				'label' => ! empty( $event['start_time'] ) || ! empty( $event['all_day'] ) ? 'Uhrzeit vorhanden' : 'Uhrzeit fehlt',
			),
			array(
				'ok'    => '' !== trim( (string) $event['venue_name'] ),
				'label' => '' !== trim( (string) $event['venue_name'] ) ? 'Ort vorhanden' : 'Ort fehlt',
			),
			array(
				'ok'    => '' !== trim( (string) $event['venue_address'] ),
				'label' => '' !== trim( (string) $event['venue_address'] ) ? 'Adresse vorhanden' : 'Adresse fehlt',
			),
		);
		$items[] = self::indexable( $event )
			? array( 'ok' => true, 'label' => 'Für Suchmaschinen freigegeben (index)' )
			: array( 'ok' => false, 'label' => 'Nicht für Suchmaschinen freigegeben (noindex): Ort oder Adresse fehlt' );
		return $items;
	}

	/* ------------------------------------------------------------------ Sitemap */

	/**
	 * Eigener Anbieter für die WordPress-Sitemap (wp-sitemap-eventcrawler-1.xml), nur mit Detailseiten und Einstellung.
	 */
	public static function register_sitemap() {
		if ( ! self::enabled() || 1 !== (int) Eventcrawler_Settings::get( 'seo_sitemap' ) || ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}
		require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-sitemap-provider.php';
		wp_register_sitemap_provider( Eventcrawler_Sitemap_Provider::NAME, new Eventcrawler_Sitemap_Provider() );
	}

	/**
	 * @return string Erster Tag, der in die Sitemap kommt (heute minus „vergangene Tage“).
	 */
	public static function sitemap_from() {
		$days = (int) Eventcrawler_Settings::get( 'seo_sitemap_past_days' );
		return wp_date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $days . ' days' ) );
	}

	/* ------------------------------------------------------------------ Seite */

	/**
	 * Inhalt der Detailseite (ohne Kopf/Fuß des Themes).
	 *
	 * @param array $event Termin.
	 * @return string HTML.
	 */
	public static function render_content( array $event ) {
		$time        = Eventcrawler_Frontend::format_time( $event );
		$url         = Eventcrawler_Frontend::public_url( $event['event_url'] );
		$description = self::public_description( $event );
		$venue       = trim( (string) $event['venue_name'] );
		ob_start();
		?>
		<article class="eventcrawler eventcrawler-detail">
			<h1 class="eventcrawler-detail__title"><?php echo esc_html( $event['title'] ); ?></h1>
			<?php if ( self::is_past( $event ) ) : ?>
				<p class="eventcrawler-detail__past" role="note">Diese Veranstaltung hat bereits stattgefunden.</p>
			<?php endif; ?>
			<dl class="eventcrawler-event__facts eventcrawler-detail__facts">
				<div class="eventcrawler-event__fact">
					<dt>Datum</dt>
					<dd><time datetime="<?php echo esc_attr( self::iso( $event['start_date'], empty( $event['all_day'] ) ? $event['start_time'] : null ) ); ?>"><?php echo esc_html( Eventcrawler_Frontend::format_date_range( $event ) ); ?></time></dd>
				</div>
				<?php if ( '' !== $time ) : ?>
					<div class="eventcrawler-event__fact">
						<dt>Uhrzeit</dt>
						<dd><?php echo esc_html( $time ); ?></dd>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $venue ) : ?>
					<div class="eventcrawler-event__fact">
						<dt>Ort</dt>
						<dd><?php echo esc_html( $venue ); ?></dd>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $event['venue_address'] ) ) : ?>
					<div class="eventcrawler-event__fact">
						<dt>Adresse</dt>
						<dd><?php echo esc_html( $event['venue_address'] ); ?></dd>
					</div>
				<?php endif; ?>
				<div class="eventcrawler-event__fact">
					<dt>Stadt</dt>
					<dd><?php echo esc_html( $event['city'] ); ?></dd>
				</div>
			</dl>
			<?php if ( '' !== $description ) : ?>
				<div class="eventcrawler-detail__description"><?php echo nl2br( esc_html( $description ) ); ?></div>
			<?php endif; ?>
			<?php if ( '' !== $url ) : ?>
				<p class="eventcrawler-detail__links">
					<a class="eventcrawler-button eventcrawler-button--secondary" href="<?php echo esc_url( $url, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer">Zur Veranstaltungsseite<span class="eventcrawler-sr-only"> (öffnet in neuem Fenster)</span></a>
				</p>
			<?php endif; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
