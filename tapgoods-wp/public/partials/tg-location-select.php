<?php
// Log before generating CSS
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    error_log("tg_location_styles(): Generating CSS as this is not an AJAX request.");
    echo '<style>';
    echo tg_location_styles(); // Call the function only when not an AJAX request
    echo '</style>';
} else {
    error_log("tg_location_styles(): Skipping CSS generation due to AJAX request.");
}

// Log to check if location IDs are being retrieved correctly
$location_ids = maybe_unserialize(get_option('tg_locationIds', []));
error_log("Location IDs retrieved: " . print_r($location_ids, true));

$current_location = get_option('tg_default_location'); // Get the current default location
error_log("Current default location: " . $current_location);

?>
<div class="tapgoods location-select container">
    <div class="wrapper row row-cols-auto align-items-center">
        <span class="icon dashicons dashicons-location col"></span>
        <select class="form-select col pe-5" id="tg-location-select">
            <option value="">— Choose a Location —</option>
            <?php foreach ($location_ids as $location_id) : 
                // Get location name from 'tg_location_{ID}'
                $location_data = maybe_unserialize(get_option("tg_location_{$location_id}"));
                $location_name = $location_data['fullName'] ?? "Location {$location_id}";
                
                // Log each location data for debugging
                error_log("Location ID: $location_id, Name: $location_name");
            ?>
                <option value="<?php echo esc_attr($location_id); ?>" <?php selected($current_location, $location_id); ?>>
                    <?php echo esc_html("{$location_id} - {$location_name}"); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<script type="text/javascript">
var tg_ajax = {
    ajaxurl: "<?php echo admin_url('admin-ajax.php'); ?>"
};

jQuery(document).ready(function($) {
    $('#tg-location-select').on('change', function() {
        var selectedLocation = $(this).val();
        
        if (selectedLocation) {
            console.log("Attempting to set location:", selectedLocation); // Client-side log
            $.ajax({
                type: 'POST',
                url: tg_ajax.ajaxurl,
                data: {
                    action: 'set_default_location',
                    location_id: selectedLocation,
                },
                success: function(response) {
                    console.log("AJAX response:", response); // Client-side log
                    if (response.success) {
                        location.reload(); // Reload page after setting location
                    } else {
                        alert('Failed to set default location.');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                }
            });
        } else {
            alert('Please select a location.');
        }
    });
});
</script>

<?php
// Add AJAX action handler in PHP
add_action('wp_ajax_set_default_location', 'tg_set_default_location');
add_action('wp_ajax_nopriv_set_default_location', 'tg_set_default_location');

function tg_set_default_location() {
    error_log("AJAX action 'set_default_location' triggered."); // Log AJAX call

    // Check if 'location_id' is provided
    if (isset($_POST['location_id'])) {
        $location_id = sanitize_text_field($_POST['location_id']);
        error_log("Received location_id: $location_id");

        // Update default location in 'tg_default_location'
        if (update_option('tg_default_location', $location_id)) {
            error_log("Default location updated successfully.");
            wp_send_json_success();
        } else {
            error_log("Failed to update default location.");
            wp_send_json_error('Failed to update default location.');
        }
    } else {
        error_log("No location_id provided in AJAX request.");
        wp_send_json_error('Location ID not provided.');
    }

    wp_die(); // Stop execution to avoid extra output
}
?>
