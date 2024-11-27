<?php
// Get the locations set in 'tg_locationIds'
$locations = maybe_unserialize(get_option('tg_locationIds'));
if (!is_array($locations)) {
    $locations = []; // Ensure it's an array
}

// Check if a selection exists and display its details
$selected_location = isset($_POST['selected_location']) ? sanitize_text_field($_POST['selected_location']) : null;

// Get the current default location if no selection exists
$default_location = get_option('tg_default_location');
if (empty($selected_location)) {
    $selected_location = $default_location;
}

// Save default location when "Set as Default" button is pressed
if (isset($_POST['set_default_location']) && $selected_location) {
    update_option('tg_default_location', $selected_location);
    $default_location = $selected_location;
}

// Get the data for the selected location only if it exists
$location_data = null;
if (!empty($selected_location)) {
    $selected_location_data = get_option('tg_location_' . $selected_location);
    $location_data = maybe_unserialize($selected_location_data);
}
?>

<div class="wrap">
    <h1>Multi Location - Select Default Location</h1>
    
    <!-- Form to select location -->
    <form method="post" action="">
        <label for="selected_location">Select Default Location:</label>
        <select name="selected_location" id="selected_location" onchange="this.form.submit();">
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
    </form>

    <!-- Show data for the selected location automatically if a location has been selected -->
    <?php if (!empty($selected_location) && $location_data): ?>
        <h2>Location Details</h2>
        <form method="post" action="">
            <input type="hidden" name="selected_location" value="<?php echo esc_attr($selected_location); ?>">
            <button type="submit" name="set_default_location" class="button button-secondary">Set as Default</button>
        </form>
        
        <?php if (!empty($default_location) && $default_location == $selected_location): ?>
            <p id="default_message"><em>This is the current default location.</em></p>
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
