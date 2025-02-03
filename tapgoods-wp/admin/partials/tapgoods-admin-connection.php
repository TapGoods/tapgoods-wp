<?php

// Initialize connection information and variables
$tg_api  = Tapgoods_Connection::get_instance();
$api_key = $tg_api->get_key();

$connected       = ( ! empty( get_option( 'tg_api_connected', false ) ) );
$button_text     = ( $connected ) ? 'CONNECTED' : 'CONNECT';
$key_disabled    = ( defined( 'TAPGOODS_KEY' ) ) ? 'disabled' : '';
$button_disabled = ( defined( 'TAPGOODS_KEY' ) || '' !== $api_key ) ? 'disabled' : '';
$sync_hidden     = ( $connected ) ? '' : 'hidden style="display: none;"';

// Check if a reset occurred
$reset_done = get_option('tg_reset_done', false);

// Handle the "Reset to Default" action
if (isset($_POST['confirm_reset'])) {
    delete_option('tg_key'); // Remove the API key
    delete_option('tg_api_connected'); // Remove connection status
    delete_option('tg_location_settings'); // Remove location settings
    delete_option('tg_default_location'); // Remove default location
    delete_option('tg_locationIds'); // Remove location IDs
    delete_option('tg_businessId'); // Remove business ID
    delete_option('tg_last_api_key'); // Remove last stored API key
    delete_option('tg_last_sync_progress'); // Remove last sync progress
    delete_option('tg_last_sync_info'); // Remove last sync info

    // Delete all categories and tags
    $taxonomies = ['tg_category', 'tg_tags', 'tg_location'];
    foreach ($taxonomies as $taxonomy) {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'ids',
        ]);
        foreach ($terms as $term_id) {
            wp_delete_term($term_id, $taxonomy);
        }
    }

    // Delete all inventory items
    $args = [
        'post_type'   => 'tg_inventory',
        'numberposts' => -1,
        'fields'      => 'ids',
        'post_status' => 'any',
    ];
    $posts = get_posts($args);
    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }

    // Set a flag to indicate the reset has occurred
    update_option('tg_reset_done', true);
    $connected = false; // Reset connection state
    echo "<p style='color: green;'>All data has been successfully deleted.</p>";
    echo '<script>showSuccessMessage();</script>';
    exit;
}
?>

<h2>Connect to your TapGoods account</h2>
<div id="tg_ajax_connection" hidden></div>
<form name="tapgoods_connection" id="tg_connection_form" method="post" action="">
    <input type="hidden" name="tapgoods_hidden" value="1">
    <?php wp_nonce_field( 'save', '_tgnonce_connection' ); ?>
    <div class="row">
        <div class="col col-sm-6">
            <input type="password" id="tapgoods_api_key" name="tapgoods_api_key" value="<?php echo wp_kses( $api_key, 'strip' ); ?>" data-original="<?php echo wp_kses( $api_key, 'strip' ); ?>" size="60" class="form-control round bg-gray px-3 py-2 api-key" <?php echo $key_disabled; ?>>
        </div>
        <div class="col col-sm-3">
            <button type="submit" name="submit" id="tg_update_connection" value="tg_update_connection" class="btn btn-primary bg-blue w-100 py-2 round" data-original="<?php echo esc_attr( $button_text ); ?>" <?php echo $button_disabled; ?>>
                <?php echo esc_html( $button_text ); ?>
            </button>
        </div>
        <div class="col col-sm-3">
            <button type="button" name="tg_sync" id="tg_api_sync" value="tg_api_sync" class="btn btn-primary w-100 py-2 round" <?php echo $sync_hidden; ?>>SYNC</button>
        </div>
    </div>

    <?php if ( '' !== $key_disabled || $reset_done  || get_option( 'tg_api_connected' ) == false ) { ?>
        <!-- Show message for API key generation -->
        <p class="help-text"> Generate an API key in your <a href="https://business.tapgoods.com/admin/storefront/wordpress" target="_blank">TapGoods WordPress Settings</a> </p>
        <p> <span style="font-weight: bold; color: #d63638; margin: 0;">Important note:</span> This key is only generated once and won't be displayed again. <br>
    Make sure to copy and save it immediately in a secure place.</p>       
    <?php if ($reset_done) delete_option('tg_reset_done'); // Clear reset flag ?>
    <?php } elseif ($connected) { ?>
        <p class="help-text">Your site is connected to TapGoods. To generate a new API key, please contact <a href="mailto:support@tapgoods.com">support@tapgoods.com</a>.</p>
    <?php } ?>
</form>


<?php 
$sync_message = $tg_api->last_sync_message();
error_log('Last Sync Message: ' . $sync_message); // Debug: Log sync message
 ?>


<div id="tg_connection_test">
<?php if ( $sync_message && get_option( 'tg_api_connected' ) == !false  ) : ?>
    <?php echo wp_kses( wpautop( $sync_message ), 'post' ); ?>
<?php endif; ?>
</div>

<!-- <?php if ($connected) : ?>
    <p class="help-text">
        <a href="#popup">Reset to Default</a>
    </p>
<?php endif; ?> -->

<!-- Reset confirmation popup -->
<div id="popup" class="overlay" style="display: none;">
    <div class="popup">
        <h1 style="color: white;">Are you sure you want to reset to default?</h1>
        <p>This will disconnect your website from TapGoods and delete all inventory from your website. This action cannot be undone.</p>
        <form method="post">
            <button type="submit" name="confirm_reset" style="background-color: #e74c3c; color: white; margin-top: 20px; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">Yes, Reset to Default</button>
            <button type="button" style="background-color: #bdc3c7; color: black; margin-top: 20px; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;" onclick="closePopup();">Cancel</button>
        </form>
    </div>
</div>

<p class="help-text">
    <a href="javascript:void(0);" onclick="openPopup()">Reset to Default</a>
</p>

<script>
    function openPopup() {
        const popup = document.getElementById('popup');
        if (popup) {
            popup.style.display = 'flex';
        }
    }

    function closePopup() {
        const popup = document.getElementById('popup');
        if (popup) {
            popup.style.display = 'none';
        }
    }
</script>

<style>
    .overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }
    .popup {
        background: #333;
        color: #ecf0f1;
        padding: 20px;
        border-radius: 8px;
        width: 400px;
        text-align: center;
    }
</style>
