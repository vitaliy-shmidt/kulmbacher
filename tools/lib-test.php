<?php
/**
 * NUR FÜR ENTWICKLUNG: gemeinsame Helfer der Testsuiten test-parallel.php, test-import.php, test-seo.php und
 * test-admin.php (seit 2026-11-03). Wird nicht mit dem Plugin ausgeliefert.
 *
 * - ec_check(): Vergleich mit Ausgabe "ok"/"FEHLER" wie in den älteren Suites
 * - ec_call(): ruft einen Admin-Handler auf und fängt wp_die() bzw. den abschließenden Redirect ab (statt exit)
 * - ec_user(): legt Testbenutzer an (nur innerhalb der Transaktion des Tests)
 *
 * @package Eventcrawler
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "Nur über die Kommandozeile ausführbar.\n" );
}

$GLOBALS['ec_passed'] = 0;
$GLOBALS['ec_failed'] = 0;

/**
 * Lädt WordPress (wp-load.php aus $argv[1]) und die Admin-Klassen des Plugins.
 *
 * @param string $script Name des Testskripts (für die Hilfe).
 */
function ec_bootstrap( $script ) {
	global $argv;
	if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) {
		fwrite( STDERR, "Aufruf: php {$script} <pfad/zu/wp-load.php>\n" );
		exit( 1 );
	}
	$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
	$_SERVER['REQUEST_URI'] = '/';
	require $argv[1];
	if ( ! class_exists( 'Eventcrawler_Settings' ) ) {
		fwrite( STDERR, "Plugin Eventcrawler ist nicht aktiv.\n" );
		exit( 1 );
	}
	require_once ABSPATH . 'wp-admin/includes/admin.php'; // submit_button(), WP_List_Table usw. wie im Backend
	foreach ( glob( EVENTCRAWLER_PATH . 'includes/admin/class-eventcrawler-*.php' ) as $file ) {
		if ( false === strpos( $file, 'list-table' ) ) {
			require_once $file;
		}
	}
}

/**
 * @param string $name     Testname.
 * @param mixed  $actual   Ergebnis.
 * @param mixed  $expected Erwartung.
 */
function ec_check( $name, $actual, $expected ) {
	if ( $actual === $expected ) {
		$GLOBALS['ec_passed']++;
		echo "  ok    {$name}\n";
		return;
	}
	$GLOBALS['ec_failed']++;
	echo "  FEHLER {$name}\n         erwartet: " . var_export( $expected, true ) . "\n         erhalten: " . var_export( $actual, true ) . "\n";
}

/**
 * Abbruch eines Handlers (wp_die oder Redirect) als Ausnahme.
 */
class Ec_Test_Stop extends Exception {
	/** @var string die|redirect|json */
	public $kind;
	/** @var int */
	public $status;
	/** @var string */
	public $target;

	public function __construct( $kind, $status, $target, $message = '' ) {
		parent::__construct( $message );
		$this->kind   = $kind;
		$this->status = (int) $status;
		$this->target = (string) $target;
	}
}

/**
 * Ruft $callback auf. Ergebnis: array( 'kind' => die|redirect|return, 'status' => HTTP-Status, 'target' => URL,
 * 'message' => Text ). wp_die() und wp_redirect() werfen dabei eine Ausnahme statt den Prozess zu beenden.
 *
 * @param callable $callback Handler.
 * @param array    $post     $_POST (wird mit wp_slash() gesetzt).
 * @param string   $method   REQUEST_METHOD.
 * @param array    $get      $_GET.
 * @return array
 */
function ec_call( $callback, array $post = array(), $method = 'POST', array $get = array() ) {
	$_POST                     = wp_slash( $post );
	$_REQUEST                  = array_merge( $get, $_POST );
	$_GET                      = $get;
	$_SERVER['REQUEST_METHOD'] = $method;

	$die = function () {
		return function ( $message, $title = '', $args = array() ) {
			$status = is_array( $args ) && isset( $args['response'] ) ? $args['response'] : 500;
			if ( is_wp_error( $message ) ) {
				$message = $message->get_error_message();
			}
			throw new Ec_Test_Stop( 'die', $status, '', wp_strip_all_tags( (string) $message ) );
		};
	};
	$redirect = function ( $location, $status ) {
		throw new Ec_Test_Stop( 'redirect', $status, $location );
	};
	add_filter( 'wp_die_handler', $die, 999 );
	add_filter( 'wp_die_ajax_handler', $die, 999 );
	add_filter( 'wp_redirect', $redirect, 999, 2 );

	$result = array(
		'kind'    => 'return',
		'status'  => 200,
		'target'  => '',
		'message' => '',
		'output'  => '',
	);
	ob_start();
	try {
		call_user_func( $callback );
	} catch ( Ec_Test_Stop $stop ) {
		$result['kind']    = $stop->kind;
		$result['status']  = $stop->status;
		$result['target']  = $stop->target;
		$result['message'] = $stop->getMessage();
	}
	$result['output'] = (string) ob_get_clean();

	remove_filter( 'wp_die_handler', $die, 999 );
	remove_filter( 'wp_die_ajax_handler', $die, 999 );
	remove_filter( 'wp_redirect', $redirect, 999 );
	$_POST    = array();
	$_GET     = array();
	$_REQUEST = array();
	return $result;
}

/**
 * Legt einen Benutzer mit Rolle an und meldet ihn an (nur innerhalb einer Transaktion verwenden).
 *
 * @param string $role administrator|editor|subscriber.
 * @return int User-ID.
 */
function ec_user( $role ) {
	static $users = array();
	if ( ! isset( $users[ $role ] ) ) {
		$users[ $role ] = wp_insert_user(
			array(
				'user_login' => 'ec_test_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => $role,
			)
		);
	}
	wp_set_current_user( $users[ $role ] );
	return $users[ $role ];
}

/**
 * Gibt die Zusammenfassung aus und beendet mit Exit-Code.
 */
function ec_finish() {
	echo "\n" . $GLOBALS['ec_passed'] . ' bestanden, ' . $GLOBALS['ec_failed'] . " fehlgeschlagen\n";
	exit( $GLOBALS['ec_failed'] ? 1 : 0 );
}
