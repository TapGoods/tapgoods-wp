<?php
/**
 * PHPUnit bootstrap for the INTEGRATION suite.
 *
 * Unlike tests/bootstrap.php (which never loads WordPress), this boots the real
 * WordPress test framework shipped by wp-phpunit/wp-phpunit, then loads and
 * activates the plugin. These tests run inside @wordpress/env (`wp-env`), which
 * provides the WordPress core install and a throw-away MySQL database.
 *
 * Runner: this suite runs under the ISOLATED PhpUnit 9.6 toolchain in
 * tools/phpunit9/ (the WordPress test framework is not compatible with the
 * PHPUnit 10+ used by the unit suite). That toolchain's autoloader is already
 * active when this file runs, which is what provides Yoast\PHPUnitPolyfills.
 *
 * The external TapGoods API is pinned to the offline mock (TG_MOCK) so that even
 * a real-WordPress test never touches the network: real WP + deterministic
 * external boundary.
 *
 * @package Tapgoods\Tests\Integration
 */

$plugin_root = dirname( __DIR__, 2 );

// Locate the WordPress test library. Prefer an explicit WP_PHPUNIT__DIR, then
// wp-env's bundled copy (WP_TESTS_DIR), then the pinned wp-phpunit in vendor/.
$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	$vendor_wp = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';
	$env_wp    = getenv( 'WP_TESTS_DIR' );
	if ( file_exists( $vendor_wp . '/includes/functions.php' ) ) {
		$_tests_dir = $vendor_wp;
	} elseif ( $env_wp && file_exists( $env_wp . '/includes/functions.php' ) ) {
		$_tests_dir = $env_wp;
	}
}

if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not locate the WordPress test library (wp-phpunit).\n" );
	exit( 1 );
}

// Point the WP test framework at the DB/ABSPATH config we ship (see
// tests/Integration/wp-tests-config.php), instead of the copy it would
// otherwise look for inside the test library directory.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

// Force the offline mock TapGoods API for every integration test.
if ( ! defined( 'TG_MOCK' ) ) {
	define( 'TG_MOCK', true );
}
putenv( 'tg_mock=1' );

// Give access to tests_add_filter().
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin under test before WordPress finishes booting.
 */
function _tapgoods_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/tapgoods.php';
}
tests_add_filter( 'muplugins_loaded', '_tapgoods_manually_load_plugin' );

// Boot the WordPress test environment.
require $_tests_dir . '/includes/bootstrap.php';
