<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Tag archive fallback, used only on a site with no [tapgoods-inventory] page.
 *
 * Where a shop page exists, tapgrein_redirect_tag_archives() has already sent
 * the visitor there with ?tags=<slug> and this file never runs.
 */

// The queried term, not the URL. Parsing REQUEST_URI for the literal segment
// 'tags' broke on any site that changed the tag permalink base on
// Settings > Permalinks (tg_tag_base), which is a supported setting.
$queried = get_queried_object();
$tag     = ( $queried instanceof WP_Term && 'tg_tags' === $queried->taxonomy ) ? $queried->slug : '';

// Set up inventory display similar to tg-inventory.php
$show_search = true;
$show_filters = true;
$show_pricing = 'show_pricing="true"';
$per_page_default = 'per_page_default="14"';
// NOT esc_attr(): this is shortcode source, not an HTML attribute. esc_attr()
// turns the quotes into &quot;, which the shortcode parser then reads as part of
// the value, so the grid looked for a term slug of tag-&quot;tag-x&quot; and
// rendered an empty grid -- the WPB-166 symptom.
$tags_attribute = !empty($tag) ? 'tags="' . tapgrein_sanitize_slug_list($tag) . '"' : '';

$tg_inventory_grid_class = $show_filters
    ? 'col-sm-8 col-xs-12'
    : 'col-sm-12 col-xs-12';

// Force load TapGoods styles directly for tag pages
// Build correct plugin URLs
$global_styles_url = '/wp-content/plugins/tapgoods-wp/public/css/global-styles.css';
$public_css_url = '/wp-content/plugins/tapgoods-wp/public/css/tapgoods-public.css';
$complete_css_url = '/wp-content/plugins/tapgoods-wp/assets/css/tapgoods-complete-styles.css';
$inline_styles_url = '/wp-content/plugins/tapgoods-wp/assets/css/tapgoods-inline-styles.css';
$custom_css_url = '/wp-content/plugins/tapgoods-wp/public/css/tapgoods-custom.css';

// Load styles that work well for tag pages (excluding tg-bootstrap.css that conflicts)
echo '<link rel="stylesheet" href="' . esc_url($global_styles_url) . '?v=0.1.124-tag-direct" type="text/css" media="all">';
echo '<link rel="stylesheet" href="' . esc_url($public_css_url) . '?v=0.1.124-tag-direct" type="text/css" media="all">';
echo '<link rel="stylesheet" href="' . esc_url($complete_css_url) . '?v=0.1.124-tag-direct" type="text/css" media="all">';
echo '<link rel="stylesheet" href="' . esc_url($inline_styles_url) . '?v=0.1.124-tag-direct" type="text/css" media="all">';
echo '<link rel="stylesheet" href="' . esc_url($custom_css_url) . '?v=0.1.124-tag-direct" type="text/css" media="all">';

// Add location-specific dynamic styles (colors, themes, etc.)
$location_styles = tapgrein_location_styles();
if (!empty($location_styles)) {
    echo '<style type="text/css">';
    echo wp_kses_post($location_styles);
    echo '</style>';
}



// Force enqueue jQuery and main script for tag pages
wp_enqueue_script('jquery');
wp_enqueue_script(
    'tapgoods-public-complete',
    plugin_dir_url(dirname(dirname(__FILE__))) . 'public/js/tapgoods-public-complete.js',
    array('jquery'),
    '0.1.124-tag-inline',
    true
);

// Localize script with necessary data
wp_localize_script('tapgoods-public-complete', 'tg_public_vars', array(
    'ajaxurl' => admin_url('admin-ajax.php'),
    'default_location' => get_option('tapgreino_default_location'),
    'plugin_url' => plugin_dir_url(dirname(dirname(__FILE__)))
));

// Print scripts immediately - this forces them to load
wp_print_scripts('jquery');
wp_print_scripts('tapgoods-public-complete');
?>

<!-- Initialize tag page functionality -->
<script>
console.log('TapGoods: Tag page inline script starting');

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('TapGoods: Tag page DOM ready, checking for TG namespace');
    
    if (typeof window.TG !== 'undefined') {
        console.log('TapGoods: TG namespace found, initializing tag page functions');
        
        try {
            // Initialize all necessary functions for tag pages
            window.TG.initLocationSelector();
            window.TG.initInventoryGrid();
            window.TG.initFilterHandlers();
            window.TG.initSearchHandlers();
            window.TG.initCartHandlers();
            console.log('TapGoods: Tag page initialization complete');
        } catch (e) {
            console.error('TapGoods: Error during tag page initialization:', e);
        }
    } else {
        console.error('TapGoods: TG namespace not available');
        
        // Fallback: try again after a short delay
        setTimeout(function() {
            if (typeof window.TG !== 'undefined') {
                console.log('TapGoods: TG namespace found on retry, initializing');
                try {
                    window.TG.initLocationSelector();
                    window.TG.initInventoryGrid();
                    window.TG.initFilterHandlers();
                    window.TG.initSearchHandlers();
                    window.TG.initCartHandlers();
                    console.log('TapGoods: Tag page initialization complete (retry)');
                } catch (e) {
                    console.error('TapGoods: Error during tag page retry initialization:', e);
                }
            } else {
                console.error('TapGoods: TG namespace still not available after retry');
            }
        }, 500);
    }
});
</script>

<div id="tg-shop" class="tapgoods tapgoods-inventory container-fluid">
    <?php if ( false !== $show_search ) : ?>
        <?php
        echo do_shortcode(
            '[tapgoods-search nos="true" ' . $show_pricing . ' ' .
            $tags_attribute . ' ' .
            $per_page_default . ']'
        );
        ?>
    <?php endif; ?>
    <div class="container shop">
        <div class="row align-items-start">
            <?php if ( false !== $show_filters ) : ?>
                <?php echo do_shortcode('[tapgoods-filter]'); ?>
            <?php endif; ?>
            <section class="<?php echo esc_attr( $tg_inventory_grid_class ); ?>" id="tg-inventory-grid-container">
                <div id="tg-inventory-grid">
                    <?php 
                    echo do_shortcode(
                        '[tapgoods-inventory-grid ' . $per_page_default . ' ' .
                        $show_pricing . ' ' .
                        $tags_attribute . ']'
                    );
                    ?>
                </div>
            </section>
        </div>
    </div>
</div>

