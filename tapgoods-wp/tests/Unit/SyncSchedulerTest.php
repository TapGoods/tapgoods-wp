<?php
/**
 * Unit tests for Tapgoods_Sync_Scheduler, the Action Scheduler driver for the
 * inventory sync. Isolated (no WordPress): the `as_*()` functions and the WP
 * option/time helpers are stubbed via Brain\Monkey.
 *
 * Covers the chaining contract:
 *   - a slice that leaves the run IN PROGRESS enqueues the next action;
 *   - a COMPLETED run enqueues nothing;
 *   - a latched-ERROR run enqueues nothing;
 *   - the start/re-arm entry (what the manual "Sync Now" button calls) enqueues
 *     the first action, and never a duplicate when one is already scheduled.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Sync_Scheduler;
use Tapgoods_Sync_State;

final class SyncSchedulerTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var int Fake clock. */
	private $clock = 1000;

	/** @var int Count of as_enqueue_async_action() calls seen. */
	private $enqueued = 0;

	/** @var array Args captured from the last as_enqueue_async_action() call. */
	private $last_enqueue = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store        = array();
		$this->enqueued     = 0;
		$this->last_enqueue = array();
		$store              = &$this->store;

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$clock = &$this->clock;
		Functions\when( 'current_time' )->alias(
			static function () use ( &$clock ) {
				return $clock;
			}
		);

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-sync-scheduler.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		$this->reset_singletons();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singletons(): void {
		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class ) as $class ) {
			$ref  = new ReflectionClass( $class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setValue( null, null );
		}
	}

	/**
	 * Stub the Action Scheduler API. All four functions Tapgoods_Sync_Scheduler
	 * probes are defined so is_available() is true; as_enqueue_async_action is a
	 * spy, as_has_scheduled_action is driven by $already_scheduled.
	 *
	 * @param bool $already_scheduled What as_has_scheduled_action reports.
	 * @return void
	 */
	private function stub_action_scheduler( bool $already_scheduled = false ): void {
		$enqueued     = &$this->enqueued;
		$last_enqueue = &$this->last_enqueue;

		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued, &$last_enqueue ) {
				++$enqueued;
				$last_enqueue = array(
					'hook'  => $hook,
					'args'  => $args,
					'group' => $group,
				);
				return 123; // A fake action id, as Action Scheduler returns.
			}
		);
		Functions\when( 'as_has_scheduled_action' )->justReturn( $already_scheduled );
		Functions\when( 'as_next_scheduled_action' )->justReturn( $already_scheduled ? 12345 : false );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( 0 );
	}

	// --- enqueue_slice: start / re-arm (the manual "Sync Now" entry) ----------

	public function test_manual_start_enqueues_the_first_action() {
		$this->stub_action_scheduler( false ); // Nothing scheduled yet.

		$result = Tapgoods_Sync_Scheduler::enqueue_slice();

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->enqueued, 'The first slice must be enqueued exactly once.' );
		$this->assertSame( Tapgoods_Sync_Scheduler::HOOK, $this->last_enqueue['hook'] );
		$this->assertSame( Tapgoods_Sync_Scheduler::GROUP, $this->last_enqueue['group'] );
	}

	public function test_enqueue_slice_does_not_duplicate_when_one_is_already_scheduled() {
		$this->stub_action_scheduler( true ); // A slice is already pending/running.

		$result = Tapgoods_Sync_Scheduler::enqueue_slice();

		$this->assertTrue( $result, 'Reports success (one is already scheduled) ...' );
		$this->assertSame( 0, $this->enqueued, '... but must NOT enqueue a duplicate.' );
	}

	// --- maybe_chain: continue-if-in-progress, stop-on-complete/error ---------

	public function test_slice_in_progress_enqueues_the_next_action() {
		$this->stub_action_scheduler( true ); // Irrelevant: chaining ignores the guard.

		// A run still ACTIVE (paging/finalize left to do): work remains.
		Tapgoods_Sync_State::get_instance()->begin_prep()->mark_active();

		$chained = Tapgoods_Sync_Scheduler::maybe_chain();

		$this->assertTrue( $chained, 'An in-progress run must chain the next slice.' );
		$this->assertSame( 1, $this->enqueued, 'Exactly one successor is enqueued.' );
		// Chaining does NOT self-block on the pending/running guard (the running
		// action would otherwise always cancel its own successor).
		$this->assertSame( Tapgoods_Sync_Scheduler::HOOK, $this->last_enqueue['hook'] );
	}

	public function test_completed_run_does_not_enqueue() {
		$this->stub_action_scheduler( false );

		Tapgoods_Sync_State::get_instance()->begin_prep()->mark_active();
		Tapgoods_Sync_State::get_instance()->mark_completed();

		$chained = Tapgoods_Sync_Scheduler::maybe_chain();

		$this->assertFalse( $chained, 'A COMPLETED run must stop the chain.' );
		$this->assertSame( 0, $this->enqueued );
	}

	public function test_error_latched_run_does_not_enqueue() {
		$this->stub_action_scheduler( false );

		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep()->mark_active();
		// Exhaust the retry budget so the state latches to ERROR (MAX_RETRIES + 1).
		for ( $i = 0; $i <= Tapgoods_Sync_State::MAX_RETRIES; $i++ ) {
			$state->mark_error( 'boom' );
		}
		$this->assertSame( Tapgoods_Sync_State::STATE_ERROR, $state->get_state(), 'Precondition: latched ERROR.' );

		$chained = Tapgoods_Sync_Scheduler::maybe_chain();

		$this->assertFalse( $chained, 'A latched-ERROR run must NOT be re-enqueued (no retry storm).' );
		$this->assertSame( 0, $this->enqueued );
	}

	// --- run_slice: one slice per action, then chain --------------------------

	public function test_run_slice_runs_exactly_one_slice_then_chains_when_in_progress() {
		$this->stub_action_scheduler( true );

		// Park the shared state ACTIVE so the stub connection's sync_state() (which
		// returns the real singleton) reports "in progress" after the slice.
		Tapgoods_Sync_State::get_instance()->begin_prep()->mark_active();

		$stub = $this->install_stub_connection();

		Tapgoods_Sync_Scheduler::run_slice();

		$this->assertSame( 1, $stub->ran, 'run_slice runs exactly one bounded slice.' );
		$this->assertSame( array( false, 'as_slice' ), $stub->last_args, 'Slice runs non-manual, labelled as_slice.' );
		$this->assertSame( 1, $this->enqueued, 'In-progress after the slice => chain the next.' );
	}

	public function test_run_slice_does_not_chain_when_the_run_completed() {
		$this->stub_action_scheduler( false );

		// The slice drives the run to COMPLETED.
		$stub = $this->install_stub_connection(
			static function () {
				Tapgoods_Sync_State::get_instance()->mark_completed();
			}
		);
		Tapgoods_Sync_State::get_instance()->begin_prep()->mark_active();

		Tapgoods_Sync_Scheduler::run_slice();

		$this->assertSame( 1, $stub->ran );
		$this->assertSame( 0, $this->enqueued, 'A completed run stops the chain.' );
	}

	/**
	 * Swap the Tapgoods_Connection singleton for a stub that records the slice
	 * call and reports the shared state machine, so run_slice can be exercised
	 * without a real sync.
	 *
	 * @param callable|null $on_slice Optional side effect to run inside the slice.
	 * @return object The installed stub (has ->ran and ->last_args).
	 */
	private function install_stub_connection( $on_slice = null ) {
		$stub = new class( $on_slice ) {
			/** @var int */
			public $ran = 0;
			/** @var array */
			public $last_args = array();
			/** @var callable|null */
			private $on_slice;
			public function __construct( $on_slice ) {
				$this->on_slice = $on_slice;
			}
			public function sync_inventory_in_batches( $manual = false, $trigger = null ) {
				++$this->ran;
				$this->last_args = array( $manual, $trigger );
				if ( null !== $this->on_slice ) {
					call_user_func( $this->on_slice );
				}
				return array( 'success' => true );
			}
			public function sync_state() {
				return Tapgoods_Sync_State::get_instance();
			}
		};

		$ref  = new ReflectionClass( Tapgoods_Connection::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, $stub );

		return $stub;
	}
}
