<?php
/**
 * Shortcode templates must not declare named PHP functions (WPB-187).
 *
 * Tapgoods_Shortcodes::shortcode_handler() renders each shortcode with a plain
 * `include` of its template, so a template runs once per render. A named
 * function declared in a template is declared again on the second render in the
 * same request and PHP stops with "Cannot redeclare". That is what took down
 * the page editor on a page carrying [tapgoods-location-select]: the edit screen
 * renders the content more than once. Shared helpers belong in
 * includes/tapgoods-core-functions.php, which is loaded once.
 *
 * Template JavaScript is inline HTML to the PHP tokenizer, so its `function`
 * keywords are not seen here. Closures have no name and are allowed.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PartialsDeclareNoFunctionsTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function partials(): array {
		$cases = array();
		foreach ( glob( ABSPATH . 'public/partials/*.php' ) as $file ) {
			$cases[ basename( $file ) ] = array( $file );
		}
		return $cases;
	}

	/**
	 * @dataProvider partials
	 */
	public function test_template_declares_no_named_function( string $file ) {
		$tokens   = token_get_all( file_get_contents( $file ) );
		$declared = array();
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			// The next meaningful token is the name for a declaration, "(" or "&" for a closure.
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$declared[] = $tokens[ $j ][1] . '() on line ' . $tokens[ $j ][2];
				}
				break;
			}
		}

		$this->assertSame(
			array(),
			$declared,
			basename( $file ) . ' is included on every render; a named function in it fatals the second time.'
		);
	}

	public function test_the_location_select_template_is_among_those_checked() {
		$this->assertArrayHasKey( 'tg-location-select.php', self::partials() );
	}
}
