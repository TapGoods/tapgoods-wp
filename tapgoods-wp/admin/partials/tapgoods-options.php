<?php
// Get the locations set in 'tg_locationIds'
$locations = maybe_unserialize(get_option('tg_locationIds'));
if (!is_array($locations)) {
    $locations = []; // Ensure it's an array
}

// Check if a selection exists and display its details

$selected_location = isset($_POST['selected_location']) ? sanitize_text_field( wp_unslash( $_POST['selected_location'] ) ) : null;

// Get the current default location if no selection exists
$default_location = get_option('tg_default_location');
if (empty($selected_location)) {
    $selected_location = $default_location;
}

// Save default location when "Set as Default" button is pressed
if (isset($_POST['set_default_location']) && $selected_location) {
    // Update the default location in the database
    update_option('tg_default_location', $selected_location);

    // Assign the selected location to the variable
    $default_location = $selected_location;

    // Output JavaScript to save in Local Storage
    echo "
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var defaultLocation = '" . esc_js( $default_location ) . "';
            localStorage.setItem('tg_user_location', defaultLocation);
            console.log('Default location saved to Local Storage:', defaultLocation);
        });
    </script>
    ";
}

// Get the data for the selected location only if it exists
$location_data = null;
if (!empty($selected_location)) {
    $selected_location_data = get_option('tg_location_' . $selected_location);
    $location_data = maybe_unserialize($selected_location_data);
}
?>

<div class="container" style="overflow: auto; word-wrap: break-word;">
    <h2>Default Location</h2>
    
    <!-- Form to select location -->
    <div class="row align-items-center">
    <!-- First Form -->
    <div class="col-md-6">
        <form method="post" action="">
            <div class="position-relative">
                <select name="selected_location" id="selected_location" 
                        class="form-select" 
                        style="background-color: #E5E8E9; color: #000; border-radius: 50px; font-weight: bold; height: 2.2rem; padding: 0.35rem 2.5rem 0.35rem 1rem; border: none; appearance: none;" 
                        onchange="this.form.submit();">
                    <?php
                    foreach ($locations as $location_id) {
                        // Retrieve the location data for each ID
                        $individual_location_data = get_option("tg_location_{$location_id}");
                        $individual_location_data = maybe_unserialize($individual_location_data);
                        
                        // Get the name of the location, or use a default if not found
                        $location_name = $individual_location_data['fullName'] ?? "Location {$location_id}";
                        
                        // Display both ID and name in the option
                        echo '<option value="' . esc_attr($location_id) . '"' . selected($selected_location, $location_id, false) . '>' . esc_html("{$location_id} - {$location_name}") . '</option>';
                    }
                    ?>
                </select>
            </div>
        </form>
    </div>

    <!-- Second Form -->
    <div class="col col-sm-3">
        <form method="post" action="">
            <input type="hidden" name="selected_location" value="<?php echo esc_attr($selected_location); ?>">
            <button type="submit" name="set_default_location" 
                    class="btn btn-primary w-100 round" 
                    id="tg_api_sync2">
                SET DEFAULT
            </button>
        </form>
    </div>
</div>


    <p>Select the inventory location that your website visitors will see.</p>
    <p class="mb-4">You can use a shortcode to allow visitors to switch between locations: [tapgoods-location-select].</p>

    <div class="position-absolute start-0 end-0" style="height: 16px; background-color: #f0f0f1;"></div>

    <!-- Show data for the selected location automatically if a location has been selected -->
    <?php if (!empty($selected_location) && $location_data): ?>
        <h2 class="mb-4 pt-4 mt-5">Location Details</h2>

        
        <?php if (!empty($default_location) && $default_location == $selected_location): ?>
            <p id="default_message">This is the current default location.</p>
        <?php endif; ?>

        <ul>
            <?php foreach ($location_data as $key => $value): ?>
                <li><strong><?php echo esc_html($key); ?>:</strong> 
                    <?php 
                    if (is_array($value)) {
                        echo esc_html(json_encode($value));
                    } else {
                        echo esc_html((string)$value);
                    }
                    ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
