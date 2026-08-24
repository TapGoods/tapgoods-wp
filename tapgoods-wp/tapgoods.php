<?php

/*
 * Plugin Name:       TapGoods Rental Inventory
 * Plugin URI:        https://github.com/TapGoods/tapgoods_wp-plugin
 * Description:       WordPress integration for TapGoods
 * Version:           0.1.154
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Aaron Valiente <aaron.valiente@tapgoods.com>
 * Author URI:        https://www.tapgoods.com/pro/
 * License:           MIT
 * Text Domain:       tapgoods
 * Domain Path:       /languages
 * Update URI:        false
 *
 * "Update URI: false" tells WordPress 5.8+ that this plugin has no update
 * source, so core stops asking wordpress.org about it. Without it, every site
 * running this plugin sends the folder name "tapgoods-wp" to the .org update
 * API, and an unrelated .org plugin claiming that same slug would be offered
 * to our users as an "update" and overwrite this one. Remove this line only if
 * the plugin is actually published to wordpress.org, or replace it with the
 * URL of a self-hosted update server.
 *
 *
 * MIT License
 *
 * Copyright (c) 2024 TapGoods
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice (including the next paragraph) shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 */

// define( 'TAPGOODS_KEY', 'YOUR API KEY' );
// define( 'TAPGOODS_DEV', true );

// exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}
define( 'TAPGOODS_PLUGIN_FILE', __FILE__ );
define( 'TAPGOODSWP_VERSION', '0.1.2' );
define( 'TAPGREIN_PLUGIN_DIR',  plugin_dir_path( TAPGOODS_PLUGIN_FILE ) );



/**
 * Path to the plugin root directory.
 */
define( 'TAPGOODS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/*
 * Action Scheduler (bundled, pinned 3.9.3).
 *
 * Action Scheduler is the background driver for the inventory sync: each sync
 * "slice" runs as one `tapgoods_sync_slice` async action, and each slice chains
 * the next while work remains, so a large catalog syncs back-to-back instead of
 * one slice per five-minute cron tick.
 *
 * It MUST be loaded as early as possible (its own docs require it to be required
 * on every request, before `plugins_loaded`) so the `as_*()` functions exist by
 * the time any hook fires. This runs at plugin-include time, which is before
 * `plugins_loaded`. It is a TRACKED, shipped directory under lib/ (NOT a Composer
 * runtime dependency), because the release zip excludes vendor/. The file_exists
 * guard keeps the plugin from fataling if the library is ever missing; the sync
 * then falls back to the legacy cron self-ping driver (see Tapgoods::tapgrein_cron_exec).
 */
$tapgoods_action_scheduler = TAPGOODS_PLUGIN_PATH . 'lib/action-scheduler/action-scheduler.php';
if ( file_exists( $tapgoods_action_scheduler ) ) {
	require_once $tapgoods_action_scheduler;
}
unset( $tapgoods_action_scheduler );
/**
 * Url to the plugin root directory.
 */
define( 'TAPGOODS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
$uploads = wp_upload_dir();
define( 'TAPGOODS_UPLOADS', trailingslashit( $uploads['basedir'] . '/tapgoods' ) );

// TODO: register activate hook
function tapgrein_activate() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tapgoods-activator.php';
	Tapgoods_Activator::activate();
}

function tapgrein_deactivate() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tapgoods-deactivator.php';
	Tapgoods_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'tapgrein_activate' );
register_deactivation_hook( __FILE__, 'tapgrein_deactivate' );

function tapgrein_init_tapgoods_wp() {

	require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-wp.php';
	$tapgoods = Tapgoods::get_instance();
	$tapgoods->init();
}

add_action( 'plugins_loaded', 'tapgrein_init_tapgoods_wp' );

if ( ! function_exists( 'tapgrein_getenv_docker' ) ) {
	function tapgrein_getenv_docker( $env, $default ) {
		if ( $fileEnv = getenv( $env . '_FILE' ) ) {
			return rtrim( file_get_contents( $fileEnv ), "\r\n" );
		} elseif ( ( $val = getenv( $env ) ) !== false ) {
			return $val;
		} else {
			return $default;
		}
	}
}

// AJAX function to set default location
function tapgrein_set_default_location() {
    if (!isset($_POST['location_id']) || !is_numeric($_POST['location_id'])) {
        wp_send_json_error();
    }

    $location_id = intval($_POST['location_id']);
    update_option('tapgreino_default_location', $location_id); // Save location as default in WP options
    wp_send_json_success();
}

// Adds AJAX actions for authenticated and unauthenticated users
add_action('wp_ajax_set_default_location', 'tapgrein_set_default_location');
add_action('wp_ajax_nopriv_set_default_location', 'tapgrein_set_default_location');
