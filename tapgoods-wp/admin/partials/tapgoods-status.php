<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// Check API connection status
$api_connected = get_option('tg_api_connected');

// Check if TAPGOODS_KEY is defined
$key_defined = defined('TAPGOODS_KEY');

// Retrieve location settings
$location_settings = maybe_unserialize(get_option('tg_location_settings'));

// Check if reset has been done
$reset_done = get_option('tapgreino_reset_done');
?>

<?php
// Status page styles are now handled by Tapgoods_Enqueue class
// All styles moved to tapgoods-complete-styles.css
?>
    
    
    <div class="container">
    <!-- Status Information -->
    <h2 class="mb-4">Status Information</h2>
    <ul class="list-unstyled mb-4">
        <li class="d-flex align-items-center mb-2">
            <strong class="me-2">API Connected:</strong>
            <span class="<?php echo $api_connected ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                <?php echo $api_connected ? 'Yes' : 'No'; ?>
            </span>
        </li>
        <li class="d-flex align-items-center mb-2">
            <strong class="me-2">TAPGOODS_KEY Defined:</strong>
            <span class="<?php echo $key_defined ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                <?php echo $key_defined ? 'Yes' : 'No'; ?>
            </span>
        </li>
        <li class="d-flex align-items-center">
            <strong class="me-2">Reset Done:</strong>
            <span class="<?php echo $reset_done ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                <?php echo $reset_done ? 'Yes' : 'No'; ?>
            </span>
        </li>
    </ul>

    <div class="position-absolute start-0 end-0" style="height: 16px; background-color: #f0f0f1;"></div>

    <!-- Location Settings -->

    <h2 class="mb-4 pt-4 mt-5">Location Settings</h2>
    <div class="accordion" id="locationSettingsAccordion">
        <?php foreach ($location_settings as $location_id => $settings): ?>
            <div class="accordion-item mb-3">
                <h2 class="accordion-header" id="heading<?php echo esc_attr($location_id); ?>">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo esc_attr($location_id); ?>" aria-expanded="false" aria-controls="collapse<?php echo esc_attr($location_id); ?>">
                        <?php echo esc_html($location_id); ?>
                    </button>
                </h2>
                <div id="collapse<?php echo esc_attr($location_id); ?>" class="accordion-collapse collapse" aria-labelledby="heading<?php echo esc_attr($location_id); ?>" data-bs-parent="#locationSettingsAccordion">
                    <div class="accordion-body">
                        <ul class="list-unstyled">
                            <?php foreach ($settings as $key => $value): ?>
                                <li class="mb-2">
                                    <strong><?php echo esc_html($key); ?>:</strong> 
                                    <span>
                                        <?php echo esc_html(is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value); ?>
                                    </span>
                                </li>
                                
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <hr class="my-4">
        <?php endforeach; ?>
    </div>
</div>

<?php
// Add status tab functionality using wp_add_inline_script
wp_add_inline_script('tapgoods-admin-complete', '
document.addEventListener("DOMContentLoaded", function () {
    const statusTab = document.querySelector("#nav-status-tab");
    const statusContent = document.querySelector("#tapgrein-status-content");

    if (statusTab) {
        statusTab.addEventListener("click", function () {
            // reload the content when the tab is clicked
            fetch("' . esc_url( admin_url('admin-ajax.php') ) . '", {
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
            .catch(error => console.error("TapGoods Admin: Error loading status content:", error));
        });
    }
});
');
?>