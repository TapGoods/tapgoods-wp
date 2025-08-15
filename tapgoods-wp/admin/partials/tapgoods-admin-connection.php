<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Initialize connection information and variables
$tg_api  = Tapgoods_Connection::get_instance();
$api_key = $tg_api->get_key();

$connected       = ( ! empty( get_option( 'tg_api_connected', false ) ) );
$button_text     = ( $connected ) ? 'CONNECTED' : 'CONNECT';
$key_disabled    = ( defined( 'TAPGOODS_KEY' ) ) ? 'disabled' : '';
$button_disabled = ( defined( 'TAPGOODS_KEY' ) || '' !== $api_key ) ? 'disabled' : '';
$sync_hidden     = ( $connected ) ? '' : 'hidden style="display: none !important;"';

// Check if a reset occurred
$reset_done = get_option('tapgreino_reset_done', false);

// Handle the "Reset to Default" action
if (isset($_POST['confirm_reset'])) {
    delete_option('tg_key'); 
    delete_option('tg_api_connected');
    delete_option('tg_location_settings'); 
    delete_option('tapgreino_default_location');
    delete_option('tg_locationIds');
    delete_option('tg_businessId');
    delete_option('tg_last_api_key');
    delete_option('tg_last_sync_progress');
    delete_option('tg_last_sync_info');

    // Delete all categories and tags
    $taxonomies = ['tg_category', 'tg_tags', 'tg_location'];
    foreach ($taxonomies as $taxonomy) {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids']);
        foreach ($terms as $term_id) {
            wp_delete_term($term_id, $taxonomy);
        }
    }

    // Delete all inventory items
    $args = ['post_type' => 'tg_inventory', 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any'];
    $posts = get_posts($args);
    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }

    update_option('tapgreino_reset_done', true);
    $connected = false;
    echo '<p class="tapgoods-success">All data has been successfully deleted.</p>';
    wp_add_inline_script('tapgoods-admin-complete', 'showSuccessMessage();');
    exit;
}
?>

<h2>Connect to your TapGoods account</h2>
<form name="tapgoods_connection" id="tapgrein_connection_form" method="post" action="">
    <input type="hidden" name="tapgoods_hidden" value="1">
    <?php wp_nonce_field( 'save', '_tgnonce_connection' ); ?>
    <div class="row">
        <div class="col col-sm-6">
            <input type="password" id="tapgoods_api_key" name="tapgoods_api_key" value="<?php echo esc_attr( $api_key ); ?>" size="60" class="form-control round api-key" <?php echo esc_attr( $key_disabled ); ?>>
        </div>
        <div class="col col-sm-3">
            <button type="submit" name="submit" id="tapgrein_update_connection" value="tapgrein_update_connection" class="btn btn-primary bg-blue w-100 round" <?php echo esc_attr( $button_disabled ); ?>>
                <?php echo esc_html( $button_text ); ?>
            </button>
        </div>
        <div class="col col-sm-3">
            <button type="button" name="tapgrein_sync" id="tapgrein_api_sync" value="tapgrein_api_sync" class="btn btn-primary w-100 round" <?php echo esc_attr( $sync_hidden ); ?>>SYNC</button>
        </div>
    </div>

    <?php if ( '' !== $key_disabled || $reset_done  || get_option( 'tg_api_connected' ) == false ) { ?>
        <!-- Show message for API key generation -->
        <p class="help-text"> Generate an API key in your <a href="https://business.tapgoods.com/admin/storefront/wordpress" target="_blank">TapGoods WordPress Settings</a> </p>
        <p> <span style="font-weight: bold; color: #d63638; margin: 0;">Important note:</span> This key is only generated once and won't be displayed again. <br>
        Make sure to copy and save it immediately in a secure place.</p>       
        <?php if ($reset_done) delete_option('tapgreino_reset_done'); ?>
    <?php } elseif ($connected) { ?>
        <p class="help-text">Your site is connected to TapGoods. To generate a new API key, please contact <a href="mailto:support@tapgoods.com">support@tapgoods.com</a>.</p>
    <?php } ?>
</form>

<!-- Sync Status Message -->
<div id="tapgrein_connection_test">
    <?php 
    $sync_message = $tg_api->last_sync_message();
    if ($sync_message && get_option('tg_api_connected')) :
        echo wp_kses(wpautop($sync_message), 'post');
    endif;
    ?>
</div>

<!-- Sync progress modal -->
<div id="syncProgressModal" class="overlay" style="display: none;">
    <div class="popup sync-popup">
        <div class="sync-icon">
            <span class="spinner-border spinner-border-lg text-primary" role="status" aria-hidden="true"></span>
        </div>
        <h2>Synchronization in Progress</h2>
        <p>Please wait while we sync your inventory and locations from TapGoods. This process may take several minutes depending on the amount of data.</p>
        <div class="sync-details">
            <p><strong>What we're doing:</strong></p>
            <ul>
                <li>✓ Fetching categories and tags</li>
                <li>✓ Updating inventory items</li>
                <li>✓ Syncing location data</li>
                <li>✓ Processing images and metadata</li>
            </ul>
        </div>
        <p class="sync-warning"><strong>Important:</strong> Please do not close this window or navigate away until synchronization is complete.</p>
        <div id="syncProgressStatus" class="sync-status">
            <p>Initializing synchronization...</p>
        </div>
    </div>
</div>

<!-- Reset confirmation popup -->
<div id="popup" class="overlay" style="display: none;">
    <div class="popup">
        <h1>Are you sure you want to reset to default?</h1>
        <p>This will disconnect your website from TapGoods and delete all inventory from your website. This action cannot be undone.</p>
        <form method="post">
            <button type="submit" name="confirm_reset" class="btn btn-danger">Yes, Reset to Default</button>
            <button type="button" class="btn btn-secondary" onclick="closePopup();">Cancel</button>
        </form>
    </div>
</div>

<p class="help-text">
    <a href="javascript:void(0);" onclick="openPopup()">Reset to Default</a>
</p>

