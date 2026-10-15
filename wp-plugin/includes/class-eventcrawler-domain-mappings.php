<?php
/**
 * Link-Bezeichnungen für den XML-Export: Domain -> frei wählbarer Ausgabetext (WordPress-Option).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zuordnungen liegen in der Option "eventcrawler_export_domain_mappings" als Liste von
 * array( 'id' => string, 'domain' => string (Hostname, klein), 'label' => string, 'enabled' => bool ).
 *
 * Gilt global nach Domain (nicht je Quelle) und NUR für die sichtbare Ausgabe nach "Info:" im XML. Gespeicherte URLs,
 * Matching und Crawler bleiben unberührt (docs/07-export.md Abschnitt 13). Es gibt bewusst keine Default-Einträge.
 */
class Eventcrawler_Domain_Mappings {

	const OPTION = 'eventcrawler_export_domain_mappings';

	const MAX_LABEL_LENGTH  = 100;
	const MAX_DOMAIN_LENGTH = 253;

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
	 * Alle gültigen gespeicherten Zuordnungen. Ungültige Einträge werden ignoriert, eine beschädigte Option ergibt eine
	 * leere Liste (Export fällt dann auf die Domain zurück).
	 *
	 * @return array[]
	 */
	public static function get_all() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$mappings = array();
		foreach ( $raw as $mapping ) {
			if ( is_array( $mapping ) && ! empty( $mapping['id'] ) && ! empty( $mapping['domain'] ) && isset( $mapping['label'] ) && '' !== (string) $mapping['label'] ) {
				$mappings[] = array(
					'id'      => (string) $mapping['id'],
					'domain'  => (string) $mapping['domain'],
					'label'   => (string) $mapping['label'],
					'enabled' => ! empty( $mapping['enabled'] ),
				);
			}
		}
		return $mappings;
	}

	/**
	 * Aktive Zuordnungen als Lookup für den Export (einmal je Export laden).
	 *
	 * @return string[] Domain (klein) => Ausgabetext.
	 */
	public static function get_lookup() {
		$lookup = array();
		foreach ( self::get_all() as $mapping ) {
			if ( $mapping['enabled'] ) {
				$lookup[ $mapping['domain'] ] = $mapping['label'];
			}
		}
		return $lookup;
	}

	/**
	 * @param string $id Zuordnungs-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::get_all() as $mapping ) {
			if ( $mapping['id'] === $id ) {
				return $mapping;
			}
		}
		return null;
	}

	/**
	 * @param string $domain  Domain oder URL (roh).
	 * @param string $label   Ausgabetext (roh).
	 * @param bool   $enabled Status.
	 * @return string|WP_Error ID der neuen Zuordnung.
	 */
	public static function add( $domain, $label, $enabled = true ) {
		$valid = self::validate( $domain, $label, '' );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$id         = 'd' . substr( md5( wp_generate_uuid4() ), 0, 12 );
		$mappings   = self::get_all();
		$mappings[] = array(
			'id'      => $id,
			'domain'  => $valid['domain'],
			'label'   => $valid['label'],
			'enabled' => (bool) $enabled,
		);
		self::save( $mappings );
		return $id;
	}

	/**
	 * @param string $id      Zuordnungs-ID.
	 * @param string $domain  Domain oder URL (roh).
	 * @param string $label   Ausgabetext (roh).
	 * @param bool   $enabled Status.
	 * @return true|WP_Error
	 */
	public static function update( $id, $domain, $label, $enabled ) {
		if ( null === self::get( $id ) ) {
			return new WP_Error( 'eventcrawler_domain', 'Link-Bezeichnung nicht gefunden.' );
		}
		$valid = self::validate( $domain, $label, $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$mappings = self::get_all();
		foreach ( $mappings as $i => $mapping ) {
			if ( $mapping['id'] === $id ) {
				$mappings[ $i ] = array_merge( $mapping, $valid, array( 'enabled' => (bool) $enabled ) );
			}
		}
		self::save( $mappings );
		return true;
	}

	/**
	 * @param string $id Zuordnungs-ID.
	 * @return bool|null Neuer Status oder null, wenn nicht gefunden.
	 */
	public static function toggle( $id ) {
		$mappings = self::get_all();
		foreach ( $mappings as $i => $mapping ) {
			if ( $mapping['id'] === $id ) {
				$mappings[ $i ]['enabled'] = ! $mapping['enabled'];
				self::save( $mappings );
				return $mappings[ $i ]['enabled'];
			}
		}
		return null;
	}

	/**
	 * @param string $id Zuordnungs-ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		$mappings = self::get_all();
		$left     = array_values(
			array_filter(
				$mappings,
				function ( $mapping ) use ( $id ) {
					return $mapping['id'] !== $id;
				}
			)
		);
		if ( count( $left ) === count( $mappings ) ) {
			return false;
		}
		self::save( $left );
		return true;
	}

	/**
	 * Eingabe auf den Hostnamen reduzieren: "https://Pages.ET4.de/foo?x=1" -> "pages.et4.de", "www.example.de/pfad"
	 * -> "www.example.de". Protokoll, Port, Pfad, Query und Fragment entfallen; "www." bleibt (wie im Export,
	 * Eventcrawler_Exporter::display_url()). Ungültig: andere Schemes als http(s), "@", Zeichen außerhalb a-z, 0-9, "-", ".".
	 *
	 * @param string $input Domain oder URL.
	 * @return string Hostname in Kleinbuchstaben oder '' bei ungültiger Eingabe.
	 */
	public static function normalize_domain( $input ) {
		$input = trim( sanitize_text_field( (string) $input ) );
		// Kein "@" (E-Mail-Adresse, Zugangsdaten) und nur http(s) als Schema, sonst würde z. B. aus
		// "mailto:info@example.de" stillschweigend "example.de".
		if ( '' === $input || false !== strpos( $input, '@' ) ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $input ) ) {
			if ( ! preg_match( '#^https?://#i', $input ) ) {
				return '';
			}
		} else {
			$input = 'http://' . ltrim( $input, '/' );
		}
		$host = wp_parse_url( $input, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = rtrim( strtolower( $host ), '.' );
		$part = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
		if ( strlen( $host ) > self::MAX_DOMAIN_LENGTH || ! preg_match( '/^' . $part . '(?:\.' . $part . ')*$/', $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * Bereinigt und prüft Eingaben. Ausgabetext: HTML entfernt, Whitespace zusammengefasst, max. MAX_LABEL_LENGTH
	 * Zeichen, sonst freier Text (keine URL-Prüfung). Domain: nur einmal (Groß-/Kleinschreibung egal).
	 *
	 * @param string $domain     Domain oder URL (roh).
	 * @param string $label      Ausgabetext (roh).
	 * @param string $exclude_id Eigene ID beim Bearbeiten.
	 * @return array|WP_Error domain, label.
	 */
	private static function validate( $domain, $label, $exclude_id ) {
		if ( '' === trim( (string) $domain ) ) {
			return new WP_Error( 'eventcrawler_domain', 'Die Domain darf nicht leer sein.' );
		}
		$host = self::normalize_domain( $domain );
		if ( '' === $host ) {
			return new WP_Error( 'eventcrawler_domain', 'Ungültige Domain. Erlaubt sind z. B. „pages.et4.de“ oder eine vollständige URL.' );
		}
		$label = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $label ) ) );
		if ( '' === $label ) {
			return new WP_Error( 'eventcrawler_domain', 'Die Ausgabe im XML darf nicht leer sein (HTML wird entfernt).' );
		}
		if ( mb_strlen( $label ) > self::MAX_LABEL_LENGTH ) {
			return new WP_Error( 'eventcrawler_domain', 'Die Ausgabe im XML ist zu lang (höchstens ' . self::MAX_LABEL_LENGTH . ' Zeichen).' );
		}
		foreach ( self::get_all() as $mapping ) {
			if ( $mapping['id'] !== $exclude_id && strtolower( $mapping['domain'] ) === $host ) {
				return new WP_Error( 'eventcrawler_domain', 'Für die Domain „' . $host . '“ gibt es bereits eine Link-Bezeichnung.' );
			}
		}
		return array(
			'domain' => $host,
			'label'  => $label,
		);
	}

	/**
	 * @param array[] $mappings Zuordnungen.
	 */
	private static function save( array $mappings ) {
		update_option( self::OPTION, array_values( $mappings ), false );
	}
}
