<?php

//$loader = $tapgoods->get_loader();
$card_class = 'card text-light d-block pt-3 pb-4 px-1 h-100 my-0';
$key_disabled = '';
$success = false;

// are we saving the API Key?
if( isset($_POST['tapgoods_hidden']) && $_POST['tapgoods_hidden'] == 1) {
    $api_key = sanitize_text_field($_POST['tapgoods_api_key']);
    $success = update_option('tapgoods_key', $api_key);
} else {
    $api_key = (get_option('tapgoods_key')) ? get_option('tapgoods_key') : '';
}

// if the key was defined in config use that and disable input
if (defined('TAPGOODS_KEY')) {
    $key_disabled = 'disabled';
    $api_key = TAPGOODS_KEY;
}

if( $success ) {
    wp_admin_notice(
        __( 'Company Key Updated.', 'tapgoods' ),
        array(
        'type'               => 'success',
        'dismissible'        => true,
        'additional_classes' => array( 'inline', 'notice-alt' ),
        'attributes'         => array( 'data-slug' => 'plugin-slug' )
        )
    );
}

?>
<div class="wrap tapgoods">
    <h1 class="wp-heading-inline">TapGoods Settings</h1>
    <hr class="wp-header-end">
    <?php 


    // TapGoods Quick Links
    ?>
    <div class="tapgoods-links">
        <div class="container text-center text-light px-0 mx-0">
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 gx-4 gy-3 m-0 p-0 justify-content-start align-items-stretch">
                <div class="col">            
                    <a class='<?php echo $card_class; ?> bg-blue' href='https://business.tapgoods.com/admin/settings/order' target=”_blank”>
                        <span class="dashicons dashicons-products icon py-2"></span><br>My Order Settings
                    </a>
                </div>
                <div class="col">
                    <a class='<?php echo $card_class; ?> bg-blue' href='https://business.tapgoods.com/admin/storefront/pages/shop' target=”_blank”>
                        <span class="dashicons dashicons-cart icon py-2"></span><br>Shop + Cart Settings
                    </a>
                </div>
                <div class="col">
                    <a class='<?php echo $card_class; ?> bg-blue' href='https://business.tapgoods.com/inventory' target=”_blank”>
                        <span class="dashicons dashicons-screenoptions icon py-2"></span><br>Manage Inventory
                    </a>            
                </div>
                <div class="col">
                    <a class='<?php echo $card_class; ?> bg-purple' href='https://tapgoods.zendesk.com' target=”_blank”>
                        <span class="dashicons dashicons-editor-help icon py-2"></span><br>Help Articles
                    </a>
                </div>
            </div>
        </div>
    </div><?php
    

    // TapGoods Settings Form
    ?>
    <div class="tapgoods-settings mt-4">
    <div class="nav nav-links" id="nav-tab" role="tablist">
        <button class="nav-link active" id="nav-connection-tab" data-bs-toggle="tab" data-bs-target="#connection" type="button" role="tab" aria-controls="nav-connection" aria-selected="true">Connection</button>
        <button class="nav-link" id="nav-styling-tab" data-bs-toggle="tab" data-bs-target="#styling" type="button" role="tab" aria-controls="nav-styling" aria-selected="false">Styling</button>
        <button class="nav-link" id="nav-shortcodes-tab" data-bs-toggle="tab" data-bs-target="#shortcodes" type="button" role="tab" aria-controls="nav-shortcodes" aria-selected="false">Shortcodes</button>
        <button class="nav-link" id="nav-shortcodes-tab" data-bs-toggle="tab" data-bs-target="#advanced" type="button" role="tab" aria-controls="nav-advanced" aria-selected="false">Advanced</button>
        <button class="nav-link" id="nav-shortcodes-tab" data-bs-toggle="tab" data-bs-target="#dev" type="button" role="tab" aria-controls="nav-dev" aria-selected="false">Dev</button>
    </div>
    <div class="tab-content container-fluid" id="nav-tabContent">
        <div class="tab-pane fade bg-white p-4 show active" id="connection" role="tabpanel" aria-labelledby="nav-connection-tab" tabindex="0">
            <h2>Connect to your TapGoods account</h2>
            <form name="tapgoods_connection" method="post", action="<?php echo $_SERVER['REQUEST_URI']; ?>">
                <input type="hidden" name="tapgoods_hidden" value="1">
                <input type="text" name="tapgoods_api_key" value="<?php echo $api_key;?>" size="60" class="round bg-gray px-3 py-2 api-key" <?php echo $key_disabled ?>>
                <input type="submit" name="Connect" value="<?php _e('CONNECT', 'tapgoods-wp'); ?>" class="btn btn-primary bg-blue px-5 py-2 round" <?php echo $key_disabled ?>>
                <?php if ('' !== $key_disabled) { ?>
                    <p class="help-text">Company Key was defined in config files and cannot be changed here</p>
                <?php } ?>
                <p class="help-text">Find your Company ID in your TapGoods account under…</p>
            </form>
        </div>
        <div class="tab-pane fade bg-white p-4" id="styling" role="tabpanel" aria-labelledby="nav-styling-tab" tabindex="0">
            <h2>Styling</h2>
            <pre><code class="language-css">p { color: red }</code></pre>
        </div>
        <div class="tab-pane fade bg-white p-4" id="shortcodes" role="tabpanel" aria-labelledby="nav-shortcodes-tab" tabindex="0">
            <?php include_once(TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-admin-shortcodes.php'); ?>
        </div>
        <div class="tab-pane fade bg-white p-4" id="advanced" role="tabpanel" aria-labelledby="nav-advanced-tab" tabindex="0">
            <h2>Advanced</h2>
        </div>
        <div class="tab-pane fade bg-white p-4 shortcodes-tab" id="dev" role="tabpanel" aria-labelledby="nav-dev-tab" tabindex="0">
            <h2>Development</h2>
        </div>
    </div>
    </div>
</div>