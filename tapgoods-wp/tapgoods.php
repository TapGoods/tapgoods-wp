<?php

/*
 * Plugin Name:       TapGoods WordPress
 * Plugin URI:        https://github.com/TapGoods/tapgoods_wp-plugin
 * Description:       WordPress integration for TapGoods
 * Version:           0.1.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            TapGoods
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

// define( 'WP_DEBUG', true);
// define('TAPGOODS_KEY', 'foo');
define( 'TAPGOODS_DEV', true );

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
	Tapgoods_WP_Activator::activate();
}

function tapgoods_deactivate() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tapgoods-deactivator.php';
	Tapgoods_WP_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'tapgoods_activate' );
register_deactivation_hook( __FILE__, 'tapgoods_deactivate' );

function init_tapgoods_wp() {

	require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-wp.php';
	$tapgoods = Tapgoods_WP::get_instance();
	$tapgoods->init();
}

add_action( 'plugins_loaded', 'init_tapgoods_wp' );
