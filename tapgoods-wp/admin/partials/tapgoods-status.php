<?php
// Check API connection status
$api_connected = get_option('tg_api_connected');

// Check if TAPGOODS_KEY is defined
$key_defined = defined('TAPGOODS_KEY');

// Retrieve location settings
$location_settings = maybe_unserialize(get_option('tg_location_settings'));

// Check if reset has been done
$reset_done = get_option('tg_reset_done');
?>

<div class="wrap">
    <h1>Status Overview</h1>
    
    <style>
        .wrap {
            overflow: auto;
        }
        .wrap ul {
            word-wrap: break-word; /* Break long words */
            white-space: pre-wrap; /* Maintain line breaks and wrap text */
            background: #ffffff; /* White background */
            padding: 10px; /* Inner spacing */
            border: 1px solid #ddd; /* Optional: Border */
            border-radius: 5px; /* Optional: Rounded corners */
        }
        .status-yes {
            color: green;
            font-weight: bold;
        }
        .status-no {
            color: red;
            font-weight: bold;
        }
    </style>
    
    <!-- Status Details -->
    <h2>Status Information</h2>
    <ul>
        <li><strong>API Connected:</strong> 
            <span class="<?php echo $api_connected ? 'status-yes' : 'status-no'; ?>">
                <?php echo $api_connected ? 'Yes' : 'No'; ?>
            </span>
        </li>
        <li><strong>TAPGOODS_KEY Defined:</strong> 
            <span class="<?php echo $key_defined ? 'status-yes' : 'status-no'; ?>">
                <?php echo $key_defined ? 'Yes' : 'No'; ?>
            </span>
        </li>
        <li><strong>Reset Done:</strong> 
            <span class="<?php echo $reset_done ? 'status-yes' : 'status-no'; ?>">
                <?php echo $reset_done ? 'Yes' : 'No'; ?>
            </span>
        </li>
    </ul>
    
    <!-- Location Settings -->
    <?php if (!empty($location_settings)): ?>
        <h2>Location Settings</h2>
        <ul>
            <?php foreach ($location_settings as $key => $value): ?>
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
    <?php else: ?>
        <p><em>No location settings found.</em></p>
    <?php endif; ?>
</div>
