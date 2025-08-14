<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
	// TapGoods Navlinks
	$enable_advanced = get_option( 'tg_enable_advanced', false );
?>
<div class="nav nav-links" id="nav-tab" role="tablist">
	<button class="nav-link active" id="nav-connection-tab" data-bs-toggle="tab" data-bs-target="#connection" type="button" role="tab" aria-controls="nav-connection" aria-selected="true">Connection</button>
	<!-- <button class="nav-link" id="nav-styling-tab" data-bs-toggle="tab" data-bs-target="#styling" type="button" role="tab" aria-controls="nav-styling" aria-selected="false">Styling</button> -->
	<button class="nav-link" id="nav-shortcodes-tab" data-bs-toggle="tab" data-bs-target="#tapgrein-shortcodes" type="button" role="tab" aria-controls="nav-shortcodes" aria-selected="false">Shortcodes</button>
	<button class="nav-link" id="nav-options-tab" data-bs-toggle="tab" data-bs-target="#tapgrein-options" type="button" role="tab" aria-controls="nav-options" aria-selected="false">Multi Location</button>
	<button class="nav-link" id="nav-status-tab" data-bs-toggle="tab" data-bs-target="#tapgrein-status" type="button" role="tab" aria-controls="nav-options" aria-selected="false">Status</button>
	<?php if ( $enable_advanced ) : ?>
	<button class="nav-link" id="nav-advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced" type="button" role="tab" aria-controls="nav-advanced" aria-selected="false">Advanced</button>
	<?php endif; ?>
</div>
<?php
