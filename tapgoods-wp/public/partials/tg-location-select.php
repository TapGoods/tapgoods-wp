<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Generate location styles when needed
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    echo '<style>';
    echo wp_kses_post( tapgrein_location_styles() );
    echo '</style>';
}

// Retrieve location IDs and default location
$location_ids = maybe_unserialize(get_option('tg_locationIds', []));
$default_location = get_option('tapgreino_default_location'); // Default location in options
// Prefer user-selected location from cookie, if present
$cookie_location = isset($_COOKIE['tg_user_location']) ? sanitize_text_field( wp_unslash( $_COOKIE['tg_user_location'] ) ) : '';
$selected_location = $cookie_location !== '' ? $cookie_location : $default_location;
?>
<div class="tapgoods location-select container">
    <div class="wrapper row row-cols-auto align-items-center">
        <span class="icon dashicons dashicons-location col-auto"></span>
        <select class="form-select col pe-5" id="tg-location-select" style="border-radius:.5rem; border:none; box-shadow:none; background:transparent;">
            <option value="">— Choose a Location —</option>
            <?php foreach ($location_ids as $location_id) : 
                $location_data = maybe_unserialize(get_option("tg_location_{$location_id}"));
                $location_name = $location_data['fullName'] ?? "Location {$location_id}";
                $is_selected = selected((string)$selected_location, (string)$location_id, false);
            ?>
                <option value="<?php echo esc_attr($location_id); ?>" <?php echo $is_selected; ?>>
                    <?php echo esc_html("{$location_id} - {$location_name}"); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="caret col-auto ps-2"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
    </div>
</div>

<?php
// Location selector functionality is now handled automatically by Tapgoods_Enqueue class
// No inline script needed - the system detects shortcodes and adds appropriate scripts
?>
