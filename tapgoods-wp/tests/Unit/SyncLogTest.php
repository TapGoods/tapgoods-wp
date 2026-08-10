<?php
/**
 * Unit tests for Tapgoods_Sync_Log (the sync activity log).
 *
 * The class takes its target directory and file-name secret as constructor
 * arguments, so the whole write path (format, rotation, retention, redaction,
 * the error_log() mirror and the silent no-op) is exercised in isolation against
 * a throw-away temp directory, with WordPress stubbed via Brain\Monkey.
 *
 * The mirror is captured by pointing PHP's own `error_log` ini setting at a file
 * for the duration of a single assertion, which is the only way to observe what
 * error_log() actually emitted.
 *
 * @package Tapgoods\Tests
 */

namespace Tapgoods\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;
use Tapgoods_Sync_Log;

final class SyncLogTest extends TestCase {

	/** @var string Temp directory for this test's log files. */
	private $dir;

	/** @var string Fixed secret so the file name is predictable. */
	private $secret = 'testsecret';

	/** @var string File PHP's error_log is redirected to for the whole class. */
	private $sink;

	/** @var array Saved ini values. */
	private $ini = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->dir = rtrim( sys_get_temp_dir(), '/' ) . '/tg-sync-log-' . uniqid( '', false ) . '/';

		// The logger mirrors part of what it writes to error_log(). Redirect that
		// stream to a file for the whole test class: it is how the mirror is
		// asserted, and it keeps mirrored lines out of the test runner's output.
		$this->sink       = rtrim( sys_get_temp_dir(), '/' ) . '/tg-sync-mirror-' . uniqid( '', false ) . '.log';
		$this->ini        = array(
			'error_log'  => ini_get( 'error_log' ),
			'log_errors' => ini_get( 'log_errors' ),
		);
		ini_set( 'error_log', $this->sink );
		ini_set( 'log_errors', '1' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->ini['error_log'] );
		ini_set( 'log_errors', (string) $this->ini['log_errors'] );
		if ( file_exists( $this->sink ) ) {
			unlink( $this->sink );
		}

