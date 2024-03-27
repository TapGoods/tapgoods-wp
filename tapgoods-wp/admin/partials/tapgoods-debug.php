<?php 

// $api = Tapgoods_Connection::get_instance();
// var_dump( $api->test_connection() );

?>
<h2>Development Settings</h2>
<div class="container">
<form>
	<h4>Dev Connection Settings</h4>
	<div class="row">
		<div class="col">
			<label for="developer_user">Developer User</label>
			<input type="email" id="developer_user" name="developer_user" class="form-control mb-3">
		</div>
		<div class="col">
			<label for="developer_pass">Developer Password</label>
			<input type="password" id="develoepr_pass" name="developer_pass" class="form-control mb-3">
		</div>
	</div>
	<div class="row">
		<div class="col">
			<label for="admin_user">Admin User</label>
			<input type="email" id="admin_user" name="admin_user" class="form-control mb-3">
		</div>
		<div class="col">
			<label for="admin_pass">Admin Password</label>
			<input type="password" id="admin_pass" name="admin_pass" class="form-control mb-3">
		</div>
	</div>
	<?php wp_nonce_field( 'save', '_tgnonce_dev' ); ?>
	<input type="submit" name="Connect" value="<?php esc_attr_e( 'Save', 'tapgoods-wp' ); ?>" class="btn btn-primary bg-blue px-5 py-2 round">
</form>
</div>
<?php
