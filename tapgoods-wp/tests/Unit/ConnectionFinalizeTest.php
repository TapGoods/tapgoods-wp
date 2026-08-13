<?php
/**
 * Unit tests for the MEMORY-BOUNDED, chunked, resumable finalize phase and the
 * run-token reconciliation helpers (WPB-172 OOM fix).
 *
 * Two layers are exercised:
 *   1. The removal helpers in isolation (real methods, a fake $wpdb):
 *      - remove_items_not_in_run() deletes only a bounded LIMIT-N page and never
 *        loads the whole catalog; a blank token deletes nothing.
 *      - remove_terms_not_in_run() is guarded so it can never wipe a taxonomy the
 *        run stamped no terms in.
 *   2. run_finalize_slice() orchestration (real method, a probe stubbing the
 *      removal seams): the empty-pass safety valve, token-keyed removal, and
 *      resuming a budget-interrupted finalize across slices.
 *
 * Isolated (no WordPress) via Brain\Monkey.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tapgoods_Connection;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;

final class ConnectionFinalizeTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var object Fake clock holder ({ t: int }). */
	private $clock;

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		$this->clock   = (object) array( 't' => 1000 );
		$clock         = $this->clock;
		$this->log_dir = sys_get_temp_dir() . '/tg-final-' . uniqid( '', true );
		mkdir( $this->log_dir, 0777, true );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
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
		Functions\when( 'current_time' )->alias(
			static function () use ( $clock ) {
				return $clock->t;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		$this->reset_singletons();
		$this->set_singleton( Tapgoods_Sync_Log::class, new Tapgoods_Sync_Log( $this->log_dir, 'testsecret' ) );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->log_dir ) ) {
			foreach ( glob( $this->log_dir . '/*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->log_dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singletons(): void {
		foreach ( array( Tapgoods_Connection::class, Tapgoods_Sync_State::class, Tapgoods_Sync_Log::class ) as $class ) {
			$this->set_singleton( $class, null );
		}
	}

	private function set_singleton( $class, $value ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setValue( null, $value );
	}

	private function log_contents(): string {
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	// --- Layer 1: the removal helpers in isolation ---------------------------

	public function test_remove_items_not_in_run_deletes_only_a_bounded_page() {
		$deleted = array();

		global $wpdb;
		$wpdb = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $last_sql  = '';
			public $last_args = array();
			public function prepare( $query, ...$args ) {
				$this->last_sql  = $query;
				$this->last_args = $args;
				return $query;
			}
			public function get_col( $query ) {
				// Two ids in this LIMIT page (fewer than the batch => caller stops).
				return array( 11, 22 );
			}
		};

		Functions\when( 'wp_delete_post' )->alias(
			static function ( $id, $force ) use ( &$deleted ) {
				$deleted[] = (int) $id;
				return true;
			}
		);

		$conn    = Tapgoods_Connection::get_instance();
		$removed = $conn->remove_items_not_in_run( 'tok-abc', 100 );

		$this->assertSame( 2, $removed );
		$this->assertSame( array( 11, 22 ), $deleted, 'Exactly the ids the bounded query returned are force-deleted.' );

		// The query is bounded (LIMIT %d) and keyed on the run-token meta + post type,
		// and it never selects the whole catalog into memory (the WPB-172 OOM did that).
		$this->assertStringContainsStringIgnoringCase( 'LIMIT', $wpdb->last_sql );
		$this->assertContains( 'tg_inventory', $wpdb->last_args );
		$this->assertContains( Tapgoods_Connection::SYNC_RUN_META, $wpdb->last_args );
		$this->assertContains( 'tok-abc', $wpdb->last_args );
		$this->assertContains( 100, $wpdb->last_args, 'The batch LIMIT is bound.' );
	}

	public function test_remove_items_not_in_run_blank_token_is_a_noop() {
		global $wpdb;
		$wpdb = new class() {
			public $posts    = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public function prepare( $query, ...$args ) {
				throw new \RuntimeException( 'A blank token must not query at all.' );
			}
			public function get_col( $query ) {
				return array();
			}
		};
		Functions\when( 'wp_delete_post' )->justReturn( true );

		$conn = Tapgoods_Connection::get_instance();
		$this->assertSame( 0, $conn->remove_items_not_in_run( '', 100 ), 'A blank token deletes nothing (safety).' );
	}

	public function test_remove_terms_not_in_run_is_skipped_when_no_terms_are_stamped() {
		// count_terms_in_run() returns 0 => the taxonomy has no term stamped with this
		// run's token (e.g. every category fetch failed). Deleting everything then would
		// wipe the taxonomy, so removal must be skipped entirely.
		global $wpdb;
		$wpdb = new class() {
			public $term_taxonomy = 'wp_term_taxonomy';
			public $termmeta      = 'wp_termmeta';
			public $get_col_calls = 0;
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_var( $query ) {
				return 0; // no stamped terms.
			}
			public function get_col( $query ) {
				++$this->get_col_calls;
				return array( 1, 2, 3 );
			}
		};
		$deleted = 0;
		Functions\when( 'wp_delete_term' )->alias(
			static function () use ( &$deleted ) {
				++$deleted;
				return true;
			}
		);

		$conn    = Tapgoods_Connection::get_instance();
		$removed = $conn->remove_terms_not_in_run( 'tg_category', 'tok-abc', 100 );

		$this->assertSame( 0, $removed, 'No terms removed when the run stamped none.' );
		$this->assertSame( 0, $deleted, 'wp_delete_term must never be called on an unstamped taxonomy.' );
		$this->assertSame( 0, $wpdb->get_col_calls, 'The delete query must not even run.' );
		$this->assertStringContainsString( 'no_stamped_terms', $this->log_contents() );
	}

	public function test_remove_terms_not_in_run_deletes_bounded_page_when_stamped() {
		global $wpdb;
		$wpdb = new class() {
			public $term_taxonomy = 'wp_term_taxonomy';
			public $termmeta      = 'wp_termmeta';
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_var( $query ) {
				return 7; // the run stamped some terms => reconciliation is safe.
			}
			public function get_col( $query ) {
				return array( 5, 6 ); // two obsolete terms in this page.
			}
		};
		$deleted = array();
		Functions\when( 'wp_delete_term' )->alias(
			static function ( $term_id, $tax ) use ( &$deleted ) {
				$deleted[] = (int) $term_id;
				return true;
			}
		);

		$conn = Tapgoods_Connection::get_instance();
		$this->assertSame( 2, $conn->remove_terms_not_in_run( 'tg_tags', 'tok-abc', 100 ) );
		$this->assertSame( array( 5, 6 ), $deleted );
	}

	// --- Layer 2: run_finalize_slice() orchestration -------------------------

	/**
	 * Build a probe whose finalize removal seams are programmable and recorded, so
	 * run_finalize_slice()'s orchestration is asserted without a database.
	 *
	 * @param int[] $item_returns Successive return values for remove_items_not_in_run().
	 */
	private function make_finalize_probe( array $item_returns = array( 0 ) ) {
		$clock = $this->clock;
		$probe = new class( $clock, $item_returns ) extends Tapgoods_Connection {
			public $clock;
			public $item_returns;                // queued return values.
			public $item_calls   = array();      // tokens passed to remove_items_not_in_run.
			public $term_calls   = array();      // [{taxonomy, token}].
			public $cleanup_ran  = false;
			public $item_advance = 0;            // seconds added per item-removal call.

			public function __construct( $clock, $item_returns ) {
				$this->clock        = $clock;
				$this->item_returns = $item_returns;
			}
			public function remove_items_not_in_run( $run_token, $limit ) {
				$this->item_calls[] = (string) $run_token;
				$this->clock->t    += $this->item_advance;
				return empty( $this->item_returns ) ? 0 : array_shift( $this->item_returns );
			}
			public function remove_terms_not_in_run( $taxonomy, $run_token, $limit ) {
				$this->term_calls[] = array( 'taxonomy' => $taxonomy, 'token' => (string) $run_token );
				return 0;
			}
			public function remove_unused_terms( $taxonomy ) {
				return 0;
			}
			public function remove_duplicate_items() {
				return 0;
			}
			public function update_sync_info( $start_time ) {
				$this->cleanup_ran = true;
			}
		};
		return $probe;
	}

	private function finalize_state( $total_items, $run_token = 'tok-run' ): Tapgoods_Sync_State {
		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep( 1 )->mark_active();
		$state->init_cursor( array( 5001 ), $run_token );
		$cursor                = $state->get_cursor();
		$cursor['total_items'] = (int) $total_items;
		$cursor['phase']       = 'finalize';
		$state->save_cursor( $cursor );
		return $state;
	}

	private function invoke_finalize( $probe, Tapgoods_Sync_State $state ) {
		$method = new ReflectionMethod( Tapgoods_Connection::class, 'run_finalize_slice' );
		$pages  = 0;
		$cursor = $state->get_cursor();
		$start  = $this->clock->t;
		return $method->invokeArgs( $probe, array( $state, $cursor, $start, &$pages, microtime( true ) ) );
	}

	public function test_finalize_skips_item_removal_on_an_empty_pass() {
		// DELETION SAFETY: a pass that synced nothing must never remove items.
		$state  = $this->finalize_state( 0 );
		$probe  = $this->make_finalize_probe();

		$result = $this->invoke_finalize( $probe, $state );

		$this->assertNull( $result, 'Finalize completes in one slice here.' );
		$this->assertSame( array(), $probe->item_calls, 'Item removal must be skipped on an empty synced set.' );
		$this->assertTrue( $probe->cleanup_ran, 'The rest of finalize still runs.' );
		$this->assertStringContainsString( 'empty_synced_set', $this->log_contents() );
	}

	public function test_finalize_removal_is_keyed_on_the_run_token_and_chunked() {
		// A full pass (5 items). Item removal returns a full batch once (=> more remain,
		// loop again) then 0 (drained). Term removal runs once per taxonomy. Everything
		// is keyed on the SAME run token.
		$limit = Tapgoods_Connection::finalize_delete_batch();
		$state = $this->finalize_state( 5, 'tok-XYZ' );
		$probe = $this->make_finalize_probe( array( $limit, 0 ) );

		$result = $this->invoke_finalize( $probe, $state );

		$this->assertNull( $result, 'A generous budget finishes finalize in one slice.' );
		// Item removal looped until it drained (>= 2 calls), all with the run token.
		$this->assertGreaterThanOrEqual( 2, count( $probe->item_calls ), 'Item removal is chunked (looped) until drained.' );
		foreach ( $probe->item_calls as $tok ) {
			$this->assertSame( 'tok-XYZ', $tok );
		}
		// Obsolete-term removal ran once per taxonomy, with the run token.
		$taxes = array();
		foreach ( $probe->term_calls as $c ) {
			$taxes[] = $c['taxonomy'];
			$this->assertSame( 'tok-XYZ', $c['token'], 'Term removal must use the same run token that stamped the terms.' );
		}
		$this->assertSame( array( 'tg_category', 'tg_tags' ), $taxes );
		$this->assertTrue( $probe->cleanup_ran );
	}

	public function test_finalize_resumes_across_a_budget_interrupt() {
		// Item removal always reports a full batch (more remain) and each call spends
		// the whole slice budget, so the first slice must checkpoint mid-finalize and
		// hand off in-progress at finalize_step=items.
		$limit = Tapgoods_Connection::finalize_delete_batch();
		$state = $this->finalize_state( 5, 'tok-RES' );

		$probe1               = $this->make_finalize_probe( array( $limit, $limit, $limit ) );
		$probe1->item_advance = Tapgoods_Connection::sync_time_budget() + 1; // trip the budget after one batch.

		$result1 = $this->invoke_finalize( $probe1, $state );

		$this->assertIsArray( $result1 );
		$this->assertTrue( ! empty( $result1['in_progress'] ), 'A budget-interrupted finalize hands off as in-progress.' );
		$this->assertSame( 1, count( $probe1->item_calls ), 'Only one bounded batch ran before the budget tripped.' );

		$cursor = Tapgoods_Sync_State::get_instance()->get_cursor();
		$this->assertSame( 'finalize', $cursor['phase'] );
		$this->assertSame( 'items', $cursor['finalize_step'], 'Finalize checkpointed at the items step for the next tick.' );

		// Second slice: item removal now drains (0), so finalize converges to done.
		$probe2  = $this->make_finalize_probe( array( 0 ) );
		$result2 = $this->invoke_finalize( $probe2, Tapgoods_Sync_State::get_instance() );

		$this->assertNull( $result2, 'The resumed finalize completes.' );
		$this->assertSame( 'tok-RES', $probe2->item_calls[0], 'The resumed finalize uses the SAME run token.' );
		$this->assertTrue( $probe2->cleanup_ran );
		$this->assertStringContainsString( 'sync.finalize.done', $this->log_contents() );
	}
}
