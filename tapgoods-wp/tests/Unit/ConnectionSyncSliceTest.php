<?php
/**
 * Unit tests for the WPB-173 bounded/resumable sync additions to
 * Tapgoods_Connection: term-query chunking, abnormal-termination self-heal,
 * and entry-point guarding. All isolated (no WordPress) via Brain\Monkey.
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

final class ConnectionSyncSliceTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var int Fake clock. */
	private $clock = 1000;

	/** @var int Count of delete_transient() calls seen. */
	private $deleted_transients = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store              = array();
		$this->deleted_transients = 0;
		$store                    = &$this->store;

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
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';
		require_once dirname( __DIR__ ) . '/mock/class-tapgoods-mock-api-client.php';

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

	private function set_private( $object, $name, $value ): void {
		$ref  = new ReflectionClass( $object );
		$prop = $ref->getProperty( $name );
		$prop->setValue( $object, $value );
	}

	// --- Task A: term-query chunking -----------------------------------------

	public function test_chunk_ids_never_exceeds_the_cap() {
		$ids    = range( 1, 400 );
		$chunks = Tapgoods_Connection::tapgrein_chunk_ids( $ids );

		$this->assertCount( 3, $chunks, '400 ids at a 150 cap => 3 chunks.' );

		$flat = array();
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual(
				Tapgoods_Connection::TERM_CHUNK_SIZE,
				count( $chunk ),
				'No chunk may exceed the term-query cap.'
			);
			$flat = array_merge( $flat, $chunk );
		}
		$this->assertSame( $ids, $flat, 'Chunking must preserve every id, in order.' );
	}

	public function test_chunk_ids_edges() {
		$this->assertSame( array(), Tapgoods_Connection::tapgrein_chunk_ids( array() ) );
		$this->assertCount( 2, Tapgoods_Connection::tapgrein_chunk_ids( range( 1, 4 ), 2 ) );
	}

	public function test_remove_unused_terms_never_queries_more_than_the_cap() {
		// A taxonomy with 400 terms would previously load them in a single
		// "... WHERE term_id IN (<400 ids>)" query (the WPB-165 KILLED QUERY).
		// Assert every get_terms() include list stays within the cap.
		$captured_include_sizes = array();

		global $wpdb;
		$wpdb = new class() {
			public $term_taxonomy = 'wp_term_taxonomy';
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_col( $query ) {
				return range( 1, 400 );
			}
		};

		Functions\when( 'get_terms' )->alias(
			static function ( $args ) use ( &$captured_include_sizes ) {
				$captured_include_sizes[] = count( $args['include'] );
				return array(); // no term objects => nothing to delete.
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_delete_term' )->justReturn( true );

		Tapgoods_Connection::get_instance()->remove_unused_terms( 'tg_category' );

		$this->assertNotEmpty( $captured_include_sizes );
		$this->assertCount( 3, $captured_include_sizes, '400 ids / 150 cap => 3 queries.' );
		$this->assertLessThanOrEqual(
			Tapgoods_Connection::TERM_CHUNK_SIZE,
			max( $captured_include_sizes ),
			'No term query may carry more than the cap.'
		);
	}

	// --- Task D: entry-point guarding ----------------------------------------

	public function test_locked_run_returns_in_progress_without_starting_prep() {
		// Both the manual path and the cron path (sync_from_api) must bail when
		// the execution mutex is held by another request. If PREP ran it would
		// call the client; we inject a client that fails the test if touched.
		$this->store['tg_last_api_key'] = ''; // == get_key() so no data wipe.

		Functions\when( 'get_transient' )->justReturn( 1 ); // lock held.
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$exploding = new class() {
			public function get_location_ids() {
				throw new \RuntimeException( 'PREP must not run while the lock is held.' );
			}
		};
		Functions\when( 'apply_filters' )->justReturn( $exploding );

		$conn = Tapgoods_Connection::get_instance();

		$manual = $conn->sync_inventory_in_batches( true );
		$this->assertTrue( $manual['success'] );
		$this->assertTrue( $manual['in_progress'] );

		$cron = $conn->sync_from_api(); // delegates to the same guarded entry.
		$this->assertTrue( $cron['success'] );
		$this->assertTrue( $cron['in_progress'] );
	}

	// --- Task C: abnormal-termination self-heal ------------------------------

	private function spy_transients(): void {
		$counter = &$this->deleted_transients;
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$counter ) {
				++$counter;
				return true;
			}
		);
	}

	public function test_shutdown_leaves_a_resumable_run_intact_and_frees_the_lock() {
		$this->spy_transients();

		// An ACTIVE run with paging still to do.
		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep()->mark_active();
		$state->init_cursor( array( 5001, 5002 ) );

		$conn = Tapgoods_Connection::get_instance();
		$this->set_private( $conn, 'active_run', true );

		$conn->on_sync_shutdown();

		// Cursor untouched, still ACTIVE => the next tick resumes; lock freed.
		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, Tapgoods_Sync_State::get_instance()->get_state() );
		$this->assertSame( 0, Tapgoods_Sync_State::get_instance()->get_failure_count() );
		$this->assertSame( 1, $this->deleted_transients, 'The run lock must be released.' );
	}

	public function test_shutdown_records_abnormal_end_for_a_non_resumable_run() {
		$this->spy_transients();

		// Died during PREP (no cursor initialised) => not resumable.
		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep();

		$conn = Tapgoods_Connection::get_instance();
		$this->set_private( $conn, 'active_run', true );

		$conn->on_sync_shutdown();

		$after = Tapgoods_Sync_State::get_instance();
		$this->assertSame( 1, $after->get_failure_count(), 'Abnormal end must count as a failure (retry budget).' );
		$this->assertSame( Tapgoods_Sync_State::STATE_IDLE, $after->get_state(), 'First failure retries via IDLE.' );
		$this->assertSame( 1, $this->deleted_transients, 'The run lock must be released.' );
	}

	public function test_shutdown_is_a_noop_after_a_clean_slice() {
		$this->spy_transients();

		$state = Tapgoods_Sync_State::get_instance();
		$state->begin_prep()->mark_active();
		$state->init_cursor( array( 5001 ) );

		$conn = Tapgoods_Connection::get_instance();
		// active_run defaults to false (a clean slice clears it before returning).
		$conn->on_sync_shutdown();

		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, Tapgoods_Sync_State::get_instance()->get_state() );
		$this->assertSame( 0, $this->deleted_transients, 'A clean end must not touch the lock.' );
	}
}
