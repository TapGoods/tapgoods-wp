<?php
/**
 * Tapgoods Asset Functions
 *
 * Cache-busting version strings for the plugin's own local JS/CSS.
 *
 * @package Tapgoods\Functions
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache-busting version for a plugin-owned local asset.
 *
 * Every enqueue/registration of a plugin JS or CSS file keeps the same URL
 * across releases (the release workflow bumps the plugin header `Version:`
 * and `readme.txt` `Stable tag`, never the `TAPGOODSWP_VERSION` constant
 * those enqueues used as `?ver=`), so a browser, CDN, or page cache on a
 * customer host keeps serving the old file after an upgrade. Returning the
 * file's own filemtime() instead makes the query string change exactly when
 * the file's contents change, with no manual version bump required.
 *
 * A release zip is unpacked fresh on every customer upgrade, so filemtime()
 * is a reliable per-release signal there. A content hash (md5_file()) would
 * be more robust against a host that somehow preserves mtimes across
 * deploys, but costs a full file read on every call; filemtime() is a single
 * stat() and is memoised per relative path below, so it is preferred. If a
 * host is ever found to preserve mtimes across deploys, revisit this.
 *
 * @param string $relative_path Asset path relative to the plugin root, e.g.
 *                               'public/js/tapgoods-public-complete.js'.
 * @return string The file's filemtime() as a string, or TAPGOODSWP_VERSION
 *                 when the file cannot be found (e.g. an unexpected path),
 *                 so callers always get a usable, non-empty version string.
 */
function tapgrein_asset_version( $relative_path ) {
	static $cache = array();

	// Memoised per relative path for the life of the request: the file is not
	// going to change mid-request, and this keeps a slice with several of the
	// same asset (e.g. tapgoods-public-complete.js enqueued from more than one
	// code path) down to one stat() call instead of one per call site.
	$relative_path = ltrim( (string) $relative_path, '/' );

	if ( isset( $cache[ $relative_path ] ) ) {
		return $cache[ $relative_path ];
	}

	// Resolve the plugin root from this file's own location rather than via
	// TAPGOODS_PLUGIN_PATH/TAPGREIN_PLUGIN_DIR: those constants are define()d
	// from a function-call expression (not a literal), so PHPStan cannot see
	// them and every existing use is baselined per-file at an exact count in
	// phpstan-baseline.neon. A new use here would grow those counts, which is
	// not allowed. dirname( __DIR__ ) needs no constant at all.
	$absolute_path = dirname( __DIR__ ) . '/' . $relative_path;

	$version = file_exists( $absolute_path ) ? (string) filemtime( $absolute_path ) : TAPGOODSWP_VERSION;

	$cache[ $relative_path ] = $version;

	return $version;
}
