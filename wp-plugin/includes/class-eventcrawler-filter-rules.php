<?php
/**
 * Speicherung der textbasierten Ausschlussregeln (WordPress-Option).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regeln liegen in der Option "eventcrawler_filter_rules" als Liste von
 * array( 'id' => string, 'term' => string, 'category' => stammtisch|politics, 'match' => contains|word, 'enabled' => bool ).
 *
 * Code-Defaults (Eventcrawler_Filter::default_rules()) werden NUR bei der Erstinitialisierung übernommen, also wenn
 * die Option noch nicht existiert. Danach gelten ausschließlich die gespeicherten Regeln (docs/05-filter.md).
 */
class Eventcrawler_Filter_Rules {

	const OPTION = 'eventcrawler_filter_rules';

	/**
	 * Kategorien: interner Key => Anzeigename. Weitere Kategorien können hier ergänzt werden.
	 */
	const CATEGORIES = array(
		'stammtisch' => 'Stammtisch / unerwünschte Treffen',
		'politics'   => 'Politische Inhalte',
	);

	/**
	 * Vergleichsarten (wie bisher im Code): Teilstring bzw. ganzes Wort.
	 */
	const MATCH_MODES = array(
		'contains' => 'enthält (auch als Wortteil)',
		'word'     => 'ganzes Wort',
	);

	const MAX_LENGTH = 100;

	/**
	 * Stand der Default-Regeln, die eine Installation bereits erhalten hat (Option VERSION_OPTION).
	 */
	const VERSION_OPTION = 'eventcrawler_filter_rules_version';
	const RULES_VERSION  = 2;

	/**
	 * Default-Regeln, die NACH der Erstbelegung neu hinzugekommen sind: Version => Regeln. Sie werden in bestehenden
	 * Installationen genau einmal ergänzt (docs/05-filter.md Abschnitt 2).
	 */
	const ADDED_DEFAULTS = array(
		2 => array(
			array( 'term' => 'bürgerversammlung', 'category' => 'politics', 'match' => 'contains' ),
		),
	);

	/**
	 * Legt die Option mit den Code-Defaults an, falls sie noch nicht existiert. Existiert sie, werden nur Default-Regeln
	 * einer neueren Version einmalig ergänzt (sofern der Begriff in der Kategorie noch nicht vorhanden ist). Gelöschte
	 * oder deaktivierte Regeln kommen nie automatisch zurück. Eine beschädigte Option wird nicht angefasst.
	 */
	public static function maybe_initialize() {
		if ( false === get_option( self::OPTION ) ) {
			$rules = array();
			foreach ( Eventcrawler_Filter::default_rules() as $rule ) {
				$rule['id']      = self::new_id();
				$rule['enabled'] = true;
				$rules[]         = $rule;
			}
			add_option( self::OPTION, $rules );
			update_option( self::VERSION_OPTION, self::RULES_VERSION, false );
			return;
		}

		$version = (int) get_option( self::VERSION_OPTION, 1 );
		if ( $version >= self::RULES_VERSION || self::is_corrupt() ) {
			return;
		}
		$rules = self::get_all();
		foreach ( self::ADDED_DEFAULTS as $since => $added ) {
			if ( $since <= $version ) {
				continue;
			}
			foreach ( $added as $rule ) {
				foreach ( $rules as $existing ) {
					if ( $existing['category'] === $rule['category'] && mb_strtolower( $existing['term'] ) === mb_strtolower( $rule['term'] ) ) {
						continue 2;
					}
				}
				$rule['id']      = self::new_id();
				$rule['enabled'] = true;
				$rules[]         = $rule;
			}
		}
		self::save( $rules );
		update_option( self::VERSION_OPTION, self::RULES_VERSION, false );
	}

	/**
	 * True, wenn die Option existiert, aber kein Array ist (z. B. manuell beschädigt).
	 *
	 * @return bool
	 */
	public static function is_corrupt() {
		$raw = get_option( self::OPTION );
		return false !== $raw && ! is_array( $raw );
	}

