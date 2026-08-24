<?php
/**
 * Unit tests for the offline mock client (tests/mock/class-tapgoods-mock-api-client.php)
 *
 * Confirms the mock serves the static fixtures in the same shape the real
 * Tapgoods_API_Client returns, so callers can trust it as a stand-in.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tapgoods_Mock_API_Client;

final class MockApiClientTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/mock/class-tapgoods-mock-api-client.php';
	}

	private function client(): Tapgoods_Mock_API_Client {
		return new Tapgoods_Mock_API_Client(
			array(
				'base_url' => 'https://openapi.tapgoods.com',
				'api_key'  => 'test-key',
				'tg_env'   => 'tapgoods.com',
			)
		);
	}

	public function test_validate_key_returns_business_and_locations() {
		$business = $this->client()->validate_key();

		$this->assertSame( 1042, $business['businessId'] );
		$this->assertSame( array( 5001, 5002 ), $business['locationIds'] );
	}

	public function test_get_location_ids() {
		$this->assertSame( array( 5001, 5002 ), $this->client()->get_location_ids() );
	}

	public function test_get_categories_returns_category_tree() {
		$categories = $this->client()->get_categories_from_graph( 5001 );

		// The mock stands in for the API, so it returns what the API returns: a FLAT
		// list carrying storefront categories, the internal buckets alongside them,
		// AND sub-categories repeated as their own top-level entries. Filtering is the
		// consumer's job (Tapgoods_Connection::storefront_category_list).
		$this->assertCount( 4, $categories );
		$this->assertSame( 'Tables', $categories[0]['name'] );
		$this->assertCount( 3, $categories[0]['sfSubCategories'] );
	}

	public function test_categories_carry_the_storefront_visibility_flag() {
		$categories = $this->client()->get_categories_from_graph( 5001 );

		$by_name = array();
		foreach ( $categories as $category ) {
			$by_name[ $category['name'] ] = $category;
		}

		// The fixture has to keep exercising both sides of the flag, on categories and
		// on sub-categories, or the filter has nothing to bite on end to end.
		$this->assertTrue( $by_name['Tables']['visibleOnSf'] );
		$this->assertFalse( $by_name['Linens (Uncategorized)']['visibleOnSf'] );

		// And the flat duplicate carries its parent, which is what marks it a child.
		$this->assertNull( $by_name['Tables']['parentId'] );
		$this->assertSame( 701, $by_name['Round Tables']['parentId'] );

		$subs = array();
		foreach ( $by_name['Tables']['sfSubCategories'] as $sub ) {
			$subs[ $sub['name'] ] = $sub;
		}
		$this->assertTrue( $subs['Round Tables']['visibleOnSf'] );
		$this->assertFalse( $subs['Hidden Sub Bucket']['visibleOnSf'] );
	}

	public function test_get_location_details_adds_derived_fields() {
		$details = $this->client()->get_location_details_from_graph( 5001 );

		$this->assertSame( 5001, $details['id'] );
		$this->assertSame( 'Downtown Warehouse (DTW)', $details['fullName'] );
		// A configured domain drives the storefront URL.
		$this->assertSame( 'https://shop.example.com/', $details['sf_url'] );
		$this->assertSame( 'https://shop.example.com/cart?externalRedirect=true', $details['cart_url'] );
	}

	public function test_get_inventories_paginates_and_terminates() {
		$client = $this->client();

		$page1 = $client->get_inventories_from_graph( 5001, 1, 50 );
		$this->assertCount( 2, $page1['collection'] );
		$this->assertSame( 1, $page1['metadata']['currentPage'] );
		$this->assertSame( '6ft Round Table', $page1['collection'][0]['name'] );

		// Later pages come back empty so batched sync loops terminate.
		$page2 = $client->get_inventories_from_graph( 5001, 2, 50 );
		$this->assertSame( array(), $page2['collection'] );
	}
}
