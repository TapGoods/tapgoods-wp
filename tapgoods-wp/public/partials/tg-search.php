<?php

// Check if the 'nos' attribute is set; default to false if not
$nos = isset($atts['nos']) ? filter_var($atts['nos'], FILTER_VALIDATE_BOOLEAN) : false;

// Define options for the "posts per page" dropdown
$posts_per_page_options = apply_filters(
    'tg_per_page_options',
    array('12', '24', '48') // Default options
);

// Get the 'tg-per-page' value from the cookie or fallback to the default option
$tg_per_page = (isset($_COOKIE['tg-per-page'])) 
    ? sanitize_text_field(wp_unslash($_COOKIE['tg-per-page'])) 
    : get_option('tg_per_page', '12');

// Debug: Log the 'tg-per-page' cookie value
error_log('tg-per-page cookie value: ' . ($_COOKIE['tg-per-page'] ?? 'not set'));

// Get the default or selected location ID from the cookie or fallback
$location_id = isset($_COOKIE['tg_location_id']) ? sanitize_text_field($_COOKIE['tg_location_id']) : get_option('tg_default_location');

// Action hook before rendering the search form
do_action('tg_before_search_form');
?>

<!-- Search container -->
<div id="tg-search-container" class="container mb-5">
    <form id="tg-search-form" method="get" action="<?php echo esc_url(plugin_dir_url(__FILE__) . 'tg-search-results.php'); ?>">
        <?php do_action('tg_before_search_input'); ?>
        
        <!-- Search input -->
        <input type="hidden" name="post_type" value="tg_inventory">
        <input id="tg-search" class="form-control form-control-lg" name="s" type="text" placeholder="Search" aria-label=".form-control-lg example">

        <?php do_action('tg_after_search_form'); ?>

        <!-- Posts per page dropdown -->
        <?php if (!$nos): // Only display the dropdown if 'nos' is false ?>
            <select id="tg-per-page" name="per-page" class="number-select">
                <?php foreach ($posts_per_page_options as $option) : ?>
                    <?php 
                    // Add the 'selected' attribute to the currently selected option
                    $selected = ((int) $option === (int) $tg_per_page) ? ' selected' : ''; 
                    ?>
                    <option value="<?php echo esc_attr($option); ?>"<?php echo $selected; ?>>
                        <?php echo esc_html($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <!-- Hidden input for location ID -->
        <input type="hidden" name="tg_location_id" value="<?php echo esc_attr($location_id); ?>">
    </form>

    <?php do_action('tg_after_search_form'); ?>

    <!-- Suggestion box -->
    <div class="suggestion-box" hidden>
        <ul id="suggestion-list" class="suggestion-list"></ul>
    </div>
</div>
<?php
// Action hook after the search form
do_action('tg_after_search_form');
?>
