<?php
/**
 * TapGoods sync activity log.
 *
 * A small, always-on activity log for the inventory sync. It exists because when
 * a sync fails on a customer site the only durable state used to be the
 * `tg_sync_state` option: there was no record of which batch failed, how many
 * pages were fetched, what the API returned, or what triggered the run.
 *
 * Two sinks, deliberately different in volume:
 *
 *   1. A file under TAPGOODS_UPLOADS (`wp-content/uploads/tapgoods/`). Every
 *      event, one line each, size-rotated. This is the artefact support asks a
 *      customer to download.
 *   2. `error_log()`, for a thin subset only (run start, run end, errors and a
 *      refused/already-running run). On managed hosts that stream is readable
 *      from the host control panel with no extra credentials, so it is the only
 *      "free" transport we have. It is kept thin on purpose: mirroring per-batch
 *      progress would flood a portal view that only keeps a few thousand rows.
 *
 * Both sinks are always on and independent of WP_DEBUG, and both apply the same
 * redaction rules. Treat every line as potentially readable by someone who is
 * not us: never write the API key or any derived credential, and never a full
 * GraphQL response body. Ids, counts, page numbers, timings and error messages
 * are fine. Values are capped in length, which is the structural guarantee that
 * a payload dump cannot land in either sink even by accident.
 *
 * Logging is best-effort by contract: if the uploads directory is missing or not
 * writable it degrades to a silent no-op. It must never warn, never throw and
 * never interrupt a sync.
 *
 * That contract is also why the file writes go through plain PHP functions rather
 * than WP_Filesystem: Tapgoods_Filesystem::put_file() requires a POST request and
 * a nonce, and WP_Filesystem() can ask for FTP credentials, neither of which
 * exists during a cron-driven sync. Every filesystem call here is suppressed and
 * its failure simply turns logging off for the rest of the request.
 *
 * The class only touches a handful of WordPress helpers, all behind
 * function_exists(), and takes its directory / file-name secret as constructor
 * arguments, so it is fully unit-testable in isolation against a temp directory.
 *
 * @package Tapgoods\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Tapgoods_Sync_Log {

	const LEVEL_DEBUG = 'DEBUG';
	const LEVEL_INFO  = 'INFO';
	const LEVEL_WARN  = 'WARN';
	const LEVEL_ERROR = 'ERROR';

	/** Option holding the unguessable component of the log file name. */
	const SECRET_OPTION = 'tg_sync_log_secret';

	/** Rotate once a file would grow past this many bytes. */
	const MAX_BYTES = 1048576;

	/** How many rotated backups to keep (so at most MAX_FILES + 1 files exist). */
	const MAX_FILES = 3;

	/** Hard caps that make a payload dump impossible. */
	const MAX_VALUE_LENGTH = 300;
	const MAX_LINE_LENGTH  = 2000;

	/** Marker that makes mirrored lines findable in a crowded host error log. */
	const MIRROR_PREFIX = 'TapGoods sync: ';

	/**
	 * The only events mirrored to error_log(). Keep this list short: it is a
	 * shared, size-limited stream on managed hosts.
	 */
	const MIRRORED_EVENTS = array(
		'sync.run.start',
		'sync.run.end',
		'sync.run.aborted',
		'sync.run.locked',
		'sync.api.error',
		'sync.error',
	);

	private static $instance = null;

	/**
	 * Directory the log lives in, with a trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Unguessable component of the file name.
	 *
	 * @var string|null
	 */
	private $secret;

	/**
	 * Whether the target directory is usable. Resolved lazily, once.
	 *
	 * @var bool|null
	 */
	private $ready = null;

	/**
	 * Correlation id of the run in flight, '' when there is none.
	 *
	 * @var string
	 */
	private $run_id = '';

	/**
	 * Nesting depth, so nested entry points share a single run.
	 *
	 * @var int
	 */
	private $run_depth = 0;

	/**
	 * Start of the current run, as returned by microtime(true).
	 *
	 * @var float|null
	 */
	private $run_started = null;

	/**
	 * Totals accumulated by any layer of the current run.
	 *
	 * @var array
	 */
	private $run_totals = array();

	/**
	 * Worst result reported by any layer of the current run.
	 *
	 * @var string
	 */
	private $run_result = 'ok';

	/**
	 * Cached minimum level for this request.
	 *
	 * @var string|null
	 */
	private $min_level = null;

	/**
	 * Whether the shutdown guard has been registered for this instance.
	 *
	 * @var bool
	 */
	private $shutdown_armed = false;

	/**
	 * Build a logger. Both arguments exist so tests can point it at a temp dir.
	 *
	 * @param string|null $dir    Target directory. Defaults to TAPGOODS_UPLOADS.
	 * @param string|null $secret File-name secret. Defaults to a per-site value.
	 */
	public function __construct( $dir = null, $secret = null ) {
		if ( null === $dir ) {
			$dir = defined( 'TAPGOODS_UPLOADS' ) ? TAPGOODS_UPLOADS : '';
		}
		$this->dir    = ( '' === $dir ) ? '' : rtrim( (string) $dir, '/\\' ) . '/';
		$this->secret = $secret;
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// --- Writing -------------------------------------------------------------

	/**
	 * Write one event.
	 *
	 * @param string $level   One of the LEVEL_* constants.
	 * @param string $event   Short stable event name, e.g. 'sync.batch.page'.
	 * @param array  $context key => value pairs appended to the line.
	 * @return bool True when the line reached the log file.
	 */
	public function log( $level, $event, $context = array() ) {

		if ( ! $this->level_enabled( $level ) ) {
			return false;
		}

		$line = self::format_line( $level, $event, $this->with_run_context( $context ) );

		// The two sinks are independent: a failed file write must not stop the
		// mirror, which on managed hosts is the copy we can actually read.
		$this->mirror( $event, $line );

		return $this->append( $line );
	}

	public function debug( $event, $context = array() ) {
		return $this->log( self::LEVEL_DEBUG, $event, $context );
	}

	public function info( $event, $context = array() ) {
		return $this->log( self::LEVEL_INFO, $event, $context );
	}

	public function warn( $event, $context = array() ) {
		return $this->log( self::LEVEL_WARN, $event, $context );
	}

	public function error( $event, $context = array() ) {
		return $this->log( self::LEVEL_ERROR, $event, $context );
	}

	// --- Run lifecycle -------------------------------------------------------

	/**
	 * Open a run, or join the one already open.
	 *
	 * Nested entry points (sync_from_api() calling sync_inventory_in_batches())
	 * share a single run so exactly one start and one end line is written per
	 * invocation, whichever layer was entered first.
	 *
	 * @param string $trigger Which entry point started this run.
	 * @param array  $context Extra fields for the run-start line.
	 * @return string The run correlation id.
	 */
	public function begin_run( $trigger = 'unknown', $context = array() ) {

		++$this->run_depth;
		if ( $this->run_depth > 1 ) {
			return $this->run_id;
		}

		$this->run_id      = substr( md5( uniqid( 'tg', true ) ), 0, 8 );
		$this->run_started = microtime( true );
		$this->run_totals  = array();
		$this->run_result  = 'ok';

		$this->arm_shutdown_guard();

		$this->info( 'sync.run.start', array_merge( array( 'trigger' => $trigger ), $context ) );

		return $this->run_id;
	}

	/**
	 * Make sure an open run always gets a terminal line, even on a fatal.
	 *
	 * A run start with no run end is the worst thing this log can produce: it is
	 * indistinguishable from a sync that is still going, which is the very
	 * confusion the log exists to remove. And it is reachable: the sync calls into
	 * an API client that throws from outside any try/catch, and one of the classes
	 * it throws (TG_Unknown_Error_Exception) is not even defined, so that path is a
	 * hard fatal rather than a catchable exception. No try/catch can cover a fatal;
	 * a shutdown function can.
	 *
	 * Registered once per instance, and a no-op when the run closed normally.
	 *
	 * @return void
	 */
	private function arm_shutdown_guard() {

		if ( $this->shutdown_armed ) {
			return;
		}

		$this->shutdown_armed = true;

		/*
		 * Two mechanisms, because on a real WordPress fatal the obvious one does
		 * not run. WordPress registers its own fatal handler in wp-settings.php,
		 * before any plugin is loaded, and that handler ends in wp_die(); exiting
		 * inside a shutdown function skips every shutdown function registered
		 * after it, so ours would never fire. Verified, not assumed: a deliberate
		 * fatal mid-sync produced no terminal line until this filter was added.
		 *
		 * `wp_should_handle_php_error` is applied inside that handler, just before
		 * it takes over, which is the last moment at which anything of ours can
		 * still write. It only fires when a fatal actually happened.
		 */
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_should_handle_php_error', array( $this, 'note_fatal_error' ), 10, 2 );
		}

		/*
		 * And the plain shutdown function for every other way a request can end
		 * without a terminal line: a fatal when WordPress's handler is disabled
		 * (WP_DISABLE_FATAL_ERROR_HANDLER), a timeout, a die() somewhere in the
		 * call stack. Both paths funnel into close_open_run(), which is
		 * idempotent, so whichever fires first wins and the other does nothing.
		 */
		if ( function_exists( 'register_shutdown_function' ) ) {
			register_shutdown_function( array( $this, 'close_open_run' ) );
		}
	}

	/**
	 * Write the terminal line while WordPress's fatal handler is deciding.
	 *
	 * Hooked to `wp_should_handle_php_error`; passes the decision straight
	 * through, since this is an observer and must not change how WordPress
	 * handles the error.
	 *
	 * @param bool  $should_handle Whether WordPress will handle this error.
	 * @param array $error         The error, as returned by error_get_last().
	 * @return bool $should_handle, unchanged.
	 */
	public function note_fatal_error( $should_handle, $error = null ) {
		// Prefer the error WordPress is actually handling over error_get_last().
		$this->close_open_run( is_array( $error ) ? $error : null );
		return $should_handle;
	}

	/**
	 * Terminal line for a run that never closed. Public because the shutdown
	 * handler and the fatal-error filter both call it; safe to call at any time,
	 * and a no-op once the run has closed normally.
	 *
	 * @param array|null $error Error to report, in error_get_last() shape. Falls
	 *                          back to error_get_last() when not supplied.
	 * @return void
	 */
	public function close_open_run( $error = null ) {

		if ( $this->run_depth < 1 ) {
			return;
		}

		$context = array( 'reason' => 'no_terminal_event' );

		// If PHP died on us, say what it died of: that is usually the whole answer.
		$fatal = ( null === $error ) ? error_get_last() : $error;
		if ( is_array( $fatal ) && isset( $fatal['type'], $fatal['message'], $fatal['file'], $fatal['line'] ) && in_array( (int) $fatal['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			$context['reason']  = 'fatal';
			$context['message'] = $fatal['message'];
			$context['file']    = $fatal['file'];
			$context['line']    = $fatal['line'];
		}

		$this->error( 'sync.run.aborted', $context );

		// Collapse any nesting so exactly one run-end line is written.
		$this->run_depth  = 1;
		$this->run_result = 'aborted';
		$this->end_run( 'aborted' );
	}

	/**
	 * Close a run. Only the outermost call writes the single run-end line.
	 *
	 * @param string $result  'ok', 'error' or 'skipped'. The worst result
	 *                        reported by any layer wins.
	 * @param array  $context Totals to report, merged across layers.
	 * @return void
	 */
	public function end_run( $result = 'ok', $context = array() ) {

		if ( $this->run_depth < 1 ) {
			// Unpaired close (a caller bug): never invent a run-end line for it.
			$this->run_depth = 0;
			return;
		}

		$this->run_totals = array_merge( $this->run_totals, $context );
		if ( 'ok' !== $result && 'ok' === $this->run_result ) {
			$this->run_result = (string) $result;
		}

		--$this->run_depth;
		if ( 0 !== $this->run_depth ) {
			return;
		}

		$totals               = array_merge( array( 'result' => $this->run_result ), $this->run_totals );
		$totals['duration_s'] = round( microtime( true ) - (float) $this->run_started, 1 );

		$this->info( 'sync.run.end', $totals );

		$this->run_id      = '';
		$this->run_started = null;
		$this->run_totals  = array();
		$this->run_result  = 'ok';
	}

	public function has_open_run() {
		return $this->run_depth > 0;
	}

	public function get_run_id() {
		return $this->run_id;
	}

	// --- Reading -------------------------------------------------------------

	/**
	 * Absolute path of the current log file, or '' when logging has no target.
	 *
	 * @return string
	 */
	public function get_file_path() {
		if ( '' === $this->dir ) {
			return '';
		}
		return $this->dir . 'tg-sync-' . $this->secret() . '.log';
	}

	/**
	 * File name only, safe to show to an administrator so support can point a
	 * customer at the right file.
	 *
	 * @return string
	 */
	public function get_file_name() {
		$path = $this->get_file_path();
		return ( '' === $path ) ? '' : basename( $path );
	}

	/**
	 * Size of the current log file in bytes (0 when there is none).
	 *
	 * @return int
	 */
	public function get_size() {
		$path = $this->get_file_path();
		if ( '' === $path || ! file_exists( $path ) ) {
			return 0;
		}
		// Suppressed: file_exists() above is a TOCTOU check and this is also
		// reached from the sync path, where a warning must never surface.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size = @filesize( $path );
		return ( false === $size ) ? 0 : (int) $size;
	}

	/**
	 * The most recent lines, newest last. Reads at most the tail of the file.
	 *
	 * @param int $limit How many lines to return.
	 * @return string[]
	 */
	public function get_recent_lines( $limit = 40 ) {

		$path = $this->get_file_path();
		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		$tail_bytes = 262144;
		$size       = $this->get_size();
		$offset     = ( $size > $tail_bytes ) ? $size - $tail_bytes : 0;

		$contents = file_get_contents( $path, false, null, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( (string) $contents ) ) {
			return array();
		}

		$lines = preg_split( "/\r\n|\n|\r/", trim( (string) $contents ) );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		if ( $offset > 0 ) {
			// The first line is very likely cut in half by the offset.
			array_shift( $lines );
		}

		$limit = max( 1, (int) $limit );
		return array_slice( $lines, -1 * $limit );
	}

	/**
	 * Whole current log file, for the admin download. False when unavailable.
	 *
	 * @return string|false
	 */
	public function read_all() {
		$path = $this->get_file_path();
		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	// --- Formatting / redaction ----------------------------------------------

	/**
	 * Build one log line.
	 *
	 * Format: `<UTC ISO-8601> <LEVEL> <event> key=value key=value ...`
	 * Values containing spaces are quoted, so the line stays one grep away from
	 * being readable by a non-engineer.
	 *
	 * @param string $level   Level constant.
	 * @param string $event   Event name.
	 * @param mixed  $context key => value pairs (anything else is ignored).
	 * @return string
	 */
	public static function format_line( $level, $event, $context = array() ) {

		$line = gmdate( 'Y-m-d\TH:i:s\Z' ) . ' ' . strtoupper( (string) $level ) . ' ' . self::sanitize_event( $event );

		if ( is_array( $context ) ) {
			foreach ( $context as $key => $value ) {
				$key = self::sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}
				$line .= ' ' . $key . '=' . self::format_value( $key, $value );
			}
		}

		$line = str_replace( array( "\r", "\n" ), ' ', $line );

		if ( strlen( $line ) > self::MAX_LINE_LENGTH ) {
			$line = substr( $line, 0, self::MAX_LINE_LENGTH ) . ' [truncated]';
		}

		return $line;
	}

	/**
	 * Render a single context value: never a payload, never a credential.
	 *
	 * @param string $key   Already sanitized field name.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	public static function format_value( $key, $value ) {

		if ( is_array( $value ) ) {
			return 'array(' . count( $value ) . ')';
		}
		if ( is_object( $value ) ) {
			return 'object(' . get_class( $value ) . ')';
		}
		if ( null === $value ) {
			return '-';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		$string = self::redact( $key, (string) $value );

		// Collapse whitespace so one event is always exactly one line.
		$string = trim( preg_replace( '/\s+/', ' ', $string ) );

		if ( strlen( $string ) > self::MAX_VALUE_LENGTH ) {
			$string = substr( $string, 0, self::MAX_VALUE_LENGTH ) . '[truncated]';
		}

		if ( '' === $string ) {
			return '""';
		}

		if ( preg_match( '/[\s"=]/', $string ) ) {
			return '"' . str_replace( '"', '\\"', $string ) . '"';
		}

		return $string;
	}

	/**
	 * Strip anything credential-shaped.
	 *
	 * This is defence in depth, not the primary control. The primary control is
	 * that call sites pass ids, counts and timings, and that the API key travels
	 * in an Authorization header and in validate_key()'s POST body, neither of
	 * which any call site logs. But two of the events here are mirrored into a
	 * host error log that stock WordPress serves over HTTP, so the cost of one
	 * upstream refactor putting a key into an error message is a public
	 * credential leak. The layers therefore assume the worst:
	 *
	 *   1. credential-sounding field names never render their value at all.
	 *      Matched as a SUBSTRING, so apikey / keys / authorization / x-api-key
	 *      are all covered without maintaining a list of separator styles;
	 *   2. a credential-sounding assignment ANYWHERE inside a value loses its
	 *      right-hand side: `?key=abc`, `{"api_key":"abc"}`,
	 *      `bearerToken: \"abc\"` and `Authorization: Bearer abc` all collapse.
	 *      This is what covers a short key, which no length heuristic can catch;
	 *   3. any long opaque run (24+ chars of the base64/hex alphabet carrying at
	 *      least one digit and one letter) is dropped, as a SUBSTRING rather than
	 *      a whitespace-delimited word, so a trailing dot or comma no longer
	 *      saves the token.
	 *
	 * Rule 3 deliberately excludes '/' from its alphabet: with it, ordinary URL
	 * paths and long error strings get eaten. A bare slash-bearing base64 blob
	 * pasted into a message with no `key=` next to it is the known residual gap;
	 * rules 1 and 2 cover every shape the plugin can actually produce, and the
	 * 300-character value cap bounds the rest.
	 *
	 * @param string $key   Field name.
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function redact( $key, $value ) {

		// 1. Credential-sounding field name: the value never renders.
		if ( preg_match( '/(key|token|secret|password|passwd|auth|bearer|credential|nonce|signature)/i', (string) $key ) ) {
			return '<REDACTED>';
		}

		$value = (string) $value;

		// 2. `<credential word><separator><value>` anywhere inside the string.
		$value = preg_replace(
			// The optional Bearer/Token prefix inside the captured group matters:
			// without it, `Authorization: Bearer abc` loses only the word "Bearer".
			'/((?:api[_.-]?key|apikey|keys?|bearer[_.-]?token|token|secret|password|passwd|authorization|auth|credential|signature)["\']?\s*(?:[:=]|=>)\s*["\']?\\\\?"?)((?:bearer\s+|token\s+)?[^"\'\s,;&})\]]{3,})/i',
			'$1<REDACTED>',
			$value
		);

		// `Bearer <token>` has no separator, so it needs its own pass.
		$value = preg_replace( '/\bBearer\s+\S+/i', 'Bearer <REDACTED>', $value );

		// 3. Long opaque runs, matched as substrings.
		return preg_replace_callback(
			'/[A-Za-z0-9+=_-]{24,}/',
			static function ( $matches ) {
				$candidate  = $matches[0];
				$has_digit  = (bool) preg_match( '/[0-9]/', $candidate );
				$has_letter = (bool) preg_match( '/[A-Za-z]/', $candidate );
				return ( $has_digit && $has_letter ) ? '<REDACTED>' : $candidate;
			},
			$value
		);
	}

	private static function sanitize_event( $event ) {
		$event = preg_replace( '/[^A-Za-z0-9._:-]/', '_', (string) $event );
		return ( '' === $event ) ? 'unknown' : $event;
	}

	private static function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		$key = preg_replace( '/[^a-z0-9_]/', '_', $key );
		return trim( (string) $key, '_' );
	}

	// --- Internals -----------------------------------------------------------

	/**
	 * Add the run correlation id to every line written inside a run.
	 *
	 * @param array $context Context to extend.
	 * @return array
	 */
	private function with_run_context( $context ) {
		if ( '' === $this->run_id ) {
			return $context;
		}
		return array_merge( array( 'run' => $this->run_id ), $context );
	}

	/**
	 * Minimum level written this request.
	 *
	 * INFO by default: the DEBUG lines are the per-item ones, and leaving them
	 * off is what keeps the file small on a site with tens of thousands of
	 * items. Support can raise verbosity with the `tapgoods_sync_log_level`
	 * filter without touching code.
	 *
	 * @return string
	 */
	private function min_level() {
		if ( null !== $this->min_level ) {
			return $this->min_level;
		}
		$level = self::LEVEL_INFO;
		if ( function_exists( 'apply_filters' ) ) {
			$level = apply_filters( 'tapgoods_sync_log_level', $level );
		}
		$level = strtoupper( (string) $level );
		if ( ! isset( self::level_weights()[ $level ] ) ) {
			$level = self::LEVEL_INFO;
		}
		$this->min_level = $level;
		return $level;
	}

	private static function level_weights() {
		return array(
			self::LEVEL_DEBUG => 10,
			self::LEVEL_INFO  => 20,
			self::LEVEL_WARN  => 30,
			self::LEVEL_ERROR => 40,
		);
	}

	private function level_enabled( $level ) {
		$weights = self::level_weights();
		$level   = strtoupper( (string) $level );
		if ( ! isset( $weights[ $level ] ) ) {
			return false;
		}
		return $weights[ $level ] >= $weights[ $this->min_level() ];
	}

	/**
	 * Mirror the thin subset to the host PHP error log.
	 *
	 * Same redaction as the file (the line is already formatted and redacted at
	 * this point). Failures are swallowed: a sync must never break because a
	 * host disabled error_log().
	 *
	 * @param string $event Event name.
	 * @param string $line  Formatted, redacted line.
	 * @return bool Whether the line was mirrored.
	 */
	private function mirror( $event, $line ) {

		if ( ! in_array( self::sanitize_event( $event ), self::MIRRORED_EVENTS, true ) ) {
			return false;
		}
		if ( ! function_exists( 'error_log' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.NoSilencedErrors.Discouraged -- the mirror IS the feature; failures are swallowed by contract.
		return (bool) @error_log( self::MIRROR_PREFIX . $line );
	}

	/**
	 * Append one line to the log file, rotating first if needed.
	 *
	 * @param string $line Formatted line, without the newline.
	 * @return bool
	 */
	private function append( $line ) {

		if ( ! $this->prepare() ) {
			return false;
		}

		$path = $this->get_file_path();
		$data = $line . "\n";

		if ( ! $this->enforce_size_cap( $path, strlen( $data ) ) ) {
			// Rotation was needed and could not be done (open_basedir, a read-only
			// mount, rename() disabled, .1 occupied by something we can't move).
			// Silent degradation is right for logging but NOT for the cap: growing
			// past it is somebody's disk. Drop the history instead, and say so in
			// the file so nobody mistakes the gap for a quiet sync.
			if ( ! $this->reset_file( $path ) ) {
				// Can't rotate and can't truncate: stop writing for this request
				// rather than grow without bound.
				$this->ready = false;
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$written = @file_put_contents( $path, $data, FILE_APPEND | LOCK_EX );

		return false !== $written;
	}

	/**
	 * Keep the log inside its size budget, rotating if needed.
	 *
	 * The size check and the renames run under an exclusive lock on a separate
	 * lock file, and the size is re-checked after the lock is held. Without that,
	 * two writers arriving near the boundary both decide to rotate and the second
	 * one's rename() clobbers the backup the first just created with a nearly
	 * empty file, silently destroying history. A lock on the log file itself would
	 * not do: after the rename the descriptor no longer refers to that path, so a
	 * third writer would lock a different inode.
	 *
	 * @param string $path     Current log file.
	 * @param int    $incoming Bytes about to be appended.
	 * @return bool True when the cap is satisfied, false when rotation was needed
	 *              but failed.
	 */
	private function enforce_size_cap( $path, $incoming ) {

		if ( ! $this->over_cap( $path, $incoming ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		$lock = @fopen( $path . '.lock', 'c' );
		if ( is_resource( $lock ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@flock( $lock, LOCK_EX );
		}

		// Re-check while holding the lock: another writer may have just rotated.
		$rotated = true;
		if ( $this->over_cap( $path, $incoming ) ) {
			$rotated = $this->rotate( $path );
		}

		if ( is_resource( $lock ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
			@fclose( $lock );
		}

		return $rotated;
	}

	/**
	 * Whether appending $incoming bytes would push the file past the cap.
	 *
	 * Deliberately impure: it stats the filesystem every time it is called, which
	 * is the whole point of asking again once the rotation lock is held.
	 *
	 * @phpstan-impure
	 *
	 * @param string $path     Current log file.
	 * @param int    $incoming Bytes about to be appended.
	 * @return bool
	 */
	private function over_cap( $path, $incoming ) {

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@clearstatcache( true, $path );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// Suppressed: file_exists() above is a TOCTOU check, and this runs on the
		// sync path where a warning could print into an AJAX response body.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size = @filesize( $path );

		return ( false !== $size && ( $size + $incoming ) > self::MAX_BYTES );
	}

	/**
	 * Size-based rotation: current -> .1 -> .2 -> .3, oldest dropped.
	 *
	 * A fixed number of fixed-size files is the only thing standing between this
	 * log and a customer's disk, so it is not optional. Called with the rotation
	 * lock held.
	 *
	 * @param string $path Current log file.
	 * @return bool True when the current file was moved out of the way.
	 */
	private function rotate( $path ) {

		for ( $i = self::MAX_FILES; $i >= 1; $i-- ) {
			$from = ( 1 === $i ) ? $path : $path . '.' . ( $i - 1 );
			$to   = $path . '.' . $i;

			if ( ! file_exists( $from ) ) {
				continue;
			}

			if ( self::MAX_FILES === $i && file_exists( $to ) ) {
				// Retention: the oldest backup falls off the end.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $to );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem needs credentials we may not have during cron.
			$moved = @rename( $from, $to );

			// Only the current file matters for the cap: if the older backups
			// cannot shuffle along we lose history, not containment.
			if ( 1 === $i && ! $moved ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Start the log file over, recording why the history is gone.
	 *
	 * @param string $path Current log file.
	 * @return bool
	 */
	private function reset_file( $path ) {

		$notice = self::format_line(
			self::LEVEL_WARN,
			'sync.log.rotation_failed',
			array(
				'action' => 'truncated',
				'reason' => 'rotation_unavailable',
				'cap'    => self::MAX_BYTES,
			)
		) . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$written = @file_put_contents( $path, $notice, LOCK_EX );

		return false !== $written;
	}

	/**
	 * Make sure the target directory exists, is writable and is guarded.
	 *
	 * Resolved once per instance. Any failure disables logging for the rest of
	 * the request, silently.
	 *
	 * @return bool
	 */
	private function prepare() {

		if ( null !== $this->ready ) {
			return $this->ready;
		}

		$this->ready = false;

		if ( '' === $this->dir ) {
			return false;
		}

		if ( ! is_dir( $this->dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $this->dir );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged
				@mkdir( $this->dir, 0755, true );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- see the class docblock: WP_Filesystem is unusable here.
		if ( ! is_dir( $this->dir ) || ! is_writable( $this->dir ) ) {
			return false;
		}

		$this->write_guards();

		$this->ready = true;
		return true;
	}

	/**
	 * Keep the log out of reach over HTTP.
	 *
	 * .htaccess only does anything on Apache, and the index.php only stops a
	 * directory listing, so neither is a substitute for the unguessable file
	 * name. All three together are the defence.
	 *
	 * @return void
	 */
	private function write_guards() {

		$htaccess = $this->dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# TapGoods: deny direct HTTP access to plugin log files (Apache only).\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $htaccess, $rules );
		}

		$index = $this->dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Per-site unguessable component of the file name (defence in depth only).
	 *
	 * @return string
	 */
	private function secret() {

		if ( null !== $this->secret && '' !== $this->secret ) {
			return $this->secret;
		}

		$stored = function_exists( 'get_option' ) ? get_option( self::SECRET_OPTION, '' ) : '';

		if ( ! is_string( $stored ) || strlen( $stored ) < 8 ) {
			if ( function_exists( 'wp_generate_password' ) ) {
				$stored = strtolower( wp_generate_password( 20, false, false ) );
			} else {
				$stored = substr( md5( uniqid( 'tg', true ) ), 0, 20 );
			}
			if ( function_exists( 'update_option' ) ) {
				update_option( self::SECRET_OPTION, $stored );
			}
		}

		$this->secret = preg_replace( '/[^a-z0-9]/', '', strtolower( $stored ) );
		return $this->secret;
	}
}