		$this->cleanup( $this->dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Remove only the file names this test suite can possibly have created.
	 *
	 * @param string $dir Directory to clean.
	 * @return void
	 */
	private function cleanup( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$names = array( '.htaccess', 'index.php', $this->log_name() );
		for ( $i = 1; $i <= Tapgoods_Sync_Log::MAX_FILES + 2; $i++ ) {
			$names[] = $this->log_name() . '.' . $i;
		}
		foreach ( $names as $name ) {
			if ( file_exists( $dir . $name ) ) {
				unlink( $dir . $name );
			}
		}
		rmdir( $dir );
	}

	private function log_name(): string {
		return 'tg-sync-' . $this->secret . '.log';
	}

	private function log(): Tapgoods_Sync_Log {
		return new Tapgoods_Sync_Log( $this->dir, $this->secret );
	}

	private function contents(): string {
		$path = $this->dir . $this->log_name();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Run a callback and return everything it sent to error_log().
	 *
	 * @param callable $callback Code that may call error_log().
	 * @return string
	 */
	private function capture_error_log( callable $callback ): string {
		file_put_contents( $this->sink, '' );

		$callback();

		return file_exists( $this->sink ) ? (string) file_get_contents( $this->sink ) : '';
	}

	// --- Line format ---------------------------------------------------------

	public function test_line_format_is_timestamp_level_event_then_fields() {
		$line = Tapgoods_Sync_Log::format_line( Tapgoods_Sync_Log::LEVEL_INFO, 'sync.batch.page', array( 'page' => 3, 'items' => 50 ) );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z INFO sync\.batch\.page page=3 items=50$/',
			$line
		);
	}

	public function test_values_with_spaces_are_quoted_so_one_event_is_one_line() {
		$line = Tapgoods_Sync_Log::format_line( 'ERROR', 'sync.error', array( 'message' => "boom\nsecond line" ) );

		$this->assertStringContainsString( 'message="boom second line"', $line );
		$this->assertStringNotContainsString( "\n", $line );
	}

	public function test_non_scalars_are_described_never_serialized() {
		$line = Tapgoods_Sync_Log::format_line( 'INFO', 'sync.debug', array( 'payload' => array( 1, 2, 3 ), 'obj' => new \stdClass() ) );

		$this->assertStringContainsString( 'payload=array(3)', $line );
		$this->assertStringContainsString( 'obj=object(stdClass)', $line );
	}

	public function test_event_and_field_names_are_sanitized() {
		$line = Tapgoods_Sync_Log::format_line( 'INFO', "sync run\nstart", array( 'Weird Field!' => 'x' ) );

		$this->assertStringContainsString( 'sync_run_start', $line );
		$this->assertStringContainsString( 'weird_field=x', $line );
	}

	public function test_a_written_line_ends_with_a_newline_and_carries_the_level() {
		$log = $this->log();
		$this->assertTrue( $log->info( 'sync.run.start', array( 'trigger' => 'cron_daily' ) ) );

		$contents = $this->contents();
		$this->assertStringEndsWith( "\n", $contents );
		$this->assertStringContainsString( ' INFO sync.run.start trigger=cron_daily', $contents );
	}

	// --- Levels --------------------------------------------------------------

	public function test_debug_is_below_the_default_level_and_is_not_written() {
		$log = $this->log();

		$this->assertFalse( $log->debug( 'sync.cleanup.item_removed', array( 'tg_id' => 11001 ) ) );
		$this->assertTrue( $log->warn( 'sync.run.locked' ) );

		$contents = $this->contents();
		$this->assertStringNotContainsString( 'sync.cleanup.item_removed', $contents );
		$this->assertStringContainsString( 'WARN sync.run.locked', $contents );
	}

	public function test_level_filter_can_turn_debug_on() {
		Filters\expectApplied( 'tapgoods_sync_log_level' )->andReturn( 'DEBUG' );

		$log = $this->log();
		$this->assertTrue( $log->debug( 'sync.cleanup.item_removed', array( 'tg_id' => 11001 ) ) );
		$this->assertStringContainsString( 'DEBUG sync.cleanup.item_removed tg_id=11001', $this->contents() );
	}

	// --- Redaction -----------------------------------------------------------

	public function test_credential_shaped_field_names_never_render_their_value() {
		$line = Tapgoods_Sync_Log::format_line( 'INFO', 'sync.run.start', array( 'api_key' => 'super-secret-value', 'token' => 'abc', 'items' => 4 ) );

		$this->assertStringNotContainsString( 'super-secret-value', $line );
		$this->assertStringNotContainsString( '=abc', $line );
		$this->assertStringContainsString( 'api_key=<REDACTED>', $line );
		$this->assertStringContainsString( 'token=<REDACTED>', $line );
		$this->assertStringContainsString( 'items=4', $line );
	}

	public function test_inline_bearer_token_is_stripped_from_an_error_message() {
		$line = Tapgoods_Sync_Log::format_line(
			'ERROR',
			'sync.api.error',
			array( 'message' => 'Unauthorized: Bearer tg_live_abc123 rejected' )
		);

		$this->assertStringNotContainsString( 'tg_live_abc123', $line );
		$this->assertStringContainsString( 'Bearer <REDACTED>', $line );
	}

	public function test_long_opaque_tokens_are_stripped_wherever_they_appear() {
		$key  = str_repeat( 'A1b2C3d4', 6 ); // 48 chars, key-shaped.
		$line = Tapgoods_Sync_Log::format_line( 'ERROR', 'sync.error', array( 'message' => 'request failed for ' . $key . ' end' ) );

		$this->assertStringNotContainsString( $key, $line );
		$this->assertStringContainsString( '<REDACTED>', $line );
	}

	public function test_short_ids_and_counts_survive_redaction() {
		$line = Tapgoods_Sync_Log::format_line( 'INFO', 'sync.cleanup.items_removed', array( 'count' => 3, 'sample_ids' => '11001,11002,11003' ) );

		$this->assertStringContainsString( 'count=3', $line );
		$this->assertStringContainsString( 'sample_ids=11001,11002,11003', $line );
	}

	public function test_a_response_body_cannot_land_in_the_log_because_values_are_capped() {
		$body = '{"data":{"inventories":{"collection":[' . str_repeat( '{"id":1,"name":"x"},', 400 ) . ']}}}';
		$line = Tapgoods_Sync_Log::format_line( 'ERROR', 'sync.error', array( 'message' => $body ) );

		$this->assertStringContainsString( '[truncated]', $line );
		$this->assertLessThanOrEqual( Tapgoods_Sync_Log::MAX_LINE_LENGTH + 20, strlen( $line ) );
		$this->assertStringNotContainsString( ']}}}', $line );
	}

	// --- Rotation / retention ------------------------------------------------

	public function test_rotation_happens_when_the_file_would_pass_the_size_cap() {
		$log  = $this->log();
		$path = $this->dir . $this->log_name();

		// First write creates the directory and the file.
		$log->info( 'sync.run.start' );
		$this->assertFileExists( $path );

		// Fill it to the cap, then write once more.
		file_put_contents( $path, str_repeat( 'x', Tapgoods_Sync_Log::MAX_BYTES ) );
		$log->info( 'sync.run.end', array( 'result' => 'ok' ) );

		$this->assertFileExists( $path . '.1' );
		$this->assertSame( Tapgoods_Sync_Log::MAX_BYTES, filesize( $path . '.1' ) );
		$this->assertStringContainsString( 'sync.run.end', (string) file_get_contents( $path ) );
		$this->assertLessThan( Tapgoods_Sync_Log::MAX_BYTES, filesize( $path ) );
	}

	public function test_retention_keeps_a_fixed_number_of_files() {
		$log  = $this->log();
		$path = $this->dir . $this->log_name();

		// Force one rotation more than the retention budget.
		for ( $i = 1; $i <= Tapgoods_Sync_Log::MAX_FILES + 1; $i++ ) {
			$log->info( 'sync.rotation.marker', array( 'pass' => $i ) );
			file_put_contents( $path, str_repeat( 'x', Tapgoods_Sync_Log::MAX_BYTES ) );
		}
		$log->info( 'sync.rotation.final' );

		for ( $i = 1; $i <= Tapgoods_Sync_Log::MAX_FILES; $i++ ) {
			$this->assertFileExists( $path . '.' . $i );
		}
		$this->assertFileDoesNotExist( $path . '.' . ( Tapgoods_Sync_Log::MAX_FILES + 1 ) );
	}

	// --- Degradation ---------------------------------------------------------

	public function test_unwritable_target_is_a_silent_no_op() {
		// A regular file where a directory would have to be: mkdir cannot succeed,
		// which is the closest deterministic stand-in for a locked-down uploads dir
		// (a chmod would not stop the root user the test suite may run as).
		$blocker = rtrim( sys_get_temp_dir(), '/' ) . '/tg-sync-blocker-' . uniqid( '', false );
		file_put_contents( $blocker, 'not a directory' );

		$log = new Tapgoods_Sync_Log( $blocker . '/logs', $this->secret );

		$this->assertFalse( $log->info( 'sync.run.start' ) );
		$this->assertFalse( $log->error( 'sync.error', array( 'message' => 'boom' ) ) );
		$this->assertSame( array(), $log->get_recent_lines() );
		$this->assertFalse( $log->read_all() );
		$this->assertSame( 0, $log->get_size() );

		unlink( $blocker );
	}

	public function test_no_target_directory_at_all_is_also_a_silent_no_op() {
		$log = new Tapgoods_Sync_Log( '', $this->secret );

		$this->assertSame( '', $log->get_file_path() );
		$this->assertFalse( $log->info( 'sync.run.start' ) );
	}

	public function test_http_guards_are_written_next_to_the_log() {
		$log = $this->log();
		$log->info( 'sync.run.start' );

		$this->assertFileExists( $this->dir . '.htaccess' );
		$this->assertFileExists( $this->dir . 'index.php' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $this->dir . '.htaccess' ) );
	}

