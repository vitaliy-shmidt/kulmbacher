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
	const CALENDAR_TITLES = 2;

	/**
	 * @var Eventcrawler_Repository|null
	 */
	private static $repository;

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

	public static function register_assets() {
		wp_register_style( 'eventcrawler-frontend', plugins_url( 'assets/eventcrawler-frontend.css', EVENTCRAWLER_PATH . 'eventcrawler.php' ), array(), EVENTCRAWLER_VERSION );
	}

	/**
	 * [eventcrawler_events view="list|day" per_page="20" sources="manual,coburg" city="Kulmbach" show_filters="1" show_calendar="1"]
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
			)
		);
	}

	/**
	 * [eventcrawler_calendar month="11" year="2026" sources="…" link="https://…/veranstaltungen/"]
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
			),
			$atts,
			'eventcrawler_calendar'
		);
		wp_enqueue_style( 'eventcrawler-frontend' );
		return '<div class="eventcrawler eventcrawler-calendar-page">' . self::render_calendar( $atts, self::parse_sources( $atts['sources'] ) ) . '</div>';
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

		ob_start();
		echo '<div class="eventcrawler eventcrawler-events eventcrawler-events--' . esc_attr( $view ) . '">';

		if ( '0' !== (string) $atts['show_filters'] ) {
			self::render_filter_form( $sources, $city, $search, 'list' === $view ? $date : null, '' !== trim( (string) $atts['city'] ), 'day' === $view ? $date : null );
		}

		if ( 'day' === $view ) {
			self::render_day_navigation( $date );
		}

		if ( '0' !== (string) $atts['show_calendar'] ) {
			$month_open = null !== self::valid_month( self::request_text( self::PARAM_MONTH, 7 ) );
			$base       = null === $date ? $today : $date;
			echo '<details class="eventcrawler-calendar-toggle"' . ( $month_open ? ' open' : '' ) . '><summary>Monatsübersicht</summary>';
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

		if ( ! $events ) {
			echo '<p class="eventcrawler-empty">' . esc_html( 'day' === $view ? 'Keine Veranstaltungen an diesem Tag.' : 'Keine Veranstaltungen gefunden.' ) . '</p>';
		} else {
			if ( 'list' === $view ) {
				printf( '<p class="eventcrawler-count">%s</p>', esc_html( sprintf( 1 === $total ? '%d Veranstaltung' : '%d Veranstaltungen', $total ) ) );
			}
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
		$time  = self::format_time( $event );
		$place = self::join( array( $event['venue_name'], $event['city'] ) );
		$url   = self::public_url( $event['event_url'] );

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
			<details>
				<summary>
					<span class="eventcrawler-event__time"><?php echo esc_html( $time ); ?></span>
					<span class="eventcrawler-event__title"><?php echo esc_html( $event['title'] ); ?></span>
					<?php if ( '' !== $place ) : ?>
						<span class="eventcrawler-event__place"><?php echo esc_html( $place ); ?></span>
					<?php endif; ?>
				</summary>
				<div class="eventcrawler-event__details">
					<dl>
						<dt>Datum</dt>
						<dd><?php echo esc_html( self::format_date_range( $event ) ); ?></dd>
						<?php if ( '' !== $time ) : ?>
							<dt>Uhrzeit</dt>
							<dd><?php echo esc_html( $time ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $event['venue_name'] ) ) : ?>
							<dt>Ort</dt>
							<dd><?php echo esc_html( $event['venue_name'] ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $event['venue_address'] ) ) : ?>
							<dt>Adresse</dt>
							<dd><?php echo esc_html( $event['venue_address'] ); ?></dd>
						<?php endif; ?>
						<dt>Stadt</dt>
						<dd><?php echo esc_html( $event['city'] ); ?></dd>
					</dl>
					<?php if ( '' !== $description ) : ?>
						<div class="eventcrawler-event__description"><?php echo nl2br( esc_html( $description ) ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $url || self::can_edit( $event ) ) : ?>
						<p class="eventcrawler-event__links">
							<?php if ( '' !== $url ) : ?>
								<a href="<?php echo esc_url( $url, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer">Zur Webseite</a>
							<?php endif; ?>
							<?php if ( self::can_edit( $event ) ) : ?>
								<a class="eventcrawler-event__edit" href="<?php echo esc_url( Eventcrawler_Manual_Events::edit_url( (int) $event['id'] ) ); ?>">Bearbeiten</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</details>
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
		$prev  = ( clone $first )->modify( '-1 month' )->format( 'Y-m' );
		$next  = ( clone $first )->modify( '+1 month' )->format( 'Y-m' );

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

		$link  = self::public_url( (string) $atts['link'] );
		$today = current_time( 'Y-m-d' );

		ob_start();
		?>
		<div class="eventcrawler-calendar">
			<div class="eventcrawler-calendar__header">
				<a class="eventcrawler-calendar__nav" href="<?php echo esc_url( self::url( array( self::PARAM_MONTH => $prev ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow" aria-label="Vorheriger Monat">‹</a>
				<span class="eventcrawler-calendar__title"><?php echo esc_html( Eventcrawler_Exporter::MONTHS[ $month ] . ' ' . $year ); ?></span>
				<a class="eventcrawler-calendar__nav" href="<?php echo esc_url( self::url( array( self::PARAM_MONTH => $next ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow" aria-label="Nächster Monat">›</a>
			</div>
			<table class="eventcrawler-calendar__grid">
				<thead>
					<tr>
						<?php foreach ( array( 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' ) as $weekday ) : ?>
							<th scope="col"><?php echo esc_html( $weekday ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<tr>
					<?php
					$offset = (int) $first->format( 'N' ) - 1;
					for ( $i = 0; $i < $offset; $i++ ) {
						echo '<td class="eventcrawler-calendar__day is-empty"></td>';
					}
					$days = (int) $last->format( 'j' );
					for ( $day = 1; $day <= $days; $day++ ) {
						$ymd     = sprintf( '%04d-%02d-%02d', $year, $month, $day );
						$events  = isset( $by_day[ $ymd ] ) ? $by_day[ $ymd ] : array();
						$classes = array( 'eventcrawler-calendar__day' );
						if ( $ymd === $today ) {
							$classes[] = 'is-today';
						}
						if ( $events ) {
							$classes[] = 'has-events';
						}
						if ( 0 === ( $offset + $day - 1 ) % 7 && 1 !== $day ) {
							echo '</tr><tr>';
						}
						echo '<td class="' . esc_attr( implode( ' ', $classes ) ) . '">';
						if ( $events ) {
							$day_url = self::day_url( $link, $ymd );
							printf(
								'<a class="eventcrawler-calendar__number" href="%1$s" aria-label="%2$s">%3$d</a>',
								esc_url( $day_url ),
								esc_attr( sprintf( '%s: %d %s', self::format_day( $ymd ), count( $events ), 1 === count( $events ) ? 'Veranstaltung' : 'Veranstaltungen' ) ),
								(int) $day
							);
							echo '<span class="eventcrawler-calendar__count">' . (int) count( $events ) . '</span>';
							echo '<ul class="eventcrawler-calendar__events">';
							foreach ( array_slice( $events, 0, self::CALENDAR_TITLES ) as $event ) {
								printf(
									'<li><a href="%1$s">%2$s%3$s</a></li>',
									esc_url( $day_url . '#eventcrawler-event-' . (int) $event['id'] ),
									empty( $event['start_time'] ) || ! empty( $event['all_day'] ) ? '' : '<span class="eventcrawler-calendar__time">' . esc_html( substr( $event['start_time'], 0, 5 ) ) . '</span> ',
									esc_html( wp_trim_words( $event['title'], 4, '…' ) )
								);
							}
							if ( count( $events ) > self::CALENDAR_TITLES ) {
								printf( '<li class="eventcrawler-calendar__more"><a href="%1$s">+%2$d weitere</a></li>', esc_url( $day_url ), count( $events ) - self::CALENDAR_TITLES );
							}
							echo '</ul>';
						} else {
							echo '<span class="eventcrawler-calendar__number">' . (int) $day . '</span>';
						}
						echo '</td>';
					}
					$rest = ( 7 - ( $offset + $days ) % 7 ) % 7;
					for ( $i = 0; $i < $rest; $i++ ) {
						echo '<td class="eventcrawler-calendar__day is-empty"></td>';
					}
					?>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
		return (string) ob_get_clean();
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
		?>
		<form class="eventcrawler-filter" method="get" action="<?php echo esc_url( $action ); ?>" role="search">
			<?php self::render_hidden_query_args(); ?>
			<?php if ( null !== $day ) : ?>
				<input type="hidden" name="<?php echo esc_attr( self::PARAM_DATE ); ?>" value="<?php echo esc_attr( $day ); ?>">
			<?php endif; ?>
			<p class="eventcrawler-filter__field">
				<label for="eventcrawler-search">Suche</label>
				<input type="search" id="eventcrawler-search" name="<?php echo esc_attr( self::PARAM_SEARCH ); ?>" value="<?php echo esc_attr( $search ); ?>" maxlength="100">
			</p>
			<?php if ( ! $city_fixed ) : ?>
				<p class="eventcrawler-filter__field">
					<label for="eventcrawler-city">Stadt</label>
					<select id="eventcrawler-city" name="<?php echo esc_attr( self::PARAM_CITY ); ?>">
						<option value="">Alle Städte</option>
						<?php foreach ( $cities as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"<?php selected( $city, $option ); ?>><?php echo esc_html( $option ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>
			<?php if ( null !== $date || '' !== $search ) : ?>
				<p class="eventcrawler-filter__field">
					<label for="eventcrawler-date">ab Datum</label>
					<input type="date" id="eventcrawler-date" name="<?php echo esc_attr( self::PARAM_DATE ); ?>" value="<?php echo esc_attr( (string) $date ); ?>">
				</p>
			<?php endif; ?>
			<p class="eventcrawler-filter__actions">
				<button type="submit">Anzeigen</button>
				<a href="<?php echo esc_url( self::url( array(), array( self::PARAM_SEARCH, self::PARAM_CITY, self::PARAM_DATE, self::PARAM_PAGE, self::PARAM_MONTH ) ) ); ?>">Zurücksetzen</a>
			</p>
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
		<nav class="eventcrawler-day-nav" aria-label="Tage">
			<a class="eventcrawler-day-nav__prev" href="<?php echo esc_url( self::url( array( self::PARAM_DATE => $prev ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow">← Vorheriger Tag</a>
			<span class="eventcrawler-day-nav__current"><?php echo esc_html( self::format_day( $date ) ); ?></span>
			<a class="eventcrawler-day-nav__next" href="<?php echo esc_url( self::url( array( self::PARAM_DATE => $next ), array( self::PARAM_PAGE ) ) ); ?>" rel="nofollow">Nächster Tag →</a>
		</nav>
		<?php
	}

	/**
	 * @param int $page  Aktuelle Seite.
	 * @param int $pages Seitenanzahl.
	 */
	private static function render_pagination( $page, $pages ) {
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="eventcrawler-pagination" aria-label="Seiten">';
		if ( $page > 1 ) {
			printf( '<a class="eventcrawler-pagination__prev" href="%s">← Zurück</a>', esc_url( self::url( array( self::PARAM_PAGE => $page - 1 ) ) ) );
		}
		printf( '<span class="eventcrawler-pagination__status">Seite %1$d von %2$d</span>', (int) $page, (int) $pages );
		if ( $page < $pages ) {
			printf( '<a class="eventcrawler-pagination__next" href="%s">Weiter →</a>', esc_url( self::url( array( self::PARAM_PAGE => $page + 1 ) ) ) );
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
	 * @return Eventcrawler_Repository
	 */
	private static function repository() {
		if ( null === self::$repository ) {
			self::$repository = new Eventcrawler_Repository();
		}
		return self::$repository;
	}
}
