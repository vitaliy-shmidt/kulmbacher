<?php
/**
 * Öffentliche Ausgabe: Veranstaltungsliste und Kalender als Shortcodes.
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ersatz für das Frontend von „WP Kulmbacher Land“ (docs/10-frontend.md Abschnitt 12).
 *
 * - [eventcrawler_events]: Liste ab einem Datum (Standard: heute) oder Tagesansicht, mit Suche, Stadt, Datum, Pagination.
 * - [eventcrawler_calendar]: Monatsraster, Tage verlinken auf die Liste (?date=JJJJ-MM-TT wie bei WPEM).
 * - [events_list] / [events_calendar]: Aliase für bestehende WPEM-Seiten, nur wenn WPEM die Shortcodes nicht selbst
 *   registriert (also nach dem Deaktivieren).
 *
 * Alles serverseitig über GET-Parameter (kein AJAX, kein JavaScript, keine Nonces nötig, da nur gelesen wird).
 * Datenquelle ausschließlich {prefix}eventcrawler_events über Eventcrawler_Repository.
 */
class Eventcrawler_Frontend {

	const PER_PAGE     = 20;
	const MAX_PER_PAGE = 100;

	/**
	 * GET-Parameter. "date" ist bewusst derselbe Name wie bei WPEM (bestehende Links auf Tagesansichten).
	 */
	const PARAM_DATE   = 'date';
	const PARAM_SEARCH = 'ec_search';
	const PARAM_CITY   = 'ec_city';
	const PARAM_PAGE   = 'ec_page';
	const PARAM_MONTH  = 'ec_month';

	/**
	 * Titel je Kalendertag, danach "+n weitere" (wie WPEM).
	 */
	const CALENDAR_TITLES = 3;

	/**
	 * Standardüberschriften der Bereiche (Attribut title="" blendet sie aus).
	 */
	const TITLE_EVENTS   = 'Veranstaltungen';
	const TITLE_CALENDAR = 'Veranstaltungskalender';

	/**
	 * @var Eventcrawler_Repository|null
	 */
	private static $repository;

	/**
	 * Zähler für eindeutige IDs, falls mehrere Shortcodes auf einer Seite stehen.
	 *
	 * @var int[]
	 */
	private static $ids = array();

	/**
	 * Steht auf der Seite schon ein eigenständiger Kalender (oberhalb der Liste), entfällt die Monatsübersicht der Liste,
	 * sonst erschiene der Kalender beim Blättern (ec_month) doppelt.
	 *
	 * @var bool
	 */
	private static $calendar_rendered = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Eigene Shortcodes immer; die WPEM-Namen nur, wenn sie frei sind (WPEM registriert sie beim Laden des Plugins,
	 * also vor init). Solange WPEM aktiv ist, bleibt dessen Ausgabe unverändert – Paralleltest möglich.
	 */
	public static function register_shortcodes() {
		add_shortcode( 'eventcrawler_events', array( __CLASS__, 'shortcode_events' ) );
		add_shortcode( 'eventcrawler_calendar', array( __CLASS__, 'shortcode_calendar' ) );

		if ( ! shortcode_exists( 'events_list' ) ) {
			add_shortcode( 'events_list', array( __CLASS__, 'shortcode_wpem_events_list' ) );
		}
		if ( ! shortcode_exists( 'events_calendar' ) ) {
			add_shortcode( 'events_calendar', array( __CLASS__, 'shortcode_wpem_events_calendar' ) );
		}
	}

	/**
	 * Zustand pro Seitenaufruf zurücksetzen (für Tests, die mehrere Seiten in einem Prozess rendern).
	 */
	public static function reset_page_state() {
		self::$ids               = array();
		self::$calendar_rendered = false;
	}

	public static function register_assets() {
		wp_register_style( 'eventcrawler-frontend', plugins_url( 'assets/eventcrawler-frontend.css', EVENTCRAWLER_PATH . 'eventcrawler.php' ), array(), EVENTCRAWLER_VERSION );
	}

