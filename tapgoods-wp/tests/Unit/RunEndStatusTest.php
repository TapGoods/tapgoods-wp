<?php
/**
 * Unit tests for how a slice's sync.run.end result is labelled.
 *
 * This is a reporting rule, and it was wrong in a way that cost real debugging
 * time. It consulted has_error(), which stays true after a failure the run has
 * already recovered from, because error_message is only cleared when a run
 * completes. On a customer site that meant 823 consecutive successful slices,
 * fourteen hours of steady progress, were every one of them logged result=error.
 * The log read as a site-wide failure while nothing was actually failing, and the
 * genuine problem in it was buried under the false alarms.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Sync_State;

final class RunEndStatusTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( 1000 );
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

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class ) as $class ) {
			$ref  = new ReflectionClass( $class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setValue( null, null );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function state(): Tapgoods_Sync_State {
		return Tapgoods_Sync_State::get_instance();
	}

	/** Spend the retry budget so the run latches to ERROR. */
	private function latch_error( Tapgoods_Sync_State $state ): void {
		for ( $i = 0; $i <= Tapgoods_Sync_State::MAX_RETRIES; $i++ ) {
			$state->mark_error( 'gave up' );
		}
	}

	public function test_a_successful_slice_is_ok() {
		$state = $this->state();
		$state->begin_prep()->mark_active();

		$this->assertSame( 'ok', Tapgoods_Connection::run_end_status( true, $state ) );
	}

	public function test_a_recovered_error_does_not_taint_later_slices() {
		// The exact customer scenario: one transient failure hours ago, the run
		// carried on, and every slice since reported error.
		$state = $this->state();
		$state->begin_prep()->mark_active();
		// One failure inside the retry budget: state drops to IDLE, message sticks.
		$will_retry = $state->mark_error( 'URL failed to respond: https://openapi.tapgoods.com/v1/external/graphql' );
		$this->assertTrue( $will_retry, 'A single failure is retried, not latched.' );
		$state->mark_active(); // The next tick resumes the run.

		$this->assertTrue( $state->has_error(), 'The message is still there for the admin screen to show.' );
		$this->assertFalse( $state->has_latched_error(), 'But the run is not stopped.' );
		$this->assertSame(
			'ok',
			Tapgoods_Connection::run_end_status( true, $state ),
			'A slice that succeeded must be logged ok, whatever happened earlier in the run.'
		);
	}

	public function test_a_latched_error_is_reported_as_error() {
		$state = $this->state();
		$state->begin_prep()->mark_active();
		$this->latch_error( $state );

		$this->assertTrue( $state->has_latched_error() );
		$this->assertSame( 'error', Tapgoods_Connection::run_end_status( true, $state ) );
	}

	public function test_a_failed_slice_is_reported_as_error() {
		$state = $this->state();
		$state->begin_prep()->mark_active();

		$this->assertSame( 'error', Tapgoods_Connection::run_end_status( false, $state ) );
	}

	public function test_has_error_and_has_latched_error_agree_on_a_stopped_run() {
		$state = $this->state();
		$state->begin_prep()->mark_active();
		$this->latch_error( $state );

		$this->assertTrue( $state->has_error() );
		$this->assertTrue( $state->has_latched_error() );
	}

	public function test_a_completed_run_clears_the_error_message() {
		// Completion is what clears the sticky message today, which is why the bug
		// only showed on long runs. Pinned so that stays true.
		$state = $this->state();
		$state->begin_prep()->mark_active();
		$state->mark_error( 'transient' );
		$state->mark_active();
		$state->mark_completed();

		$this->assertFalse( $state->has_error() );
		$this->assertFalse( $state->has_latched_error() );
		$this->assertSame( 0, $state->get_failure_count() );
	}
}
