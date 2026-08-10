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

	public function test_sync_writes_the_run_lifecycle_to_the_activity_log() {
		$log = Tapgoods_Sync_Log::get_instance();
		$this->assertNotSame( '', $log->get_file_path(), 'The log needs a target under wp-content/uploads.' );

		$this->connection()->sync_inventory_in_batches( true, 'admin_manual' );

		$contents = $this->sync_log_contents();
		$this->assertNotSame( '', $contents, 'The sync must have written an activity log.' );

		// Run start, naming the entry point that fired it.
		$this->assertStringContainsString( 'sync.run.start', $contents );
		$this->assertStringContainsString( 'trigger=admin_manual', $contents );

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

		// The mock client's key (see the connection config) must never appear, and
		// nothing that looks like a serialized GraphQL envelope may either.
		$this->assertStringNotContainsString( 'Bearer ', $contents );
		$this->assertStringNotContainsString( '"data"', $contents );
		$this->assertStringNotContainsString( 'collection', $contents );
		$this->assertStringNotContainsString( 'Array', $contents, 'No print_r() output may reach the log.' );

		// A run that came in through sync_from_api() syncs categories twice; the
		// pass labels are what make that visible.
		$this->assertStringContainsString( 'pass=pre_inventory', $contents );
		$this->assertStringContainsString( 'pass=post_inventory', $contents );
	}

	public function test_a_refused_concurrent_run_is_recorded() {
		// Park the state machine in ACTIVE: the next run must refuse and say so,
		// which is the case that used to leave no trace at all.
		Tapgoods_Sync_State::get_instance()->begin_prep( 3 )->mark_active();

		$result = $this->connection()->sync_inventory_in_batches( false, 'cron_daily' );

		$this->assertTrue( $result['in_progress'] );

		$contents = $this->sync_log_contents();
		$this->assertStringContainsString( 'sync.run.locked', $contents );
		$this->assertStringContainsString( 'reason=already_running', $contents );
		$this->assertStringContainsString( 'trigger=cron_daily', $contents );
		$this->assertStringContainsString( 'result=skipped', $contents );
		$this->assertMatchesRegularExpression( '/sync\.run\.locked .*age_s=\d+/', $contents );
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
}
