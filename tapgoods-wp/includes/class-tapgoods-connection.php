<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods_Connection {

	private static $instance = null;

	private $client = null;
	protected $key;

	private $hash           = null;
	private $u_sync_start   = null;
	private $u_sync_end     = null;
	private $sync_duration  = null;
	private $last_sync_time = null;

	/**
	 * True only while a bounded sync slice is executing in THIS request. The
	 * shutdown handler uses it to tell a clean end from an abnormal one.
	 *
	 * @var bool
	 */
	private $active_run = false;

	/** Whether the shutdown handler has already been registered this request. */
	private $shutdown_registered = false;

	/** Execution mutex transient: one request processes a slice at a time. */
	const RUN_LOCK = 'tapgrein_sync_lock';

	/**
	 * TTL for the execution mutex. Must auto-expire so a request killed without
	 * running its shutdown handler cannot wedge the sync forever; the next cron
	 * tick then reacquires and resumes from the checkpointed cursor.
	 */
	const RUN_LOCK_TTL = 300;

	/** Max term IDs per query when chunking large term operations. */
	const TERM_CHUNK_SIZE = 150;

	/** Default wall-clock budget (seconds) for one bounded sync slice. */
	const SYNC_TIME_BUDGET = 20;

	/** Default hard cap on pages fetched per slice (belt-and-braces with the clock). */
	const SYNC_MAX_PAGES_PER_RUN = 25;

	/**
	 * Items requested per getInventories call. Smaller pages come back faster and
	 * are far less likely to exceed the HTTP timeout or the host's request limit on
	 * a slow API / large catalog (see WPB-165). Overridable via TG_SYNC_PAGE_SIZE.
	 */
	const SYNC_PAGE_SIZE = 25;

	private function __construct( $key = null ) {
		if ( null === $key ) {
			$key = $this->get_key();
		}

		$this->set_key( $key );
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_connection( $configs = array() ) {

		$env = ( defined( 'TG_ENV' ) ) ? TG_ENV : tapgrein_getenv_docker( 'tg_env', 'tapgoods.com' );
		// Merge the passed conigs with defaults
		$config = array_merge(
			array(
				'base_url'        => "https://openapi.{$env}",
				'tg_env'          => $env,
				'api_key'         => $this->key,
				'no_cache_routes' => array(),
			),
			$configs
		);

		// If we already have a client update the configs and return it ( To preserve Auth tokens/cookies/transients )
		if ( null !== $this->client ) {
			// A client injected via the `tapgoods_api_client` filter may not implement
			// set_config(); guard so a non-conforming client can't fatal the plugin.
			if ( method_exists( $this->client, 'set_config' ) ) {
				$this->client->set_config( $configs );
			}
			return $this->client;
		}

		$this->client = $this->create_client( $config );
		return $this->client;
	}

	/**
	 * Build the API client.
	 *
	 * Small testing / offline-dev seam. Production behaviour is unchanged unless a
	 * mock is explicitly requested:
	 *  - The `tapgoods_api_client` filter lets a client be injected. Unit tests use
	 *    this to drive the connection end-to-end with no network.
	 *  - When the TG_MOCK constant, or the `tg_mock` environment variable, is truthy
	 *    an offline mock client that serves static JSON fixtures is used instead of
	 *    the real client (see tests/mock/class-tapgoods-mock-api-client.php).
	 *
	 * @param array $config Client configuration.
	 * @return Tapgoods_API_Client|Tapgoods_Mock_API_Client|object
	 */
	private function create_client( $config ) {

		// Allow tests / integrations to inject a client. Only accept an object: a
		// filter that returns false/'' (a common WP filter mistake) must not become
		// the client, or later ->validate_key() calls would fatal.
		$injected = apply_filters( 'tapgoods_api_client', null, $config );
		if ( is_object( $injected ) ) {
			return $injected;
		}

		// Offline mock, gated by env var / constant.
		if ( self::use_mock_api() ) {
			$mock_file = TAPGOODS_PLUGIN_PATH . 'tests/mock/class-tapgoods-mock-api-client.php';
			if ( file_exists( $mock_file ) ) {
				require_once $mock_file;
				return new Tapgoods_Mock_API_Client( $config );
			}
		}

		return new Tapgoods_API_Client( $config );
	}

	/**
	 * Whether the offline mock TapGoods API should be used.
	 *
	 * Enable it by defining the TG_MOCK constant, or setting the `tg_mock`
	 * environment variable, to a truthy value. "Truthy" is evaluated the same way
	 * for both: real booleans/ints, or the strings 1/true/yes/on (case-insensitive).
	 * A defined constant always takes precedence over the env var. This means, e.g.,
	 * `define( 'TG_MOCK', 'false' )` correctly disables the mock rather than being
	 * treated as a truthy string. Keeps tests and local dev fully offline.
	 *
	 * @return bool
	 */
	public static function use_mock_api() {
		$raw = defined( 'TG_MOCK' ) ? TG_MOCK : tapgrein_getenv_docker( 'tg_mock', '' );
		return self::is_truthy_flag( $raw );
	}

	/**
	 * Normalize a config flag (bool, int, or string) to a boolean.
	 *
	 * @param mixed $value Raw flag value.
	 * @return bool
	 */
	private static function is_truthy_flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	public function get_key() {

		// if the API key is defined in code, use that, or default to empty string for key input
		$api_key = ( defined( 'TAPGOODS_KEY' ) ) ? TAPGOODS_KEY : '';

		// Otherwise check the options table for a saved key
		if ( '' === $api_key ) {
			$encryption    = new Tapgoods_Encryption();
			$encrypted_key = get_option( 'tg_key' );
			$api_key       = ( $encrypted_key ) ? $encryption->tapgrein_decrypt( $encrypted_key ) : '';
		}

		return $api_key;
	}

	private function set_key( $key ) {
		$this->key = $key;
	}

	public function get_business() {

		$client   = $this->get_connection();
		$business = $client->validate_key();

		if ( false === $business ) {
			return false;
		}

		update_option( 'tg_businessId', $business['businessId'] );
		update_option( 'tg_locationIds', $business['locationIds'] );

		return $business;
	}

	public function test_connection( $key = false, $fail = false ) {

		// If want the test to fail for debugging return false
		if ( $fail ) {
			return false;
		}

		$config = array();
		if ( false !== $key ) {
			$config['api_key'] = $key;
		}

		$client = $this->get_connection( $config );

		try {
			$success = $client->validate_key();
		} catch ( Exception $error ) {
			$success = false;
			return $error;
		}

		return $success;
	}

	/**
	 * The sync flow state machine (single source of truth for sync status).
	 *
	 * @return Tapgoods_Sync_State
	 */
	public function sync_state() {
		return Tapgoods_Sync_State::get_instance();
	}

	/**
	 * The sync activity log (always on, independent of WP_DEBUG).
	 *
	 * @return Tapgoods_Sync_Log
	 */
	public function sync_log() {
		return Tapgoods_Sync_Log::get_instance();
	}

	/**
	 * Milliseconds elapsed since a microtime(true) mark, for log timings.
	 *
	 * @param float $since microtime(true) value.
	 * @return int
	 */
	private static function elapsed_ms( $since ) {
		return (int) round( ( microtime( true ) - (float) $since ) * 1000 );
	}

	/**
	 * HTTP status of the client's last request, when the client can report one.
	 *
	 * A revoked key (401), an outage (500) and a rate limit (429) all surface as
	 * `false` from the client, and that difference is most of what support needs
	 * to triage. Guarded by method_exists so an injected or mock client without
	 * the accessor simply reports nothing.
	 *
	 * @param object|null $client API client.
	 * @return int|null
	 */
	private function client_http_status( $client ) {
		if ( is_object( $client ) && method_exists( $client, 'get_last_http_code' ) ) {
			return $client->get_last_http_code();
		}
		return null;
	}

	public function start_sync() {
		$this->hash         = wp_hash( current_time( 'mysql' ) );
		$this->u_sync_start = current_time( 'timestamp' );

		do_action( 'tg_start_api_sync', $this->u_sync_start );

		// Enter the state machine: PREP then ACTIVE.
		$this->sync_state()->begin_prep()->mark_active();
		set_transient( 'tg_u_sync_start', $this->u_sync_start, 60 );
	}

	public function is_active() {
		return $this->sync_state()->is_running();
	}

	public function stop_sync( $error = false, $message = '' ) {

		// Drive the state machine to its terminal state.
		if ( false !== $error ) {
			$this->sync_state()->mark_error( $message );
		} else {
			$this->sync_state()->mark_completed();
		}

		$this->u_sync_end = current_time( 'timestamp' ); // phpcs:ignore

		// Maybe stop sync called from a new thread, check for transient
		if ( is_null( $this->u_sync_start ) ) {
			$sync_start = get_transient( 'tg_u_sync_start' );
			if ( false !== $sync_start ) {
				$this->u_sync_start = $sync_start;
			}
		}

		$duration = $this->u_sync_end - $this->u_sync_start;

		$sync_info = array(
			'last_sync_start'    => $this->u_sync_start,
			'last_sync_end'      => $this->u_sync_end,
			'last_sync_duration' => $duration,
			'error'              => $error,
			'message'            => $message,
		);

		do_action( 'tg_stop_api_sync', $sync_info );
		update_option( 'tg_last_sync_info', $sync_info );
	}
	public function last_sync_message() {
		$api_connected = get_option('tg_api_connected', false);

		if (!$api_connected) {
			return 'The last sync finish';
		}

		$state = $this->sync_state();

		// Check if the synchronization is in progress
		if ($state->is_running()) {
			//return 'Sync in progress. Please wait...';
			return '';
		}

		$last_success = $state->get_last_success();
		$duration     = $state->get_last_duration();

		// Verify that we have a recorded successful sync to report on.
		if (empty($last_success) || null === $duration) {
			return 'No sync information available';
		}

		// Calculate elapsed time and duration
		$time = current_time('timestamp'); // phpcs:ignore
		$time_ago = $time - $last_success;
		$time_ago_str = tapgrein_seconds_to_string($time_ago);
		$duration_str = tapgrein_seconds_to_string($duration);

		// Build the last synchronization message
		$message = 'The last sync finished ' . $time_ago_str . " ago and took {$duration_str} seconds to run";

		return $message;
	}
	
	/**
	 * Entry point for an inventory sync. Both the cron path and the manual
	 * "Sync Now" button land here.
	 *
	 * The sync is RESUMABLE and BOUNDED per request: a single invocation only
	 * does as much work as fits in a wall-clock budget (self::sync_time_budget())
	 * / page cap, then checkpoints its position in the state-machine cursor and
	 * returns, leaving the run ACTIVE so the next cron tick resumes exactly where
	 * it stopped instead of restarting at page 1. This keeps every request well
	 * under any host's request-time limit (the WPB-165 failure was trying to page
	 * a 372-page catalog in one request), with no host-specific branching.
	 *
	 * @param bool        $manual_trigger True when invoked from the admin "Sync Now".
	 * @param string|null $trigger        Which entry point started the run, for the
	 *                                    activity log. $manual_trigger cannot answer
	 *                                    that: most paths pass false (the five-minute
	 *                                    cron self-ping, the daily tg_auto_sync_event,
	 *                                    the frontend 24h fallback and the public
	 *                                    execute_manual_sync AJAX endpoint), so every
	 *                                    caller names itself instead. Known names:
	 *                                    admin_manual, cron_selfping, cron_daily,
	 *                                    frontend_fallback, ajax_manual, ajax_nopriv,
	 *                                    admin_ajax_sync, ajax_sync_unauth, unknown.
	 * @return array { success: bool, message: string, in_progress?: bool }
	 */
	public function sync_inventory_in_batches($manual_trigger = false, $trigger = null) {
		$log = $this->sync_log();
		if (null === $trigger) {
			$trigger = $manual_trigger ? 'admin_manual' : 'unknown';
		}
		$log->begin_run($trigger, array('entry' => 'sync_inventory_in_batches', 'manual' => $manual_trigger ? 1 : 0));

		$current_key = $this->get_key();
		$stored_key  = get_option('tg_last_api_key');

		// Handle API key change (only meaningful when no run is in flight).
		if ($current_key !== $stored_key && ! $this->sync_state()->is_running()) {
			// Never the key itself, only that it changed and what that cost.
			$log->warn('sync.key.changed', array('action' => 'cleared_local_data'));
			$this->tapgrein_delete_data();
			update_option('tg_last_api_key', $current_key);
		}

		$state = $this->sync_state();

		// A latched ERROR blocks everything until an admin clears it.
		if ($state->has_error() && Tapgoods_Sync_State::STATE_ERROR === $state->get_state()) {
			$log->warn('sync.run.blocked', array('reason' => 'error_state', 'state' => $state->get_state()));
			$log->end_run('skipped', array('state' => $state->get_state()));
			return array('success' => false, 'message' => 'Sync is in an error state. Clear the sync error before retrying.');
		}

		// Only one request may process a slice at a time. This is the execution
		// mutex; the ACTIVE state + cursor is the durable "work remains" marker.
		// A refused lock performs no state transition, so without this line a site
		// stuck behind a slice that never finished looks silent (the WPB-165 report).
		if (! $this->acquire_run_lock()) {
			$log->warn(
				'sync.run.locked',
				array(
					'reason' => 'lock_held',
					'state'  => $state->get_state(),
					'age_s'  => $state->get_age(),
					'pages'  => $state->get_pages_completed() . '/' . $state->get_total_pages(),
				)
			);
			$log->end_run('skipped', array('state' => $state->get_state()));
			return array('success' => true, 'in_progress' => true, 'message' => $this->in_progress_message());
		}

		// Arm the abnormal-termination safety net now, before PREP: a fatal (e.g.
		// max-execution-time) during PREP must be recovered too, not just during
		// paging. on_sync_shutdown() gates its own work on $active_run.
		$this->active_run = true;
		$this->maybe_register_shutdown();
		$log->info('sync.lock.acquired', array('state' => $state->get_state()));

		$result  = array('success' => false, 'message' => 'Sync did not run.');
		$proceed = true;

		// Everything that talks to the API lives inside the try. get_location_ids()
		// and compute_total_sync_pages() both reach Tapgoods_API_Request, which
		// throws on a WordPress http_request_failed (a DNS blip is enough); before,
		// that threw past the run and left a run start with no terminal line.
		try {
			// Resume an in-flight run only when it has a properly initialised
			// cursor. A run that is "running" but has no location list (e.g. a
			// request that died mid-PREP before init_cursor) must NOT be resumed:
			// resuming it would fall straight through to finalize with an empty
			// synced set and could delete the whole catalog. Start such a run fresh.
			$resume = $state->is_running() && ! empty($state->get_cursor()['location_ids']);

			if ($resume) {
				// Resume-from-cursor: a bounded slice is continuing a run that a
				// previous cron tick could not finish in one request.
				$cursor = $state->get_cursor();
				$log->info(
					'sync.resume',
					array(
						'phase'          => $cursor['phase'],
						'location_index' => (int) $cursor['location_index'],
						'next_page'      => (int) $cursor['next_page'],
						'pages'          => $state->get_pages_completed() . '/' . $state->get_total_pages(),
					)
				);
			} else {
				$client       = $this->get_connection();
				$location_ids = $client->get_location_ids();
				if (false === $location_ids) {
					// PREP failed before any paging: log, latch for retry, and let the
					// terminal end_run line below close the run.
					$log->error('sync.api.error', array('op' => 'get_location_ids', 'stage' => 'prep', 'status' => $this->client_http_status($client)));
					$will_retry = $state->mark_error('Failed to retrieve location information.');
					$log->warn('sync.retry', array('will_retry' => $will_retry ? 1 : 0, 'failures' => $state->get_failure_count(), 'state' => $state->get_state()));
					$this->active_run = false;
					$result  = array('success' => false, 'message' => 'Failed to retrieve location information.');
					$proceed = false;
				} else {
					$state->begin_prep();
					$log->info('sync.prep.locations', array('count' => count($location_ids)));

					$prep_started = microtime(true);
					$total_pages  = $this->compute_total_sync_pages($client, $location_ids, self::sync_page_size());
					$state->set_total_pages($total_pages);
					$state->init_cursor($location_ids);
					$log->info('sync.prep.pages', array('total_pages' => $total_pages, 'elapsed_ms' => self::elapsed_ms($prep_started)));

					$state->mark_active();
				}
			}

			if ($proceed) {
				$result = $this->run_sync_slice($state);
			}
		} catch (Exception $e) {
			// PREP reaches the API (get_location_ids / compute_total_sync_pages),
			// which throws on a WordPress http_request_failed. run_sync_slice guards
			// the paging/finalize phases itself, so this only catches PREP: without
			// it a prep-stage throw would escape past the run and leave a start line
			// with no terminal line (the WPB-172 failure). Slice semantics are
			// unchanged; this only guarantees the run is always closed.
			$log->error('sync.error', array('stage' => 'prep', 'class' => get_class($e), 'message' => $e->getMessage(), 'status' => $this->client_http_status($this->get_connection())));
			$will_retry = $state->mark_error($e->getMessage());
			$log->warn('sync.retry', array('will_retry' => $will_retry ? 1 : 0, 'failures' => $state->get_failure_count(), 'state' => $state->get_state()));
			$this->active_run = false;
			$result = array('success' => false, 'message' => 'Sync failed: ' . $e->getMessage());
		} finally {
			$this->release_run_lock();
		}

		// The single run-end line for THIS request's slice. Page counts come from
		// the state machine, not from a return value; an in-progress checkpoint is
		// still a clean end for the request that produced it (the next cron tick
		// resumes it), so it is reported as ok with in_progress=1.
		$status = ( ! empty($result['success']) && ! $state->has_error() ) ? 'ok' : 'error';
		$log->end_run(
			$status,
			array(
				'pages'       => $state->get_pages_completed() . '/' . $state->get_total_pages(),
				'state'       => $state->get_state(),
				'in_progress' => ! empty($result['in_progress']) ? 1 : 0,
			)
		);

		return $result;
	}

	/**
	 * Process one bounded slice of the current run and checkpoint.
	 *
	 * Walks the resumable cursor: a bounded category/tag pass (one location per
	 * iteration, checkpointed), then paging through locations (writing items +
	 * assigning their terms), then a finalize phase (cleanup + removal
	 * reconciliation) once every location is done. Returns as soon as the
	 * per-request budget is spent, leaving the run resumable, or drives the state
	 * machine to COMPLETED when everything is done.
	 *
	 * @param Tapgoods_Sync_State $state The sync state machine.
	 * @return array Result envelope for the caller.
	 */
	private function run_sync_slice($state) {
		$log            = $this->sync_log();
		$client         = $this->get_connection();
		$batch_size     = self::sync_page_size();
		$start_time     = current_time('timestamp');
		$slice_started  = microtime(true);
		$pages_this_run = 0;

		// The abnormal-termination safety net is armed by the caller
		// (sync_inventory_in_batches) before PREP; keep it armed here defensively.
		$this->active_run = true;
		$this->maybe_register_shutdown();

		$cursor = $state->get_cursor();

		try {
			// CATEGORIES PHASE: bounded and resumable, mirroring the paging loop.
			// Terms are synced up front so they exist before items are paged and each
			// item's own tapgrein_assign_terms() can find them. A large business has
			// thousands of categories/tags across ~18 locations; doing them all in one
			// request is what stalled sync on a request-time-limited host (WPB-165), so
			// each location is done in isolation and checkpointed. Obsolete-term
			// reconciliation runs ONLY after every location's categories are collected
			// (the FULL accumulated valid-id set), never on a partial pass.
			if (empty($cursor['categories_done'])) {
				$location_ids = $cursor['location_ids'];
				$cat_idx      = (int) $cursor['cat_location_index'];

				// Announce the categories phase exactly once, when it first begins.
				if (0 === $cat_idx) {
					$log->info('sync.categories.start', array('pass' => 'sliced', 'locations' => count($location_ids)));
				}

				while ($cat_idx < count($location_ids)) {
					$lid         = $location_ids[$cat_idx];
					$cat_started = microtime(true);
					$touched     = $this->sync_categories_for_location($lid);

					if (! $touched['ok']) {
						$log->warn('sync.categories.fetch_failed', array('location' => $lid, 'pass' => 'sliced', 'status' => $this->client_http_status($client)));
					} else {
						// Accumulate this location's valid ids into the durable cursor so
						// the eventual reconciliation sees the FULL set across every slice.
						$cursor['valid_category_ids'] = array_values(array_unique(array_merge((array) $cursor['valid_category_ids'], $touched['category_ids'])));
						$cursor['valid_tag_ids']      = array_values(array_unique(array_merge((array) $cursor['valid_tag_ids'], $touched['tag_ids'])));
					}

					++$cat_idx;
					$cursor['cat_location_index'] = $cat_idx;
					$state->save_cursor($cursor); // Checkpoint after each location.

					if ($this->slice_budget_spent($start_time, $pages_this_run)) {
						// Budget spent mid-categories: resume at cat_location_index next
						// tick. Do NOT set categories_done and do NOT reconcile removals.
						$log->info('sync.checkpoint', array('stage' => 'categories', 'phase' => $cursor['phase'], 'cat_location_index' => $cat_idx . '/' . count($location_ids), 'pages' => $state->get_pages_completed() . '/' . $state->get_total_pages(), 'elapsed_ms' => self::elapsed_ms($slice_started)));
						$this->active_run = false;
						return array('success' => true, 'in_progress' => true, 'message' => $this->in_progress_message());
					}
				}

				// Every location's categories are collected: NOW it is safe to reconcile
				// obsolete terms against the FULL accumulated valid-id set. Doing this on
				// a partial set would delete a not-yet-processed location's terms and
				// re-add them next slice (churn + transient storefront 404s).
				$this->remove_obsolete_terms('tg_category', $cursor['valid_category_ids']);
				$this->remove_obsolete_terms('tg_tags', $cursor['valid_tag_ids']);

				$cursor['categories_done'] = true;
				$state->save_cursor($cursor);

				$log->info('sync.categories.done', array('pass' => 'sliced', 'ok' => 1, 'categories' => count($cursor['valid_category_ids']), 'tags' => count($cursor['valid_tag_ids'])));

				if ($this->slice_budget_spent($start_time, $pages_this_run)) {
					$log->info('sync.checkpoint', array('after' => 'categories', 'phase' => $cursor['phase'], 'pages_this_slice' => $pages_this_run, 'pages' => $state->get_pages_completed() . '/' . $state->get_total_pages(), 'elapsed_ms' => self::elapsed_ms($slice_started)));
					$this->active_run = false;
					return array('success' => true, 'in_progress' => true, 'message' => $this->in_progress_message());
				}
			}

			// PAGING PHASE.
			if ('paging' === $cursor['phase']) {
				$location_ids = $cursor['location_ids'];
				$idx          = (int) $cursor['location_index'];
				$page         = (int) $cursor['next_page'];

				while ($idx < count($location_ids)) {
					$lid          = $location_ids[$idx];
					$page_started = microtime(true);
					$response     = $client->get_inventories_from_graph($lid, $page, $batch_size);

					if (false === $response) {
						// A false response is an API ERROR, not "no more items".
						// Treating it as end-of-location would let finalize delete
						// items that still exist (a partial/aborted pass). Abort the
						// run instead so it is retried fresh, with no deletions.
						throw new Exception("API error paging location {$lid} (page {$page}); aborting before finalize to protect against deleting items on a partial pass.");
					}

					if (empty($response['collection'])) {
						// Location genuinely exhausted: advance to the next one.
						$log->info('sync.batch.end', array('location' => $lid, 'page' => $page, 'reason' => 'empty_collection', 'elapsed_ms' => self::elapsed_ms($page_started)));
						++$idx;
						$page = 1;
					} else {
						$inventory  = $response['collection'];
						$page_items = count($inventory);
						foreach ($inventory as $item) {
							$this->sync_inventory_item($item);
							$cursor['synced_ids'][] = (string) $item['id'];
						}
						$cursor['total_items'] += $page_items;
						$state->increment_pages_completed();
						++$pages_this_run;

						// `items` is this page only; `total_items` is the running total.
						$log->info('sync.batch.page', array('location' => $lid, 'page' => $page, 'items' => $page_items, 'total_items' => (int) $cursor['total_items'], 'elapsed_ms' => self::elapsed_ms($page_started)));

						if ($page_items < $batch_size) {
							++$idx;
							$page = 1;
						} else {
							++$page;
						}
					}

					// Checkpoint after every page so a resume loses at most one page.
					$cursor['location_index'] = $idx;
					$cursor['next_page']      = $page;
					$state->save_cursor($cursor);

					if ($this->slice_budget_spent($start_time, $pages_this_run)) {
						// Slice budget spent mid-paging: persist where we stopped so the
						// next tick resumes here rather than restarting at page 1.
						$log->info('sync.checkpoint', array('phase' => 'paging', 'location_index' => $idx, 'next_page' => $page, 'pages_this_slice' => $pages_this_run, 'pages' => $state->get_pages_completed() . '/' . $state->get_total_pages(), 'elapsed_ms' => self::elapsed_ms($slice_started)));
						$this->active_run = false;
						return array('success' => true, 'in_progress' => true, 'message' => $this->in_progress_message());
					}
				}

				// All locations paged: hand over to the finalize phase.
				$cursor['phase'] = 'finalize';
				$state->save_cursor($cursor);

				if ($this->slice_budget_spent($start_time, $pages_this_run)) {
					$log->info('sync.checkpoint', array('phase' => 'finalize', 'before' => 'finalize', 'pages_this_slice' => $pages_this_run, 'pages' => $state->get_pages_completed() . '/' . $state->get_total_pages(), 'elapsed_ms' => self::elapsed_ms($slice_started)));
					$this->active_run = false;
					return array('success' => true, 'in_progress' => true, 'message' => $this->in_progress_message());
				}
			}

			// FINALIZE PHASE: only reached once a FULL pass is confirmed, so it is
			// safe to reconcile removals against the accumulated synced-id set.
			$this->finalize_sync($cursor['synced_ids']);
			$this->update_sync_info($start_time);
		} catch (Exception $e) {
			$log->error('sync.error', array('stage' => 'inventory', 'class' => get_class($e), 'message' => $e->getMessage(), 'status' => $this->client_http_status($client)));
			$will_retry = $state->mark_error($e->getMessage());
			$log->warn('sync.retry', array('will_retry' => $will_retry ? 1 : 0, 'failures' => $state->get_failure_count(), 'state' => $state->get_state()));
			$this->active_run = false;
			return array('success' => false, 'message' => 'Sync failed: ' . $e->getMessage());
		}

		// SYNC COMPLETED.
		$state->mark_completed();
		$this->active_run = false;

		// A full pass finished in this slice. Detailed counts are their own events
		// (sync.batch.page, sync.finalize.done); this line marks the logical sync
		// complete. The per-request terminal line is written by the caller's end_run.
		$log->info(
			'sync.run.completed',
			array(
				'items' => (int) $cursor['total_items'],
				'pages' => $state->get_pages_completed() . '/' . $state->get_total_pages(),
				'state' => $state->get_state(),
			)
		);

		// Check if anything was actually synced
		if (0 === (int) $cursor['total_items']) {
			return array('success' => true, 'message' => 'Everything is up to date. Nothing to sync.');
		}
		return array('success' => true, 'message' => '');
	}

	/**
	 * Finalize a completed full pass: reconcile removals and clean up terms.
	 *
	 * Runs ONLY after every location/page has been fetched, so the synced-id set
	 * is complete and it is safe to delete WordPress items the API no longer
	 * returns. All term operations here are chunked (see remove_unused_terms) so
	 * no single query carries thousands of term IDs.
	 *
	 * @param array $synced_ids tg_ids seen during this run's full pass.
	 * @return void
	 */
	private function finalize_sync($synced_ids) {
		$log            = $this->sync_log();
		$existing_items = $this->get_all_existing_inventory_ids();

		// Deletion safety valve: only reconcile item removals when this pass
		// actually saw items. An empty synced set reaching finalize (e.g. a
		// business that legitimately returned nothing, or an unexpected code
		// path) must NOT wipe every existing item. Term cleanup (which only
		// removes genuinely unused/obsolete terms) is still safe to run.
		$removed_items = 0;
		if (! empty($synced_ids)) {
			$removed_items = $this->remove_missing_items_from_wordpress($existing_items, $synced_ids);
		} else {
			// The size of the mass deletion this safety valve just prevented is the
			// first thing to look at if items ever vanish unexpectedly (WPB-165).
			$log->warn('sync.cleanup.skipped', array('reason' => 'empty_synced_set', 'existing' => count($existing_items)));
		}

		$removed_terms  = $this->remove_unused_terms('tg_category');
		$removed_terms += $this->remove_unused_terms('tg_tags');
		$removed_duplicates = $this->remove_duplicate_items();

		$log->info(
			'sync.finalize.done',
			array(
				'items_removed'      => (int) $removed_items,
				'terms_removed'      => (int) $removed_terms,
				'duplicates_removed' => (int) $removed_duplicates,
				'synced'             => count($synced_ids),
			)
		);
	}

	// --- Bounded-slice helpers ------------------------------------------------

	/**
	 * Wall-clock budget (seconds) for one sync slice. Overridable via the
	 * TG_SYNC_TIME_BUDGET constant so a host with generous request limits can
	 * page more per request.
	 *
	 * @return int
	 */
	public static function sync_time_budget() {
		if (defined('TG_SYNC_TIME_BUDGET') && (int) TG_SYNC_TIME_BUDGET > 0) {
			return (int) TG_SYNC_TIME_BUDGET;
		}
		return self::SYNC_TIME_BUDGET;
	}

	/**
	 * Hard cap on pages fetched per slice. Overridable via TG_SYNC_MAX_PAGES.
	 *
	 * @return int
	 */
	public static function sync_max_pages_per_run() {
		if (defined('TG_SYNC_MAX_PAGES') && (int) TG_SYNC_MAX_PAGES > 0) {
			return (int) TG_SYNC_MAX_PAGES;
		}
		return self::SYNC_MAX_PAGES_PER_RUN;
	}

	/**
	 * Items requested per getInventories page. Overridable via TG_SYNC_PAGE_SIZE.
	 *
	 * @return int
	 */
	public static function sync_page_size() {
		if (defined('TG_SYNC_PAGE_SIZE') && (int) TG_SYNC_PAGE_SIZE > 0) {
			return (int) TG_SYNC_PAGE_SIZE;
		}
		return self::SYNC_PAGE_SIZE;
	}

	/**
	 * Whether the current slice has spent its per-request budget (time or pages).
	 *
	 * @param int $start_time     current_time('timestamp') when the slice began.
	 * @param int $pages_this_run Pages fetched so far this slice.
	 * @return bool
	 */
	private function slice_budget_spent($start_time, $pages_this_run) {
		if ((current_time('timestamp') - (int) $start_time) >= self::sync_time_budget()) {
			return true;
		}
		if ((int) $pages_this_run >= self::sync_max_pages_per_run()) {
			return true;
		}
		return false;
	}

	private function in_progress_message() {
		return 'Sync in progress. It continues automatically in the background; you can leave this page.';
	}

	// --- Execution mutex ------------------------------------------------------

	/**
	 * Acquire the execution mutex. Returns false if another request holds it.
	 *
	 * @return bool
	 */
	private function acquire_run_lock() {
		if (get_transient(self::RUN_LOCK)) {
			return false;
		}
		set_transient(self::RUN_LOCK, current_time('timestamp'), self::RUN_LOCK_TTL);
		return true;
	}

	private function release_run_lock() {
		delete_transient(self::RUN_LOCK);
	}

	// --- Abnormal-termination safety net -------------------------------------

	/**
	 * Register the shutdown handler once per request. register_shutdown_function
	 * cannot be undone, so on_sync_shutdown() gates its own work on $active_run.
	 *
	 * @return void
	 */
	private function maybe_register_shutdown() {
		if ($this->shutdown_registered) {
			return;
		}
		$this->shutdown_registered = true;
		register_shutdown_function(array($this, 'on_sync_shutdown'));
	}

	/**
	 * Runs on request shutdown. If the request ended WHILE a slice was executing
	 * (a PHP fatal such as the max-execution-time timeout, which a try/catch can't
	 * catch), recover gracefully:
	 *   - resumable run (paging still has work, checkpoint intact): leave the
	 *     cursor so the next cron tick resumes; just free the lock.
	 *   - otherwise (died in PREP, or in finalize with no remaining paging):
	 *     record the abnormal end so the retry budget applies, then free the lock.
	 * A clean slice clears $active_run before returning, so this is a no-op then.
	 *
	 * @return void
	 */
	public function on_sync_shutdown() {
		if (! $this->active_run) {
			return; // Slice ended cleanly, or this request never ran a slice.
		}

		$state = $this->sync_state();
		$st    = $state->get_state();

		if (Tapgoods_Sync_State::STATE_ACTIVE === $st && $state->cursor_has_remaining_paging()) {
			// Resumable: keep the checkpointed cursor; the next tick continues.
			$this->console_log('Sync request ended mid-slice; run is resumable and will continue on the next cron tick.');
		} elseif (in_array($st, array(Tapgoods_Sync_State::STATE_PREP, Tapgoods_Sync_State::STATE_ACTIVE), true)) {
			// Abnormal end with no resumable work left (PREP, or finalize): let the
			// retry budget decide whether the next tick starts fresh or latches ERROR.
			$state->mark_error('Sync ended unexpectedly (request terminated).');
			$this->console_log('Sync request ended abnormally with no resumable work; recorded for retry.');
		}

		$this->active_run = false;
		$this->release_run_lock();
	}

	/**
	 * Compute how many paging sync calls the full inventory set will require.
	 *
	 * Runs during SYNC PREP: for each location it reads the first page's
	 * metadata (totalPages) and sums the result. Locations whose metadata is
	 * unavailable but which returned at least one item count as a single page.
	 *
	 * @param object $client       The API client.
	 * @param array  $location_ids Location IDs to page through.
	 * @param int    $batch_size   Items per page.
	 * @return int Total number of paging calls across all locations.
	 */
	private function compute_total_sync_pages($client, $location_ids, $batch_size) {
		$total = 0;
		foreach ($location_ids as $lid) {
			$response = $client->get_inventories_from_graph($lid, 1, $batch_size);
			if (false === $response) {
				continue;
			}
			if (isset($response['metadata']['totalPages'])) {
				$total += (int) $response['metadata']['totalPages'];
			} elseif (!empty($response['collection'])) {
				$total += 1;
			}
		}
		return $total;
	}
	
	
	/**
	 * Confirm that terms exist for a given taxonomy.
	 *
	 * @param string $taxonomy The taxonomy to confirm.
	 * @return bool True if terms exist, false otherwise.
	 */
	public function confirm_terms_ready($taxonomy) {
		// Cheap existence check: read term IDs straight from term_taxonomy with no
		// oversized IN() / term-cache prime (the WPB-165 "KILLED QUERY" fingerprint).
		$term_ids = $this->get_term_ids_for_taxonomy($taxonomy);

		if (empty($term_ids)) {
			$this->console_log("No terms found for taxonomy: $taxonomy.");
			return false;
		}

		return true;
	}

	/**
	 * All term IDs for a taxonomy, read directly from term_taxonomy.
	 *
	 * Deliberately avoids get_terms(): get_terms() primes the term object cache
	 * with a single "... WHERE t.term_id IN (<all ids>)" query, which is the
	 * ~4000-id "KILLED QUERY" seen on the failing site (WPB-165). This query has
	 * no IN() clause at all, so it is safe on any host. Callers then process the
	 * IDs in chunks (see tapgrein_chunk_ids / TERM_CHUNK_SIZE).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return int[] Term IDs.
	 */
	private function get_term_ids_for_taxonomy($taxonomy) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		);

		return array_map('intval', (array) $ids);
	}

	/**
	 * Split a list of IDs into chunks no larger than the term-query cap.
	 *
	 * Pure helper (no WordPress calls) so the chunking guarantee is unit-testable
	 * in isolation: every returned chunk has at most self::TERM_CHUNK_SIZE IDs,
	 * so no term query built from a chunk can carry an oversized IN() list.
	 *
	 * @param array    $ids  IDs to chunk.
	 * @param int|null $size Chunk size (defaults to TERM_CHUNK_SIZE).
	 * @return array[] Array of ID chunks.
	 */
	public static function tapgrein_chunk_ids($ids, $size = null) {
		$size = (null === $size) ? self::TERM_CHUNK_SIZE : max(1, (int) $size);
		if (empty($ids)) {
			return array();
		}
		return array_chunk(array_values((array) $ids), $size);
	}
	
	
	
	
	
	
	
	public function get_all_existing_inventory_ids() {
		global $wpdb;
	
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value as tg_id 
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'tg_id' // Placeholder meta_key
			),
			ARRAY_A
		);
		
		$existing_items = [];
		foreach ($results as $result) {
			$existing_items[$result['tg_id']] = $result['post_id'];
		}
	
		return $existing_items;
	}

	/**
	 * Delete the tg_inventory posts that the run did not see in the API.
	 *
	 * @param array $existing_items tg_id => post_id map of everything in WordPress.
	 * @param array $synced_items   tg_ids the run actually fetched.
	 * @return int Number of posts deleted.
	 */
	public function remove_missing_items_from_wordpress($existing_items, $synced_items) {
		$log = $this->sync_log();
		$items_to_remove = array_diff(array_keys($existing_items), $synced_items);

		// The plan is logged BEFORE anything is deleted: when a page fetch failed
		// earlier in the run this number is the size of an accidental mass
		// deletion, and it is the first thing to look at (WPB-165).
		$log->info(
			'sync.cleanup.plan',
			array(
				'existing'  => count($existing_items),
				'synced'    => count($synced_items),
				'to_remove' => count($items_to_remove),
			)
		);

		$removed = 0;
		$sample  = array();

		foreach ($items_to_remove as $tg_id) {
			$post_id = $existing_items[$tg_id];
			wp_delete_post($post_id, true);
			++$removed;
			if (count($sample) < 20) {
				$sample[] = $tg_id;
			}
			$log->debug('sync.cleanup.item_removed', array('tg_id' => $tg_id, 'post' => $post_id));
		}

		$log->info('sync.cleanup.items_removed', array('count' => $removed, 'sample_ids' => implode(',', $sample)));

		return $removed;
	}
	
	
	public function update_sync_info($start_time) {
		$end_time = current_time('timestamp');
		$sync_duration = $end_time - $start_time;
	
		// Save the synchronization information
		$sync_info = array(
			'last_sync_end' => $end_time,
			'last_sync_duration' => $sync_duration
		);
	
		update_option('tg_last_sync_info', $sync_info);
	}

	
	public function sync_location_settings($force_update = false) {
		$log = $this->sync_log();
		$settings_started = microtime(true);
		$log->info('sync.location.start', array('force' => $force_update ? 1 : 0));

		$client = $this->get_connection();
		$location_ids = get_option('tg_locationIds', false);
		$business_id = get_option('tg_businessId', false);

		if ($force_update) {
			$this->console_log('Force update enabled. Clearing cached location_ids and business_id.');
			delete_option('tg_locationIds');
			delete_option('tg_businessId');
			$location_ids = false;
			$business_id = false;
		}
	
	//	$this->console_log('Obtained location_ids: ' . print_r($location_ids, true));
	//	$this->console_log('Obtained business_id: ' . print_r($business_id, true));
	
		if (empty($location_ids) || empty($business_id)) {
			$this->console_log('location_ids or business_id are empty. Trying to obtain the business.');
			$business = $this->get_business();
	//		$this->console_log('Result of get_business: ' . print_r($business, true));
	
			if (false === $business) {
				$log->error('sync.location.error', array('reason' => 'no_business', 'status' => $this->client_http_status($client), 'elapsed_ms' => self::elapsed_ms($settings_started)));
				return false;
			}
	
			$location_ids = get_option('tg_locationIds', false);
			$business_id = get_option('tg_businessId', false);
	//		$this->console_log('After get_business, location_ids: ' . print_r($location_ids, true));
	//		$this->console_log('After get_business, business_id: ' . print_r($business_id, true));
		}
	
		if (false === $location_ids || false === $business_id) {
			$log->error('sync.location.error', array('reason' => 'invalid_ids', 'elapsed_ms' => self::elapsed_ms($settings_started)));
			return false;
		}
	
		// Array para almacenar los detalles de las ubicaciones
		$location_info = [];
	
		foreach ($location_ids as $location_id) {
			$location_transient = $client->transient_name('location_info_' . $location_id);
	
			// Si se fuerza la actualización, elimina el transient actual
			if ($force_update) {
				$this->console_log('Force update enabled. Clearing transient for location_id: ' . $location_id);
				delete_transient($location_transient);
			}
	
			// Intenta obtener los detalles del transient
			$location_details = get_transient($location_transient);
	
			// Si no hay datos en el transient, obtén los datos desde el API
			if (false === $location_details || empty($location_details)) {
				$this->console_log('No location_info found in transient. Trying to get it from the API for location_id: ' . $location_id);
				$location_details = $client->get_location_details_from_graph($location_id);
	//			$this->console_log('Result of get_location_details_from_graph for location_id ' . $location_id . ': ' . print_r($location_details, true));
	
				if (false === $location_details) {
					$log->warn('sync.location.fetch_failed', array('location' => $location_id, 'status' => $this->client_http_status($client)));
					continue;
				}
	
				// Guarda los datos en el transient
				set_transient($location_transient, $location_details, 300);
			}
	
			// Verifica que los datos obtenidos sean para el ID correcto
			if (!isset($location_details['id']) || $location_details['id'] != $location_id) {
				$log->warn('sync.location.mismatch', array('location' => $location_id));
				continue;
			}
	
			// Guarda los datos en la opción tg_location_{id}
			update_option('tg_location_' . $location_id, $location_details);
			$this->console_log('Saved location details to tg_location_' . $location_id);
	
			// Almacena los datos en el array de información general
			$location_info[$location_id] = $location_details;
		}
	
		// Actualiza la opción tg_location_settings con todos los datos
		update_option('tg_location_settings', $location_info);
		$this->console_log('Updated tg_location_settings option.');
	
		// Verifica y actualiza el valor por defecto si es necesario
		$default_location = get_option('tapgreino_default_location', false);
		if (empty($default_location) || !in_array($default_location, $location_ids)) {
			$this->console_log('Default location is not set or invalid. Setting to the first location_id.');
			$new_default_location = reset($location_ids);
			update_option('tapgreino_default_location', $new_default_location);
			$this->console_log('Updated tapgreino_default_location to: ' . $new_default_location);
		}

		$log->info(
			'sync.location.done',
			array(
				'locations'  => count($location_info),
				'requested'  => count($location_ids),
				'elapsed_ms' => self::elapsed_ms($settings_started),
			)
		);
		return true;
	}
	
	
	
	
	
	
	
	public function get_location_info() {

		$client = $this->get_connection();

		$transient_name = $client->transient_name( 'location_info' );

		// check if we have the info in a transient
		$location_info = get_transient( $transient_name );
		if ( false !== $location_info ) {
			return $location_info;
		}

		// if its not cached, fallback to the most recent options
		$location_info = get_option( $transient_name );
		if ( false !== $location_info ) {
			return $location_info;
		}

		// if no options, get new data from the API
		$location_info = $this->sync_location_settings(true);
		return $location_info;
	}

	public function update_location_term_meta( $term_id, $info ) {
		if ( term_exists( $term_id, 'tg_location' ) ) {
			foreach ( $info as $k => $v ) {
				update_term_meta( $term_id, 'tg_' . $k, $v );
			}
		}
	}

	// Updates the inventory and returns the number of products updated
	// Returns false if something went wrong
	public function sync_inventory_from_api() {
		$client = $this->get_connection();
		$location_ids = $client->get_location_ids();
	
		if (false === $location_ids) {
			return false;
		}
	
		$count = 0;
		foreach ($location_ids as $lid) {
			$current = 1;
			$response = $client->get_inventories_from_graph($lid);
	
			if (false === $response) {
				return false;
			}
	
			$meta     = $response['metadata'];
			$tg_order = 0;
	
			while ($meta['totalPages'] >= $current) {
				$response = $client->get_inventories_from_graph($lid, $current);
	
				if (false === $response) {
					return false;
				}
	
				$meta      = $response['metadata'];
				$inventory = $response['collection'];
				$inv_count = count($inventory);
	
				foreach ($inventory as $data) {
					++$tg_order;
	
					// Log to check update/insert status
					$this->console_log('Updating/inserting item with token: ' . $data['token']);
	
					// We update or insert the inventory
					$update = $this->tapgrein_update_inventory($data, $tg_order);
	
					// Assign locations to the item
					$this->console_log('Assigning location for item: ' . $data['token']);
					$this->tapgrein_assign_location($update, $lid);  // <-- New feature to assign location
	
					// Assign terms (categories, tags)
					$this->tapgrein_assign_terms($update);
	
					++$count;
				}
				++$current;
			}
		}
		return $count;
	}
	
	public function tapgrein_assign_location($post_id, $location_id) {
		// Get location details from 'tg_locationIds'
		$location_details = get_option('tg_location_' . $location_id);
	
		if (empty($location_details)) {
			$this->console_log('No location details found for Location ID: ' . $location_id);
			return;
		}
	
		// Assign the location term to the post (item)
		$query_args = array(
			'taxonomy'     => 'tg_location',
			'hide_empty'   => false,
			'meta_key'     => 'tg_id',
			'meta_value'   => $location_id,
			'meta_compare' => '=',
			'number'       => 1,
			'fields'       => 'ids',
		);
		$location_terms = get_terms($query_args);
	
		if (empty($location_terms)) {
			$this->console_log('No location term found for Location ID: ' . $location_id . ' and post ID: ' . $post_id);
			return;
		}
	
		// Assign the location to the post
		wp_set_post_terms($post_id, $location_terms, 'tg_location');
		$this->console_log('Assigned location (term ID: ' . $location_terms[0] . ') to post ID: ' . $post_id);
	
		// Save location details as metadata in the post
		update_post_meta($post_id, 'tg_location_city', $location_details['physicalAddress']['city']);
		update_post_meta($post_id, 'tg_location_locale', $location_details['physicalAddress']['locale']);
		update_post_meta($post_id, 'tg_location_address', $location_details['physicalAddress']['fullAddress']);
		$this->console_log('Saved location details to post meta for post ID: ' . $post_id);
	}
	
	

	public function tapgrein_update_inventory($product, $tg_order) {
		$args = array(
			'post_type' => 'tg_inventory',
			'posts_per_page' => 1,
			'meta_key' => 'tg_token',
			'meta_value' => $product['token'],
		);
	
		$query = new WP_Query($args);
		$post_id = false;
	
		if (count($query->posts) > 0) {
			$post_id = $query->posts[0]->ID;
			$this->console_log('Found existing post with tg_token: ' . $product['token'] . ' (Post ID: ' . $post_id . ')');
		} else {
			$this->console_log('No post found for tg_token: ' . $product['token'] . '. Inserting new post.');
		}
	
		$slug = str_replace('_', '-', $product['slug']);
		$meta = $this->prepare_meta_input($product);
		$meta['tg_hash'] = $this->hash;
	
		$post_arr = array(
			'post_type' => 'tg_inventory',
			'post_title' => $product['name'],
			'post_name' => $slug,
			'menu_order' => $tg_order,
			'meta_input' => $meta,
			'post_status' => 'publish',
		);
	
		if (false === $post_id) {
			$insert = wp_insert_post($post_arr, true);
			if (is_wp_error($insert)) {
				$this->console_log('Error inserting item: ' . $product['id'] . ' - ' . $insert->get_error_message());
				return false;
			}
			update_post_meta($insert, 'tg_token', $product['token']);
			$post_id = $insert;
		} else {
			$post_arr['ID'] = $post_id;
			$update = wp_update_post($post_arr, true);
			if (is_wp_error($update)) {
				$this->console_log('Error updating item: ' . $product['id'] . ' - ' . $update->get_error_message());
				return false;
			}
		}
	
		if ($post_id) {
			$this->tapgrein_assign_terms($post_id);
		} else {
			$this->console_log('Failed to assign terms: post ID is invalid');
		}
	
		return $post_id;
	}
	
	
	
	
	
	/**
	 * Sync storefront categories (and their subcategories, as tags) from the API.
	 *
	 * One-shot form kept for direct (non-sliced) callers: it loops every location
	 * through sync_categories_for_location(), accumulates the touched term ids, and
	 * only then reconciles obsolete terms against the FULL set. The bounded sync
	 * slice does NOT call this method; it drives sync_categories_for_location() one
	 * location at a time so the work can be checkpointed across cron ticks (see
	 * run_sync_slice()). For a large, request-time-limited host this all-locations
	 * form cannot finish in one request, which is exactly the WPB-165 stall.
	 *
	 * @param string $pass Which pass this is, for the activity log.
	 * @return bool
	 */
	public function sync_categories_from_api($pass = 'standalone') {
		$log = $this->sync_log();
		$started = microtime(true);
		$log->info('sync.categories.start', array('pass' => $pass));

		$client = $this->get_connection();
		$location_ids = $client->get_location_ids();

		if (false === $location_ids || is_wp_error($location_ids)) {
			$log->error('sync.api.error', array('op' => 'get_location_ids', 'stage' => 'categories', 'pass' => $pass, 'status' => $this->client_http_status($client)));
			$log->info('sync.categories.done', array('pass' => $pass, 'ok' => 0, 'elapsed_ms' => self::elapsed_ms($started)));
			return false;
		}

		$valid_category_ids = array();
		$valid_tag_ids = array();

		foreach ($location_ids as $lid) {
			$touched = $this->sync_categories_for_location($lid);

			if (! $touched['ok']) {
				$log->warn('sync.categories.fetch_failed', array('location' => $lid, 'pass' => $pass, 'status' => $this->client_http_status($client)));
				continue;
			}

			$valid_category_ids = array_merge($valid_category_ids, $touched['category_ids']);
			$valid_tag_ids      = array_merge($valid_tag_ids, $touched['tag_ids']);
		}

		// Remove obsolete terms. Safe here because every location has been collected,
		// so this reconciles against the FULL valid-id set (never a partial pass).
		$this->remove_obsolete_terms('tg_category', $valid_category_ids);
		$this->remove_obsolete_terms('tg_tags', $valid_tag_ids);

		$log->info(
			'sync.categories.done',
			array(
				'pass'       => $pass,
				'ok'         => 1,
				'categories' => count($valid_category_ids),
				'tags'       => count($valid_tag_ids),
				'elapsed_ms' => self::elapsed_ms($started),
			)
		);
		return true;
	}

	/**
	 * Sync ONE location's storefront categories (and their subcategories, as tags).
	 *
	 * Extracted from sync_categories_from_api() so the bounded sync slice can
	 * process a single location per request and checkpoint between locations
	 * (WPB-165: the one-shot all-locations pass could not finish inside a single
	 * request on a request-time-limited host, so paging never started). It upserts
	 * each category as a tg_category term and every sfSubCategories entry as a
	 * tg_tags term linked to its parent, and returns the term ids it touched so the
	 * caller can accumulate a full valid-id set before reconciling obsolete terms.
	 *
	 * DELETION SAFETY: this method deliberately does NOT remove obsolete terms.
	 * Removal must run only once EVERY location has been collected (the full
	 * accumulated valid-id set), never on this one location's partial set, or a
	 * not-yet-processed location's terms would be deleted and re-added next slice.
	 *
	 * @param int|string $lid Location id.
	 * @return array {
	 *     @type bool  $ok           Whether the location's categories were fetched.
	 *     @type int[] $category_ids tg_category term ids upserted for this location.
	 *     @type int[] $tag_ids      tg_tags term ids upserted for this location.
	 * }
	 */
	public function sync_categories_for_location($lid) {
		$client     = $this->get_connection();
		$categories = $client->get_categories_from_graph($lid);

		if (false === $categories || is_wp_error($categories)) {
			return array('ok' => false, 'category_ids' => array(), 'tag_ids' => array());
		}

		$category_ids = array();
		$tag_ids      = array();

		foreach ($categories as $category) {
			$category_term_id = $this->tg_insert_or_update_term($category, 'tg_category');
			if ($category_term_id) {
				$category_ids[] = $category_term_id;

				// Process subcategories as tags and link them to their parent category
				if (!empty($category['sfSubCategories'])) {
					foreach ($category['sfSubCategories'] as $tag) {
						// Pass the parent category term ID to establish the relationship
						$tag_term_id = $this->tg_insert_or_update_term($tag, 'tg_tags', $category_term_id);
						if ($tag_term_id) {
							$tag_ids[] = $tag_term_id;
						}
					}
				}
			}
		}

		return array('ok' => true, 'category_ids' => $category_ids, 'tag_ids' => $tag_ids);
	}
	
	
	
	
	
	public function remove_obsolete_terms($taxonomy, $valid_ids) {
		$valid    = array_map('intval', (array) $valid_ids);
		$term_ids = $this->get_term_ids_for_taxonomy($taxonomy);

		// Chunked purely to keep memory bounded on huge taxonomies; the delete
		// decision is an in-memory in_array against the valid set (no term query).
		foreach (self::tapgrein_chunk_ids($term_ids) as $chunk) {
			foreach ($chunk as $term_id) {
				if (!in_array((int) $term_id, $valid, true)) {
					wp_delete_term($term_id, $taxonomy);
					$this->console_log("Removed obsolete term ID: {$term_id} from taxonomy: {$taxonomy}");
				}
			}
		}
	}
	
	
	
	
	public function remove_missing_terms($taxonomy, $valid_ids) {
		$term_ids = $this->get_term_ids_for_taxonomy($taxonomy);

		foreach (self::tapgrein_chunk_ids($term_ids) as $chunk) {
			foreach ($chunk as $term_id) {
				$tg_id   = get_term_meta($term_id, 'tg_id', true);
				$tg_hash = get_term_meta($term_id, 'tg_hash', true);

				// Si el término tiene un tg_id o tg_hash válido, no lo elimines
				if (in_array($tg_id, $valid_ids) || $tg_hash === $this->hash) {
					continue;
				}

				wp_delete_term($term_id, $taxonomy);
				$this->console_log("Removed term ID: {$term_id} from taxonomy: {$taxonomy}");
			}
		}
	}
	
	

	

	public function tg_insert_or_update_term($term, $tax, $parent_category_id = null) {
		$tg_id = $term['id']; // The unique ID of the term from the external source.
		$name = $term['name']; // The name of the term.
		$original_slug = $term['slug'] ?? sanitize_title($term['name']); // Use original slug from API

		// Add taxonomy prefix to prevent WordPress from sharing term_id between taxonomies
		// Only tags get 'tag-' prefix to differentiate from categories
		if ($tax === 'tg_tags') {
			$slug = 'tag-' . $original_slug;
		} else {
			// Categories use original slug without prefix
			$slug = $original_slug;
		}

		$term_args = array('slug' => $slug);
	
		// Check if a term already exists using the tg_id meta.
		$existing_term = get_terms([
			'taxonomy' => $tax,
			'hide_empty' => false,
			'meta_key' => 'tg_id',
			'meta_value' => $tg_id,
			'meta_compare' => '=',
			'number' => 1,
			'fields' => 'ids',
		]);
	
		if (!empty($existing_term)) {
			$term_id = $existing_term[0];
			$existing_term_obj = get_term($term_id, $tax);
			
			if (is_wp_error($existing_term_obj)) {
				$this->console_log("Error getting existing term: {$existing_term_obj->get_error_message()}");
				return false;
			}
			
			// Check if name or slug has changed
			$name_changed = $existing_term_obj->name !== $name;
			$slug_changed = $existing_term_obj->slug !== $slug;
			
			if ($name_changed || $slug_changed) {
				$this->console_log("Term exists for tg_id: $tg_id (ID: {$term_id}), changes detected - updating...");
				
				if ($name_changed) {
					$this->console_log("Name changed from '{$existing_term_obj->name}' to '{$name}'");
				}
				if ($slug_changed) {
					$this->console_log("Slug changed from '{$existing_term_obj->slug}' to '{$slug}'");
				}
				
				// Update the existing term with new name and slug
				$update_result = wp_update_term($term_id, $tax, array(
					'name' => $name,
					'slug' => $slug
				));
				
				if (is_wp_error($update_result)) {
					$this->console_log("Error updating term: {$update_result->get_error_message()}");
				} else {
					$this->console_log("Successfully updated term: $name with slug: $slug");
				}
			} else {
				$this->console_log("Term exists for tg_id: $tg_id (ID: {$term_id}), no changes detected - skipping update");
			}
			
			// Always update metadata hash
			update_term_meta($term_id, 'tg_hash', $this->hash);

			// If this is a tag and has a parent category, update the relationship
			if ($tax === 'tg_tags' && $parent_category_id) {
				update_term_meta($term_id, 'tg_parent_category', $parent_category_id);
				$this->console_log("Updated parent category link for tag $name (ID: $term_id) to category ID: $parent_category_id");
			}

			return $term_id; // Return the term ID.
		}
	
		// Attempt to insert the new term.
		$term_result = wp_insert_term($name, $tax, $term_args);
	
		// Check if the result is a WP_Error and log it if necessary.
		if (is_wp_error($term_result)) {
			$this->console_log("Error inserting term: {$term_result->get_error_message()}");
			return false;
		}
	
		// Ensure that the term ID exists in the result.
		$term_id = isset($term_result['term_id']) ? $term_result['term_id'] : null;
		if (!$term_id) {
			$this->console_log("Failed to retrieve term_id for term: {$name}");
			return false;
		}
	
		// Save metadata for the term.
		update_term_meta($term_id, 'tg_id', $tg_id);
		update_term_meta($term_id, 'tg_hash', $this->hash);

		// If this is a tag and has a parent category, save the relationship
		if ($tax === 'tg_tags' && $parent_category_id) {
			update_term_meta($term_id, 'tg_parent_category', $parent_category_id);
			$this->console_log("Linked tag $name (ID: $term_id) to parent category ID: $parent_category_id");
		}

		$this->console_log("Inserted term: $name with tg_id: $tg_id and taxonomy: $tax");

		return $term_id; // Return the new term ID.
	}
	
	
	
	
	
	
	
	
	

	public function tapgrein_assign_terms($post_id) {
		// Fetch categories and subcategories (tags) from post meta
		$categories = get_post_meta($post_id, 'tg_sfCategories', true);
		$tags = get_post_meta($post_id, 'tg_sfSubCategories', true);
	
		$assigned_categories = [];
		$assigned_tags = [];
	
		// Process categories
		if (!empty($categories)) {
			foreach ($categories as $category) {
				// Check if a term with the given tg_id exists
				$term = get_terms([
					'taxonomy' => 'tg_category',
					'hide_empty' => false,
					'meta_query' => [
						[
							'key' => 'tg_id',
							'value' => $category['id'],
							'compare' => '='
						]
					],
					'fields' => 'ids',
				]);
	
				if (empty($term)) {
					// Check if a term with the same name exists
					$existing_term = get_terms([
						'taxonomy' => 'tg_category',
						'hide_empty' => false,
						'name' => $category['name'],
						'fields' => 'ids',
					]);
	
					if (!empty($existing_term)) {
						// Use the existing term
						$this->console_log("Category found by name: {$category['name']}. Assigning to post.");
						$assigned_categories[] = $existing_term[0];
					} else {
						// Create category by name if it doesn't exist
						$this->console_log("Category not found for tg_id: {$category['id']}. Creating by name: {$category['name']}");
						$result = wp_insert_term($category['name'], 'tg_category', ['slug' => sanitize_title($category['slug'])]);
						if (is_wp_error($result)) {
							$this->console_log("Error creating category: {$category['name']} - {$result->get_error_message()}");
						} else {
							$term_id = $result['term_id'];
							update_term_meta($term_id, 'tg_id', $category['id']);
							$assigned_categories[] = $term_id;
						}
					}
				} else {
					$assigned_categories[] = $term[0];
				}
			}
	
			// Assign categories to the post
			if (!empty($assigned_categories)) {
				wp_set_post_terms($post_id, $assigned_categories, 'tg_category');
				$this->console_log("Assigned categories to post ID: $post_id");
			}
		}
	
		// Process tags
		if (!empty($tags)) {
			foreach ($tags as $tag) {
				// Check if a term with the given tg_id exists
				$term = get_terms([
					'taxonomy' => 'tg_tags',
					'hide_empty' => false,
					'meta_query' => [
						[
							'key' => 'tg_id',
							'value' => $tag['id'],
							'compare' => '='
						]
					],
					'fields' => 'ids',
				]);
	
				if (empty($term)) {
					// Check if a term with the same name exists
					$existing_term = get_terms([
						'taxonomy' => 'tg_tags',
						'hide_empty' => false,
						'name' => $tag['name'],
						'fields' => 'ids',
					]);
	
					if (!empty($existing_term)) {
						// Use the existing term
						$this->console_log("Tag found by name: {$tag['name']}. Assigning to post.");
						$assigned_tags[] = $existing_term[0];
					} else {
						// Create tag by name if it doesn't exist
						$this->console_log("Tag not found for tg_id: {$tag['id']}. Creating by name: {$tag['name']}");
						$result = wp_insert_term($tag['name'], 'tg_tags', ['slug' => sanitize_title($tag['name'])]);
						if (is_wp_error($result)) {
							$this->console_log("Error creating tag: {$tag['name']} - {$result->get_error_message()}");
						} else {
							$term_id = $result['term_id'];
							update_term_meta($term_id, 'tg_id', $tag['id']);
							$assigned_tags[] = $term_id;
						}
					}
				} else {
					$assigned_tags[] = $term[0];
				}
			}
	
			// Assign tags to the post
			if (!empty($assigned_tags)) {
				wp_set_post_terms($post_id, $assigned_tags, 'tg_tags');
				$this->console_log("Assigned tags to post ID: $post_id");
			}
		}
	}
	
	
	
	
	
	
	/**
	 * Delete terms in a taxonomy that no post is assigned to.
	 *
	 * @param string $taxonomy Taxonomy to clean up.
	 * @return int Number of terms deleted.
	 */
	public function remove_unused_terms($taxonomy) {
		$log = $this->sync_log();

		// Read the full ID list without priming caches (no oversized IN()), then
		// load the term objects (which carry the post-count) a chunk at a time.
		// The old single get_terms() over ~4000 terms produced the "KILLED QUERY"
		// on the failing site; chunking caps every query's IN() at TERM_CHUNK_SIZE.
		$term_ids = $this->get_term_ids_for_taxonomy($taxonomy);
		$total    = count($term_ids);
		$removed  = 0;

		foreach (self::tapgrein_chunk_ids($term_ids) as $chunk) {
			$terms = get_terms([
				'taxonomy'               => $taxonomy,
				'hide_empty'             => false, // Include terms even if they're not assigned to posts.
				'include'                => $chunk,
				'update_term_meta_cache' => false,
			]);

			if (is_wp_error($terms)) {
				$log->warn('sync.cleanup.terms_error', array('taxonomy' => $taxonomy, 'message' => $terms->get_error_message()));
				continue;
			}

			foreach ($terms as $term) {
				// Check if the term is assigned to any posts.
				if (0 === (int) $term->count) {
					// Delete the term if it has no assignments.
					$deleted = wp_delete_term($term->term_id, $taxonomy);

					if (is_wp_error($deleted)) {
						$log->warn('sync.cleanup.term_error', array('taxonomy' => $taxonomy, 'term' => $term->term_id, 'message' => $deleted->get_error_message()));
					} else {
						++$removed;
						$log->debug('sync.cleanup.term_removed', array('taxonomy' => $taxonomy, 'term' => $term->term_id));
					}
				}
			}
		}

		$log->info('sync.cleanup.terms_removed', array('taxonomy' => $taxonomy, 'count' => $removed, 'total' => $total));

		return $removed;
	}
	
	
	
	
	

	
	public function sync_terms_from_api($taxonomy, $data) {
		$api_ids = array();
	
		foreach ($data as $item) {
			$tg_id = $item['id'];
			$api_ids[] = $tg_id;
	
			$this->tg_insert_or_update_term($item, $taxonomy);
		}
	
		$this->remove_missing_terms($taxonomy, $api_ids);
	}
	

	public function tapgrein_async_sync_from_api( $action = 'tapgrein_api_sync' ) {

		$url = admin_url( "admin-ajax.php?action={$action}" );

		$args = array(
			'blocking'  => false,
			'sslverify' => false,
			'timeout'   => 1,
		);

		wp_remote_get( $url, $args );
		return;
	}

	public function tapgrein_delete_data() {
		$post_args = array(
			'post_type'   => 'tg_inventory',
			'numberposts' => -1,
			'fields'      => 'ids',
			'post_status' => 'any',
		);
		$posts     = get_posts( $post_args );
		foreach ( $posts as $pid ) {
			wp_delete_post( $pid, true );
		}

		$taxonomies = Tapgoods_Post_Types::tapgrein_get_taxonomies();
		foreach ( $taxonomies as $tax ) {
			$tax_args = array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'fields'     => 'ids',
			);
			$terms    = get_terms( $tax_args );

			foreach ( $terms as $tid ) {
				wp_delete_term( $tid, $tax );
			}
		}
	}

	// Function to delete items that have been previously added but no longer exist
	// Works by finding items that weren't signed with the latest API sync hash value
	public function tapgrein_remove_deleted_inventory() {

		// If there's no hash, bail
		if ( is_null( $this->hash ) ) {
			return false;
		}

		$count = 0;

		// Find everything that DOESNT have the current hash
		$posts = get_posts(
			array(
				'post_type'    => 'tg_inventory',
				'numberposts'  => -1,
				'fields'       => 'ids',
				'post_status'  => 'any',
				'meta_key'     => 'tg_hash',
				'meta_value'   => $this->hash,
				'meta_compare' => 'NOT LIKE',
			)
		);

		if ( count( $posts ) > 0 ) {
			foreach ( $posts as $pid ) {
				$lid = get_post_meta( $pid, 'tg_locationId', true );
				$id  = get_post_meta( $pid, 'tg_id', true );

				$client = $this->get_connection();

				$item_exists = $client->item_exists( $lid, $id );

				if ( false === $item_exists ) {
					wp_delete_post( $pid, true );
					++$count;
				}
			}
		}



		return $count;
	}

	// Function to delete terms that have been previously added but no longer exist
	// Works by finding items that weren't signed with the latest API sync hash value
	public function tapgrein_remove_deleted_terms() {

		// If there's no hash, bail
		if ( is_null( $this->hash ) ) {
			return false;
		}

		$count = 0;

		$taxonomies = Tapgoods_Post_Types::tapgrein_get_taxonomies();
		foreach ( $taxonomies as $tax ) {
			$tax_args = array(
				'taxonomy'     => $tax,
				'hide_empty'   => false,
				'fields'       => 'ids',
				'meta_key'     => 'tg_hash',
				'meta_value'   => $this->hash,
				'meta_compare' => 'NOT LIKE',
			);
			$terms    = get_terms( $tax_args );

			foreach ( $terms as $tid ) {

				// Maybe fetch from API first?

				wp_delete_term( $tid, $tax );
				++$count;
			}
		}
		return $count;
	}

	public function get_client() {
		$client = $this->get_connection();
		return $client;
	}

	public function tapgrein_get_colors_from_rest() {
	}

	public function tapgrein_get_departments_from_api() {
	}


	/**
	 * Cron entry point (fired via the unauthenticated admin-ajax self-ping).
	 *
	 * Delegates entirely to sync_inventory_in_batches(), which owns the execution
	 * mutex and the state machine. Crucially, the category/tag pass now happens
	 * INSIDE that guarded slice (as the cursor's one-shot categories step), so it
	 * can no longer run concurrently with, or on top of, an in-flight run started
	 * by the manual "Sync Now" button.
	 *
	 * This method only names the trigger for the activity log and wraps the
	 * delegated slice in a single run; it returns the slice's real result (which
	 * may be an in-progress checkpoint), so the log never claims a success the
	 * bounded slice did not observe.
	 *
	 * @param string $trigger Which entry point started the run (activity log).
	 * @return array Result envelope from the bounded slice.
	 */
	public function sync_from_api( $trigger = 'unknown' ) {
		$log = $this->sync_log();
		$log->begin_run($trigger, array('entry' => 'sync_from_api'));

		$result = $this->sync_inventory_in_batches(false, $trigger);

		$state  = $this->sync_state();
		$status = ( ! empty($result['success']) && ! $state->has_error() ) ? 'ok' : 'error';
		$log->end_run($status, array('in_progress' => ! empty($result['in_progress']) ? 1 : 0));

		return $result;
	}
	
	public function sync_inventory_item($item) {
		try {
			$existing_item_by_id = $this->get_existing_inventory_item_by_tg_id($item['id']);

			if ($existing_item_by_id) {
				$this->console_log('Updating item with tg_id: ' . $item['id'] . ' (' . $item['name'] . ')');
				$post_id = $existing_item_by_id->ID;
				$this->update_inventory_item($post_id, $item);
			} else {
				$this->console_log('Inserting new item: ' . $item['name']);
				$post_id = $this->tapgrein_insert_inventory($item);
			}

			// Assign categories and tags to the item using the real WP post ID.
			// (Previously a newly-inserted item was passed its tg_id here, so its
			// terms were never assigned during item sync and depended on a separate
			// re-assignment pass. Categories are now synced before paging, so this
			// per-item assignment is sufficient for both new and existing items.)
			if ($post_id) {
				$this->tapgrein_assign_terms($post_id);
			}
		} catch (Exception $e) {
			$this->console_log('Error syncing item: ' . $item['id'] . ' - ' . $e->getMessage());
		}
	}
	
	
	
	/**
	 * Remove duplicate tg_inventory posts sharing a tg_id, keeping the lowest ID.
	 *
	 * @return int Number of posts deleted.
	 */
	public function remove_duplicate_items() {
		global $wpdb;
		$log = $this->sync_log();
	
		$duplicates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS tg_id, COUNT(*) as count, MIN(post_id) as keep_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				GROUP BY meta_value
				HAVING COUNT(*) > 1",
				'tg_id'
			)
		);

		$removed = 0;

		foreach ($duplicates as $duplicate) {
			$duplicate_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s AND meta_value = %s AND post_id != %d",
					'tg_id', $duplicate->tg_id, $duplicate->keep_id
				)
			);			
	
			foreach ($duplicate_ids as $post_id) {
				wp_delete_post($post_id, true);
				++$removed;
				$log->debug('sync.cleanup.duplicate_removed', array('post' => $post_id, 'tg_id' => $duplicate->tg_id));
			}
		}

		$log->info('sync.cleanup.duplicates_removed', array('count' => $removed, 'groups' => count($duplicates)));

		return $removed;
	}
	
	
	
	public function get_existing_inventory_item_by_title($title) {
		$args = array(
			'post_type' => 'tg_inventory',
			'title'     => $title,
			'posts_per_page' => 1,
		);
		$query = new WP_Query($args);
		
		if ($query->have_posts()) {
			$this->console_log('Found existing item with title: ' . $title);
			return $query->posts[0];
		} else {
			$this->console_log('No existing item found with title: ' . $title);
			return false;
		}
	}
	
	public function update_inventory_item($post_id, $item) {
		$existing_meta = get_post_meta($post_id); // Get all existing meta fields
		$new_meta = $this->prepare_meta_input($item); // Get updated meta fields from the API
	
		// Define meta keys that should not be removed
		$protected_meta_keys = [
			'tg_custom_description',
			// Yoast SEO meta fields - proteger todos los campos de Yoast
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc', 
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_meta-robots-adv',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_bctitle',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
			'_yoast_wpseo_twitter-image',
			'_yoast_wpseo_linkdex',
			'_yoast_wpseo_content_score',
			'_yoast_wpseo_estimated-reading-time-minutes',
			'_yoast_wpseo_wordproof_timestamp'
		];
	
		// Detect and remove meta fields that are no longer present in the API response, except protected ones
		foreach ($existing_meta as $meta_key => $value) {
			if (!array_key_exists($meta_key, $new_meta) && !in_array($meta_key, $protected_meta_keys)) {
				delete_post_meta($post_id, $meta_key); // Delete meta field if it no longer exists in the API
				$this->console_log('Deleted meta_key: ' . $meta_key . ' from post ID: ' . $post_id);
			}
		}
	
		// Prepare the array for updating the post
		$post_arr = array(
			'ID' => $post_id,
			'post_title' => $item['name'],
			'meta_input' => $new_meta, // Insert/update meta fields
		);
	
		$this->console_log('Updating post ID: ' . $post_id . ' with item name: ' . $item['name']);
	
		$update = wp_update_post($post_arr);
	
		if (is_wp_error($update)) {
			$this->console_log('Error updating item: ' . $item['id'] . ' - ' . $update->get_error_message());
		} else {
			$this->console_log('Post updated successfully with ID: ' . $post_id);
			$this->tapgrein_assign_terms($post_id); // Assign categories and tags after updating the post
		}
	}
	
	

	
	public function get_existing_inventory_item_by_tg_id($tg_id) {
		$args = array(
			'post_type' => 'tg_inventory',
			'meta_query' => array(
				array(
					'key' => 'tg_id',
					'value' => $tg_id,
					'compare' => '='
				)
			),
			'posts_per_page' => 1
		);
		$query = new WP_Query($args);
		if ($query->have_posts()) {
			$this->console_log('Found existing item with tg_id: ' . $tg_id);
			return $query->posts[0];
		} else {
			$this->console_log('No existing item found with tg_id: ' . $tg_id);
			return false;
		}
	}
	
	public function tapgrein_insert_inventory($product) {
		$tg_id = $product['id'];
		if (!$tg_id) {
			$this->console_log("Skipping item: No tg_id found");
			return false;
		}
	
		$slug = str_replace('_', '-', $product['slug']);
		$post_arr = [
			'post_type' => 'tg_inventory',
			'post_title' => $product['name'],
			'post_name' => wp_unique_post_slug($slug, 0, 'publish', 'tg_inventory', 0),
			'meta_input' => $this->prepare_meta_input($product),
			'post_status' => 'publish',
		];
	
		$post_id = wp_insert_post($post_arr, true);
	
		if (is_wp_error($post_id)) {
			$this->console_log("Error inserting item with tg_id: $tg_id - {$post_id->get_error_message()}");
			return false;
		}
	
		update_post_meta($post_id, 'tg_id', $tg_id);
		$this->console_log("Inserted item with tg_id: $tg_id and post ID: $post_id");
		return $post_id;
	}
	
	
	
	
	public function prepare_meta_input($product) {
		$meta = array();
		$exclude = array('businessInfo', 'location', 'suppliers', 'slug');
		foreach ($product as $k => $v) {
			if (in_array($k, $exclude, true)) {
				continue;
			}
			if (null === $v) {
				continue;
			}
			$key = 'tg_' . $k;
			$meta[$key] = $v;
		}
		$meta['tg_id'] = $product['id'];
		return $meta;
	}
	
	// public function console_log($message) {
	// 	add_action('admin_footer', function() use ($message) {
	// 		echo "<script>console.log('{$message}');</script>";
	// 	});
	// }
	
	public function manual_sync_trigger() {
		$log = $this->sync_log();
		$log->begin_run('admin_manual', array('entry' => 'manual_sync_trigger'));

		$location_info = $this->sync_location_settings(true);

		if (current_user_can('manage_options')) {
			$result = $this->sync_inventory_in_batches(true, 'admin_manual');

			// Every branch below ends in wp_send_json_*(), which calls wp_die():
			// the run has to be closed before that or it is never closed at all.
			$log->end_run(
				! empty($result['success']) ? 'ok' : 'error',
				array(
					'location_settings' => $location_info ? 1 : 0,
					'in_progress'       => ! empty($result['in_progress']) ? 1 : 0,
				)
			);

			// The manual sync is now NON-BLOCKING: it runs a single bounded slice and
			// returns, cron continues the rest in the background. Send back the state
			// machine summary so the admin screen can show "in progress / continues in
			// the background" with planned-vs-completed pages instead of implying the
			// whole sync finished in this one request.
			$payload = array(
				'message'     => isset($result['message']) ? $result['message'] : '',
				'in_progress' => ! empty($result['in_progress']),
				'state'       => $this->sync_state()->get_summary(),
			);

			if ($result['success']) {
				wp_send_json_success($payload);
			} else {
				wp_send_json_error($payload);
			}
		} else {
			$log->warn('sync.run.denied', array('reason' => 'missing_capability'));
			$log->end_run('error', array('location_settings' => $location_info ? 1 : 0));
			wp_send_json_error('You do not have permission to access this endpoint.');
		}
	}
	

	/**
	 * AJAX handler: clear a latched sync error so cron can start syncing again.
	 *
	 * Admin-only and nonce-protected (the shared `tapgrein_sync_nonce`). Returns
	 * the refreshed state summary so the admin screen can update in place.
	 */
	public function clear_sync_errors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have permission to perform this action.' );
		}

		check_ajax_referer( 'tapgrein_sync_nonce', 'nonce' );

		$state = $this->sync_state();
		$state->clear_errors();

		wp_send_json_success(
			array(
				'message' => 'Sync errors cleared.',
				'state'   => $state->get_summary(),
			)
		);
	}

	public function console_log($message) {
//		error_log($message); // Log to PHP error log
	}

	

}
	// Add action for synchronization via AJAX
	add_action('wp_ajax_tapgrein_api_sync', array(Tapgoods_Connection::get_instance(), 'manual_sync_trigger'));

	// Clear a latched sync error (admin-only, nonce-protected).
	add_action('wp_ajax_tapgrein_clear_sync_errors', array(Tapgoods_Connection::get_instance(), 'clear_sync_errors'));
	
// Create an endpoint to manually execute synchronization without using cron
add_action('wp_ajax_tapgoods_manual_sync', [Tapgoods_Connection::get_instance(), 'manual_sync_trigger']);
add_action('wp_ajax_nopriv_tapgoods_manual_sync', function() {
    wp_send_json(array('success' => false, 'message' => 'Unauthorized request.'));
});

// Sync button and script now handled by Tapgoods_Enqueue class
// This function is deprecated but kept for backward compatibility