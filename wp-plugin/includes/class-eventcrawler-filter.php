<?php
/**
 * Zentrale Filterlogik.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ausschlussregeln aus docs/05-filter.md. Läuft im Crawler nach der Normalisierung, vor dem Matching.
 * Keine quellenspezifischen Regeln hier und keine Filter in den Adaptern.
 *
 * Die Begriffslisten unten sind nur noch CODE-DEFAULTS für die Erstinitialisierung der Option
 * eventcrawler_filter_rules (Eventcrawler_Filter_Rules). Zur Laufzeit gelten ausschließlich die aktiven gespeicherten Regeln.
 */
class Eventcrawler_Filter {

	/**
	 * Default: Teilstrings (case-insensitive) in Titel oder Beschreibung.
	 */
	const STAMMTISCH_TERMS = array( 'stammtisch' );

	/**
	 * Default: Teilstrings (case-insensitive) für politische Veranstaltungen.
	 */
	const POLITICAL_TERMS = array(
		'wahlkampf',
		'wahlkundgebung',
		'wahlveranstaltung',
		'wahlforum',
		'podiumsdiskussion zur wahl',
		'parteitag',
		'parteiveranstaltung',
		'ortsverband',
		'kreisverband',
		'ortsverein',
		'mitgliederversammlung der',
		'stadtratssitzung',
		'stadtrat',
		'gemeinderatssitzung',
		'gemeinderat',
		'kreistagssitzung',
		'kreistag',
		'marktgemeinderat',
		'bezirkstag',
		'ausschusssitzung',
		'bürgerversammlung', // seit 2026-10-02 (Kundenvorgabe); erfasst auch "Bürgerversammlungen".
	);

	/**
	 * Default: Parteinamen, nur als ganze Wörter.
	 */
	const PARTY_NAMES = array(
		'CSU',
		'SPD',
		'FDP',
		'AfD',
		'ÖDP',
		'BSW',
		'Die Linke',
		'Bündnis 90',
		'Die Grünen',
		'Freie Wähler',
		'Junge Union',
		'Jusos',
		'Junge Liberale',
		'Grüne Jugend',
	);

	/**
	 * Code-Defaults als Regelliste (in dieser Reihenfolge; ohne id/enabled). Nur für die Erstinitialisierung.
	 *
	 * @return array[] term, category, match.
	 */
	public static function default_rules() {
		$rules = array();
		foreach ( self::STAMMTISCH_TERMS as $term ) {
			$rules[] = array( 'term' => $term, 'category' => 'stammtisch', 'match' => 'contains' );
		}
		foreach ( self::POLITICAL_TERMS as $term ) {
			$rules[] = array( 'term' => $term, 'category' => 'politics', 'match' => 'contains' );
		}
		foreach ( self::PARTY_NAMES as $term ) {
			$rules[] = array( 'term' => $term, 'category' => 'politics', 'match' => 'word' );
		}
		return $rules;
	}

	/**
	 * Prüft ein normalisiertes Event. Geprüft werden Titel und Beschreibung (wie bisher), ohne Groß-/Kleinschreibung.
	 *
	 * @param array         $event          Normalisiertes Event (title, description, city).
	 * @param string[]|null $allowed_cities Ortsregel der Quelle oder null.
	 * @param array[]|null  $rules          Aktive Regeln (vom Crawler einmal je Lauf geladen); null = jetzt laden.
	 * @return string|null Grund ('ort' oder Kategorie-Key der Regel) oder null, wenn das Event übernommen wird.
	 */
	public static function get_exclusion_reason( array $event, $allowed_cities = null, $rules = null ) {
		if ( null !== $allowed_cities && ! self::is_allowed_city( $event['city'], $allowed_cities ) ) {
			return 'ort';
		}

		$rule = self::find_matching_rule( $event['title'] . ' ' . $event['description'], null === $rules ? Eventcrawler_Filter_Rules::get_active() : $rules );

		return $rule ? $rule['category'] : null;
	}

	/**
	 * Erste passende Regel für einen Text (Reihenfolge der gespeicherten Regeln).
	 * "contains": Teilstring, "word": ganzes Wort (Grenze = kein Buchstabe/keine Ziffer davor/danach).
	 *
	 * @param string  $text  Text.
	 * @param array[] $rules Aktive Regeln.
	 * @return array|null Regel oder null.
	 */
	public static function find_matching_rule( $text, array $rules ) {
		foreach ( $rules as $rule ) {
			$term = (string) $rule['term'];
			if ( '' === $term ) {
				continue;
			}
			if ( 'word' === $rule['match'] ) {
				if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu', $text ) ) {
					return $rule;
				}
			} elseif ( false !== mb_stripos( $text, $term ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Ortsregel: Stadt muss in der Liste stehen; Stadtteilzusätze ("Nürnberg-Langwasser") und
	 * Umschreibungen ("Nuernberg") werden berücksichtigt. Leere Stadt = nicht erlaubt.
	 *
	 * @param string   $city    Stadt des Events.
	 * @param string[] $allowed Erlaubte Städte.
	 * @return bool
	 */
	public static function is_allowed_city( $city, array $allowed ) {
		$city = self::normalize_city( $city );
		if ( '' === $city ) {
			return false;
		}
		foreach ( $allowed as $allowed_city ) {
			$allowed_city = self::normalize_city( $allowed_city );
			if ( $city === $allowed_city || 0 === strpos( $city, $allowed_city . '-' ) || 0 === strpos( $city, $allowed_city . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $city Stadt.
	 * @return string
	 */
	private static function normalize_city( $city ) {
		$city = mb_strtolower( trim( (string) $city ) );
		return str_replace( array( 'ä', 'ö', 'ü', 'ß' ), array( 'ae', 'oe', 'ue', 'ss' ), $city );
	}
}