	/**
	 * Alle gültigen gespeicherten Regeln. Ungültige Einträge werden ignoriert; bei beschädigter Option: leere Liste
	 * (keine Rückkehr zu den Defaults, der Administrator entscheidet).
	 *
	 * @return array[]
	 */
	public static function get_all() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rules = array();
		foreach ( $raw as $rule ) {
			if ( is_array( $rule ) && ! empty( $rule['id'] ) && isset( $rule['term'] ) && '' !== (string) $rule['term']
				&& isset( $rule['category'], self::CATEGORIES[ $rule['category'] ] ) && isset( $rule['match'], self::MATCH_MODES[ $rule['match'] ] ) ) {
				$rules[] = array(
					'id'       => (string) $rule['id'],
					'term'     => (string) $rule['term'],
					'category' => $rule['category'],
					'match'    => $rule['match'],
					'enabled'  => ! empty( $rule['enabled'] ),
				);
			}
		}
		return $rules;
	}

	/**
	 * Aktive Regeln für den Crawler (einmal je Lauf laden).
	 *
	 * @return array[]
	 */
	public static function get_active() {
		$active = array_values(
			array_filter(
				self::get_all(),
				function ( $rule ) {
					return $rule['enabled'];
				}
			)
		);
		/**
		 * Entwickler-Hook: aktive Regeln zur Laufzeit ergänzen/ändern (ersetzt den früheren Hook eventcrawler_exclude_terms).
		 */
		return apply_filters( 'eventcrawler_filter_rules', $active );
	}

	/**
	 * @param string $id Regel-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::get_all() as $rule ) {
			if ( $rule['id'] === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * @param string $term     Begriff (roh).
	 * @param string $category Kategorie-Key.
	 * @param string $match    Vergleichsart.
	 * @return string|WP_Error ID der neuen Regel.
	 */
	public static function add( $term, $category, $match ) {
		$rule = self::validate( $term, $category, $match, '' );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$rule['id']      = self::new_id();
		$rule['enabled'] = true;
		$rules           = self::get_all();
		$rules[]         = $rule;
		self::save( $rules );
		return $rule['id'];
	}

	/**
	 * @param string $id       Regel-ID.
	 * @param string $term     Begriff (roh).
	 * @param string $category Kategorie-Key.
	 * @param string $match    Vergleichsart.
	 * @param bool   $enabled  Status.
	 * @return true|WP_Error
	 */
	public static function update( $id, $term, $category, $match, $enabled ) {
		if ( null === self::get( $id ) ) {
			return new WP_Error( 'eventcrawler_rule', 'Filterregel nicht gefunden.' );
		}
		$valid = self::validate( $term, $category, $match, $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$rules = self::get_all();
		foreach ( $rules as $i => $rule ) {
			if ( $rule['id'] === $id ) {
				$rules[ $i ] = array_merge( $rule, $valid, array( 'enabled' => (bool) $enabled ) );
			}
		}
		self::save( $rules );
		return true;
	}

	/**
	 * @param string $id Regel-ID.
	 * @return bool|null Neuer Status oder null, wenn nicht gefunden.
	 */
	public static function toggle( $id ) {
		$rules = self::get_all();
		foreach ( $rules as $i => $rule ) {
			if ( $rule['id'] === $id ) {
				$rules[ $i ]['enabled'] = ! $rule['enabled'];
				self::save( $rules );
				return $rules[ $i ]['enabled'];
			}
		}
		return null;
	}

	/**
	 * @param string $id Regel-ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		$rules = self::get_all();
		$left  = array_values(
			array_filter(
				$rules,
				function ( $rule ) use ( $id ) {
					return $rule['id'] !== $id;
				}
			)
		);
		if ( count( $left ) === count( $rules ) ) {
			return false;
		}
		self::save( $left );
		return true;
	}

	/**
	 * Bereinigt und prüft Eingaben. HTML wird entfernt; Duplikate (gleiche Kategorie, ohne Groß-/Kleinschreibung) abgelehnt.
	 *
	 * @param string $term       Begriff (roh).
	 * @param string $category   Kategorie-Key.
	 * @param string $match      Vergleichsart.
	 * @param string $exclude_id Eigene ID beim Bearbeiten.
	 * @return array|WP_Error term, category, match.
	 */
	private static function validate( $term, $category, $match, $exclude_id ) {
		$term = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $term ) ) );
		if ( '' === $term ) {
			return new WP_Error( 'eventcrawler_rule', 'Der Begriff darf nicht leer sein (HTML wird entfernt).' );
		}
		if ( mb_strlen( $term ) > self::MAX_LENGTH ) {
			return new WP_Error( 'eventcrawler_rule', 'Der Begriff ist zu lang (höchstens ' . self::MAX_LENGTH . ' Zeichen).' );
		}
		if ( ! isset( self::CATEGORIES[ $category ] ) ) {
			return new WP_Error( 'eventcrawler_rule', 'Unbekannte Kategorie.' );
		}
		if ( ! isset( self::MATCH_MODES[ $match ] ) ) {
			return new WP_Error( 'eventcrawler_rule', 'Unbekannte Vergleichsart.' );
		}
		foreach ( self::get_all() as $rule ) {
			if ( $rule['id'] !== $exclude_id && $rule['category'] === $category && mb_strtolower( $rule['term'] ) === mb_strtolower( $term ) ) {
				return new WP_Error( 'eventcrawler_rule', 'Diesen Begriff gibt es in dieser Kategorie bereits.' );
			}
		}
		return array(
			'term'     => $term,
			'category' => $category,
			'match'    => $match,
		);
	}

	/**
	 * @param array[] $rules Regeln.
	 */
	private static function save( array $rules ) {
		update_option( self::OPTION, array_values( $rules ) );
	}

	/**
	 * @return string
	 */
	private static function new_id() {
		return 'r' . substr( md5( wp_generate_uuid4() ), 0, 12 );
	}
}
