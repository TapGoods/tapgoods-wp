<?php
// Settings page for advanced settings

$rewrite_permalink = get_option( 'tg_inventory_permalink' );
$form_action       = ( isset( $_SERVER['REQUEST_URI'] ) ) ? wp_kses( wp_unslash( $_SERVER['REQUEST_URI'] ), 'strip' ) : '';
$message           = __( 'Warning: Changing these settings could cause errors on your website, proceed with caution.', 'tapgoods' );
$submit_text       = __( 'Save Settings', 'tapgoods' );
$notice            = Tapgoods_Admin::tapgoods_admin_notice(
	$message,
	array(
		'type'        => 'warning',
		'dismissible' => false,
	),
	false
);
?>
<h2>Advanced</h2>
<?php echo esc_html( $notice ); ?>
<form name="tapgoods_advanced" method="post", action="">
	<?php wp_nonce_field( 'save', '_tgnonce_advanced' ); ?>
	<div class="mb-3">
		<label for="tg_inventory_permalink" class="form-label">TapGoods Inventory Post Type Permalink Setting</label>
		<div class="form-text">This will control the rewrite setting for the TapGoods Inventory post type. By default we set the following structure <code>shop/%tg_category%/%postname%/</code></div>
		<input type="text" class="form-control" name="tg_inventory_permalink" value="<?php echo esc_attr( $rewrite_permalink ); ?>">
	</div>
	<input type="submit" name="Connect" value="<?php echo esc_attr( $submit_text ); ?>" class="btn btn-primary bg-blue px-5 py-2 round">
</form>
<?php
