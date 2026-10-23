<?php
/**
 * Integration: the Action Scheduler driver against a REAL WordPress + real
 * Action Scheduler (the bundled lib/action-scheduler, loaded by the plugin
 * bootstrap) and the offline TapGoods mock (TG_MOCK).
 *
 * Verifies what the isolated unit suite cannot: that the bundled library really
 * loads, its queue store accepts an enqueue, the no-duplicate guard holds
 * against the real store, and driving the plugin's AS hook runs a real slice
 * that reaches COMPLETED and materialises posts.
 *
 * @package Tapgoods\Tests\Integration
 */

namespace Tapgoods\Tests\Integration;

use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_Scheduler;
use Tapgoods_Sync_State;
use WP_UnitTestCase;

final class SyncSchedulerTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_singleton( Tapgoods_Connection::class );
		$this->reset_singleton( Tapgoods_Sync_State::class );
		$this->reset_singleton( Tapgoods_Sync_Log::class );

		// Start every test from an empty slice queue so the guard assertions are
		// not polluted by an action left over from a previous test.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Tapgoods_Sync_Scheduler::HOOK, array(), Tapgoods_Sync_Scheduler::GROUP );
		}
	}

	private function reset_singleton( string $class ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	private function connection(): Tapgoods_Connection {
		return Tapgoods_Connection::get_instance();
	}

	public function test_action_scheduler_is_bundled_and_available() {
		$this->assertTrue( class_exists( 'ActionScheduler' ), 'The bundled Action Scheduler library must be loaded.' );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ), 'as_enqueue_async_action must exist.' );
		$this->assertTrue( Tapgoods_Sync_Scheduler::is_available(), 'The scheduler must report itself available.' );
	}

	public function test_enqueue_creates_one_action_and_the_guard_prevents_duplicates() {
		$this->assertFalse(
			as_has_scheduled_action( Tapgoods_Sync_Scheduler::HOOK, null, Tapgoods_Sync_Scheduler::GROUP ),
			'Precondition: no slice queued.'
		);

		$this->assertTrue( Tapgoods_Sync_Scheduler::enqueue_slice() );
		$this->assertTrue(
			as_has_scheduled_action( Tapgoods_Sync_Scheduler::HOOK, null, Tapgoods_Sync_Scheduler::GROUP ),
			'A slice must now be scheduled in the real AS store.'
		);
		$this->assertSame( 1, $this->count_pending_slices() );

		// The guard must refuse to pile up a second identical slice.
		$this->assertTrue( Tapgoods_Sync_Scheduler::enqueue_slice() );
		$this->assertSame( 1, $this->count_pending_slices(), 'enqueue_slice must not duplicate an already-scheduled slice.' );

		// Deactivation-style cleanup drains the queue.
		Tapgoods_Sync_Scheduler::unschedule_all();
		$this->assertSame( 0, $this->count_pending_slices(), 'unschedule_all must drain the plugin queue.' );
	}

	public function test_driving_the_as_hook_advances_and_chains_to_completed() {
		// Fire the plugin's AS hook the way Action Scheduler would run each queued
		// action: one bounded slice per call, chaining the next while work remains.
		// This is budget-independent on purpose (another integration test in this
		// process permanently shrinks the per-slice page cap to 1 via
		// TG_SYNC_MAX_PAGES), so pump the hook until the run converges.
		$guard = 0;
		while ( Tapgoods_Sync_State::STATE_COMPLETED !== Tapgoods_Sync_State::get_instance()->get_state() ) {
			do_action( Tapgoods_Sync_Scheduler::HOOK );
			$this->assertLessThan( 25, ++$guard, 'The AS chain must converge, not loop forever.' );
		}

		$this->assertSame(
			Tapgoods_Sync_State::STATE_COMPLETED,
			Tapgoods_Sync_State::get_instance()->get_state(),
			'Driving the AS hook must advance the sync to COMPLETED.'
		);
		$this->assertGreaterThan( 1, $guard, 'The run should span multiple chained slices under the shrunk page cap.' );

		$posts = get_posts(
			array(
				'post_type'   => 'tg_inventory',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);
		$this->assertCount( 2, $posts, 'The AS-driven chain must materialise the fixture items.' );

		// Once COMPLETED, the last slice chained nothing. Intermediate slices did
		// enqueue successors in the real store; drain them so the next test starts
		// from an empty queue (setUp also does this).
		Tapgoods_Sync_Scheduler::unschedule_all();
	}

	public function test_chain_continues_while_in_progress_then_stops_when_completed() {
		// While a run is still ACTIVE, the chain must enqueue the next slice.
		Tapgoods_Sync_State::get_instance()->begin_prep()->mark_active();
		$this->assertTrue( Tapgoods_Sync_Scheduler::maybe_chain(), 'In-progress => chain the next slice.' );
		$this->assertSame( 1, $this->count_pending_slices(), 'The next slice must be queued in the real store.' );

		// Once the run is COMPLETED, the chain must stop (no new slice).
		as_unschedule_all_actions( Tapgoods_Sync_Scheduler::HOOK, array(), Tapgoods_Sync_Scheduler::GROUP );
		Tapgoods_Sync_State::get_instance()->mark_completed();
		$this->assertFalse( Tapgoods_Sync_Scheduler::maybe_chain(), 'COMPLETED => stop the chain.' );
		$this->assertSame( 0, $this->count_pending_slices() );
	}

	/**
	 * Count pending slice actions in the real Action Scheduler store.
	 *
	 * @return int
	 */
	private function count_pending_slices(): int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Tapgoods_Sync_Scheduler::HOOK,
				'group'    => Tapgoods_Sync_Scheduler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			),
			'ids'
		);
		return count( $actions );
	}
}
