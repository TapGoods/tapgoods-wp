<?php
// Get the locations set in 'tg_locationIds'
$locations = maybe_unserialize(get_option('tg_locationIds'));
if (!is_array($locations)) {
    $locations = []; // Make sure it is an array
}

// Check if a selection exists and display its details
$selected_location = isset($_POST['selected_location']) ? sanitize_text_field($_POST['selected_location']) : null;

// Save default location when "Set as Default" button is pressed
if (isset($_POST['set_default_location']) && $selected_location) {
    update_option('tg_default_location', $selected_location);
}

// Get the current default location
$default_location = get_option('tg_default_location');

// Get the data for the selected location if it exists
if ($selected_location) {
    $location_option = get_option('tg_location_' . $selected_location);
    $location_data = maybe_unserialize($location_option);
} else {
    $location_data = null;
}
?>

<div class="wrap">
    <h1>Options - Select Default Location</h1>
    
    <!-- Form to select location -->
    <form method="post" action="">
        <label for="selected_location">Select Default Location:</label>
        <select name="selected_location" id="selected_location">
            <?php
            foreach ($locations as $location_id) {
                // Retrieve the location data for each ID
                $location_data = get_option("tg_location_{$location_id}");
                $location_data = maybe_unserialize($location_data);
                
                // Get the name of the location, or use a default if not found
                $location_name = $location_data['fullName'] ?? "Location {$location_id}";
                
                // Display both ID and name in the option
                echo '<option value="' . esc_attr($location_id) . '"' . selected($selected_location, $location_id, false) . '>' . esc_html("{$location_id} - {$location_name}") . '</option>';
            }
            ?>
        </select>
        <button type="submit" class="button button-primary">View Details</button>
    </form>

    <!-- Show data for the selected location only if a location has been selected -->
    <?php if (!empty($selected_location) && $location_data): ?>
        <h2>Location Details</h2>
        <form method="post" action="">
            <input type="hidden" name="selected_location" value="<?php echo esc_attr($selected_location); ?>">
            <button type="submit" name="set_default_location" class="button button-secondary">Set as Default</button>
        </form>
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

        <!-- Button to set the location as default -->
        <form method="post" action="">
            <input type="hidden" name="selected_location" value="<?php echo esc_attr($selected_location); ?>">
            <button type="submit" name="set_default_location" class="button button-secondary">Set as Default</button>
        </form>
        
        <?php if ($default_location == $selected_location): ?>
            <p><em>This is the current default location.</em></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
