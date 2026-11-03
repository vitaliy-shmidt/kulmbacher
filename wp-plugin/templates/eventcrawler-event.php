<?php
/**
 * Vorlage der Eventcrawler-Detailseite (docs/12-seo.md Abschnitt 4).
 *
 * Block-Themes: Kopf- und Fußbereich des Themes als Template-Parts (vor wp_head gerendert, damit ihre Styles geladen
 * werden). Klassische Themes (z. B. Betheme): get_header()/get_footer(). Inhalt aus Eventcrawler_Seo::render_content().
 *
 * @package Eventcrawler
 */

defined( 'ABSPATH' ) || exit;

$eventcrawler_event = Eventcrawler_Seo::current_event();
wp_enqueue_style( 'eventcrawler-frontend' );
$eventcrawler_content = Eventcrawler_Seo::render_content( $eventcrawler_event );

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	$eventcrawler_header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
	$eventcrawler_footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $eventcrawler_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template-Part des Themes. ?>
	<main class="wp-block-group eventcrawler-detail-main has-global-padding is-layout-constrained">
		<?php echo $eventcrawler_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_content() escapt selbst. ?>
	</main>
	<?php echo $eventcrawler_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template-Part des Themes. ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
	<?php
	return;
}

get_header();
?>
<div id="primary" class="content-area eventcrawler-detail-main">
	<main id="main" class="site-main">
		<?php echo $eventcrawler_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_content() escapt selbst. ?>
	</main>
</div>
<?php
get_footer();
