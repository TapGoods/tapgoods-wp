<?php
/**
 * Unit tests for the inventory grid page-size helpers (WPB-187).
 *
 * [tapgoods-inventory items_per_page="12|24|36|all"] resolves through
 * tapgrein_resolve_items_per_page() into one number that tg-inventory.php hands
 * to both the grid and the search box as per_page_default. "all" must come
 * out as -1, WP_Query's "no limit": the parser it replaced stripped every
 * non-digit, so "-1" became 1 and "all" became 0.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ItemsPerPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// tapgoods-core-functions.php registers hooks at include time.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		require_once ABSPATH . 'includes/tapgoods-core-functions.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -- tapgrein_resolve_items_per_page --------------------------------------

	public function test_defaults_to_twelve_without_attributes() {
		$this->assertSame( 12, tapgrein_resolve_items_per_page( array() ) );
		$this->assertSame( 12, tapgrein_resolve_items_per_page( null ) );
	}

	public function test_listed_options_are_honoured() {
		foreach ( array( '12' => 12, '24' => 24, '36' => 36 ) as $value => $expected ) {
			$this->assertSame(
				$expected,
				tapgrein_resolve_items_per_page( array( 'items_per_page' => (string) $value ) )
			);
		}
	}

	public function test_all_means_no_limit() {
		$this->assertSame( -1, tapgrein_resolve_items_per_page( array( 'items_per_page' => 'all' ) ) );
		$this->assertSame( -1, tapgrein_resolve_items_per_page( array( 'items_per_page' => 'ALL' ) ) );
	}

	public function test_editor_quotes_are_tolerated() {
		$this->assertSame( 24, tapgrein_resolve_items_per_page( array( 'items_per_page' => '“24”' ) ) );
		$this->assertSame( -1, tapgrein_resolve_items_per_page( array( 'items_per_page' => '"all"' ) ) );
	}

	public function test_items_per_page_wins_over_per_page_default() {
		$this->assertSame(
			36,
			tapgrein_resolve_items_per_page(
				array(
					'per_page_default' => 12,
					'items_per_page'   => '36',
				)
			)
		);
	}

	public function test_unlisted_value_falls_back_to_per_page_default() {
		foreach ( array( '48', '0', 'lots', '-5' ) as $value ) {
			$this->assertSame(
				12,
				tapgrein_resolve_items_per_page(
					array(
						'per_page_default' => 12,
						'items_per_page'   => $value,
					)
				),
				"items_per_page=\"{$value}\" should be ignored"
			);
		}
	}

	public function test_empty_items_per_page_keeps_per_page_default() {
		// The JSON default for items_per_page is "", and tg-tag-results.php
		// passes per_page_default="14" to the grid; that must not change.
		$this->assertSame(
			14,
			tapgrein_resolve_items_per_page(
				array(
					'per_page_default' => '14',
					'items_per_page'   => '',
				)
			)
		);
	}

	public function test_resolved_all_survives_the_hand_off_to_the_grid() {
		// tg-inventory.php passes the resolved -1 on as per_page_default="-1";
		// the grid and search box resolve it again and must still get -1.
		$this->assertSame( -1, tapgrein_resolve_items_per_page( array( 'per_page_default' => '-1' ) ) );
	}

	// -- tapgrein_normalize_per_page ------------------------------------------

	public function test_normalize_rejects_non_numbers() {
		$this->assertSame( 12, tapgrein_normalize_per_page( 'abc' ) );
		$this->assertSame( 12, tapgrein_normalize_per_page( '' ) );
		$this->assertSame( 12, tapgrein_normalize_per_page( '0' ) );
		$this->assertSame( 12, tapgrein_normalize_per_page( array( 24 ) ) );
		$this->assertSame( 7, tapgrein_normalize_per_page( '2.5', 7 ) );
	}

	public function test_normalize_accepts_integers_and_all() {
		$this->assertSame( 20, tapgrein_normalize_per_page( 20 ) );
		$this->assertSame( 20, tapgrein_normalize_per_page( ' 20 ' ) );
		$this->assertSame( -1, tapgrein_normalize_per_page( -1 ) );
		$this->assertSame( -1, tapgrein_normalize_per_page( 'all' ) );
	}
}
