<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Debug log to verify this template is being used
error_log('TapGoods: Tag results template loaded - tg-tag-results.php');

global $wp;

// Get the tag from url
$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
$segments = explode('/', trim($request_uri, '/'));
$tag = '';

// Find 'tags' in segments and get the next segment
$tags_index = array_search('tags', $segments);
if ($tags_index !== false && isset($segments[$tags_index + 1])) {
    $tag = sanitize_text_field($segments[$tags_index + 1]);
}

// Set up inventory display similar to tg-inventory.php
$show_search = true;
$show_filters = true;
$show_pricing = 'show_pricing="true"';
$per_page_default = 'per_page_default="14"';
$tags_attribute = !empty($tag) ? 'tags="' . esc_attr($tag) . '"' : '';

$tg_inventory_grid_class = $show_filters 
    ? 'col-sm-8 col-xs-12' 
    : 'col-sm-12 col-xs-12';

// Debug log
error_log('TapGoods: Tag results - Found tag: ' . $tag . ', Grid class: ' . $tg_inventory_grid_class);
error_log('TapGoods: Tag results - Tags attribute: ' . $tags_attribute);

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
if (function_exists('tg_location_styles')) {
    $location_styles = tg_location_styles();
    if (!empty($location_styles)) {
        echo '<style type="text/css">';
        echo wp_kses_post($location_styles);
        echo '</style>';
    }
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
    'default_location' => get_option('tg_default_location'),
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
            '[tapgoods-search nos="true" ' . esc_attr($show_pricing) . ' ' . 
            esc_attr($tags_attribute) . ' ' . 
            esc_attr($per_page_default) . ']' 
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
                        '[tapgoods-inventory-grid ' . esc_attr($per_page_default) . ' ' . 
                        esc_attr($show_pricing) . ' ' . 
                        esc_attr($tags_attribute) . ']' 
                    ); 
                    ?>
                </div>
            </section>
        </div>
    </div>
</div>

