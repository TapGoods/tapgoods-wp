<?php
/**
 * Unit tests for Tapgoods_Connection::filter_storefront_visible().
 *
 * getStorefrontCagetories returns every NestedSfCategory for a location, including
 * the internal buckets TapGoods keeps alongside the real ones. The bulk of those
 * come from an API migration that gave each parentless storefront sub-category a
 * synthetic parent named "<sub-category> (Uncategorized)" with visible_on_sf false.
 * On one live site that is 6,343 categories created every pass and deleted again by
 * finalize.
 *
 * The rule is deliberately the flag and not the name: TapGoods' own customer-facing
 * storefront filters the same model on `visible_on_sf: true`, and a merchant is free
 * to have a genuine category with "(Uncategorized)" in its name.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tapgoods_Connection;

final class ConnectionCategoryVisibilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @param array $names Category names present in the result. */
	private function names( $categories ) {
		return array_map(
			static function ( $category ) {
				return $category['name'];
			},
			$categories
		);
	}

	public function test_hidden_categories_are_dropped_and_visible_ones_kept() {
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array( 'id' => 1, 'name' => 'Tables', 'visibleOnSf' => true ),
				array( 'id' => 2, 'name' => 'Round Tables (Uncategorized)', 'visibleOnSf' => false ),
				array( 'id' => 3, 'name' => 'Linen', 'visibleOnSf' => true ),
			)
		);

		$this->assertSame( array( 'Tables', 'Linen' ), $this->names( $filtered ) );
	}

	public function test_the_rule_is_the_flag_not_the_name() {
		// A merchant may legitimately name a visible category this way.
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array( 'id' => 1, 'name' => 'Bits and Pieces (Uncategorized)', 'visibleOnSf' => true ),
			)
		);

		$this->assertCount( 1, $filtered, 'Only the flag may decide, never the name.' );
	}

	public function test_a_missing_flag_counts_as_visible() {
		// Older API, or a fixture that does not carry the field: behaviour must not
		// change, and certainly must not silently empty the category menu.
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array( 'id' => 1, 'name' => 'Tables' ),
				array( 'id' => 2, 'name' => 'Chairs' ),
			)
		);

		$this->assertSame( array( 'Tables', 'Chairs' ), $this->names( $filtered ) );
	}

	public function test_falsey_flag_shapes_all_count_as_hidden() {
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array( 'id' => 1, 'name' => 'false',  'visibleOnSf' => false ),
				array( 'id' => 2, 'name' => 'null',   'visibleOnSf' => null ),
				array( 'id' => 3, 'name' => 'zero',   'visibleOnSf' => 0 ),
				array( 'id' => 4, 'name' => 'string', 'visibleOnSf' => '0' ),
				array( 'id' => 5, 'name' => 'keeper', 'visibleOnSf' => true ),
			)
		);

		$this->assertSame( array( 'keeper' ), $this->names( $filtered ) );
	}

	public function test_sub_categories_are_pruned_by_the_same_rule() {
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array(
					'id'              => 1,
					'name'            => 'Tables',
					'visibleOnSf'     => true,
					'sfSubCategories' => array(
						array( 'id' => 11, 'name' => 'Round', 'visibleOnSf' => true ),
						array( 'id' => 12, 'name' => 'Hidden Bucket', 'visibleOnSf' => false ),
						array( 'id' => 13, 'name' => 'No flag here' ),
					),
				),
			)
		);

		$this->assertCount( 1, $filtered );
		$this->assertSame(
			array( 'Round', 'No flag here' ),
			$this->names( $filtered[0]['sfSubCategories'] ),
			'Sub-categories follow the same rule, including "absent means visible".'
		);
	}

	public function test_a_visible_sub_category_does_not_rescue_a_hidden_parent() {
		// The live shape: the hidden synthetic parent exists precisely to hold one
		// visible orphan sub-category. Dropping the parent is the point.
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array(
					'id'              => 703,
					'name'            => 'Linens (Uncategorized)',
					'visibleOnSf'     => false,
					'sfSubCategories' => array(
						array( 'id' => 804, 'name' => 'Linens', 'visibleOnSf' => true ),
					),
				),
			)
		);

		$this->assertSame( array(), $filtered );
	}

	public function test_categories_keep_their_shape() {
		$input = array(
			array(
				'id'              => 1,
				'name'            => 'Tables',
				'slug'            => 'tables',
				'visibleOnSf'     => true,
				'sfSubCategories' => array( array( 'id' => 11, 'name' => 'Round', 'visibleOnSf' => true ) ),
			),
		);

		$filtered = Tapgoods_Connection::filter_storefront_visible( $input );

		// The upsert path reads id / name / slug straight off these, so nothing may be
		// reshaped on the way through.
		$this->assertSame( 1, $filtered[0]['id'] );
		$this->assertSame( 'tables', $filtered[0]['slug'] );
		$this->assertSame( 11, $filtered[0]['sfSubCategories'][0]['id'] );
	}

	public function test_result_is_a_list_with_no_index_holes() {
		// The bounded upsert walks this by integer index (cat_item_index), so a
		// filtered-out entry must not leave a gap.
		$filtered = Tapgoods_Connection::filter_storefront_visible(
			array(
				array( 'id' => 1, 'name' => 'hidden', 'visibleOnSf' => false ),
				array( 'id' => 2, 'name' => 'kept a', 'visibleOnSf' => true ),
				array( 'id' => 3, 'name' => 'hidden', 'visibleOnSf' => false ),
				array( 'id' => 4, 'name' => 'kept b', 'visibleOnSf' => true ),
			)
		);

		$this->assertSame( array( 0, 1 ), array_keys( $filtered ) );
	}

	public function test_junk_input_is_survivable() {
		$this->assertSame( array(), Tapgoods_Connection::filter_storefront_visible( false ) );
		$this->assertSame( array(), Tapgoods_Connection::filter_storefront_visible( null ) );
		$this->assertSame( array(), Tapgoods_Connection::filter_storefront_visible( 'nope' ) );
		$this->assertSame( array(), Tapgoods_Connection::filter_storefront_visible( array( 'not-an-array' ) ) );
	}
}
