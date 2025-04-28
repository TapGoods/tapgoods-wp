<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// Fired during plugin deactivation

class Tapgoods_Deactivator {

	public static function deactivate() {

		// check option to remove data?

		// Unschedule our cron job
		wp_clear_scheduled_hook( 'tapgoods_cron_hook' );
	}
}
