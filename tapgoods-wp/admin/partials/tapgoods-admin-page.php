<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// $loader = $tapgoods->get_loader();

$card_class   = 'card text-light d-block pt-3 pb-4 px-1 h-100 my-0';
$key_disabled = '';
$success      = false;
$notice       = false;
$tg_admin     = Tapgoods::get_instance()->get_admin();
$connected    = false;
$button_text  = 'CONNECT';
$dev          = defined( 'TAPGOODS_DEV' ) && true === TAPGOODS_DEV;
$form_action  = get_admin_url() . '?page=tapgoods';
$tg_env       = getenv_docker( 'tg_env', 'tapgoods.com' );

$enable_advanced = get_option( 'tg_enable_advanced', false );

?>
<div id="tapgoods-settings" class="wrap tapgoods">
	<h1 class="wp-heading-inline">TapGoods Settings</h1>
	<hr class="wp-header-end">
	<?php


	// TapGoods Quick Links
	?>
	<div class="tapgoods-links">
		<div class="container text-center text-light px-0 mx-0">
			<div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 gx-4 gy-3 m-0 p-0 justify-content-start align-items-stretch">
				<div class="col">            
					<a class='<?php echo esc_attr( $card_class ); ?> bg-blue' href='https://business.<?php echo esc_attr( $tg_env ); ?>/admin/settings/order' target=”_blank”>
						<span class="dashicons dashicons-products icon py-2"></span><br>My Order Settings
					</a>
				</div>
				<div class="col">
					<a class='<?php echo esc_attr( $card_class ); ?> bg-blue' href='https://business.<?php echo esc_attr( $tg_env ); ?>/admin/storefront/pages/shop' target=”_blank”>
						<span class="dashicons dashicons-cart icon py-2"></span><br>Shop + Cart Settings
					</a>
				</div>
				<div class="col">
					<a class='<?php echo esc_attr( $card_class ); ?> bg-blue' href='https://business.<?php echo esc_attr( $tg_env ); ?>/inventory' target=”_blank”>
						<span class="dashicons dashicons-screenoptions icon py-2"></span><br>Manage Inventory
					</a>            
				</div>
				<div class="col">
					<a class='<?php echo esc_attr( $card_class ); ?> bg-purple' href='https://tapgoods.zendesk.com' target=”_blank”>
						<span class="dashicons dashicons-editor-help icon py-2"></span><br>Help Articles
					</a>
				</div>
			</div>
		</div>
	</div>
	<?php


	// TapGoods Settings Form
	?>
	<div class="tapgoods-settings mt-4">
		<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-admin-navlinks.php'; ?>
	<div class="tab-content container-fluid" id="nav-tabContent">
		<div class="tab-pane fade bg-white p-4 show active" id="connection" role="tabpanel" aria-labelledby="nav-connection-tab" tabindex="0">
			<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-admin-connection.php'; ?>
		</div>
		<!-- <div class="tab-pane fade bg-white p-4" id="styling" role="tabpanel" aria-labelledby="nav-styling-tab" tabindex="0">
			<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-admin-styling.php'; ?>
		</div> -->
		<div class="tab-pane fade bg-white p-4" id="shortcodes" role="tabpanel" aria-labelledby="nav-shortcodes-tab" tabindex="0">
			<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-admin-shortcodes.php'; ?>
		</div>
		<div class="tab-pane fade bg-white p-4" id="options" role="tabpanel" aria-labelledby="nav-options-tab" tabindex="0">
			<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-options.php'; ?>
		</div>
		<div class="tab-pane fade bg-white p-4" id="status" role="tabpanel" aria-labelledby="nav-options-tab" tabindex="0">
			<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-status.php'; ?>
		</div>
		<?php if ( $enable_advanced ) : ?>
		<div class="tab-pane fade bg-white p-4" id="advanced" role="tabpanel" aria-labelledby="nav-advanced-tab" tabindex="0">
			<?php require_once TAPGOODS_PLUGIN_PATH . '/admin/partials/tapgoods-admin-advanced.php'; ?>
		</div>
		<?php endif; ?>
	</div>
	</div>
</div>
