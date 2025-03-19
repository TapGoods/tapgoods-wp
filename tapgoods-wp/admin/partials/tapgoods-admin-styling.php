<?php

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Unauthorized' );
}



// Load our filesystem class for file operations
require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-filesystem.php';

$input_submit = 'save_styles';
$nonce        = 'tapgoods-save-custom-css';
$success      = false;
$tapgoods_css = Tapgoods_Filesystem::get_file( TAPGOODS_PLUGIN_PATH . '/public/css/tapgoods-public.css' );
$save_text    = 'Save';
$tg_env       = getenv_docker( 'tg_env', 'tapgoods.com' );

?>
<h2>Styling</h2>
<p>This will override the styling in your <a href="https://business.<?php echo $tg_env; //phpcs:ignore ?>/admin/storefront/styling" target="_blank" class="link">TapGoods Storefront Settings</a>.</p>
<h3>TapGoods Styles</h3>
<textarea placeholder="" id="tg-css" spellcheck="false"><?php echo esc_textarea( $tapgoods_css ); ?></textarea>

<h3>Custom Styles</h3>
<?php


do_action( 'tg_save_custom_css', $input_submit );

$custom_css = Tapgoods_Filesystem::get_file( TAPGOODS_PLUGIN_PATH . '/public/css/tapgoods-custom.css' );

?>
<form name="tapgoods_styling" method="post" action="">
	<?php wp_nonce_field( 'save', '_tgnonce_css' ); ?>
	<textarea placeholder="" name="tg-custom-css" id="tg-custom-css" class="mb-2" spellcheck="false"><?php echo esc_textarea( $custom_css ); ?></textarea>
	<hr class="mb-2">
	<input name="<?php echo esc_attr( $input_submit ); ?>" type="submit" class="btn btn-primary round px-5" value="<?php esc_attr_e( 'SAVE', 'tapgoods' ); ?>">
<form>
<?php
