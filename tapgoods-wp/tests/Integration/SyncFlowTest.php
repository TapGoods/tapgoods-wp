<?php
/**
 * Integration: a real sync writing into real posts / meta / terms.
 *
 * Drives Tapgoods_Connection against the offline mock TapGoods API (enabled via
 * TG_MOCK in the integration bootstrap) and asserts that the sync materialises
 * WordPress data: tg_inventory posts, their tg_* meta, and tg_category /
 * tg_tags terms. No network is touched.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use ReflectionClass;
use Tapgoods_Connection;
use WP_UnitTestCase;

final class SyncFlowTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// The connection is a singleton built at plugin load; reset it so each
		// test starts from a clean client/key.
		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		delete_transient( 'tapgrein_sync_lock' );
	}

	private function connection(): Tapgoods_Connection {
		return Tapgoods_Connection::get_instance();
	}

	public function test_client_is_the_offline_mock() {
		$client = $this->connection()->get_client();
		$this->assertInstanceOf( \Tapgoods_Mock_API_Client::class, $client );
	}

	public function test_category_sync_creates_terms() {
		$ok = $this->connection()->sync_categories_from_api();
		$this->assertTrue( $ok );

		$tables = get_term_by( 'slug', 'tables', 'tg_category' );
		$this->assertNotFalse( $tables, 'Expected a "tables" category from the fixtures.' );

		$chairs = get_term_by( 'slug', 'chairs', 'tg_category' );
		$this->assertNotFalse( $chairs, 'Expected a "chairs" category from the fixtures.' );
	}

	public function test_inventory_sync_creates_posts_with_meta() {
		$result = $this->connection()->sync_inventory_in_batches( true );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$posts = get_posts(
			array(
				'post_type'   => 'tg_inventory',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);
		$this->assertCount( 2, $posts, 'Both fixture items should become posts.' );

		// The two fixture item ids should be present as tg_id meta.
		$ids = array();
		foreach ( $posts as $p ) {
			$ids[] = (string) get_post_meta( $p->ID, 'tg_id', true );
		}
		sort( $ids );
		$this->assertSame( array( '11001', '11002' ), $ids );

		// A representative meta field mapped by prepare_meta_input().
		$table = get_page_by_path( '6ft-round-table', OBJECT, 'tg_inventory' );
		$this->assertInstanceOf( \WP_Post::class, $table );
		$this->assertSame( '12.00', get_post_meta( $table->ID, 'tg_dailyPrice', true ) );
	}

	public function test_sync_releases_the_lock_when_it_finishes() {
		// The sync must never leave the 'tapgrein_sync_lock' transient set once it
		// returns, otherwise the every-5-minute cron keeps reporting "Sync in
		// progress" and the admin sync screen never clears (WPB-165). The lock is
		// released in a finally block so this holds even on an error mid-sync.
		$result = $this->connection()->sync_inventory_in_batches( true );
		$this->assertTrue( $result['success'] );
		$this->assertFalse(
			get_transient( 'tapgrein_sync_lock' ),
			'The sync lock must be cleared once sync_inventory_in_batches() returns.'
		);
	}

	public function test_full_sync_reports_success() {
		$result = $this->connection()->sync_from_api();
		$this->assertTrue( $result['success'] );

		// Items exist and at least one is assigned to the "Tables" category.
		$in_tables = get_posts(
			array(
				'post_type'   => 'tg_inventory',
				'numberposts' => -1,
				'tax_query'   => array(
					array(
						'taxonomy' => 'tg_category',
						'field'    => 'slug',
						'terms'    => 'tables',
					),
				),
			)
		);
		$this->assertNotEmpty( $in_tables, 'The round table should be filed under Tables.' );
	}
}
