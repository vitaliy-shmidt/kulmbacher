<?php
/**
 * Deinstallation.
 *
 * Bewusst KEINE Datenlöschung: Tabelle {prefix}eventcrawler_events und Option eventcrawler_db_version
 * bleiben erhalten, damit kein überraschender Datenverlust entsteht (docs/02-datenbank.md).
 * Eine Option "Daten bei Deinstallation löschen" ist eine spätere Erweiterung.
 *
 * @package Eventcrawler
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
