<?php
/**
 * Plugin Name:       Eventcrawler
 * Description:       Crawlt Veranstaltungen externer Websites in eine eigene Tabelle und exportiert sie als XML (MVP).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       eventcrawler
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

define( 'EVENTCRAWLER_VERSION', '0.1.0' );
define( 'EVENTCRAWLER_DB_VERSION', '3' );
define( 'EVENTCRAWLER_PATH', plugin_dir_path( __FILE__ ) );

require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-installer.php';
require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-repository.php';
require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-filter.php';
require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-filter-rules.php';
require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-crawler.php';
require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-domain-mappings.php';
require_once EVENTCRAWLER_PATH . 'includes/class-eventcrawler-exporter.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/abstract-eventcrawler-source.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-burg-rabenstein.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-bad-steben.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-coburg.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-theater-selb.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-das-zentrum-bayreuth.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-schuetzenhaus-muenchberg.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-stadtsteinach.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-helmbrechts.php';
require_once EVENTCRAWLER_PATH . 'includes/sources/class-eventcrawler-source-kronach.php';

register_activation_hook( __FILE__, array( 'Eventcrawler_Installer', 'activate' ) );

// Tabelle aktualisieren, falls sich EVENTCRAWLER_DB_VERSION ohne erneute Aktivierung geändert hat.
add_action( 'plugins_loaded', array( 'Eventcrawler_Installer', 'maybe_upgrade' ) );

// Filterregeln einmalig mit den Code-Defaults anlegen, falls die Option noch nicht existiert (auch ohne Reaktivierung).
add_action( 'plugins_loaded', array( 'Eventcrawler_Filter_Rules', 'maybe_initialize' ) );

if ( is_admin() ) {
	require_once EVENTCRAWLER_PATH . 'includes/admin/class-eventcrawler-admin.php';
	require_once EVENTCRAWLER_PATH . 'includes/admin/class-eventcrawler-filter-admin.php';
	require_once EVENTCRAWLER_PATH . 'includes/admin/class-eventcrawler-domain-admin.php';
	Eventcrawler_Admin::init();
}
