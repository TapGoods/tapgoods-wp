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
		$names = array( '.htaccess', 'index.php', $this->log_name(), $this->log_name() . '.lock' );
		for ( $i = 1; $i <= Tapgoods_Sync_Log::MAX_FILES + 2; $i++ ) {
			$names[] = $this->log_name() . '.' . $i;
		}
		foreach ( $names as $name ) {
			if ( is_dir( $dir . $name ) ) {
				// A rotation slot blocked by block_rotation().
				if ( file_exists( $dir . $name . '/occupied' ) ) {
					unlink( $dir . $name . '/occupied' );
				}
				rmdir( $dir . $name );
				continue;
			}
			if ( file_exists( $dir . $name ) ) {
				unlink( $dir . $name );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Run a callback with a handler that records every PHP diagnostic raised.
	 *
	 * phpunit.xml.dist sets failOnWarning="false", so a test that only asserted a
	 * return value would not notice this class emitting a warning. "Never emits a
	 * warning" is part of the logger's contract, so assert it directly.
	 *
	 * @param callable $callback Code to run.
	 * @return string[] Messages raised; empty when the contract held.
	 */
	private function capture_diagnostics( callable $callback ): array {
		$seen = array();

		// A custom handler is invoked even for diagnostics silenced with @, so a
		// naive handler would assert "this code contains no @" instead of "this
		// code emits nothing". PHP lowers error_reporting() for the duration of a
		// suppressed call, so a diagnostic was suppressed exactly when the level
		// inside the handler differs from the one in force here. Comparing against
		// this baseline rather than testing `error_reporting() & $errno` matters:
		// PHPUnit runs tests under a masked error_reporting of its own, and with
		// that mask the bitwise test matches nothing at all, which would make every
		// assertion built on this helper vacuous. See
		// test_the_diagnostic_capture_is_not_vacuous.
		$baseline = error_reporting();

		set_error_handler(
			static function ( $errno, $errstr ) use ( &$seen, $baseline ) {
				if ( error_reporting() === $baseline ) {
					$seen[] = $errno . ': ' . $errstr;
				}
				return true;
			}
		);

		try {
			$callback();
		} finally {
			restore_error_handler();
		}

		return $seen;
	}

	/**
	 * Make rotation impossible in a way root cannot shrug off.
	 *
	 * Every rotation slot is occupied by a non-empty directory: renaming a file
	 * onto a directory fails, and the directories cannot shuffle up the chain
	 * either because each target is a non-empty directory too. This is the
	 * deterministic stand-in for open_basedir, a read-only mount, or rename()
	 * being disabled.
	 *
	 * @param string $path Log file path.
	 * @return void
	 */
	private function block_rotation( string $path ): void {
		for ( $i = 1; $i <= Tapgoods_Sync_Log::MAX_FILES; $i++ ) {
			mkdir( $path . '.' . $i );
			file_put_contents( $path . '.' . $i . '/occupied', 'x' );
		}
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

	/**
	 * Guard the guard: capture_diagnostics() is only worth asserting on if it
	 * actually sees an unsuppressed diagnostic and ignores a suppressed one.
	 */
	public function test_the_diagnostic_capture_is_not_vacuous() {
		$unsuppressed = $this->capture_diagnostics(
			static function () {
				filesize( '/tapgoods-nonexistent-probe' );
			}
		);
		$this->assertNotSame( array(), $unsuppressed, 'An unsuppressed warning must be seen.' );

		$suppressed = $this->capture_diagnostics(
			static function () {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@filesize( '/tapgoods-nonexistent-probe' );
			}
		);
		$this->assertSame( array(), $suppressed, 'A suppressed warning must be ignored.' );
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

	/**
	 * Every shape a key can arrive in, including the ones a whitespace-delimited
	 * rule and a 32-character floor both miss.
	 *
	 * These are not hypothetical: validate_key() interpolates the key straight
	 * into a GraphQL query string, and two of the events here are mirrored into a
	 * host error log that stock WordPress serves over HTTP.
	 *
	 * @dataProvider credential_shapes
	 *
	 * @param string $field  Field name.
	 * @param string $value  Value containing the secret.
	 * @param string $secret The part that must not survive.
	 */
	public function test_credential_shapes_never_survive_formatting( string $field, string $value, string $secret ) {
		$rendered = Tapgoods_Sync_Log::format_value( $field, $value );

		$this->assertStringNotContainsString( $secret, $rendered );
		$this->assertStringContainsString( '<REDACTED>', $rendered );
	}

	public static function credential_shapes(): array {
		$short = 'tg_live_a1b2c3d4';                  // 17 chars: no length rule can catch it.
		$long  = str_repeat( 'A1b2C3d4', 4 );         // 32 chars.
		$mid   = str_repeat( 'a1b2c3d4', 3 );         // exactly 24 chars.

		return array(
			'key in a URL query string'  => array( 'message', 'GET https://openapi.tapgoods.com/v1/x?key=' . $short . '&page=2 failed', $short ),
			'key inside a JSON body'     => array( 'message', '{"api_key":"' . $short . '","businessId":1042}', $short ),
			'key in escaped quotes'      => array( 'message', 'query {bearerTokenValidator( bearerToken: \\"' . $short . '\\"){ businessId }}', $short ),
			'key in single quotes'       => array( 'message', "key='" . $short . "' was refused", $short ),
			'key with no quotes'         => array( 'message', 'apiKey=' . $short, $short ),
			'token with a trailing dot'  => array( 'message', 'rejected token ' . $long . '.', $long ),
			'token before a comma'       => array( 'message', 'tokens ' . $long . ', next', $long ),
			'24 character token'         => array( 'message', 'using ' . $mid . ' now', $mid ),
			'field named apikey'         => array( 'apikey', $short, $short ),
			'field named keys'           => array( 'keys', $short, $short ),
			'field named authorization'  => array( 'authorization', 'Bearer ' . $short, $short ),
			'field named x_api_key'      => array( 'x_api_key', $short, $short ),
			'authorization header form'  => array( 'message', 'Authorization: Bearer ' . $short, $short ),
			'bare bearer mid-sentence'   => array( 'message', 'sent Bearer ' . $short . ' upstream', $short ),
		);
	}

	/**
	 * Documents the KNOWN GAPS in the length heuristic, so that a future change
	 * which closes or widens one is noticed here rather than in production.
	 *
	 * These are not desired behaviour; they are the accepted cost of a floor that
	 * does not redact stack traces. Every one of them needs a BARE credential: no
	 * field name and no `key=` style context, either of which redacts regardless of
	 * length. Read alongside the rule-3 notes in Tapgoods_Sync_Log::redact().
	 *
	 * @dataProvider heuristic_gaps
	 *
	 * @param string $value  Value containing a bare secret.
	 * @param string $secret The part that survives today.
	 */
	public function test_documented_gaps_in_the_length_heuristic( string $value, string $secret ) {
		$this->assertStringContainsString(
			$secret,
			Tapgoods_Sync_Log::format_value( 'message', $value ),
			'A documented gap now closes. Update redact()\'s notes and move this case into credential_shapes().'
		);
	}

	public static function heuristic_gaps(): array {
		$half = 'A1b2C3d4A1b2C3d4A1b2'; // 20 chars: under the 24-char floor.

		return array(
			'bare short key'            => array( 'refused abc12345678 here', 'abc12345678' ),
			'bare 20-char digest'       => array( 'digest ' . $half . ' here', $half ),
			// Collapsing whitespace before redacting does not rescue this one: the
			// newline becomes a space and the two 20-char runs stay separate.
			'bare key split by newline' => array( 'token ' . $half . "\n" . $half . ' end', $half ),
		);
	}

	/**
	 * The same bare tokens that defeat the length rule are redacted the moment any
	 * context appears, which is what makes those gaps acceptable.
	 *
	 * @dataProvider heuristic_gaps
	 *
	 * @param string $value  Value containing a bare secret.
	 * @param string $secret The secret.
	 */
	public function test_the_documented_gaps_close_as_soon_as_there_is_context( string $value, string $secret ) {
		// Same value, but under a credential-named field.
		$this->assertStringNotContainsString( $secret, Tapgoods_Sync_Log::format_value( 'api_key', $value ) );

		// Same value, but with an assignment in front of the secret.
		$assigned = str_replace( $secret, 'api_key=' . $secret, $value );
		$this->assertStringNotContainsString( $secret, Tapgoods_Sync_Log::format_value( 'message', $assigned ) );
	}

	/**
	 * The redaction rules must not eat the fields the log exists to carry.
	 *
	 * @dataProvider innocent_values
	 *
	 * @param string $field Field name.
	 * @param string $value Value that must survive intact.
	 */
	public function test_ordinary_values_survive_redaction( string $field, string $value ) {
		$this->assertStringContainsString( $value, Tapgoods_Sync_Log::format_value( $field, $value ) );
	}

	public static function innocent_values(): array {
		return array(
			'entry point name'  => array( 'entry', 'sync_inventory_in_batches' ),
			'trigger name'      => array( 'trigger', 'frontend_fallback' ),
			'id list'           => array( 'sample_ids', '11001,11002,11003,11004,11005,11006,11007' ),
			'page ratio'        => array( 'pages', '0/3' ),
			'error text'        => array( 'message', 'Failed to retrieve location information.' ),
			'a file path'       => array( 'file', '/var/www/html/wp-content/plugins/tapgoods-wp/includes/class-tapgoods-connection.php' ),
			'an operation name' => array( 'op', 'get_location_ids' ),
			'a taxonomy'        => array( 'taxonomy', 'tg_category' ),
			'an endpoint URL'   => array( 'message', 'GET https://openapi.tapgoods.com/v1/external/graphql failed' ),
		);
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

	public function test_the_size_cap_holds_even_when_rotation_cannot_happen() {
		$log  = $this->log();
		$path = $this->dir . $this->log_name();

		$log->info( 'sync.run.start' );

		$this->block_rotation( $path );

		file_put_contents( $path, str_repeat( 'x', Tapgoods_Sync_Log::MAX_BYTES ) );

		$warnings = $this->capture_diagnostics(
			function () use ( $log ) {
				$this->assertTrue( $log->info( 'sync.run.end', array( 'result' => 'ok' ) ) );
			}
		);
		$this->assertSame( array(), $warnings, 'A failed rotation must not emit a diagnostic.' );

		// The cap held: history was dropped rather than the file growing forever.
		clearstatcache();
		$this->assertLessThan( Tapgoods_Sync_Log::MAX_BYTES, filesize( $path ) );

		$contents = $this->contents();
		$this->assertStringContainsString( 'sync.log.rotation_failed', $contents, 'The gap in history must be recorded, not silent.' );
		$this->assertStringContainsString( 'action=truncated', $contents );
		$this->assertStringContainsString( 'sync.run.end', $contents, 'Logging must continue after the truncation.' );
	}

	public function test_repeated_writes_never_exceed_the_cap_when_rotation_is_broken() {
		$log  = $this->log();
		$path = $this->dir . $this->log_name();

		$log->info( 'sync.run.start' );
		$this->block_rotation( $path );

		for ( $i = 0; $i < 3; $i++ ) {
			file_put_contents( $path, str_repeat( 'x', Tapgoods_Sync_Log::MAX_BYTES ) );
			$log->info( 'sync.batch.page', array( 'page' => $i ) );
			clearstatcache();
			$this->assertLessThan( Tapgoods_Sync_Log::MAX_BYTES, filesize( $path ) );
		}
	}

	// --- Degradation ---------------------------------------------------------

	public function test_unwritable_target_is_a_silent_no_op() {
		// A regular file where a directory would have to be: mkdir cannot succeed,
		// which is the closest deterministic stand-in for a locked-down uploads dir
		// (a chmod would not stop the root user the test suite may run as).
		$blocker = rtrim( sys_get_temp_dir(), '/' ) . '/tg-sync-blocker-' . uniqid( '', false );
		file_put_contents( $blocker, 'not a directory' );

		$log = new Tapgoods_Sync_Log( $blocker . '/logs', $this->secret );

		// Not just "returns false": the contract is that nothing is emitted either,
		// because a warning here would print into an AJAX sync response.
		$warnings = $this->capture_diagnostics(
			function () use ( $log ) {
				$this->assertFalse( $log->info( 'sync.run.start' ) );
				$this->assertFalse( $log->error( 'sync.error', array( 'message' => 'boom' ) ) );
				$this->assertSame( array(), $log->get_recent_lines() );
				$this->assertFalse( $log->read_all() );
				$this->assertSame( 0, $log->get_size() );
			}
		);

		$this->assertSame( array(), $warnings, 'The logger must never emit a diagnostic.' );

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

	/**
	 * A run start with no run end is the worst output this log can produce: it
	 * reads exactly like a sync that is still going, which is the confusion the
	 * log exists to remove. The sync reaches an API client that throws from
	 * outside any try/catch (and one of the classes it throws is not even
	 * defined, so that path is a fatal), so the guarantee cannot come from a
	 * catch block.
	 *
	 * This exercises what register_shutdown_function() calls, which is the
	 * mechanism that actually fires on a fatal. It survives WordPress's own fatal
	 * handler only because core passes 'exit' => false to wp_die(); see
	 * Tapgoods_Sync_Log::arm_shutdown_guard() for the host configurations that
	 * bypass it, and note that the guarantee is best-effort rather than absolute.
	 */
	public function test_a_run_left_open_gets_a_terminal_line_at_shutdown() {
		$log = $this->log();

		$run_id = $log->begin_run( 'cron_daily' );
		$log->info( 'sync.prep.locations', array( 'count' => 2 ) );

		// What register_shutdown_function() would call after a fatal.
		$log->close_open_run();

		$contents = $this->contents();
		$this->assertStringContainsString( 'sync.run.aborted', $contents );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ), 'Exactly one terminal line.' );
		$this->assertStringContainsString( 'result=aborted', $contents );
		$this->assertStringContainsString( 'run=' . $run_id, $contents );
		$this->assertFalse( $log->has_open_run() );
	}

	public function test_the_shutdown_guard_collapses_a_nested_run_to_one_terminal_line() {
		$log = $this->log();

		$log->begin_run( 'cron_selfping' );
		$log->begin_run( 'cron_selfping' ); // Inner entry point, never closed.

		$log->close_open_run();

		$contents = $this->contents();
		$this->assertSame( 1, substr_count( $contents, 'sync.run.start' ) );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ) );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.aborted' ) );
	}

	public function test_the_shutdown_guard_is_a_no_op_after_a_normal_run() {
		$log = $this->log();

		$log->begin_run( 'admin_manual' );
		$log->end_run( 'ok', array( 'items' => 4 ) );

		$log->close_open_run();

		$contents = $this->contents();
		$this->assertStringNotContainsString( 'sync.run.aborted', $contents );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ) );
		$this->assertStringContainsString( 'result=ok', $contents );
	}

	/**
	 * The secondary seam. This filter does NOT fire on an ordinary fatal:
	 * WP_Fatal_Error_Handler::should_handle_error() returns true for E_ERROR and
	 * friends before applying it, and core documents it as "only fired if the
	 * error is not already configured to be handled by WordPress core". It is kept
	 * for the error types core does not claim, and it must stay a pure observer.
	 */
	public function test_the_fatal_filter_writes_a_terminal_line_and_passes_the_decision_through() {
		$log = $this->log();
		$log->begin_run( 'cron_selfping' );

		$this->assertTrue( $log->note_fatal_error( true, array( 'type' => E_ERROR, 'message' => 'boom', 'file' => 'x.php', 'line' => 1 ) ) );

		$contents = $this->contents();
		$this->assertStringContainsString( 'sync.run.aborted', $contents );
		$this->assertSame( 1, substr_count( $contents, 'sync.run.end' ) );

		// And it must not invent a decision of its own.
		$this->assertFalse( $log->note_fatal_error( false ) );
	}

	public function test_the_aborted_terminal_event_is_mirrored() {
		$log = $this->log();

		$captured = $this->capture_error_log(
			static function () use ( $log ) {
				$log->begin_run( 'cron_daily' );
				$log->close_open_run();
			}
		);

		$this->assertStringContainsString( 'sync.run.aborted', $captured );
		$this->assertStringContainsString( 'result=aborted', $captured );
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
