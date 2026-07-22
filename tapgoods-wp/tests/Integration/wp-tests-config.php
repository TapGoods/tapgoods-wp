<?php
/**
 * WordPress test-suite configuration for the integration layer.
 *
 * Tuned for @wordpress/env: the DB credentials come from the container
 * environment (wp-env injects WORDPRESS_DB_* into its containers), with
 * wp-env's documented defaults as a fallback. The dedicated `wptests_` table
 * prefix keeps the test tables isolated from wp-env's own WordPress tables.
 *
 * @package Tapgoods\Tests\Integration
 */

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.WP.GlobalVariablesOverride

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'TapGoods Integration Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );

// WordPress core location inside the wp-env container.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ?: '/var/www/html/' );
}
