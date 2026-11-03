<?php
/**
 * Sitemap-Anbieter für die WordPress-Kern-Sitemap (wp-sitemap-eventcrawler-1.xml).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Listet nur Detailseiten indexierbarer Termine ab „heute minus N Tage“ (docs/12-seo.md Abschnitt 7). Keine Listen-
 * oder Filter-URLs. Abfrage nur slug/updated_at über den Index start_date, seitenweise wie der Kern.
 * Wird nur geladen, wenn Detailseiten und Sitemap eingeschaltet sind (Eventcrawler_Seo::register_sitemap()).
 */
class Eventcrawler_Sitemap_Provider extends WP_Sitemaps_Provider {

	const NAME = 'eventcrawler';

	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = 'eventcrawler_event';
	}

	/**
	 * @param int    $page_num       Seite (1-basiert).
	 * @param string $object_subtype Nicht verwendet.
	 * @return array[] loc, lastmod.
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$rows = ( new Eventcrawler_Repository() )->get_sitemap_rows( Eventcrawler_Seo::sitemap_from(), wp_sitemaps_get_max_urls( $this->object_type ), $page_num );
		$list = array();
		foreach ( $rows as $row ) {
			$entry = array( 'loc' => Eventcrawler_Seo::url( $row ) );
			$date  = date_create_immutable( (string) $row['updated_at'], wp_timezone() );
			if ( $date ) {
				$entry['lastmod'] = $date->format( DATE_W3C );
			}
			$list[] = $entry;
		}
		return $list;
	}

	/**
	 * @param string $object_subtype Nicht verwendet.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		$count = ( new Eventcrawler_Repository() )->count_sitemap_rows( Eventcrawler_Seo::sitemap_from() );
		return (int) ceil( $count / wp_sitemaps_get_max_urls( $this->object_type ) );
	}
}
