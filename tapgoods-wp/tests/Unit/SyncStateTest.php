<?php
/**
 * Unit tests for Tapgoods_Sync_State (the sync flow state machine).
 *
 * The class only touches a handful of WordPress option / time helpers, so it is
 * exercised in isolation with those stubbed via Brain\Monkey. Persistence is not
 * asserted through the option store: each test drives a single instance and
 * checks the in-memory state via the public getters (save() is a no-op stub).
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Sync_State;

final class SyncStateTest extends TestCase {

	/** @var int Mutable fake clock, advanced by the tests. */
	private $clock = 1000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// No stored state by default; each transition persists via update_option.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$clock = &$this->clock;
		Functions\when( 'current_time' )->alias(
			static function () use ( &$clock ) {
				return $clock;
			}
		);

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		$this->reset_singleton();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singleton(): void {
		$ref  = new ReflectionClass( Tapgoods_Sync_State::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, null );
	}

	private function state(): Tapgoods_Sync_State {
		return new Tapgoods_Sync_State();
	}

	public function test_starts_idle_and_can_start() {
		$s = $this->state();
		$this->assertSame( Tapgoods_Sync_State::STATE_IDLE, $s->get_state() );
		$this->assertTrue( $s->can_start() );
		$this->assertFalse( $s->is_running() );
	}

	public function test_prep_records_total_pages_and_locks() {
		$s = $this->state();
		$s->begin_prep( 7 );

		$this->assertSame( Tapgoods_Sync_State::STATE_PREP, $s->get_state() );
		$this->assertSame( 7, $s->get_total_pages() );
		$this->assertTrue( $s->is_running() );
		$this->assertFalse( $s->can_start(), 'A running sync must block a new start.' );
	}

	public function test_active_and_page_progress() {
		$s = $this->state();
		$s->begin_prep( 3 )->mark_active();

		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, $s->get_state() );
		$this->assertSame( 0, $s->get_pages_completed() );

		$s->increment_pages_completed();
		$s->increment_pages_completed();
		$this->assertSame( 2, $s->get_pages_completed() );
	}

	public function test_completed_records_success_and_duration_and_resets_failures() {
		$s = $this->state();
		$this->clock = 1000;
		$s->begin_prep( 2 )->mark_active();

		$this->clock = 1090; // 90 seconds later.
		$s->mark_completed();

		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, $s->get_state() );
		$this->assertSame( 1090, $s->get_last_success() );
		$this->assertSame( 90, $s->get_last_duration() );
		$this->assertSame( 0, $s->get_failure_count() );
		$this->assertTrue( $s->can_start() );
	}

	public function test_retries_twice_before_latching_to_error() {
		$s = $this->state();

		// First failure -> retry (back to IDLE), still startable.
		$this->assertTrue( $s->mark_error( 'boom 1' ) );
		$this->assertSame( Tapgoods_Sync_State::STATE_IDLE, $s->get_state() );
		$this->assertSame( 1, $s->get_failure_count() );
		$this->assertTrue( $s->can_start() );

		// Second failure -> still retryable.
		$this->assertTrue( $s->mark_error( 'boom 2' ) );
		$this->assertSame( Tapgoods_Sync_State::STATE_IDLE, $s->get_state() );
		$this->assertSame( 2, $s->get_failure_count() );

		// Third failure -> retry budget spent -> ERROR (terminal until cleared).
		$this->assertFalse( $s->mark_error( 'boom 3' ) );
		$this->assertSame( Tapgoods_Sync_State::STATE_ERROR, $s->get_state() );
		$this->assertSame( 3, $s->get_failure_count() );
		$this->assertTrue( $s->has_error() );
		$this->assertFalse( $s->can_start(), 'ERROR state must block cron from starting a new sync.' );
	}

	public function test_clear_errors_returns_to_idle_and_resets_budget() {
		$s = $this->state();
		$s->mark_error( 'a' );
		$s->mark_error( 'b' );
		$s->mark_error( 'c' ); // -> ERROR

		$s->clear_errors();

		$this->assertSame( Tapgoods_Sync_State::STATE_IDLE, $s->get_state() );
		$this->assertSame( 0, $s->get_failure_count() );
		$this->assertSame( '', $s->get_error_message() );
		$this->assertNull( $s->get_last_error() );
		$this->assertFalse( $s->has_error() );
		$this->assertTrue( $s->can_start() );
	}

	public function test_error_message_and_timestamp_are_recorded() {
		$s           = $this->state();
		$this->clock = 4242;
		$s->mark_error( 'the failure' );

		$this->assertSame( 'the failure', $s->get_error_message() );
		$this->assertSame( 4242, $s->get_last_error() );
	}

	public function test_stale_running_sync_is_not_running_and_can_be_taken_over() {
		$s           = $this->state();
		$this->clock = 1000;
		$s->begin_prep( 1 )->mark_active();
		$this->assertTrue( $s->is_running() );

		// Advance past the stale threshold: the crashed run frees the lock.
		$this->clock = 1000 + Tapgoods_Sync_State::STALE_AFTER + 1;
		$this->assertTrue( $s->is_stale() );
		$this->assertFalse( $s->is_running() );
		$this->assertTrue( $s->can_start() );
	}

	public function test_started_at_and_age_track_the_run_in_flight() {
		$s = $this->state();
		$this->assertNull( $s->get_started_at() );
		$this->assertNull( $s->get_age(), 'No run has ever started, so there is no age to report.' );

		$this->clock = 2000;
		$s->begin_prep( 1 );
		$this->assertSame( 2000, $s->get_started_at() );

		$this->clock = 2075;
		$this->assertSame( 75, $s->get_age() );
	}

	public function test_reset_restores_defaults() {
		$s = $this->state();
		$s->begin_prep( 5 )->mark_active();
		$s->increment_pages_completed();

		$s->reset();

		$this->assertSame( Tapgoods_Sync_State::STATE_IDLE, $s->get_state() );
		$this->assertSame( 0, $s->get_total_pages() );
		$this->assertSame( 0, $s->get_pages_completed() );
		$this->assertNull( $s->get_last_success() );
	}

	public function test_summary_shape() {
		$s = $this->state();
		$s->begin_prep( 4 )->mark_active();
		$summary = $s->get_summary();

		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, $summary['state'] );
		$this->assertSame( 'Syncing', $summary['label'] );
		$this->assertSame( 4, $summary['total_pages'] );
		$this->assertSame( Tapgoods_Sync_State::MAX_RETRIES, $summary['max_retries'] );
		$this->assertArrayHasKey( 'last_success', $summary );
		$this->assertArrayHasKey( 'error_message', $summary );
	}
}
