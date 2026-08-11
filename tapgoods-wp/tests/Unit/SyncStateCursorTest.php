<?php
/**
 * Unit tests for the resumable paging cursor added to Tapgoods_Sync_State
 * (WPB-173 bounded/resumable sync).
 *
 * Unlike SyncStateTest, these use a real in-memory option store so persistence
 * is exercised for real: a checkpoint written by one instance must be visible to
 * a freshly-loaded instance (the "next cron tick" resuming from the cursor).
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Sync_State;

final class SyncStateCursorTest extends TestCase {

	/** @var array In-memory option store shared by every instance in a test. */
	private $store = array();

	/** @var int Mutable fake clock. */
	private $clock = 1000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

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

	public function test_cursor_defaults_are_full_shaped() {
		$s      = new Tapgoods_Sync_State();
		$cursor = $s->get_cursor();

		$this->assertSame( array(), $cursor['location_ids'] );
		$this->assertSame( 0, $cursor['location_index'] );
		$this->assertSame( 1, $cursor['next_page'] );
		$this->assertSame( array(), $cursor['synced_ids'] );
		$this->assertSame( 0, $cursor['total_items'] );
		$this->assertFalse( $cursor['categories_done'] );
		$this->assertSame( 'paging', $cursor['phase'] );
	}

	public function test_init_cursor_stringifies_and_seeds_location_ids() {
		$s = new Tapgoods_Sync_State();
		$s->init_cursor( array( 5001, 5002 ) );

		$cursor = $s->get_cursor();
		$this->assertSame( array( '5001', '5002' ), $cursor['location_ids'] );
		$this->assertTrue( $s->cursor_has_remaining_paging() );
	}

	public function test_checkpoint_persists_across_a_fresh_instance() {
		// "Tick 1": begin a run and checkpoint partway through.
		$a = new Tapgoods_Sync_State();
		$a->begin_prep( 4 )->mark_active();
		$a->init_cursor( array( 5001, 5002 ) );

		$cursor                    = $a->get_cursor();
		$cursor['location_index']  = 1;
		$cursor['next_page']       = 3;
		$cursor['synced_ids']      = array( '11001', '11002' );
		$cursor['total_items']     = 2;
		$cursor['categories_done'] = true;
		$a->save_cursor( $cursor );

		// "Tick 2": a brand-new instance loads from the persisted option and must
		// resume exactly where tick 1 stopped, not restart at page 1.
		$b        = new Tapgoods_Sync_State();
		$resumed  = $b->get_cursor();

		$this->assertTrue( $b->is_running(), 'The run should still be ACTIVE for the next tick.' );
		$this->assertSame( 1, $resumed['location_index'] );
		$this->assertSame( 3, $resumed['next_page'] );
		$this->assertSame( array( '11001', '11002' ), $resumed['synced_ids'] );
		$this->assertSame( 2, $resumed['total_items'] );
		$this->assertTrue( $resumed['categories_done'] );
		$this->assertTrue( $b->cursor_has_remaining_paging() );
	}

	public function test_finalize_phase_reports_no_remaining_paging() {
		$s = new Tapgoods_Sync_State();
		$s->begin_prep()->mark_active();
		$s->init_cursor( array( 5001 ) );

		$cursor          = $s->get_cursor();
		$cursor['phase'] = 'finalize';
		$s->save_cursor( $cursor );

		$this->assertFalse( $s->cursor_has_remaining_paging() );
	}

	public function test_begin_prep_resets_a_stale_cursor() {
		$s = new Tapgoods_Sync_State();
		$s->init_cursor( array( 5001, 5002 ) );
		$cursor                   = $s->get_cursor();
		$cursor['location_index'] = 1;
		$s->save_cursor( $cursor );

		// A brand-new run must not inherit the previous run's position.
		$s->begin_prep();
		$reset = $s->get_cursor();
		$this->assertSame( array(), $reset['location_ids'] );
		$this->assertSame( 0, $reset['location_index'] );
		$this->assertSame( 1, $reset['next_page'] );
		$this->assertFalse( $reset['categories_done'] );
	}

	public function test_progress_refreshes_staleness_so_a_long_run_is_not_taken_over() {
		// A resumable run that keeps checkpointing must never look stale, even long
		// after it started: staleness is measured from the last checkpoint.
		$this->clock = 1000;
		$a           = new Tapgoods_Sync_State();
		$a->begin_prep()->mark_active();
		$a->init_cursor( array( 5001 ) );

		// Advance well past STALE_AFTER, but keep checkpointing along the way.
		$this->clock = 1000 + Tapgoods_Sync_State::STALE_AFTER + 500;
		$a->save_cursor( $a->get_cursor() ); // refreshes updated_at to "now".

		$b = new Tapgoods_Sync_State();
		$this->assertFalse( $b->is_stale(), 'A run that just checkpointed is not stale.' );
		$this->assertTrue( $b->is_running() );

		// Now let it go quiet past the window: it becomes stale and can be taken over.
		$this->clock = $this->clock + Tapgoods_Sync_State::STALE_AFTER + 1;
		$c           = new Tapgoods_Sync_State();
		$this->assertTrue( $c->is_stale() );
		$this->assertFalse( $c->is_running() );
		$this->assertTrue( $c->can_start() );
	}
}
