<?php
// Get all locations set in 'tg_locationIds' from wp_options
$location_ids = maybe_unserialize(get_option('tg_locationIds', []));
$current_location = get_option('tg_default_location'); // Get the current default location

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
            ?>
                <option value="<?php echo esc_attr($location_id); ?>" <?php selected($current_location, $location_id); ?>>
                    <?php echo esc_html("{$location_id} - {$location_name}"); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button id="tg-set-location" class="btn btn-primary">Set as Default Location</button>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#tg-set-location').on('click', function(e) {
        e.preventDefault();
        var selectedLocation = $('#tg-location-select').val();
        
        if (selectedLocation) {
            $.ajax({
                type: 'POST',
                url: tg_ajax.ajaxurl,
                data: {
                    action: 'set_default_location',
                    location_id: selectedLocation,
                },
                success: function(response) {
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
