<?php

/*
 * Plugin Name:       TapGoods Rental Inventory
 * Plugin URI:        https://github.com/TapGoods/tapgoods_wp-plugin
 * Description:       WordPress integration for TapGoods
 * Version:           0.1.71
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Aaron Valiente <aaron.valiente@tapgoods.com> and Jeremy Benson <jeremy.benson@tapgoods.com> and TapGoods
 * Author URI:        https://www.tapgoods.com/pro/
 * License:           MIT
 * Text Domain:       tapgoods-wp
 * Domain Path:       /languages
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

define( 'TAPGOODSWP_VERSION', '0.1.0' );

/**
 * Path to the plugin root directory.
 */
define( 'TAPGOODS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
/**
 * Url to the plugin root directory.
 */
define( 'TAPGOODS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$uploads = wp_upload_dir();
define( 'TAPGOODS_UPLOADS', trailingslashit( $uploads['basedir'] . '/tapgoods' ) );

// TODO: register activate hook
function tapgoods_activate() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tapgoods-activator.php';
	Tapgoods_Activator::activate();
}

function tapgoods_deactivate() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tapgoods-deactivator.php';
	Tapgoods_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'tapgoods_activate' );
register_deactivation_hook( __FILE__, 'tapgoods_deactivate' );

function init_tapgoods_wp() {

	require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-wp.php';
	$tapgoods = Tapgoods::get_instance();
	$tapgoods->init();
}

add_action( 'plugins_loaded', 'init_tapgoods_wp' );

if ( ! function_exists( 'getenv_docker' ) ) {
	function getenv_docker( $env, $default ) {
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
function tapgoods_set_default_location() {
    if (!isset($_POST['location_id']) || !is_numeric($_POST['location_id'])) {
        wp_send_json_error();
    }

    $location_id = intval($_POST['location_id']);
    update_option('tg_default_location', $location_id); // Save location as default in WP options
    wp_send_json_success();
}

// Adds AJAX actions for authenticated and unauthenticated users
add_action('wp_ajax_set_default_location', 'tapgoods_set_default_location');
add_action('wp_ajax_nopriv_set_default_location', 'tapgoods_set_default_location');
