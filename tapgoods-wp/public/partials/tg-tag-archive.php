<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Full-page template for a tg_tags archive (WPB-188).
 *
 * tapgrein_custom_tax_template() hands WordPress this file as the WHOLE
 * template for a tag archive, so it has to emit the document: doctype, <head>
 * and wp_head(), the theme's header and footer, and wp_footer(). The tag grid
 * itself stays in tg-tag-results.php.
 *
 * Before this, template_include returned tg-tag-results.php directly. It never
 * called get_header(), so wp_head() never ran: the page started at the plugin's
 * stylesheets with no doctype, no <title>, and none of what an SEO plugin prints
 * (Rank Math / Yoast meta robots, canonical, description). A noindex set on tag
 * archives was silently never applied.
 *
 * A block theme ships no header.php, and get_header() on one falls back to the
 * deprecated theme-compat header with no site header in it. So a block theme
 * gets its own header/footer template parts, rendered before wp_head() the way
 * core's template-canvas.php does, so the styles their blocks enqueue reach the
 * head.
 */

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && function_exists( 'block_header_area' ) ) {
	ob_start();
	block_header_area();
	$tapgrein_block_header = (string) ob_get_clean();

	ob_start();
	block_footer_area();
	$tapgrein_block_footer = (string) ob_get_clean();
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
	<?php echo $tapgrein_block_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup ?>
	<main class="wp-block-group">
		<?php include __DIR__ . '/tg-tag-results.php'; ?>
	</main>
	<?php echo $tapgrein_block_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
	<?php
	return;
}

get_header();

include __DIR__ . '/tg-tag-results.php';

get_footer();