	/**
	 * [eventcrawler_events view="list|day" per_page="20" sources="manual,coburg" city="Kulmbach" show_filters="1" show_calendar="1" title="Veranstaltungen"]
	 *
	 * @param array|string $atts Attribute.
	 * @return string HTML.
	 */
	public static function shortcode_events( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'          => 'list',
				'per_page'      => self::PER_PAGE,
				'sources'       => '',
				'city'          => '',
				'date'          => '',
				'show_filters'  => '1',
				'show_calendar' => '1',
				'title'         => self::TITLE_EVENTS,
			),
			$atts,
			'eventcrawler_events'
		);
		return self::render_events( $atts );
	}

	/**
	 * Alias [events_list limit="20" date="JJJJ-MM-TT" venue="…" show_past="…"]: Tagesansicht wie bei WPEM.
	 * "venue" (WPEM-Ortsslug) und "show_past" (in WPEM ohne Wirkung) werden nicht ausgewertet.
	 *
	 * @param array|string $atts Attribute.
	 * @return string HTML.
	 */
	public static function shortcode_wpem_events_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'     => self::PER_PAGE,
				'date'      => '',
				'venue'     => '',
				'show_past' => '',
			),
			$atts,
			'events_list'
		);
		return self::render_events(
			array(
				'view'          => 'day',
				'per_page'      => $atts['limit'],
				'sources'       => '',
				'city'          => '',
				'date'          => $atts['date'],
				'show_filters'  => '1',
				'show_calendar' => '1',
				'title'         => self::TITLE_EVENTS,
			)
		);
	}

	/**
	 * [eventcrawler_calendar month="11" year="2026" sources="…" link="https://…/veranstaltungen/" title="Veranstaltungskalender"]
	 *
	 * @param array|string $atts Attribute.
	 * @return string HTML.
	 */
	public static function shortcode_calendar( $atts ) {
		$atts = shortcode_atts(
			array(
				'month'   => '',
				'year'    => '',
				'sources' => '',
				'link'    => '',
				'title'   => self::TITLE_CALENDAR,
			),
			$atts,
			'eventcrawler_calendar'
		);
		wp_enqueue_style( 'eventcrawler-frontend' );
		self::$calendar_rendered = true;
		return '<div class="eventcrawler eventcrawler-calendar-page">' . self::section_title( $atts['title'] )
			. self::render_calendar( $atts, self::parse_sources( $atts['sources'] ) ) . '</div>';
	}

	/**
	 * Alias [events_calendar month="" year=""].
	 *
	 * @param array|string $atts Attribute.
	 * @return string HTML.
	 */
	public static function shortcode_wpem_events_calendar( $atts ) {
		$atts = shortcode_atts(
			array(
				'month' => '',
				'year'  => '',
			),
			$atts,
			'events_calendar'
		);
		return self::shortcode_calendar( $atts );
	}

	/**
	 * Liste bzw. Tagesansicht.
	 *
	 * @param array $atts Bereinigte Attribute (siehe shortcode_events()).
	 * @return string HTML.
	 */
	private static function render_events( array $atts ) {
		wp_enqueue_style( 'eventcrawler-frontend' );

		$today    = current_time( 'Y-m-d' );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $atts['per_page'] ) ) );
		$sources  = self::parse_sources( $atts['sources'] );
		$search   = self::request_text( self::PARAM_SEARCH, 100 );
		$city     = '' !== trim( (string) $atts['city'] ) ? sanitize_text_field( $atts['city'] ) : self::request_text( self::PARAM_CITY, 100 );
		$date     = self::valid_date( self::request_text( self::PARAM_DATE, 10 ) );
		if ( null === $date ) {
			$date = self::valid_date( (string) $atts['date'] );
		}
		$page = max( 1, absint( self::request_text( self::PARAM_PAGE, 6 ) ) );

		// Eine Suche gilt wie bei WPEM über alle kommenden Tage, nicht nur über den angezeigten Tag.
		$view = ( 'day' === $atts['view'] && '' === $search ) ? 'day' : 'list';
		if ( 'day' === $view ) {
			$date    = null === $date ? $today : $date;
			$filters = array(
				'date_from' => $date,
				'date_to'   => $date,
			);
		} else {
			$filters = array( 'date_from' => null === $date ? $today : $date );
		}
		$filters['sources'] = $sources;
		$filters['city']    = $city;
		$filters['search']  = $search;

		$repository = self::repository();
		$total      = $repository->count_events( $filters );
		$pages      = max( 1, (int) ceil( $total / $per_page ) );
		$page       = min( $page, $pages );
		$events     = $total ? $repository->get_public_events( $filters, $per_page, $page ) : array();

		$filtered = '' !== $search || ( '' !== $city && '' === trim( (string) $atts['city'] ) );

		ob_start();
		echo '<div class="eventcrawler eventcrawler-events eventcrawler-events--' . esc_attr( $view ) . '">';
		echo self::section_title( $atts['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- section_title() escapt selbst.

		if ( '0' !== (string) $atts['show_filters'] ) {
			self::render_filter_form( $sources, $city, $search, 'list' === $view ? $date : null, '' !== trim( (string) $atts['city'] ), 'day' === $view ? $date : null );
		}

		if ( '0' !== (string) $atts['show_calendar'] && ! self::$calendar_rendered ) {
			$month_open = null !== self::valid_month( self::request_text( self::PARAM_MONTH, 7 ) );
			$base       = null === $date ? $today : $date;
			echo '<details class="eventcrawler-calendar-toggle"' . ( $month_open ? ' open' : '' ) . '>';
			echo '<summary class="eventcrawler-button eventcrawler-button--secondary">' . self::icon( 'calendar' ) . 'Monatsübersicht</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG.
			echo self::render_calendar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_calendar() escapt selbst.
				array(
					'month' => (int) substr( $base, 5, 2 ),
					'year'  => (int) substr( $base, 0, 4 ),
					'link'  => '',
				),
				$sources
			);
			echo '</details>';
		}

		if ( 'day' === $view ) {
			self::render_day_navigation( $date );
		}

		if ( ! $events ) {
			echo '<div class="eventcrawler-empty"><p>';
			if ( $filtered ) {
				echo esc_html( 'Für Ihre Suche wurden keine Veranstaltungen gefunden.' );
			} else {
				echo esc_html( 'day' === $view ? 'Für diesen Tag wurden keine Veranstaltungen gefunden.' : 'Für diesen Zeitraum wurden keine Veranstaltungen gefunden.' );
			}
			echo '</p>';
			if ( $filtered ) {
				printf( '<p><a href="%s">Filter zurücksetzen</a></p>', esc_url( self::reset_url() ) );
			}
			echo '</div>';
		} else {
			$label = 1 === $total ? '%d Veranstaltung' : '%d Veranstaltungen';
			if ( $filtered ) {
				$label .= ' gefunden';
			}
			printf( '<p class="eventcrawler-count">%s</p>', esc_html( sprintf( $label, $total ) ) );
			$current_day = null;
			foreach ( $events as $event ) {
				if ( $event['start_date'] !== $current_day ) {
					if ( null !== $current_day ) {
						echo '</ul>';
					}
					$current_day = $event['start_date'];
					if ( 'list' === $view ) {
						echo '<h3 class="eventcrawler-day-heading">' . esc_html( self::format_day( $current_day ) ) . '</h3>';
					}
					echo '<ul class="eventcrawler-list">';
				}
				self::render_event( $event );
			}
			echo '</ul>';
		}

		self::render_pagination( $page, $pages );
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Ein Termin als aufklappbarer Eintrag (natives <details>, ersetzt das Accordion von WPEM).
	 * Ausgegeben werden nur öffentliche Felder; interne Spalten (external_id, event_key, content_hash, source_url,
	 * Zeitstempel) werden gar nicht erst abgefragt.
	 *
	 * @param array $event Zeile aus get_public_events().
	 */
	private static function render_event( array $event ) {
		$time    = self::format_time( $event );
		$url     = self::public_url( $event['event_url'] );
		$venue   = trim( (string) $event['venue_name'] );
		$address = self::join( array( $event['venue_address'], $event['city'] ) );
		// Ort = Stadt (z. B. „Kulmbach“, „Kulmbach“) nur einmal zeigen.
		if ( '' !== $venue && mb_strtolower( $venue ) === mb_strtolower( trim( (string) $event['city'] ) ) ) {
			$venue = '';
		}
		$datetime = $event['start_date'] . ( empty( $event['all_day'] ) && ! empty( $event['start_time'] ) ? 'T' . substr( $event['start_time'], 0, 5 ) : '' );

		/**
		 * Beschreibung öffentlich zeigen? Standard: nur bei manuellen (eigenen) Terminen; für gecrawlte Fremdtexte ist die
		 * Freigabe offen (docs/10-frontend.md E9).
		 *
		 * @param bool  $show  Anzeigen.
		 * @param array $event Termin.
		 */
		$show_description = (bool) apply_filters( 'eventcrawler_frontend_show_description', Eventcrawler_Manual_Events::SOURCE === $event['source'], $event );
		$description      = $show_description ? trim( (string) $event['description'] ) : '';
		?>
		<li class="eventcrawler-event" id="eventcrawler-event-<?php echo (int) $event['id']; ?>">
			<?php if ( '' !== $time ) : ?>
				<p class="eventcrawler-event__time"><time datetime="<?php echo esc_attr( $datetime ); ?>"><?php echo esc_html( $time ); ?></time></p>
			<?php endif; ?>
			<div class="eventcrawler-event__body">
				<h4 class="eventcrawler-event__title"><?php echo esc_html( $event['title'] ); ?></h4>
				<?php if ( '' !== $venue ) : ?>
					<p class="eventcrawler-event__venue"><?php echo esc_html( $venue ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $address ) : ?>
					<p class="eventcrawler-event__address"><?php echo esc_html( $address ); ?></p>
				<?php endif; ?>
				<details class="eventcrawler-event__more">
					<summary class="eventcrawler-event__toggle">
						<span class="eventcrawler-event__toggle-open">Details anzeigen</span>
						<span class="eventcrawler-event__toggle-close">Details schließen</span>
						<?php echo self::icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG. ?>
					</summary>
					<div class="eventcrawler-event__details">
						<dl class="eventcrawler-event__facts">
							<div class="eventcrawler-event__fact">
								<dt>Datum</dt>
								<dd><?php echo esc_html( self::format_date_range( $event ) ); ?></dd>
							</div>
							<?php if ( '' !== $time ) : ?>
								<div class="eventcrawler-event__fact">
									<dt>Uhrzeit</dt>
									<dd><?php echo esc_html( $time ); ?></dd>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $event['venue_name'] ) ) : ?>
								<div class="eventcrawler-event__fact">
									<dt>Ort</dt>
									<dd><?php echo esc_html( $event['venue_name'] ); ?></dd>
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
							<div class="eventcrawler-event__description"><?php echo nl2br( esc_html( $description ) ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== $url || self::can_edit( $event ) ) : ?>
							<p class="eventcrawler-event__links">
								<?php if ( '' !== $url ) : ?>
									<a class="eventcrawler-button eventcrawler-button--secondary" href="<?php echo esc_url( $url, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer">Zur Veranstaltungsseite<?php echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG. ?><span class="eventcrawler-sr-only"> (öffnet in neuem Fenster)</span></a>
								<?php endif; ?>
								<?php if ( self::can_edit( $event ) ) : ?>
									<a class="eventcrawler-event__edit" href="<?php echo esc_url( Eventcrawler_Manual_Events::edit_url( (int) $event['id'] ) ); ?>">Bearbeiten</a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>
				</details>
			</div>
		</li>
		<?php
	}

	/**
	 * Monatsraster. Tage mit Terminen verlinken auf die Liste (?date=), die ersten Titel auf den Termin (#Anker).
	 *
	 * @param array    $atts    month, year, link.
	 * @param string[] $sources Quellenauswahl.
	 * @return string HTML.
	 */
	private static function render_calendar( array $atts, array $sources ) {
		$requested = self::valid_month( self::request_text( self::PARAM_MONTH, 7 ) );
		if ( null !== $requested ) {
			list( $year, $month ) = array_map( 'intval', explode( '-', $requested ) );
		} else {
			$year  = (int) $atts['year'];
			$month = (int) $atts['month'];
			if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
				$year  = (int) current_time( 'Y' );
				$month = (int) current_time( 'n' );
			}
		}

		$first = new DateTime( sprintf( '%04d-%02d-01', $year, $month ) );
		$last  = ( clone $first )->modify( 'last day of this month' );
		$prev  = ( clone $first )->modify( '-1 month' );
		$next  = ( clone $first )->modify( '+1 month' );

		$by_day = array();
		$rows   = self::repository()->get_calendar_events(
			array(
				'date_from' => $first->format( 'Y-m-d' ),
				'date_to'   => $last->format( 'Y-m-d' ),
				'sources'   => $sources,
			)
		);
		foreach ( $rows as $row ) {
			$by_day[ $row['start_date'] ][] = $row;
		}

		$link       = self::public_url( (string) $atts['link'] );
		$today      = current_time( 'Y-m-d' );
		$selected   = self::valid_date( self::request_text( self::PARAM_DATE, 10 ) );
		$month_name = Eventcrawler_Exporter::MONTHS[ $month ] . ' ' . $year;
		$weekdays   = array( 'Mo' => 'Montag', 'Di' => 'Dienstag', 'Mi' => 'Mittwoch', 'Do' => 'Donnerstag', 'Fr' => 'Freitag', 'Sa' => 'Samstag', 'So' => 'Sonntag' );

		ob_start();
		?>
		<div class="eventcrawler-calendar">
			<div class="eventcrawler-calendar__header">
				<a class="eventcrawler-icon-button eventcrawler-calendar__nav eventcrawler-calendar__nav--prev" href="<?php echo esc_url( self::url( array( self::PARAM_MONTH => $prev->format( 'Y-m' ) ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow" aria-label="<?php echo esc_attr( 'Vorheriger Monat: ' . Eventcrawler_Exporter::MONTHS[ (int) $prev->format( 'n' ) ] . ' ' . $prev->format( 'Y' ) ); ?>"><?php echo self::icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG. ?></a>
				<h3 class="eventcrawler-calendar__title"><?php echo esc_html( $month_name ); ?></h3>
				<a class="eventcrawler-icon-button eventcrawler-calendar__nav eventcrawler-calendar__nav--next" href="<?php echo esc_url( self::url( array( self::PARAM_MONTH => $next->format( 'Y-m' ) ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow" aria-label="<?php echo esc_attr( 'Nächster Monat: ' . Eventcrawler_Exporter::MONTHS[ (int) $next->format( 'n' ) ] . ' ' . $next->format( 'Y' ) ); ?>"><?php echo self::icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG. ?></a>
			</div>
			<table class="eventcrawler-calendar__grid">
				<caption class="eventcrawler-sr-only"><?php echo esc_html( 'Veranstaltungen im ' . $month_name ); ?></caption>
				<thead>
					<tr>
						<?php foreach ( $weekdays as $short => $long ) : ?>
							<th scope="col"><abbr title="<?php echo esc_attr( $long ); ?>"><?php echo esc_html( $short ); ?></abbr></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<tr>
					<?php
					// Tage des Vor- und Folgemonats nur als graue Zahl (ohne Termine, ohne Link).
					$offset    = (int) $first->format( 'N' ) - 1;
					$prev_days = (int) ( clone $first )->modify( '-1 day' )->format( 'j' );
					for ( $i = 0; $i < $offset; $i++ ) {
						echo '<td class="eventcrawler-calendar__day is-outside"><span class="eventcrawler-calendar__other" aria-hidden="true">' . (int) ( $prev_days - $offset + 1 + $i ) . '</span></td>';
					}
					$days = (int) $last->format( 'j' );
					for ( $day = 1; $day <= $days; $day++ ) {
						$ymd     = sprintf( '%04d-%02d-%02d', $year, $month, $day );
						$events  = isset( $by_day[ $ymd ] ) ? $by_day[ $ymd ] : array();
						$classes = array( 'eventcrawler-calendar__day' );
						$state   = '';
						if ( $ymd === $today ) {
							$classes[] = 'is-today';
							$state    .= ', heute';
						}
						if ( $ymd === $selected ) {
							$classes[] = 'is-selected';
							$state    .= ', ausgewählt';
						}
						if ( $events ) {
							$classes[] = 'has-events';
						}
						if ( 0 === ( $offset + $day - 1 ) % 7 && 1 !== $day ) {
							echo '</tr><tr>';
						}
						echo '<td class="' . esc_attr( implode( ' ', $classes ) ) . '"' . ( $ymd === $today ? ' aria-current="date"' : '' ) . '>';
						if ( $events ) {
							$day_url = self::day_url( $link, $ymd );
							echo '<div class="eventcrawler-calendar__day-head">';
							printf(
								'<a class="eventcrawler-calendar__number" href="%1$s" aria-label="%2$s">%3$d</a>',
								esc_url( $day_url ),
								esc_attr( sprintf( '%s%s: %s', self::format_day( $ymd ), $state, self::count_label( count( $events ) ) ) ),
								(int) $day
							);
							echo '<span class="eventcrawler-calendar__count" aria-hidden="true">' . (int) count( $events ) . '</span>';
							echo '</div>';
							self::render_calendar_events( $events, $day_url, $ymd );
						} else {
							echo '<div class="eventcrawler-calendar__day-head"><span class="eventcrawler-calendar__number">' . (int) $day . '</span></div>';
						}
						echo '</td>';
					}
					$rest = ( 7 - ( $offset + $days ) % 7 ) % 7;
					for ( $i = 1; $i <= $rest; $i++ ) {
						echo '<td class="eventcrawler-calendar__day is-outside"><span class="eventcrawler-calendar__other" aria-hidden="true">' . (int) $i . '</span></td>';
					}
					?>
					</tr>
				</tbody>
			</table>
			<?php
			// Smartphone: Agenda statt Mini-Raster (CSS blendet je nach Breite eines von beiden aus).
			$past   = array();
			$coming = array();
			foreach ( $by_day as $ymd => $events ) {
				if ( $ymd < $today && substr( $today, 0, 7 ) === $first->format( 'Y-m' ) ) {
					$past[ $ymd ] = $events;
				} else {
					$coming[ $ymd ] = $events;
				}
			}
			?>
			<div class="eventcrawler-calendar__agenda">
				<?php if ( ! $by_day ) : ?>
					<p class="eventcrawler-calendar__agenda-empty"><?php echo esc_html( 'Im ' . $month_name . ' sind keine Veranstaltungen eingetragen.' ); ?></p>
				<?php endif; ?>
				<?php if ( $past ) : ?>
					<details class="eventcrawler-calendar__agenda-past">
						<summary><?php echo esc_html( sprintf( 'Vergangene Tage (%d)', count( $past ) ) ); ?></summary>
						<?php self::render_agenda( $past, $link, $today, $selected ); ?>
					</details>
				<?php endif; ?>
				<?php if ( $coming ) : ?>
					<?php self::render_agenda( $coming, $link, $today, $selected ); ?>
				<?php elseif ( $past ) : ?>
					<p class="eventcrawler-calendar__agenda-empty"><?php echo esc_html( 'Für den Rest des Monats sind keine Veranstaltungen eingetragen.' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Die ersten Termine eines Kalendertags, danach "+n weitere" (Link auf den Tag).
	 *
	 * @param array  $events  Termine des Tages.
	 * @param string $day_url Link auf die Tagesliste.
	 * @param string $ymd     Tag.
	 */
	private static function render_calendar_events( array $events, $day_url, $ymd ) {
		echo '<ul class="eventcrawler-calendar__events">';
		foreach ( array_slice( $events, 0, self::CALENDAR_TITLES ) as $event ) {
			printf(
				'<li><a class="eventcrawler-calendar__event" href="%1$s"><span class="eventcrawler-calendar__event-text">%2$s<span class="eventcrawler-calendar__event-title">%3$s</span></span></a></li>',
				esc_url( $day_url . '#eventcrawler-event-' . (int) $event['id'] ),
				empty( $event['start_time'] ) || ! empty( $event['all_day'] ) ? '' : '<span class="eventcrawler-calendar__time">' . esc_html( substr( $event['start_time'], 0, 5 ) ) . '</span> ',
				esc_html( $event['title'] )
			);
		}
		if ( count( $events ) > self::CALENDAR_TITLES ) {
			printf(
				'<li class="eventcrawler-calendar__more"><a href="%1$s" aria-label="%2$s">+%3$d weitere</a></li>',
				esc_url( $day_url ),
				esc_attr( sprintf( 'Alle %d Veranstaltungen am %s anzeigen', count( $events ), self::format_day( $ymd ) ) ),
				count( $events ) - self::CALENDAR_TITLES
			);
		}
		echo '</ul>';
	}

	/**
	 * Agenda (mobil): je Tag mit Terminen Datum, die ersten Termine und "+n weitere".
	 *
	 * @param array       $by_day   Tag => Termine.
	 * @param string      $link     Ziel der Tageslinks.
	 * @param string      $today    Heute.
	 * @param string|null $selected Ausgewählter Tag.
	 */
	private static function render_agenda( array $by_day, $link, $today, $selected ) {
		echo '<ol class="eventcrawler-calendar__agenda-days">';
		foreach ( $by_day as $ymd => $events ) {
			$classes = array( 'eventcrawler-calendar__agenda-day' );
			if ( $ymd === $today ) {
				$classes[] = 'is-today';
			}
			if ( $ymd === $selected ) {
				$classes[] = 'is-selected';
			}
			$day_url = self::day_url( $link, $ymd );
			$date    = DateTime::createFromFormat( '!Y-m-d', $ymd );
			printf(
				'<li class="%1$s"><p class="eventcrawler-calendar__agenda-head"><a class="eventcrawler-calendar__agenda-date" href="%2$s">%3$s</a>%4$s<span class="eventcrawler-calendar__agenda-count">%5$s</span></p>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $day_url ),
				esc_html( Eventcrawler_Exporter::WEEKDAYS[ (int) $date->format( 'N' ) ] . ', ' . $date->format( 'j' ) . '. ' . Eventcrawler_Exporter::MONTHS[ (int) $date->format( 'n' ) ] ),
				$ymd === $today ? ' <span class="eventcrawler-calendar__agenda-badge">heute</span>' : '',
				esc_html( self::count_label( count( $events ) ) )
			);
			self::render_calendar_events( $events, $day_url, $ymd );
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Suchfeld, Stadt, Datum (GET-Formular auf dieselbe Seite).
	 *
	 * @param string[]    $sources    Quellenauswahl (für die Stadtliste).
	 * @param string      $city       Gewählte Stadt.
	 * @param string      $search     Suchbegriff.
	 * @param string|null $date       "ab Datum" in der Listenansicht, sonst null (Feld ausgeblendet).
	 * @param bool        $city_fixed Stadt ist per Attribut fest vorgegeben.
	 * @param string|null $day        Tagesansicht: angezeigter Tag bleibt beim Absenden erhalten.
	 */
	private static function render_filter_form( array $sources, $city, $search, $date, $city_fixed, $day = null ) {
		$cities = $city_fixed ? array() : self::repository()->get_cities(
			array(
				'date_from' => current_time( 'Y-m-d' ),
				'sources'   => $sources,
			)
		);
		$action = strtok( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nur Pfad, Ausgabe über esc_url().
		$id_search = self::uid( 'eventcrawler-search' );
		$id_city   = self::uid( 'eventcrawler-city' );
		$id_date   = self::uid( 'eventcrawler-date' );
		?>
		<form class="eventcrawler-filter" method="get" action="<?php echo esc_url( $action ); ?>" role="search" aria-label="Veranstaltungen filtern">
			<?php self::render_hidden_query_args(); ?>
			<?php if ( null !== $day ) : ?>
				<input type="hidden" name="<?php echo esc_attr( self::PARAM_DATE ); ?>" value="<?php echo esc_attr( $day ); ?>">
			<?php endif; ?>
			<div class="eventcrawler-filter__field eventcrawler-filter__field--search">
				<label class="eventcrawler-filter__label" for="<?php echo esc_attr( $id_search ); ?>">Suche</label>
				<input class="eventcrawler-input" type="search" id="<?php echo esc_attr( $id_search ); ?>" name="<?php echo esc_attr( self::PARAM_SEARCH ); ?>" value="<?php echo esc_attr( $search ); ?>" maxlength="100" placeholder="Titel, Ort oder Stadt …">
			</div>
			<?php if ( ! $city_fixed ) : ?>
				<div class="eventcrawler-filter__field">
					<label class="eventcrawler-filter__label" for="<?php echo esc_attr( $id_city ); ?>">Stadt</label>
					<select class="eventcrawler-input eventcrawler-select" id="<?php echo esc_attr( $id_city ); ?>" name="<?php echo esc_attr( self::PARAM_CITY ); ?>">
						<option value="">Alle Städte</option>
						<?php foreach ( $cities as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"<?php selected( $city, $option ); ?>><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
			<?php if ( null !== $date || '' !== $search ) : ?>
				<div class="eventcrawler-filter__field eventcrawler-filter__field--date">
					<label class="eventcrawler-filter__label" for="<?php echo esc_attr( $id_date ); ?>">Ab Datum</label>
					<input class="eventcrawler-input" type="date" id="<?php echo esc_attr( $id_date ); ?>" name="<?php echo esc_attr( self::PARAM_DATE ); ?>" value="<?php echo esc_attr( (string) $date ); ?>">
				</div>
			<?php endif; ?>
			<div class="eventcrawler-filter__actions">
				<button class="eventcrawler-button eventcrawler-button--primary" type="submit">Anzeigen</button>
				<a class="eventcrawler-button eventcrawler-button--ghost" href="<?php echo esc_url( self::reset_url() ); ?>">Zurücksetzen</a>
			</div>
		</form>
		<?php
	}

	/**
	 * Fremde Query-Parameter der Seite (z. B. page_id bei einfachen Permalinks) bleiben beim Absenden erhalten.
	 */
	private static function render_hidden_query_args() {
		$own = array( self::PARAM_SEARCH, self::PARAM_CITY, self::PARAM_DATE, self::PARAM_PAGE, self::PARAM_MONTH );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesende Ansicht.
		foreach ( $_GET as $key => $value ) {
			if ( in_array( $key, $own, true ) || ! is_scalar( $value ) ) {
				continue;
			}
			printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( sanitize_key( $key ) ), esc_attr( sanitize_text_field( wp_unslash( (string) $value ) ) ) );
		}
	}

	/**
	 * @param string $date Y-m-d.
	 */
	private static function render_day_navigation( $date ) {
		$day  = new DateTime( $date );
		$prev = ( clone $day )->modify( '-1 day' )->format( 'Y-m-d' );
		$next = ( clone $day )->modify( '+1 day' )->format( 'Y-m-d' );
		?>
		<nav class="eventcrawler-day-nav" aria-label="Tagesnavigation">
			<h3 class="eventcrawler-day-nav__current"><?php echo esc_html( self::format_day( $date ) ); ?></h3>
			<a class="eventcrawler-button eventcrawler-button--secondary eventcrawler-day-nav__prev" href="<?php echo esc_url( self::url( array( self::PARAM_DATE => $prev ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow"><?php echo self::icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG. ?>Vorheriger Tag</a>
			<a class="eventcrawler-button eventcrawler-button--secondary eventcrawler-day-nav__next" href="<?php echo esc_url( self::url( array( self::PARAM_DATE => $next ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow">Nächster Tag<?php echo self::icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG. ?></a>
		</nav>
		<?php
	}

	/**
	 * "← Zurück 1 … 4 5 6 … 20 Weiter →"; auf schmalen Bildschirmen statt der Zahlen "Seite 5 von 20".
	 *
	 * @param int $page  Aktuelle Seite.
	 * @param int $pages Seitenanzahl.
	 */
	private static function render_pagination( $page, $pages ) {
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="eventcrawler-pagination" aria-label="Seiten">';
		if ( $page > 1 ) {
			printf( '<a class="eventcrawler-button eventcrawler-button--secondary eventcrawler-pagination__prev" href="%1$s">%2$sZurück</a>', esc_url( self::url( array( self::PARAM_PAGE => $page - 1 ) ) ), self::icon( 'chevron-left' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG.
		}
		echo '<ul class="eventcrawler-pagination__pages">';
		$shown = array_unique( array_filter( array( 1, $page - 1, $page, $page + 1, $pages ), function ( $n ) use ( $pages ) {
			return $n >= 1 && $n <= $pages;
		} ) );
		sort( $shown );
		$before = 0;
		foreach ( $shown as $n ) {
			if ( $n - $before > 1 ) {
				echo '<li class="eventcrawler-pagination__gap" aria-hidden="true">…</li>';
			}
			if ( $n === $page ) {
				printf( '<li><span class="eventcrawler-pagination__page is-current" aria-current="page"><span class="eventcrawler-sr-only">Seite </span>%d</span></li>', (int) $n );
			} else {
				printf( '<li><a class="eventcrawler-pagination__page" href="%1$s"><span class="eventcrawler-sr-only">Seite </span>%2$d</a></li>', esc_url( self::url( array( self::PARAM_PAGE => $n ) ) ), (int) $n );
			}
			$before = $n;
		}
		echo '</ul>';
		printf( '<span class="eventcrawler-pagination__status">Seite %1$d von %2$d</span>', (int) $page, (int) $pages );
		if ( $page < $pages ) {
			printf( '<a class="eventcrawler-button eventcrawler-button--secondary eventcrawler-pagination__next" href="%1$s">Weiter%2$s</a>', esc_url( self::url( array( self::PARAM_PAGE => $page + 1 ) ) ), self::icon( 'chevron-right' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- festes SVG.
		}
		echo '</nav>';
	}

	/**
	 * "18:30 Uhr", "18:30–22:00 Uhr", "ganztägig" oder "" (keine Uhrzeit bekannt – nie "00:00").
	 *
	 * @param array $event Termin.
	 * @return string
	 */
	public static function format_time( array $event ) {
		if ( ! empty( $event['all_day'] ) ) {
			return 'ganztägig';
		}
		if ( empty( $event['start_time'] ) ) {
			return '';
		}
		$text = substr( $event['start_time'], 0, 5 );
		// Endzeit nur am selben Tag hier; ein späteres Enddatum steht beim Datum.
		if ( ! empty( $event['end_time'] ) && ( empty( $event['end_date'] ) || $event['end_date'] === $event['start_date'] ) ) {
			$text .= '–' . substr( $event['end_time'], 0, 5 );
		}
		return $text . ' Uhr';
	}

	/**
	 * "Mittwoch, 28. Oktober 2026" bzw. "… bis Freitag, 30. Oktober 2026[, 17:00 Uhr]".
	 *
	 * @param array $event Termin.
	 * @return string
	 */
	public static function format_date_range( array $event ) {
		$text = self::format_day( $event['start_date'] );
		if ( ! empty( $event['end_date'] ) && $event['end_date'] !== $event['start_date'] ) {
			$text .= ' bis ' . self::format_day( $event['end_date'] );
			if ( empty( $event['all_day'] ) && ! empty( $event['end_time'] ) ) {
				$text .= ', ' . substr( $event['end_time'], 0, 5 ) . ' Uhr';
			}
		}
		return $text;
	}

	/**
	 * "2026-10-28" → "Mittwoch, 28. Oktober 2026" (feste deutsche Namen wie im Export).
	 *
	 * @param string $ymd Datum.
	 * @return string
	 */
	public static function format_day( $ymd ) {
		$date = DateTime::createFromFormat( '!Y-m-d', (string) $ymd );
		if ( ! $date ) {
			return (string) $ymd;
		}
		return Eventcrawler_Exporter::WEEKDAYS[ (int) $date->format( 'N' ) ] . ', ' . $date->format( 'j' ) . '. '
			. Eventcrawler_Exporter::MONTHS[ (int) $date->format( 'n' ) ] . ' ' . $date->format( 'Y' );
	}

	/**
	 * Nur http(s)-URLs mit Host, sonst "" (z. B. javascript:, mailto:, relative Pfade).
	 *
	 * @param string|null $url URL.
	 * @return string
	 */
	public static function public_url( $url ) {
		$url = trim( (string) $url );
		return preg_match( '#^https?://[^/\s?\#]+#i', $url ) ? $url : '';
	}

	/**
	 * "sources"-Attribut → gültige Slugs (Register + manual). Unbekannte Slugs werden ignoriert; leer = alle Quellen.
	 * Ist kein einziger Slug gültig, wird eine unmögliche Auswahl gesetzt, damit nicht stillschweigend alles erscheint.
	 *
	 * @param string $value Kommagetrennt.
	 * @return string[]
	 */
	public static function parse_sources( $value ) {
		$requested = array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) );
		if ( ! $requested ) {
			return array();
		}
		$known = array_keys( Eventcrawler_Crawler::get_sources() );
		$known[] = Eventcrawler_Manual_Events::SOURCE;
		$valid = array_values( array_intersect( $requested, $known ) );
		return $valid ? $valid : array( '-' );
	}

	/**
	 * @param string $value Y-m-d.
	 * @return string|null
	 */
	public static function valid_date( $value ) {
		$date = DateTime::createFromFormat( '!Y-m-d', (string) $value );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value || (int) $date->format( 'Y' ) < 2000 || (int) $date->format( 'Y' ) > 2100 ) {
			return null;
		}
		return $value;
	}

	/**
	 * @param string $value Y-m.
	 * @return string|null
	 */
	private static function valid_month( $value ) {
		return preg_match( '/^(20\d\d|2100)-(0[1-9]|1[0-2])$/', (string) $value ) ? $value : null;
	}

	/**
	 * @param string $key GET-Parameter.
	 * @param int    $max Maximale Länge.
	 * @return string
	 */
	private static function request_text( $key, $max ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesende Ansicht.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}
		return mb_substr( trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) ), 0, $max ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Aktuelle URL mit geänderten Parametern.
	 *
	 * @param array    $set    Zu setzende Parameter.
	 * @param string[] $remove Zu entfernende Parameter.
	 * @return string
	 */
	private static function url( array $set, array $remove = array() ) {
		return add_query_arg( array_map( 'rawurlencode', $set ), remove_query_arg( $remove ) );
	}

	/**
	 * Link eines Kalendertags: auf die angegebene Listenseite oder die aktuelle Seite.
	 *
	 * @param string $link Ziel-URL (http/https) oder "".
	 * @param string $ymd  Tag.
	 * @return string
	 */
	private static function day_url( $link, $ymd ) {
		if ( '' !== $link ) {
			return add_query_arg( self::PARAM_DATE, $ymd, $link );
		}
		return self::url( array( self::PARAM_DATE => $ymd ), array( self::PARAM_PAGE, self::PARAM_SEARCH ) );
	}

	/**
	 * @param array $event Termin.
	 * @return bool
	 */
	private static function can_edit( array $event ) {
		return Eventcrawler_Manual_Events::SOURCE === $event['source'] && current_user_can( Eventcrawler_Manual_Events::CAPABILITY );
	}

	/**
	 * @param array $parts Teile (leere entfallen, doppelte nur einmal).
	 * @return string
	 */
	private static function join( array $parts ) {
		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && ! in_array( mb_strtolower( $part ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $part;
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Bereichsüberschrift (Attribut "title"; leer = keine).
	 *
	 * @param string $title Text.
	 * @return string HTML.
	 */
	private static function section_title( $title ) {
		$title = trim( sanitize_text_field( (string) $title ) );
		return '' === $title ? '' : '<h2 class="eventcrawler-section-title">' . esc_html( $title ) . '</h2>';
	}

	/**
	 * Aktuelle Seite ohne eigene Parameter.
	 *
	 * @return string
	 */
	private static function reset_url() {
		return self::url( array(), array( self::PARAM_SEARCH, self::PARAM_CITY, self::PARAM_DATE, self::PARAM_PAGE, self::PARAM_MONTH ) );
	}

	/**
	 * @param int $count Anzahl.
	 * @return string "1 Veranstaltung" / "n Veranstaltungen".
	 */
	private static function count_label( $count ) {
		return sprintf( 1 === (int) $count ? '%d Veranstaltung' : '%d Veranstaltungen', (int) $count );
	}

	/**
	 * Eindeutige HTML-ID; die erste Verwendung behält den Namen ohne Zusatz.
	 *
	 * @param string $base Name.
	 * @return string
	 */
	private static function uid( $base ) {
		self::$ids[ $base ] = isset( self::$ids[ $base ] ) ? self::$ids[ $base ] + 1 : 1;
		return 1 === self::$ids[ $base ] ? $base : $base . '-' . self::$ids[ $base ];
	}

	/**
	 * Feste Inline-Icons (dekorativ, aria-hidden), Strich in der Textfarbe.
	 *
	 * @param string $name chevron-left|chevron-right|chevron-down|external|calendar.
	 * @return string SVG.
	 */
	private static function icon( $name ) {
		$paths = array(
			'chevron-left'  => '<path d="M15 18l-6-6 6-6"/>',
			'chevron-right' => '<path d="M9 18l6-6-6-6"/>',
			'chevron-down'  => '<path d="M6 9l6 6 6-6"/>',
			'external'      => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
			'calendar'      => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="eventcrawler-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * @return Eventcrawler_Repository
	 */
	private static function repository() {
		if ( null === self::$repository ) {
			self::$repository = new Eventcrawler_Repository();
		}
		return self::$repository;
	}
}
