<?php
/**
 * Unit tests for includes/tapgoods-formatting-functions.php
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FormattingFunctionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_string_to_bool_truthy_and_falsy() {
		$this->assertTrue( tg_string_to_bool( 'yes' ) );
		$this->assertTrue( tg_string_to_bool( 'YES' ) );
		$this->assertTrue( tg_string_to_bool( 'true' ) );
		$this->assertTrue( tg_string_to_bool( '1' ) );
		$this->assertTrue( tg_string_to_bool( true ) );

		$this->assertFalse( tg_string_to_bool( 'no' ) );
		$this->assertFalse( tg_string_to_bool( '' ) );
		$this->assertFalse( tg_string_to_bool( null ) );
		$this->assertFalse( tg_string_to_bool( false ) );
	}

	public function test_bool_to_string_roundtrips_with_string_to_bool() {
		$this->assertSame( 'yes', tapgrein_bool_to_string( true ) );
		$this->assertSame( 'no', tapgrein_bool_to_string( false ) );
		// Accepts strings by first converting to bool.
		$this->assertSame( 'yes', tapgrein_bool_to_string( 'yes' ) );
		$this->assertSame( 'no', tapgrein_bool_to_string( 'no' ) );
	}

	public function test_string_to_array_splits_and_filters_empties() {
		$this->assertSame( array( 'a', 'b', 'c' ), array_values( tapgrein_string_to_array( 'a,b,c' ) ) );

		// Empty segments are filtered out by array_filter.
		$this->assertSame( array( 'a', 'b' ), array_values( tapgrein_string_to_array( 'a,,b,' ) ) );

		// Custom delimiter.
		$this->assertSame( array( 'x', 'y' ), array_values( tapgrein_string_to_array( 'x|y', '|' ) ) );
	}

	public function test_string_to_array_passthrough_for_arrays_and_null() {
		$arr = array( 'one', 'two' );
		$this->assertSame( $arr, tapgrein_string_to_array( $arr ) );

		// Null becomes an empty array (guards against deprecation on PHP 8+).
		$this->assertSame( array(), tapgrein_string_to_array( null ) );
	}

	public function test_float_to_string_normalizes_decimal_point() {
		$this->assertSame( '12.5', tapgrein_float_to_string( 12.5 ) );
		// Non-floats are returned unchanged.
		$this->assertSame( 'already-a-string', tapgrein_float_to_string( 'already-a-string' ) );
		$this->assertSame( 7, tapgrein_float_to_string( 7 ) );
	}

	public function test_seconds_to_string_formats_human_readable_duration() {
		$this->assertSame( '1 second', tapgrein_seconds_to_string( 1 ) );
		$this->assertSame( '2 minutes', tapgrein_seconds_to_string( 120 ) );
		$this->assertSame( '1 hour, 1 minute, 1 second', tapgrein_seconds_to_string( 3661 ) );
		$this->assertSame( '', tapgrein_seconds_to_string( 0 ) );
	}

	public function test_sanitize_permalink_strips_protocol_and_trailing_slash() {
		// $wpdb is used only to strip invalid text; stub it to pass values through.
		$wpdb          = \Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'strip_invalid_text_for_column' )
			->andReturnUsing( static fn( $table, $col, $value ) => $value );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias( static fn( $v ) => rtrim( $v, '/' ) );

		$this->assertSame( 'example.com/shop', tapgrein_sanitize_permalink( 'http://example.com/shop/' ) );
		$this->assertSame( '', tapgrein_sanitize_permalink( null ) );

		unset( $GLOBALS['wpdb'] );
	}
}
