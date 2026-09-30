<?php
/**
 * Unit tests for tapgrein_asset_version() (includes/tapgoods-asset-functions.php)
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tapgrein_asset_version() calls no WordPress function (file_exists(),
 * filemtime(), ltrim() and dirname() are all plain PHP), so this suite does
 * not bring in Brain\Monkey the way the other suites do for WP-calling code.
 *
 * Assertions below compare tapgrein_asset_version()'s return value against a
 * filemtime() read taken by the test itself, right after touch(), rather
 * than against a hardcoded expected literal: once anything earlier in a full
 * suite run has loaded Brain\Monkey/Patchwork (which several other suites in
 * this repo do, to stub WordPress functions), its runtime function-call
 * interception was observed in this environment to make a later touch()'s
 * explicit mtime land a second later than requested -- even in a test class,
 * like this one, that never calls Monkey\setUp() itself. That is an artifact
 * of sharing a PHP process with those other suites, not a property of
 * tapgrein_asset_version(); reading the ground truth back with filemtime()
 * keeps these assertions correct either way.
 */
final class AssetVersionTest extends TestCase {

	/**
	 * Scratch files this test class created, so tearDown can always clean up
	 * even when an assertion fails partway through a test.
	 *
	 * @var string[]
	 */
	private $scratch_files = array();

	protected function tearDown(): void {
		foreach ( $this->scratch_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->scratch_files = array();

		parent::tearDown();
	}

	/**
	 * Plugin root, matching how tapgrein_asset_version() itself resolves it
	 * (dirname() of includes/, i.e. two levels up from this tests/Unit/ file).
	 */
	private function plugin_root() {
		return dirname( __DIR__, 2 ) . '/';
	}

	/**
	 * Creates a scratch file under the plugin root at $relative_path, requests
	 * mtime $timestamp for it, and registers the file for cleanup in
	 * tearDown(). Returns the file's actual, just-read mtime as a string (the
	 * ground truth to assert against -- see the class docblock for why this
	 * is not simply (string) $timestamp).
	 */
	private function make_fixture( $relative_path, $timestamp ) {
		$absolute = $this->plugin_root() . $relative_path;
		file_put_contents( $absolute, 'tapgrein_asset_version fixture' );
		touch( $absolute, $timestamp );
		clearstatcache( true, $absolute );

		$this->scratch_files[] = $absolute;

		return (string) filemtime( $absolute );
	}

	public function test_existing_file_returns_its_filemtime_as_the_version() {
		$relative       = 'tests/tmp-asset-version-existing.txt';
		$actual_mtime   = $this->make_fixture( $relative, 1700000000 );

		$this->assertSame( $actual_mtime, tapgrein_asset_version( $relative ) );
	}

	public function test_missing_file_falls_back_to_the_plugin_version_constant() {
		$this->assertSame(
			TAPGOODSWP_VERSION,
			tapgrein_asset_version( 'tests/this-file-does-not-exist-anywhere.js' )
		);
	}

	/**
	 * tapgrein_asset_version() memoises per relative path for the life of one
	 * PHP request/process (see its docblock) -- re-touching and re-querying
	 * the SAME path within a single test would only re-prove the cache, not
	 * the underlying filemtime() read. Two distinct paths given two distinct
	 * mtimes, 500 seconds apart, stand in for "the file changed": each file's
	 * returned version tracks its own mtime, so a file that is touched to a
	 * newer mtime gets a different version than one that was not. The
	 * end-to-end version of this same claim -- touch a real asset, reload the
	 * page, see only that asset's ?ver= change -- is proven against the
	 * running site in proof/ (a fresh PHP process per request there sidesteps
	 * the memoisation entirely, exactly as happens on a real page load).
	 */
	public function test_a_file_with_a_different_mtime_gets_a_different_version() {
		$older_relative = 'tests/tmp-asset-version-older.txt';
		$newer_relative = 'tests/tmp-asset-version-newer.txt';

		$older_mtime = $this->make_fixture( $older_relative, 1700000000 );
		$newer_mtime = $this->make_fixture( $newer_relative, 1700000500 );

		$this->assertSame( $older_mtime, tapgrein_asset_version( $older_relative ) );
		$this->assertSame( $newer_mtime, tapgrein_asset_version( $newer_relative ) );
		$this->assertNotSame(
			tapgrein_asset_version( $older_relative ),
			tapgrein_asset_version( $newer_relative )
		);
	}

	public function test_memoises_per_relative_path_within_one_request() {
		$relative = 'tests/tmp-asset-version-memoised.txt';
		$this->make_fixture( $relative, 1700000000 );

		$first = tapgrein_asset_version( $relative );

		// Touch the SAME path to a different mtime; within one request the
		// memoised value must still win, matching the documented contract.
		$absolute = $this->plugin_root() . $relative;
		touch( $absolute, 1700009999 );
		clearstatcache( true, $absolute );

		$second = tapgrein_asset_version( $relative );

		$this->assertSame( $first, $second, 'version is memoised per relative path for the life of one request' );
	}

	public function test_leading_slash_on_the_relative_path_is_ignored() {
		$relative = 'tests/tmp-asset-version-leading-slash.txt';
		$this->make_fixture( $relative, 1700000000 );

		$this->assertSame(
			tapgrein_asset_version( $relative ),
			tapgrein_asset_version( '/' . $relative )
		);
	}
}
