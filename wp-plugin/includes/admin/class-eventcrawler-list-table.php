<?php
/**
 * Veranstaltungsliste im Backend.
 *
 * WP_List_Table ist offiziell keine öffentliche API, wird im Admin aber verbreitet genutzt.
 * Die Nutzung ist bewusst minimal gehalten, damit ein späterer Ersatz einfach bleibt.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Tabelle der Veranstaltungen (docs/06-admin.md).
 */
class Eventcrawler_List_Table extends WP_List_Table {

	const PER_PAGE = 50;

	/**
	 * @var Eventcrawler_Repository
	 */
	private $repository;

	/**
	 * Validierte Listenfilter (Eventcrawler_Admin::read_filters()).
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * @param Eventcrawler_Repository $repository Datenzugriff.
	 * @param array                   $filters    source, month, year, search.
	 */
	public function __construct( Eventcrawler_Repository $repository, array $filters = array() ) {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
				'ajax'     => false,
			)
		);
		$this->repository = $repository;
		$this->filters    = $filters;
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'start_date'   => 'Datum',
			'start_time'   => 'Uhrzeit',
			'title'        => 'Titel',
			'venue_name'   => 'Veranstaltungsort',
			'city'         => 'Stadt',
			'source'       => 'Quelle',
			'last_seen_at' => 'zuletzt geprüft',
			'actions'      => 'Aktionen',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'start_date'   => array( 'start_date', false ),
			'title'        => array( 'title', false ),
			'city'         => array( 'city', false ),
			'source'       => array( 'source', false ),
			'last_seen_at' => array( 'last_seen_at', false ),
		);
	}

	protected function get_bulk_actions() {
		return array( 'delete' => 'Löschen' );
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Sortierparameter, Whitelist im Repository.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'start_date';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		// phpcs:enable

		// Pagination- und Sortierlinks übernehmen die aktuelle URL, die Filter (GET) bleiben dadurch erhalten.
		$total = $this->repository->count_events( $this->filters );

		$this->items = $this->repository->get_events(
			array(
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => self::PER_PAGE,
				'page'     => $this->get_pagenum(),
				'filters'  => $this->filters,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items() {
		echo 'Keine Veranstaltungen vorhanden.';
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	protected function column_venue_name( $item ) {
		$html = esc_html( (string) $item['venue_name'] );
		if ( ! empty( $item['venue_address'] ) ) {
			$html .= '<br><small>' . esc_html( $item['venue_address'] ) . '</small>';
		}
		return $html;
	}

	protected function column_start_date( $item ) {
		return esc_html( mysql2date( 'd.m.Y', $item['start_date'] ) );
	}

	/**
	 * "18:00–22:00", "18:00", "Ganztägig" oder "—"; bei abweichendem Enddatum zusätzlich "(bis TT.MM.JJJJ)".
	 */
	protected function column_start_time( $item ) {
		if ( ! empty( $item['all_day'] ) ) {
			$text = 'Ganztägig';
		} elseif ( $item['start_time'] ) {
			$text = substr( $item['start_time'], 0, 5 );
			if ( $item['end_time'] ) {
				$text .= '–' . substr( $item['end_time'], 0, 5 );
			}
		} else {
			$text = '—';
		}

		if ( $item['end_date'] && $item['end_date'] !== $item['start_date'] ) {
			$text .= ' (bis ' . mysql2date( 'd.m.Y', $item['end_date'] ) . ')';
		}

		return esc_html( $text );
	}

	protected function column_title( $item ) {
		$output = '<strong>' . esc_html( $item['title'] ) . '</strong>';

		// Beschreibung ist Fremdinhalt: nur als gekürzter Klartext ausgeben.
		if ( ! empty( $item['description'] ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $item['description'] ), 20 );
			$output .= '<br><span class="description">' . esc_html( $excerpt ) . '</span>';
		}

		return $output;
	}

	protected function column_source( $item ) {
		// Anzeigename; der gespeicherte Slug bleibt unverändert.
		return esc_html( Eventcrawler_Crawler::get_source_name( $item['source'] ) );
	}

	protected function column_last_seen_at( $item ) {
		return esc_html( mysql2date( 'd.m.Y H:i', $item['last_seen_at'] ) );
	}

	protected function column_actions( $item ) {
		$id         = (int) $item['id'];
		$delete_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array_map( 'rawurlencode', Eventcrawler_Admin::list_query_args() ), // Ansicht nach dem Löschen erhalten
					array(
						'page'   => Eventcrawler_Admin::PAGE_SLUG,
						'action' => 'delete',
						'id'     => $id,
					)
				),
				admin_url( 'admin.php' )
			),
			'eventcrawler_delete_' . $id
		);

		$links = array(
			// Detail-Modal (assets/eventcrawler-admin.js): lädt die DB-Werte erst beim Klick per AJAX, hier nur die ID.
			sprintf(
				'<button type="button" class="button-link eventcrawler-details" data-event-id="%d" aria-haspopup="dialog">Details</button>',
				$id
			),
			sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">Löschen</a>',
				esc_url( $delete_url ),
				esc_js( 'Diese Veranstaltung wirklich löschen?' )
			),
		);

		if ( ! empty( $item['source_url'] ) ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">Quelle ansehen</a>',
				esc_url( $item['source_url'] )
			);
		}

		return implode( ' | ', $links );
	}
}
