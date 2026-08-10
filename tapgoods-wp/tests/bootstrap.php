<?php
/**
 * PHPUnit bootstrap for isolated (no-WordPress) unit tests.
 *
 * This does NOT bootstrap WordPress. Each unit is tested in isolation with WP
 * functions mocked via Brain\Monkey. We only:
 *   1. load the Composer autoloader (PHPUnit + Brain\Monkey + Mockery),
 *   2. define the minimal constants the plugin's class files guard on, and
 *   3. require the source files that have no load-time WordPress calls.
 *
 * Files with load-time WP calls (e.g. class-tapgoods-connection.php, whose
 * bottom registers add_action() hooks) are required inside the individual test
 * that needs them, after Brain\Monkey has stubbed those functions.
 *
 * @package Tapgoods\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Plugin class files guard on ABSPATH; define it so they can be required.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Used by the mock-client seam in Tapgoods_Connection::create_client().
if ( ! defined( 'TAPGOODS_PLUGIN_PATH' ) ) {
	define( 'TAPGOODS_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
}

// Where the JSON response fixtures live.
if ( ! defined( 'TG_FIXTURES_PATH' ) ) {
	define( 'TG_FIXTURES_PATH', __DIR__ . '/fixtures/' );
}

/*
 * tapgrein_getenv_docker() normally lives in the plugin bootstrap (tapgoods.php),
 * which we deliberately do not load. This is a minimal stub covering only the plain
 * getenv() path the mock seam (Tapgoods_Connection::use_mock_api()) needs during
 * tests; it intentionally omits the real function's docker-secrets ("<ENV>_FILE")
 * branch, which no test exercises.
 */
if ( ! function_exists( 'tapgrein_getenv_docker' ) ) {
	function tapgrein_getenv_docker( $env, $default ) {
		$val = getenv( $env );
		return ( false !== $val ) ? $val : $default;
	}
}

// Source files that are safe to load up-front (class/function definitions only,
// no WordPress calls executed at include time).
$plugin_dir = dirname( __DIR__ );
require_once $plugin_dir . '/includes/tapgoods-formatting-functions.php';
require_once $plugin_dir . '/includes/class-tapgoods-sync-log.php';
require_once $plugin_dir . '/includes/class-tapgoods-encryption.php';
require_once $plugin_dir . '/includes/class-tapgoods-api-response.php';
require_once $plugin_dir . '/includes/class-tapgoods-api-request.php';
require_once $plugin_dir . '/includes/class-tapgoods-api-client.php';
