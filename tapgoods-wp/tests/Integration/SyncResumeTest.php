<?php
/**
 * Integration: a BOUNDED, RESUMABLE sync converges across multiple cron ticks
 * and writes the expected posts (WPB-173).
 *
 * With the per-slice page cap forced to 1 (TG_SYNC_MAX_PAGES), a single
 * invocation can only do part of the work and returns "in progress". Driving the
 * cron entry point (sync_from_api) repeatedly must resume from the persisted
 * cursor each time and eventually reach COMPLETED, with the catalog fully
 * materialised. This proves the fix for the WPB-165 "never converges" failure
 * without any host-specific branching.
 *
 * Named to sort AFTER SyncFlowTest so the TG_SYNC_MAX_PAGES constant it defines
 * (constants are process-global and permanent) cannot shrink the budget for the
 * single-shot expectations in that earlier test.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Sync_State;
use WP_UnitTestCase;

final class SyncResumeTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Force at most one inventory page per slice: the two-location fixture
		// then needs several ticks to finish (categories + each location + finalize).
		if ( ! defined( 'TG_SYNC_MAX_PAGES' ) ) {
			define( 'TG_SYNC_MAX_PAGES', 1 );
		}

		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$sref  = new ReflectionClass( Tapgoods_Sync_State::class );
		$sprop = $sref->getProperty( 'instance' );
		$sprop->setAccessible( true );
		$sprop->setValue( null, null );

		delete_option( 'tg_sync_state' );
		delete_transient( Tapgoods_Connection::RUN_LOCK );
	}

	private function connection(): Tapgoods_Connection {
		return Tapgoods_Connection::get_instance();
	}

	public function test_sync_converges_over_multiple_ticks() {
		$conn  = $this->connection();
		$state = Tapgoods_Sync_State::get_instance();

		$ticks     = 0;
		$max_ticks = 20; // safety net against an infinite loop.

		do {
			$result = $conn->sync_from_api();
			++$ticks;
			$in_progress = ! empty( $result['in_progress'] );
		} while ( $in_progress && $ticks < $max_ticks );

		$this->assertGreaterThan(
			1,
			$ticks,
			'A bounded sync of a multi-location catalog must take more than one tick.'
		);
		$this->assertLessThan( $max_ticks, $ticks, 'The sync must actually converge, not spin.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			Tapgoods_Sync_State::STATE_COMPLETED,
			Tapgoods_Sync_State::get_instance()->get_state(),
			'The run must end COMPLETED.'
		);

		// The full catalog from the fixtures is present.
		$posts = get_posts(
			array(
				'post_type'   => 'tg_inventory',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);
		$this->assertCount( 2, $posts, 'Both fixture items should exist after the resumable sync.' );

		$ids = array();
		foreach ( $posts as $p ) {
			$ids[] = (string) get_post_meta( $p->ID, 'tg_id', true );
		}
		sort( $ids );
		$this->assertSame( array( '11001', '11002' ), $ids );

		// The bounded, resumable categories pass must have materialised the fixture
		// terms and survived the deferred obsolete-term reconciliation (which only
		// runs once every location's categories are collected, against the FULL
		// valid-id set). A premature reconciliation on a partial pass would have
		// deleted these before the run finished.
		$this->assertNotFalse( get_term_by( 'slug', 'tables', 'tg_category' ), 'The "tables" category must survive the resumable run.' );
		$this->assertNotFalse( get_term_by( 'slug', 'chairs', 'tg_category' ), 'The "chairs" category must survive the resumable run.' );
		$this->assertNotFalse( get_term_by( 'slug', 'tag-round-tables', 'tg_tags' ), 'A sub-category tag must survive the resumable run.' );
	}
}