	// --- Run lifecycle -------------------------------------------------------

	public function test_nested_entry_points_share_one_run_and_one_terminal_line() {
		$log = $this->log();

		$run_id = $log->begin_run( 'cron_selfping' );
		$this->assertNotSame( '', $run_id );

		// An inner entry point joins the run instead of starting a new one.
		$this->assertSame( $run_id, $log->begin_run( 'cron_selfping' ) );
		$log->info( 'sync.batch.page', array( 'page' => 1, 'items' => 2 ) );
		$log->end_run( 'ok', array( 'items' => 2 ) );
		$this->assertTrue( $log->has_open_run() );

		$log->end_run( 'ok' );
		$this->assertFalse( $log->has_open_run() );

		$contents = $this->contents();
		$this->assertSame( 1, substr_count( $contents, 'sync.run.start' ) );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ) );
		$this->assertStringContainsString( 'trigger=cron_selfping', $contents );
		$this->assertStringContainsString( 'items=2', $contents );
		// Every line written inside the run carries the correlation id: the run
		// start, the batch line, and the single run end (the inner end_run only
		// accumulates totals, it writes nothing).
		$this->assertSame( 3, substr_count( $contents, 'run=' . $run_id ) );
	}

	public function test_the_worst_result_reported_by_any_layer_wins() {
		$log = $this->log();

		$log->begin_run( 'admin_manual' );
		$log->begin_run( 'admin_manual' );
		$log->end_run( 'error', array( 'stage' => 'inventory' ) );
		$log->end_run( 'ok' );

		$contents = $this->contents();
		$this->assertStringContainsString( 'sync.run.end', $contents );
		$this->assertStringContainsString( 'result=error', $contents );
		$this->assertStringContainsString( 'stage=inventory', $contents );
	}

	public function test_an_unpaired_end_run_never_invents_a_terminal_line() {
		$log = $this->log();
		$log->end_run( 'ok' );

		$this->assertStringNotContainsString( 'sync.run.end', $this->contents() );
	}

	// --- error_log() mirror --------------------------------------------------

	public function test_only_the_thin_subset_is_mirrored_to_error_log() {
		$log = $this->log();

		$captured = $this->capture_error_log(
			static function () use ( $log ) {
				$log->begin_run( 'cron_daily' );
				$log->info( 'sync.batch.page', array( 'page' => 1, 'items' => 50 ) );
				$log->info( 'sync.prep.pages', array( 'total_pages' => 2 ) );
				$log->warn( 'sync.run.locked', array( 'reason' => 'already_running' ) );
				$log->error( 'sync.error', array( 'message' => 'boom' ) );
				$log->end_run( 'error' );
			}
		);

		// Mirrored: run start / end, errors, lock refused.
		$this->assertStringContainsString( 'TapGoods sync: ', $captured );
		$this->assertStringContainsString( 'sync.run.start', $captured );
		$this->assertStringContainsString( 'sync.run.end', $captured );
		$this->assertStringContainsString( 'sync.run.locked', $captured );
		$this->assertStringContainsString( 'sync.error', $captured );

		// Not mirrored: per-batch progress would flood a host error log.
		$this->assertStringNotContainsString( 'sync.batch.page', $captured );
		$this->assertStringNotContainsString( 'sync.prep.pages', $captured );

		// The full detail is still in the file.
		$this->assertStringContainsString( 'sync.batch.page', $this->contents() );
		$this->assertStringContainsString( 'sync.prep.pages', $this->contents() );
	}

	public function test_mirrored_lines_are_redacted_too() {
		$log = $this->log();
		$key = str_repeat( 'A1b2C3d4', 6 );

		$captured = $this->capture_error_log(
			static function () use ( $log, $key ) {
				$log->error(
					'sync.error',
					array(
						'api_key' => 'super-secret-value',
						'message' => 'Unauthorized: Bearer ' . $key,
					)
				);
			}
		);

		$this->assertStringContainsString( 'TapGoods sync: ', $captured );
		$this->assertStringNotContainsString( 'super-secret-value', $captured );
		$this->assertStringNotContainsString( $key, $captured );
		$this->assertStringContainsString( '<REDACTED>', $captured );
	}

	public function test_the_mirror_does_not_depend_on_the_file_sink() {
		// Unwritable target: the mirror is the only copy that survives, which is
		// the point of having two sinks.
		$blocker = rtrim( sys_get_temp_dir(), '/' ) . '/tg-sync-blocker-' . uniqid( '', false );
		file_put_contents( $blocker, 'not a directory' );
		$log = new Tapgoods_Sync_Log( $blocker . '/logs', $this->secret );

		$captured = $this->capture_error_log(
			static function () use ( $log ) {
				$log->error( 'sync.error', array( 'message' => 'boom' ) );
			}
		);

		$this->assertStringContainsString( 'TapGoods sync: ', $captured );
		$this->assertStringContainsString( 'sync.error', $captured );

		unlink( $blocker );
	}

	// --- Reading -------------------------------------------------------------

	public function test_get_recent_lines_returns_the_newest_lines_last() {
		$log = $this->log();
		for ( $i = 1; $i <= 5; $i++ ) {
			$log->info( 'sync.batch.page', array( 'page' => $i ) );
		}

		$lines = $log->get_recent_lines( 3 );

		$this->assertCount( 3, $lines );
		$this->assertStringContainsString( 'page=3', $lines[0] );
		$this->assertStringContainsString( 'page=5', $lines[2] );
	}

	public function test_file_name_carries_the_unguessable_component() {
		$log = $this->log();

		$this->assertSame( 'tg-sync-' . $this->secret . '.log', $log->get_file_name() );
		$this->assertStringEndsWith( 'tg-sync-' . $this->secret . '.log', $log->get_file_path() );
	}
}
