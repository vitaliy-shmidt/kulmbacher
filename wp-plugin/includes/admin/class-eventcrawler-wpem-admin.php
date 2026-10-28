<?php
/**
 * Admin: einmalige Übernahme aus „WP Kulmbacher Land“ (WPEM).
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

/**
 * Untermenü "Eventcrawler → WPEM-Übernahme", nur sichtbar, wenn WPEM-Termine in der Datenbank stehen.
 * Zeigt immer zuerst den Probelauf; übernommen wird erst per Button (POST, Nonce, manage_options).
 */
class Eventcrawler_Wpem_Admin {

	const PAGE_SLUG = 'eventcrawler-wpem';
	const ACTION    = 'eventcrawler_wpem_migrate';

	/**
	 * Höchstens so viele Einträge je Kategorie anzeigen (die Zählung umfasst immer alle).
	 */
	const LIST_LIMIT = 200;

	const LABELS = array(
		'new'           => 'werden übernommen',
		'migrated'      => 'bereits übernommen',
		'duplicate'     => 'Dublette eines vorhandenen Termins (nicht übernommen)',
		'invalid'       => 'unvollständig/ungültig (nicht übernommen)',
		'not_published' => 'nicht veröffentlicht (nicht übernommen)',
	);

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_migrate' ) );
	}

	public static function register_menu() {
		if ( ! Eventcrawler_Wpem_Migration::has_wpem_data() ) {
			return;
		}
		add_submenu_page( Eventcrawler_Admin::PAGE_SLUG, 'WPEM-Übernahme', 'WPEM-Übernahme', Eventcrawler_Manual_Events::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function handle_migrate() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Ungültige Anfrage.', 'eventcrawler' ), 405 );
		}
		if ( ! current_user_can( Eventcrawler_Manual_Events::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}
		check_admin_referer( self::ACTION, 'eventcrawler_wpem_nonce' );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- einmaliger Lauf.
		}
		$result = ( new Eventcrawler_Wpem_Migration( new Eventcrawler_Repository() ) )->run();

		set_transient( 'eventcrawler_wpem_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( Eventcrawler_Manual_Events::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'eventcrawler' ), 403 );
		}

		$result = get_transient( 'eventcrawler_wpem_result_' . get_current_user_id() );
		if ( is_array( $result ) ) {
			delete_transient( 'eventcrawler_wpem_result_' . get_current_user_id() );
		}
		$analysis   = ( new Eventcrawler_Wpem_Migration( new Eventcrawler_Repository() ) )->analyze();
		$wpem_admin = post_type_exists( Eventcrawler_Wpem_Migration::POST_TYPE );
		?>
		<div class="wrap">
			<h1>WPEM-Übernahme</h1>
			<p>Einmalige Übernahme der Termine aus „WP Kulmbacher Land“ als manuelle Veranstaltungen. In WP Kulmbacher Land
			wird nichts verändert oder gelöscht. Mehrfaches Ausführen legt nichts doppelt an. Regeln: docs/10-frontend.md Abschnitt 10.</p>

			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-<?php echo $result['failed'] ? 'warning' : 'success'; ?>"><p>
					<?php echo esc_html( sprintf( 'Übernahme abgeschlossen: %d übernommen, %d fehlgeschlagen.', $result['inserted'], $result['failed'] ) ); ?>
				</p></div>
			<?php endif; ?>

			<h2>Probelauf</h2>
			<table class="widefat striped" style="max-width:40rem">
				<tbody>
					<?php foreach ( self::LABELS as $status => $label ) : ?>
						<tr><td><?php echo esc_html( $label ); ?></td><td><strong><?php echo (int) $analysis['counts'][ $status ]; ?></strong></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $analysis['counts']['new'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1rem 0">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION, 'eventcrawler_wpem_nonce' ); ?>
					<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( sprintf( '%d Termine jetzt übernehmen?', $analysis['counts']['new'] ) ); ?>');">
						<?php echo esc_html( sprintf( '%d Termine übernehmen', $analysis['counts']['new'] ) ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><strong>Keine neuen Termine zu übernehmen.</strong></p>
			<?php endif; ?>

			<?php
			foreach ( array( 'invalid', 'duplicate', 'new', 'not_published' ) as $status ) {
				self::render_items( $analysis['items'], $status, $wpem_admin );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param array[] $items      Ergebnis von analyze().
	 * @param string  $status     Kategorie.
	 * @param bool    $wpem_admin WPEM aktiv (dann Link zum Bearbeiten in WPEM).
	 */
	private static function render_items( array $items, $status, $wpem_admin ) {
		$rows = array_values(
			array_filter(
				$items,
				function ( $item ) use ( $status ) {
					return $item['status'] === $status;
				}
			)
		);
		if ( 'new' === $status ) {
			// Bei neuen Terminen nur die mit Hinweisen auflisten (z. B. verworfener Link).
			$rows = array_values(
				array_filter(
					$rows,
					function ( $item ) {
						return ! empty( $item['warnings'] );
					}
				)
			);
		}
		if ( ! $rows ) {
			return;
		}
		$heading = 'new' === $status ? 'Werden übernommen, mit Hinweisen' : self::LABELS[ $status ];
		?>
		<h2><?php echo esc_html( $heading . ' (' . count( $rows ) . ')' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th>WPEM-Beitrag</th><th>Titel</th><th>Datum</th><th>Grund / Hinweis</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $rows, 0, self::LIST_LIMIT ) as $item ) : ?>
				<tr>
					<td>
						<?php if ( $wpem_admin ) : ?>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $item['post_id'] . '&action=edit' ) ); ?>"><?php echo (int) $item['post_id']; ?></a>
						<?php else : ?>
							<?php echo (int) $item['post_id']; ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $item['title'] ); ?></td>
					<td><?php echo esc_html( $item['event'] ? mysql2date( 'd.m.Y', $item['event']['start_date'] ) : '—' ); ?></td>
					<td>
						<?php echo esc_html( trim( $item['reason'] . ' ' . implode( ' ', $item['warnings'] ) ) ); ?>
						<?php if ( $item['match_id'] ) : ?>
							(Eventcrawler-ID <?php echo (int) $item['match_id']; ?>)
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $rows ) > self::LIST_LIMIT ) : ?>
			<p class="description"><?php echo esc_html( sprintf( '… und %d weitere.', count( $rows ) - self::LIST_LIMIT ) ); ?></p>
		<?php endif; ?>
		<?php
	}
}
