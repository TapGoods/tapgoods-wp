<?php
/**
 * Unit tests that the WPB-173 bounded/resumable sync flow still emits the
 * WPB-172 sync activity-log events after the two were merged: a run start, a
 * per-page batch line, a slice checkpoint, a resume-from-cursor line, the
 * finalize summary, the completed marker, and the terminal run-end line.
 *
 * Isolated (no WordPress) via Brain\Monkey. A real Tapgoods_Sync_Log is pointed
 * at a temp directory so we can read the lines it actually wrote.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tapgoods_Connection;
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;

final class ConnectionSyncLogTest extends TestCase {

	/** @var array In-memory option store. */
	private $store = array();

	/** @var object Fake clock holder ({ t: int }), mutable by the fake client. */
	private $clock;

	/** @var string Temp directory the logger writes into. */
	private $log_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->store = array();
		$store       = &$this->store;

		$this->clock    = (object) array( 't' => 1000 );
		$clock          = $this->clock;
		$this->log_dir  = sys_get_temp_dir() . '/tg-synclog-' . uniqid( '', true );
		mkdir( $this->log_dir, 0777, true );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( true );
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

		// Locking: never held (each call acquires + releases cleanly).
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// Item-write + cleanup path: cheap no-op stubs.
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_insert_post' )->justReturn( 123 );
		Functions\when( 'wp_update_post' )->justReturn( 123 );
		Functions\when( 'wp_unique_post_slug' )->alias(
			static function ( $slug ) {
				return $slug;
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_delete_post' )->justReturn( true );
		Functions\when( 'wp_delete_term' )->justReturn( true );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'wp_set_post_terms' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias(
			static function ( $t ) {
				return $t;
			}
		);

		global $wpdb;
		$wpdb = new class() {
			public $postmeta      = 'wp_postmeta';
			public $term_taxonomy = 'wp_term_taxonomy';
			public function prepare( $query, ...$args ) {
				return $query;
			}
			public function get_col( $query ) {
				return array();
			}
			public function get_results( $query, $output = null ) {
				return array();
			}
		};

		require_once ABSPATH . 'includes/class-tapgoods-sync-state.php';
		require_once ABSPATH . 'includes/class-tapgoods-connection.php';

		$this->reset_singletons();

		// Point the logger at a real temp dir so we can read what it wrote.
		$this->set_singleton( Tapgoods_Sync_Log::class, new Tapgoods_Sync_Log( $this->log_dir, 'testsecret' ) );
	}

	protected function tearDown(): void {
		// Clean up temp log files.
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

	/**
	 * Inject the API client via the `tapgoods_api_client` filter, WITHOUT
	 * hijacking every other apply_filters() call (the logger reads its level via
	 * the `tapgoods_sync_log_level` filter and must still get a real string).
	 */
	private function inject_client( $client ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $client ) {
				return ( 'tapgoods_api_client' === $tag ) ? $client : $value;
			}
		);
	}

	private function log_contents(): string {
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * A run that fits entirely in one slice must emit the whole happy-path
	 * sequence: start, at least one batch.page, the finalize summary, the
	 * completed marker, and the terminal run-end line.
	 */
	public function test_single_slice_emits_start_batch_finalize_completed_end() {
		$this->store['tg_last_api_key'] = ''; // == get_key(), so no data wipe.

		// One location, one short page (3 < batch), so paging ends immediately.
		$client = new class() {
			public function get_location_ids() {
				return array( 1 );
			}
			public function get_categories_from_graph( $lid ) {
				return array();
			}
			public function get_inventories_from_graph( $lid, $page = 1, $size = 50 ) {
				if ( $page > 1 ) {
					return array( 'collection' => array(), 'metadata' => array( 'totalPages' => 1 ) );
				}
				$items = array();
				for ( $i = 0; $i < 3; $i++ ) {
					$id      = 100 + $i;
					$items[] = array( 'id' => $id, 'name' => "Item $id", 'slug' => "item-$id" );
				}
				return array( 'collection' => $items, 'metadata' => array( 'totalPages' => 1 ) );
			}
			public function get_last_http_code() {
				return 200;
			}
		};
		$this->inject_client( $client );

		$result = Tapgoods_Connection::get_instance()->sync_inventory_in_batches( true, 'admin_manual' );

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'in_progress', $result, 'A run that completes in one slice is not in progress.' );

		$log = $this->log_contents();
		$this->assertStringContainsString( 'sync.run.start', $log );
		$this->assertStringContainsString( 'trigger=admin_manual', $log );
		$this->assertStringContainsString( 'sync.batch.page', $log );
		$this->assertStringContainsString( 'total_items=3', $log );
		$this->assertStringContainsString( 'sync.finalize.done', $log );
		$this->assertStringContainsString( 'sync.run.completed', $log );
		$this->assertStringContainsString( 'sync.run.end', $log );
	}

	/**
	 * A run too large for one request must checkpoint (sync.checkpoint), return
	 * in_progress, and the next invocation must resume from the cursor
	 * (sync.resume) rather than restarting, finally completing.
	 */
	public function test_checkpoint_then_resume_are_logged() {
		$this->store['tg_last_api_key'] = '';

		$clock  = $this->clock;
		$client = new class( $clock ) {
			private $clock;
			public function __construct( $clock ) {
				$this->clock = $clock;
			}
			public function get_location_ids() {
				return array( 1 );
			}
			public function get_categories_from_graph( $lid ) {
				return array();
			}
			public function get_inventories_from_graph( $lid, $page = 1, $size = 50 ) {
				if ( 1 === (int) $page ) {
					// A full page: paging must continue. Spend the per-slice wall-clock
					// budget (default 20s) on this fetch so the slice checkpoints here.
					$this->clock->t += 25;
					$items = array();
					for ( $i = 0; $i < $size; $i++ ) {
						$id      = 1000 + $i;
						$items[] = array( 'id' => $id, 'name' => "Item $id", 'slug' => "item-$id" );
					}
					return array( 'collection' => $items, 'metadata' => array( 'totalPages' => 2 ) );
				}
				if ( 2 === (int) $page ) {
					// A short page: paging ends, no extra time spent.
					$items = array();
					for ( $i = 0; $i < 3; $i++ ) {
						$id      = 2000 + $i;
						$items[] = array( 'id' => $id, 'name' => "Item $id", 'slug' => "item-$id" );
					}
					return array( 'collection' => $items, 'metadata' => array( 'totalPages' => 2 ) );
				}
				return array( 'collection' => array(), 'metadata' => array( 'totalPages' => 2 ) );
			}
			public function get_last_http_code() {
				return 200;
			}
		};
		$this->inject_client( $client );

		$conn = Tapgoods_Connection::get_instance();

		// First slice: pages one full page, spends the budget, checkpoints.
		$first = $conn->sync_inventory_in_batches( false, 'cron_selfping' );
		$this->assertTrue( $first['success'] );
		$this->assertTrue( ! empty( $first['in_progress'] ), 'The first slice must hand off as in-progress.' );
		$this->assertSame( Tapgoods_Sync_State::STATE_ACTIVE, Tapgoods_Sync_State::get_instance()->get_state() );

		// Second slice: resumes from the cursor and finishes.
		$second = $conn->sync_inventory_in_batches( false, 'cron_selfping' );
		$this->assertTrue( $second['success'] );
		$this->assertSame( Tapgoods_Sync_State::STATE_COMPLETED, Tapgoods_Sync_State::get_instance()->get_state() );

		$log = $this->log_contents();
		$this->assertStringContainsString( 'sync.checkpoint', $log );
		$this->assertStringContainsString( 'sync.resume', $log );
		$this->assertStringContainsString( 'sync.batch.page', $log );
		$this->assertStringContainsString( 'sync.run.completed', $log );
		// Two invocations => two run starts and two run ends.
		$this->assertSame( 2, substr_count( $log, ' sync.run.start ' ) );
		$this->assertSame( 2, substr_count( $log, ' sync.run.end ' ) );
	}
}
