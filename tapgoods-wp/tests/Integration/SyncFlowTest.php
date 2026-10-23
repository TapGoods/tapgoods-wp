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
use Tapgoods_Sync_Log;
use Tapgoods_Sync_State;
use WP_UnitTestCase;

final class SyncFlowTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// The connection is a singleton built at plugin load; reset it so each
		// test starts from a clean client/key.
		$this->reset_singleton( Tapgoods_Connection::class );

		// Same for the state machine and the activity log: both are singletons
		// holding in-memory state that would otherwise leak between tests (the
		// state machine's option is rolled back by the transaction, its cached
		// copy is not).
		$this->reset_singleton( Tapgoods_Sync_State::class );
		$this->reset_singleton( Tapgoods_Sync_Log::class );

		$this->reset_sync_log_file();
	}

	/**
	 * Reset a plugin singleton so each test starts clean.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	private function reset_singleton( string $class ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Start each test from an empty activity log file.
	 *
	 * @return void
	 */
	private function reset_sync_log_file(): void {
		$path = Tapgoods_Sync_Log::get_instance()->get_file_path();
		if ( '' !== $path && file_exists( $path ) ) {
			file_put_contents( $path, '' );
		}
	}

	private function sync_log_contents(): string {
		$contents = Tapgoods_Sync_Log::get_instance()->read_all();
		return ( false === $contents ) ? '' : $contents;
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

		// CROSS-LOCATION DEDUP: the mock reports two locations (5001, 5002) that return
		// the SAME category list. The sync must collapse them to a single set of terms:
		// exactly the UNIQUE categories/tags, with no per-location duplicates. This
		// proves the dedup threaded through sync_categories_from_api preserves the full
		// unique set (deletion-safety) while doing the work once per unique id.
		$categories = get_terms(
			array(
				'taxonomy'   => 'tg_category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$tags = get_terms(
			array(
				'taxonomy'   => 'tg_tags',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertCount( 2, $categories, 'Two unique categories (Tables, Chairs) despite two locations returning them.' );
		$this->assertCount( 2, $tags, 'Two unique sub-tags (Round Tables, Banquet Tables) despite two locations.' );
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

	public function test_finalize_cleanup_converges_with_many_stale_terms() {
		// Regression guard for the AS 300s finalize loop: seed far more stale
		// (0-post) terms than one bounded cleanup batch (FINALIZE_DELETE_BATCH),
		// then run the sync to completion. The finalize phase must drain them across
		// its bounded, resumable cleanup sub-steps and reach COMPLETED - never spin
		// on an un-budgeted single-shot cleanup as it did before.
		$stale = Tapgoods_Connection::finalize_delete_batch() + 50; // > one bounded batch.
		for ( $i = 0; $i < $stale; $i++ ) {
			wp_insert_term( "stale-cat-$i", 'tg_category', array( 'slug' => "stale-cat-$i" ) );
			wp_insert_term( "stale-tag-$i", 'tg_tags', array( 'slug' => "stale-tag-$i" ) );
		}

		$conn      = $this->connection();
		$ticks     = 0;
		$max_ticks = 40; // safety net against an infinite loop (the bug).
		do {
			$result = $conn->sync_from_api( 'cron_selfping' );
			++$ticks;
			$in_progress = ! empty( $result['in_progress'] );
		} while ( $in_progress && $ticks < $max_ticks );

		$this->assertLessThan( $max_ticks, $ticks, 'The finalize must converge, not spin (the AS 300s loop).' );
		$this->assertSame(
			Tapgoods_Sync_State::STATE_COMPLETED,
			Tapgoods_Sync_State::get_instance()->get_state(),
			'The run must end COMPLETED once cleanup drains.'
		);

		// Every seeded stale term is gone; the real fixture terms survive.
		$remaining = get_terms(
			array(
				'taxonomy'   => array( 'tg_category', 'tg_tags' ),
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		$remaining = is_wp_error( $remaining ) ? array() : $remaining;
		foreach ( $remaining as $slug ) {
			$this->assertStringStartsNotWith( 'stale-', (string) $slug, "Stale term '$slug' must be reconciled away." );
		}
		$this->assertNotFalse( get_term_by( 'slug', 'tables', 'tg_category' ), 'A real fixture category must survive cleanup.' );
	}

	public function test_sync_writes_the_run_lifecycle_to_the_activity_log() {
		$log = Tapgoods_Sync_Log::get_instance();
		$this->assertNotSame( '', $log->get_file_path(), 'The log needs a target under wp-content/uploads.' );

		$this->connection()->sync_inventory_in_batches( true, 'admin_manual' );

		$contents = $this->sync_log_contents();
		$this->assertNotSame( '', $contents, 'The sync must have written an activity log.' );

		// Run start, naming the entry point that fired it.
		$this->assertStringContainsString( 'sync.run.start', $contents );
		$this->assertStringContainsString( 'trigger=admin_manual', $contents );

		// Both halves of the lock: taking it here, refusing it in the test below.
		$this->assertStringContainsString( 'sync.lock.acquired', $contents );

		// At least one inventory batch, with a page number and an item count.
		$this->assertMatchesRegularExpression( '/sync\.batch\.page .*page=1 items=\d+/', $contents );

		// Exactly one run end, reporting the observed outcome.
		$this->assertSame( 1, substr_count( $contents, 'sync.run.start' ) );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ) );
		$this->assertStringContainsString( 'result=ok', $contents );

		// The lines of one run are correlated by a run id.
		$this->assertMatchesRegularExpression( '/sync\.run\.start run=[0-9a-f]{8}/', $contents );

		// Every line is one line: timestamp, level, event, then key=value fields.
		foreach ( array_filter( explode( "\n", $contents ) ) as $line ) {
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z (DEBUG|INFO|WARN|ERROR) \S+/', $line );
		}
	}

	public function test_activity_log_leaks_neither_the_api_key_nor_a_response_body() {
		$this->connection()->sync_from_api( 'cron_selfping' );

		$contents = $this->sync_log_contents();
		$this->assertNotSame( '', $contents );

		// No credential material, and nothing shaped like a serialized payload.
		// Note what is NOT asserted here: the bare word "collection" appears
		// legitimately in `reason=empty_collection`, so asserting on it only passed
		// because these fixtures happen not to page evenly. Assert on the shapes a
		// serialized envelope actually has instead.
		$this->assertStringNotContainsString( 'Bearer ', $contents );
		$this->assertStringNotContainsString( '"data"', $contents );
		$this->assertStringNotContainsString( '{"', $contents, 'No JSON object may reach the log.' );
		$this->assertStringNotContainsString( '[{', $contents, 'No JSON array of objects may reach the log.' );
		$this->assertStringNotContainsString( '=>', $contents, 'No print_r() output may reach the log.' );
		$this->assertStringNotContainsString( 'Array', $contents, 'No print_r() output may reach the log.' );

		// And no value anywhere is long enough to be a payload.
		foreach ( array_filter( explode( "\n", $contents ) ) as $line ) {
			$this->assertLessThanOrEqual( Tapgoods_Sync_Log::MAX_LINE_LENGTH + 20, strlen( $line ) );
		}

		// Categories now sync exactly once per run: a single one-shot pass inside
		// the bounded slice (the cursor's categories_done step), replacing the old
		// pre-inventory + post-inventory double pass.
		$this->assertStringContainsString( 'sync.categories.start', $contents );
		$this->assertStringNotContainsString( 'pass=pre_inventory', $contents );
		$this->assertStringNotContainsString( 'pass=post_inventory', $contents );
	}

	public function test_a_refused_concurrent_run_is_recorded() {
		// The concurrency guard is now the execution mutex (a transient), and the
		// ACTIVE state + cursor is the durable "work remains" marker. Hold the lock
		// (and park the state ACTIVE so the run has an age to report): the next run
		// must refuse and say so, which is the case that used to leave no trace.
		Tapgoods_Sync_State::get_instance()->begin_prep( 3 )->mark_active();
		set_transient( Tapgoods_Connection::RUN_LOCK, time(), Tapgoods_Connection::RUN_LOCK_TTL );

		$result = $this->connection()->sync_inventory_in_batches( false, 'cron_daily' );

		$this->assertTrue( $result['in_progress'] );

		$contents = $this->sync_log_contents();
		$this->assertStringContainsString( 'sync.run.locked', $contents );
		$this->assertStringContainsString( 'reason=lock_held', $contents );
		$this->assertStringContainsString( 'trigger=cron_daily', $contents );
		$this->assertStringContainsString( 'result=skipped', $contents );
		$this->assertMatchesRegularExpression( '/sync\.run\.locked .*age_s=\d+/', $contents );

		delete_transient( Tapgoods_Connection::RUN_LOCK );
	}

	/**
	 * A prep-stage API failure must name the HTTP status and still close the run.
	 *
	 * The client collapses 401 (revoked key), 429 (rate limited) and 5xx (outage)
	 * into a single `false`, and telling those apart is most of what support needs.
	 */
	public function test_a_failed_prep_records_the_http_status_and_ends_the_run() {
		$stub = new class() {
			public function get_location_ids() {
				return false;
			}
			public function get_last_http_code() {
				return 401;
			}
			public function set_config( $param, $value = null ) {}
		};

		add_filter(
			'tapgoods_api_client',
			static function () use ( $stub ) {
				return $stub;
			}
		);

		$result = $this->connection()->sync_inventory_in_batches( false, 'cron_daily' );

		$this->assertFalse( $result['success'] );

		$contents = $this->sync_log_contents();
		$this->assertStringContainsString( 'sync.api.error', $contents );
		$this->assertStringContainsString( 'op=get_location_ids', $contents );
		$this->assertStringContainsString( 'status=401', $contents );

		// The run is closed exactly once even though it died during prep.
		$this->assertSame( 1, substr_count( $contents, 'sync.run.start' ) );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ) );
		$this->assertStringContainsString( 'result=error', $contents );
		$this->assertStringContainsString( 'stage=prep', $contents );

		// And the retry budget is reported, since mark_error() consumed one.
		$this->assertStringContainsString( 'sync.retry', $contents );
	}

	/**
	 * An exception thrown from the API client during prep must not leave a run
	 * start with no terminal line. get_location_ids() reaches
	 * Tapgoods_API_Request, which throws on a WordPress http_request_failed, and
	 * that call used to sit outside the try.
	 */
	public function test_an_exception_during_prep_still_closes_the_run() {
		$stub = new class() {
			public function get_location_ids() {
				throw new \RuntimeException( 'cURL error 6: Could not resolve host' );
			}
			public function set_config( $param, $value = null ) {}
		};

		add_filter(
			'tapgoods_api_client',
			static function () use ( $stub ) {
				return $stub;
			}
		);

		$result = $this->connection()->sync_inventory_in_batches( false, 'cron_selfping' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Could not resolve host', $result['message'] );

		$contents = $this->sync_log_contents();
		$this->assertSame( 1, substr_count( $contents, 'sync.run.start' ), 'One start.' );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ), 'And one terminal line.' );
		$this->assertStringContainsString( 'sync.error', $contents );
		$this->assertStringContainsString( 'class=RuntimeException', $contents );
		$this->assertStringContainsString( 'result=error', $contents );

		// A request that never reached TapGoods has no status to report, and it has
		// to render as missing rather than inheriting an earlier 200.
		$this->assertStringContainsString( 'status=-', $contents );
		$this->assertStringNotContainsString( 'status=200', $contents );
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

	/**
	 * WPB-172 end-to-end against real WordPress: a full run stamps every synced
	 * post/term with the run token and the token-based, chunked finalize removes
	 * exactly what the API no longer returns (a stale item), keeping the rest. This
	 * proves the memory-bounded reconciliation replaces the old load-everything diff
	 * without over- or under-deleting.
	 */
	public function test_full_sync_stamps_rows_and_reconciles_stale_items() {
		// A stale item the API no longer returns (not in the fixtures). It carries no
		// run-token stamp, so the token-based finalize must delete it.
		$stale = self::factory()->post->create(
			array(
				'post_type'   => 'tg_inventory',
				'post_status' => 'publish',
				'post_title'  => 'Retired Widget',
			)
		);
		update_post_meta( $stale, 'tg_id', '999999' );

		$result = $this->connection()->sync_from_api();
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			Tapgoods_Sync_State::STATE_COMPLETED,
			Tapgoods_Sync_State::get_instance()->get_state(),
			'The run must reach COMPLETED (finalize converged).'
		);

		// The stale item is gone; only the two fixture items remain.
		$this->assertNull( get_post( $stale ), 'A stale item not stamped by the run must be reconciled away.' );

		$posts = get_posts(
			array(
				'post_type'   => 'tg_inventory',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);
		$this->assertCount( 2, $posts, 'Exactly the two fixture items survive.' );

		// Every surviving item carries THIS run's token stamp.
		$token = '';
		foreach ( $posts as $p ) {
			$stamp = (string) get_post_meta( $p->ID, \Tapgoods_Connection::SYNC_RUN_META, true );
			$this->assertNotSame( '', $stamp, 'Each synced item must be stamped with the run token.' );
			if ( '' === $token ) {
				$token = $stamp;
			}
			$this->assertSame( $token, $stamp, 'All items from one run share the same token.' );
		}

		// Terms are stamped with the same token too, so obsolete-term removal is
		// keyed on it (and here nothing obsolete exists, so the fixture terms survive).
		$cats = get_terms(
			array(
				'taxonomy'   => 'tg_category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertNotEmpty( $cats );
		foreach ( $cats as $term_id ) {
			$this->assertSame( $token, (string) get_term_meta( $term_id, \Tapgoods_Connection::SYNC_RUN_META, true ), 'Each synced term shares the run token.' );
		}
	}
}
