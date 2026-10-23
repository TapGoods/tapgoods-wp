<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// Fired during plugin deactivation

class Tapgoods_Deactivator {

	public static function deactivate() {

		// check option to remove data?

		// Unschedule our cron job
		wp_clear_scheduled_hook( 'tapgoods_cron_hook' );

		// Cancel any pending/in-flight Action Scheduler sync slices so a chain in
		// flight does not keep firing after the plugin is deactivated. Guarded by
		// function_exists in case Action Scheduler is not loaded (the hook name
		// mirrors Tapgoods_Sync_Scheduler::HOOK, which may not be loaded here).
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tapgoods_sync_slice', array(), 'tapgoods-sync' );
		}
	}
}
