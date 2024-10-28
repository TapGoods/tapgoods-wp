<?php

$posts_per_page_options = apply_filters(
    'tg_per_page_options',
    array('12', '24', '48')
);

$tg_per_page = (isset($_COOKIE['tg-per-page'])) ? sanitize_text_field(wp_unslash($_COOKIE['tg-per-page'])) : get_option('tg_per_page', '12');

// Get the default location_id
$location_id = isset($_COOKIE['tg_location_id']) ? sanitize_text_field($_COOKIE['tg_location_id']) : get_option('tg_default_location');

do_action('tg_before_search_form');
?>
<div id="tg-search-container" class="container mb-5">
    <form id="tg-search-form" method="get" action="<?php echo esc_url(plugin_dir_url(__FILE__) . 'tg-search-results.php'); ?>">
        <?php do_action('tg_before_search_input'); ?>
        <input type="hidden" name="post_type" value="tg_inventory">
        <input id="tg-search" class="form-control form-control-lg" name="s" type="text" placeholder="Search" aria-label=".form-control-lg example">
        <?php do_action('tg_after_search_form'); ?>
        <select id="tg-per-page" name="per-page" class="number-select">
            <?php foreach ($posts_per_page_options as $option) : ?>
                <?php $selected = ($option === $tg_per_page) ? ' selected' : ''; ?>
                <option value="<?php echo esc_attr($option); ?>"<?php echo esc_attr($selected); ?>><?php echo esc_html($option); ?></option>
            <?php endforeach; ?>
        </select>
        <!-- Add the location_id as a hidden field in the form -->
        <input type="hidden" name="tg_location_id" value="<?php echo esc_attr($location_id); ?>">
    </form>
    <?php do_action('tg_after_search_form'); ?>
    <div class="suggestion-box" hidden>
        <ul id="suggestion-list" class="suggestion-list"></ul>
    </div>
</div>
<?php
do_action('tg_after_search_form');
?>
