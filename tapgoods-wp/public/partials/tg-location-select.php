<?php
// Log before generating CSS
if (!defined('DOING_AJAX') || !DOING_AJAX) {
 //   error_log("tg_location_styles(): Generating CSS as this is not an AJAX request.");
    echo '<style>';
    echo wp_kses_post( tg_location_styles() );
    echo '</style>';
} else {
//    error_log("tg_location_styles(): Skipping CSS generation due to AJAX request.");
}

// Retrieve location IDs and default location
$location_ids = maybe_unserialize(get_option('tg_locationIds', []));
$default_location = get_option('tg_default_location'); // Default location in options
?>
<div class="tapgoods location-select container">
    <div class="wrapper row row-cols-auto align-items-center">
        <span class="icon dashicons dashicons-location col"></span>
        <select class="form-select col pe-5" id="tg-location-select">
            <option value="">— Choose a Location —</option>
            <?php foreach ($location_ids as $location_id) : 
                $location_data = maybe_unserialize(get_option("tg_location_{$location_id}"));
                $location_name = $location_data['fullName'] ?? "Location {$location_id}";
            ?>
                <option value="<?php echo esc_attr($location_id); ?>">
                    <?php echo esc_html("{$location_id} - {$location_name}"); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function () {
    const selectElement = document.getElementById('tg-location-select');

    // Priority: Cookie -> LocalStorage -> Default Option
    const getCookie = (name) => {
        const cookies = document.cookie.split('; ');
        for (let cookie of cookies) {
            const [key, value] = cookie.split('=');
            if (key === name) {
                return value;
            }
        }
        return null;
    };

    const savedCookieLocation = getCookie('tg_user_location');
    const savedLocalStorageLocation = localStorage.getItem('tg_user_location');
    const defaultLocation = "<?php echo esc_js($default_location); ?>";

    // Determine the value to select
    const selectedLocation = savedCookieLocation || savedLocalStorageLocation || defaultLocation;

    // Set the select element to the determined value
    if (selectedLocation) {
        selectElement.value = selectedLocation;
    }

    // Save to cookie and localStorage for consistency
    if (selectedLocation) {
        document.cookie = `tg_user_location=${selectedLocation}; path=/`;
        localStorage.setItem('tg_user_location', selectedLocation);
    }

    // Handle changes in selection
    selectElement.addEventListener('change', function () {
        const selectedLocation = selectElement.value;
        if (selectedLocation) {

            // Save to localStorage and cookie
            localStorage.setItem('tg_user_location', selectedLocation);
            document.cookie = `tg_user_location=${selectedLocation}; path=/`;

            // Reload to apply changes
            location.reload();
        }
    });
});
</script>

<?php
// AJAX handler to set the default location (optional if required for server sync)
add_action('wp_ajax_set_default_location', 'tg_set_default_location');
add_action('wp_ajax_nopriv_set_default_location', 'tg_set_default_location');

if (!function_exists('tg_set_default_location')) {
    function tg_set_default_location() {
//        error_log("AJAX action 'set_default_location' triggered.");
        if (isset($_POST['location_id'])) {
            $location_id = sanitize_text_field($_POST['location_id']);
//            error_log("Received location_id: $location_id");
            wp_send_json_success("Location set to $location_id");
        } else {
//            error_log("No location_id provided.");
            wp_send_json_error('Location ID not provided.');
        }
        wp_die();
    }
}
?>
