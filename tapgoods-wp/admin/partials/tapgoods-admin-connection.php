<?php

$tg_api  = Tapgoods_Connection::get_instance();
$api_key = $tg_api->get_key();

$button_text     = ( get_option( 'tg_api_connected' ) ) ? 'CONNECTED' : 'CONNECT';
$key_disabled    = ( defined( 'TAPGOODS_KEY' ) ) ? 'disabled' : '';
$button_disabled = ( defined( 'TAPGOODS_KEY' ) || '' !== $api_key ) ? 'disabled' : '';

?>
<h2>Connect to your TapGoods account</h2>
			<div id="tg_ajax_connection" hidden>Status</div>
			<form name="tapgoods_connection" id="tg_connection_form" method="post" action="">
				<input type="hidden" name="tapgoods_hidden" value="1">
				<?php wp_nonce_field( 'save', '_tgnonce_connection' ); ?>
				<div class="row">
					<div class="col col-sm-6">
						<input type="text" id="tapgoods_api_key" name="tapgoods_api_key" value="<?php echo wp_kses( $api_key, 'strip' ); ?>" data-original="<?php echo wp_kses( $api_key, 'strip' ); ?>" size="60" class="form-control round bg-gray px-3 py-2 api-key" <?php echo $key_disabled; ?>>
					</div>
					<div class="col col-sm-3">
						<button type="submit" name="submit" id="tg_update_connection" value="tg_update_connection" class="btn btn-primary bg-blue w-100 py-2 round" data-original="<?php _e( "{$button_text}", 'tapgoods-wp' ); ?>" <?php echo $button_disabled; ?>><?php _e( "{$button_text}", 'tapgoods-wp' ); ?></button>
					</div>
				</div>
				<?php if ( '' !== $key_disabled ) { ?>
					<p class="help-text">Company Key was defined in config files and cannot be changed here</p>
				<?php } ?>
				<p class="help-text">Find your Company ID in your TapGoods account under…</p>
			</form>
			<div id="tg_connection_test" hidden>Status</div>
			<div id="tg_general_settings">
				<form name="tapgoods_settings" id="tapgoods_settings">
					
				</form>
			</div>