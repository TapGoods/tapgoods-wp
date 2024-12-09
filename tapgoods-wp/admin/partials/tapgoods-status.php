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

<div class="wrap"  id="status-content">
    <h1>Status Overview</h1>
    
    <style>
        #status-content .wrap {
            overflow: auto;
        }
        #status-content .wrap ul {
            word-wrap: break-word; /* Break long words */
            white-space: pre-wrap; /* Maintain line breaks and wrap text */
            background: #ffffff; /* White background */
            padding: 10px; /* Inner spacing */
            border: 1px solid #ddd; /* Optional: Border */
            border-radius: 5px; /* Optional: Rounded corners */
        }
        #status-content .status-yes {
            color: green;
            font-weight: bold;
        }
        #status-content .status-no {
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

    <?php if (!empty($location_settings)): ?>
        <h2>Location Settings</h2>
        <ul>
            <?php foreach ($location_settings as $key => $value): ?>
                <li><strong><?php echo esc_html($key); ?>:</strong> 
                    <?php echo esc_html(is_array($value) ? json_encode($value) : $value); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p><em>No location settings found.</em></p>
    <?php endif; ?>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const statusTab = document.querySelector('#nav-status-tab');
    const statusContent = document.querySelector('#status-content');

    if (statusTab) {
        statusTab.addEventListener('click', function () {
            // reload the content when the tab is clicked
            fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    action: "load_status_tab_content", // custom ajax action
                }),
            })
            .then(response => response.text())
            .then(data => {
                statusContent.innerHTML = data; // replace the content
            })
            .catch(error => console.error("Error loading status content:", error));
        });
    }
});
</script>