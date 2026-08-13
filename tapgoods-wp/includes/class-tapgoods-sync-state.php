<?php
/**
 * TapGoods sync state machine.
 *
 * Single source of truth for the inventory sync flow. It replaces the two
 * overlapping flags that used to guard the sync (`tapgrein_sync_active` and
 * `tapgrein_sync_lock`) with one explicit, persisted state machine.
 *
 * States (see the STATE_* constants):
 *   IDLE      - no sync running; ready to start.
 *   PREP      - counting how many paging calls the full sync will need.
 *   ACTIVE    - paging through inventory and writing it into WordPress.
 *   COMPLETED - the last run finished successfully.
 *   ERROR     - the last run failed and exhausted its retries; needs a manual
 *               "clear errors" before cron will start it again.
 *
 * A failure does not go straight to ERROR: the run is retried up to
 * MAX_RETRIES times first (the state drops back to IDLE so the next cron tick
 * picks it up). Only once the retry budget is spent does it latch to ERROR.
 *
 * The whole state lives in a single option (self::OPTION) so it is easy to read
 * from the admin screen and easy to reason about. The class makes no assumptions
 * beyond the handful of WordPress option / time helpers, so it is unit-testable
 * in isolation (WP functions stubbed via Brain\Monkey).
 *
 * @package Tapgoods\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Tapgoods_Sync_State {

	const STATE_IDLE      = 'idle';
	const STATE_PREP      = 'prep';
	const STATE_ACTIVE    = 'active';
	const STATE_COMPLETED = 'completed';
	const STATE_ERROR     = 'error';

	/** Option that stores the whole state payload. */
	const OPTION = 'tg_sync_state';

	/** How many times a failed sync is retried before latching to ERROR. */
	const MAX_RETRIES = 2;

	/**
	 * Seconds after which a PREP/ACTIVE run is treated as crashed (stale) so a
	 * new sync can take over. Generous because a large inventory sync can run
	 * for the better part of an hour.
	 */
	const STALE_AFTER = 3600;

	private static $instance = null;

	/**
	 * In-memory copy of the persisted state.
	 *
	 * @var array
	 */
	private $data;

	public function __construct() {
		$this->data = $this->load();
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default shape of the state payload.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'state'           => self::STATE_IDLE,
			'total_pages'     => 0,
			'pages_completed' => 0,
			'failure_count'   => 0,
			'started_at'      => null,
			'updated_at'      => null,
			'last_success'    => null, // last_sync_completed_success_timestamp.
			'last_error'      => null, // last_sync_error_timestamp.
			'error_message'   => '',
			'last_duration'   => null,
			// Resumable paging cursor. Persisted so a bounded-per-request sync can
			// checkpoint its position and the next cron tick resumes from here
			// instead of restarting at page 1. See self::cursor_defaults().
			'cursor'          => self::cursor_defaults(),
		);
	}

	/**
	 * Default shape of the resumable paging cursor.
	 *
	 * MEMORY BOUND (WPB-172): every field here is a SCALAR or a small
	 * location-sized list. Nothing grows with the number of items, categories or
	 * tags. The run's per-row bookkeeping lives in the DB instead: each synced
	 * inventory post and each upserted term is stamped with the run's token
	 * (Tapgoods_Connection::SYNC_RUN_META), so "did this run touch this row?" is a
	 * per-row meta read, never an in-cursor collection. This is what keeps the
	 * serialized state (and the per-slice memory) FLAT regardless of catalog size;
	 * the old synced_ids / valid_*_ids / processed_*_ids arrays are what blew the
	 * 512 MB limit during finalize on a large WP Engine catalog.
	 *
	 *  - location_ids:    ordered list of location IDs captured when the run began
	 *                     (bounded by the business's location count, ~18, not catalog size).
	 *  - location_index:  index into location_ids currently being paged.
	 *  - next_page:       next 1-based page to fetch for that location.
	 *  - run_token:       stable scalar token generated once at PREP and reused across
	 *                     EVERY resumed slice of the run. Stamped onto every synced post
	 *                     and upserted term; finalize deletes whatever is NOT stamped with
	 *                     it. Replaces the old synced_ids / valid_*_ids / processed_*_ids.
	 *  - total_items:     running count of items written this run (the scalar safety valve:
	 *                     finalize skips item removal entirely when this is 0).
	 *  - categories_done: whether the (bounded/resumable) category/tag pass has finished
	 *                     for EVERY location.
	 *  - cat_location_index: how many of location_ids have had their categories synced
	 *                     (resume point for the bounded categories loop, mirroring
	 *                     location_index for paging).
	 *  - cat_item_index:  resume point WITHIN the current location's category list, so one
	 *                     large location's category upserts are checkpointed in bounded
	 *                     batches. Reset to 0 whenever cat_location_index advances.
	 *  - cat_dupes_skipped: running count of category/tag upserts skipped because a term
	 *                     for that source id was already stamped with run_token this run
	 *                     (visibility only).
	 *  - phase:           'paging' while walking locations, 'finalize' once every
	 *                     location/page is done (cleanup + reconciliation).
	 *  - finalize_step:   which bounded finalize sub-step is in progress ('' until finalize
	 *                     begins, then 'items' -> 'obsolete_cat' -> 'obsolete_tag' ->
	 *                     'cleanup' -> 'done'). Makes finalize resumable across slices.
	 *  - finalize_items_removed / finalize_terms_removed: running counters for the final
	 *                     sync.finalize.done log line (scalars, accumulated across slices).
	 *
	 * @return array
	 */
	public static function cursor_defaults() {
		return array(
			'location_ids'           => array(),
			'location_index'         => 0,
			'next_page'              => 1,
			'run_token'              => '',
			'total_items'            => 0,
			'categories_done'        => false,
			'cat_location_index'     => 0,
			'cat_item_index'         => 0,
			'cat_dupes_skipped'      => 0,
			'phase'                  => 'paging',
			'finalize_step'          => '',
			'finalize_items_removed' => 0,
			'finalize_terms_removed' => 0,
		);
	}

	/**
	 * Generate a stable, unique token for one sync run.
	 *
	 * Scalar and self-contained (no WordPress dependency, so it stays usable in
	 * the isolated unit suite). Generated once at PREP and persisted in the cursor;
	 * every resumed slice of the run reuses the SAME token, which is what lets
	 * finalize tell "touched this run" rows from stale ones by a per-row meta stamp.
	 *
	 * @return string
	 */
	public static function generate_run_token() {
		return substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 16 );
	}

	private function load() {
		// On a persistent object cache (e.g. WP Engine) the sync state must be read
		// from the source of truth at the start of each request, not from a stale
		// cache entry. The failure this guards against: the cron self-ping writes
		// mark_active()+init_cursor() during PREP, the request is then hard-killed
		// mid-categories, and the NEXT cron tick reads back the PRE-prep value from a
		// stale cache and wrongly restarts PREP instead of resuming (WPB-165/WPB-173).
		// Dropping any cached copy before the read makes the resume decision reliable.
		// Guarded by function_exists so the isolated unit suite (no WP) is unaffected.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$data = array_merge( self::defaults(), $stored );

		// array_merge is shallow: make sure a stored cursor missing some keys
		// (e.g. saved by an older plugin version) still has the full shape.
		$data['cursor'] = array_merge(
			self::cursor_defaults(),
			( isset( $data['cursor'] ) && is_array( $data['cursor'] ) ) ? $data['cursor'] : array()
		);

		return $data;
	}

	private function save() {
		$this->data['updated_at'] = $this->now();
		// Persist NON-autoloaded: the cursor can carry thousands of synced ids, and an
		// autoloaded option is served from the single `alloptions` blob, which a
		// persistent object cache (WP Engine) can truncate or serve stale - exactly
		// the "state written in PREP not read back next tick" failure. A standalone,
		// non-autoloaded option has its own cache key and is fetched on demand.
		update_option( self::OPTION, $this->data, false );
	}

	private function now() {
		return (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	// --- Transitions ---------------------------------------------------------

	/**
	 * Begin a sync: enter PREP and record the planned number of paging calls.
	 *
	 * @param int $total_pages Number of paging calls the full sync will require.
	 * @return $this
	 */
	public function begin_prep( $total_pages = 0 ) {
		$this->data['state']           = self::STATE_PREP;
		$this->data['total_pages']     = max( 0, (int) $total_pages );
		$this->data['pages_completed'] = 0;
		$this->data['started_at']      = $this->now();
		// A fresh run starts from a clean cursor.
		$this->data['cursor'] = self::cursor_defaults();
		$this->save();
		return $this;
	}

	// --- Resumable paging cursor --------------------------------------------

	/**
	 * Seed the cursor for a new run with the ordered list of location IDs to page,
	 * and mint the run token that every synced post/term will be stamped with.
	 *
	 * @param array       $location_ids Location IDs to page through, in order.
	 * @param string|null $run_token    Token for this run; a fresh one is minted when omitted.
	 * @return $this
	 */
	public function init_cursor( $location_ids, $run_token = null ) {
		$cursor                 = self::cursor_defaults();
		$cursor['location_ids'] = array_values( array_map( 'strval', (array) $location_ids ) );
		$cursor['run_token']    = ( null !== $run_token && '' !== (string) $run_token )
			? (string) $run_token
			: self::generate_run_token();
		$this->data['cursor']   = $cursor;
		$this->save();
		return $this;
	}

	/**
	 * Current paging cursor (always full-shaped).
	 *
	 * @return array
	 */
	public function get_cursor() {
		return array_merge( self::cursor_defaults(), (array) $this->data['cursor'] );
	}

	/**
	 * Persist an updated cursor (checkpoint). Refreshes updated_at so a healthy
	 * long run never trips the stale timeout while it is making progress.
	 *
	 * @param array $cursor Cursor payload (merged over the defaults).
	 * @return $this
	 */
	public function save_cursor( array $cursor ) {
		$this->data['cursor'] = array_merge( self::cursor_defaults(), $cursor );
		$this->save();
		return $this;
	}

	/**
	 * Whether the cursor still has locations/pages left to fetch.
	 *
	 * @return bool
	 */
	public function cursor_has_remaining_paging() {
		$cursor = $this->get_cursor();
		if ( 'paging' !== $cursor['phase'] ) {
			return false;
		}
		return (int) $cursor['location_index'] < count( $cursor['location_ids'] );
	}

	/**
	 * Record (or update) the planned number of paging calls computed during PREP.
	 *
	 * @param int $total_pages Number of paging calls.
	 * @return $this
	 */
	public function set_total_pages( $total_pages ) {
		$this->data['total_pages'] = max( 0, (int) $total_pages );
		$this->save();
		return $this;
	}

	/**
	 * Move from PREP into ACTIVE (paging through inventory).
	 *
	 * @return $this
	 */
	public function mark_active() {
		$this->data['state'] = self::STATE_ACTIVE;
		$this->save();
		return $this;
	}

	/**
	 * Record that one paging call has completed.
	 *
	 * @return $this
	 */
	public function increment_pages_completed() {
		$this->data['pages_completed'] = (int) $this->data['pages_completed'] + 1;
		$this->save();
		return $this;
	}

	/**
	 * Mark the sync as finished successfully. Clears the retry budget.
	 *
	 * @return $this
	 */
	public function mark_completed() {
		$now = $this->now();
		if ( ! empty( $this->data['started_at'] ) ) {
			$this->data['last_duration'] = $now - (int) $this->data['started_at'];
		}
		$this->data['state']         = self::STATE_COMPLETED;
		$this->data['last_success']  = $now;
		$this->data['failure_count'] = 0;
		$this->data['error_message'] = '';
		// The run is done: drop the (potentially large) resumable cursor so the
		// persisted option does not carry a stale synced-id set around.
		$this->data['cursor'] = self::cursor_defaults();
		$this->save();
		return $this;
	}

	/**
	 * Record a failed sync.
	 *
	 * Increments the failure counter and records the error. While the retry
	 * budget remains the state drops back to IDLE so the next cron tick retries;
	 * once the budget is spent it latches to ERROR.
	 *
	 * @param string $message Human-readable error message.
	 * @return bool True if the sync will be retried, false if it latched to ERROR.
	 */
	public function mark_error( $message = '' ) {
		$this->data['failure_count'] = (int) $this->data['failure_count'] + 1;
		$this->data['last_error']    = $this->now();
		$this->data['error_message'] = (string) $message;

		$will_retry = $this->data['failure_count'] <= self::MAX_RETRIES;

		$this->data['state'] = $will_retry ? self::STATE_IDLE : self::STATE_ERROR;
		$this->save();

		return $will_retry;
	}

	/**
	 * Clear a latched error and return to IDLE. Resets the retry budget.
	 *
	 * @return $this
	 */
	public function clear_errors() {
		$this->data['failure_count'] = 0;
		$this->data['error_message'] = '';
		$this->data['last_error']    = null;
		if ( self::STATE_ERROR === $this->data['state'] ) {
			$this->data['state'] = self::STATE_IDLE;
		}
		$this->save();
		return $this;
	}

	/**
	 * Reset the whole state back to defaults (used when data is wiped).
	 *
	 * @return $this
	 */
	public function reset() {
		$this->data = self::defaults();
		$this->save();
		return $this;
	}

	// --- Queries -------------------------------------------------------------

	public function get_state() {
		return $this->data['state'];
	}

	/**
	 * Whether a sync is currently in flight (PREP or ACTIVE) and not stale.
	 *
	 * @return bool
	 */
	public function is_running() {
		if ( ! in_array( $this->data['state'], array( self::STATE_PREP, self::STATE_ACTIVE ), true ) ) {
			return false;
		}
		return ! $this->is_stale();
	}

	/**
	 * Whether an in-flight run looks crashed (started too long ago). A stale run
	 * is not considered running, so a fresh sync can take over.
	 *
	 * @return bool
	 */
	public function is_stale() {
		if ( ! in_array( $this->data['state'], array( self::STATE_PREP, self::STATE_ACTIVE ), true ) ) {
			return false;
		}
		// Measure staleness from the last checkpoint, not the run's start: a
		// resumable sync legitimately spans many cron ticks, and each bounded
		// slice refreshes updated_at. Only a run that stopped making progress
		// (crashed / externally killed with no resume) goes stale and frees the
		// lock. Fall back to started_at for pre-cursor state payloads.
		$last_progress = ! empty( $this->data['updated_at'] ) ? (int) $this->data['updated_at'] : (int) $this->data['started_at'];
		if ( empty( $last_progress ) ) {
			return false;
		}
		return ( $this->now() - $last_progress ) > self::STALE_AFTER;
	}

	/**
	 * Whether a new sync may start: nothing running and not latched in ERROR.
	 *
	 * @return bool
	 */
	public function can_start() {
		if ( self::STATE_ERROR === $this->data['state'] ) {
			return false;
		}
		return ! $this->is_running();
	}

	public function has_error() {
		return self::STATE_ERROR === $this->data['state'] || ! empty( $this->data['error_message'] );
	}

	public function get_failure_count() {
		return (int) $this->data['failure_count'];
	}

	public function get_total_pages() {
		return (int) $this->data['total_pages'];
	}

	public function get_pages_completed() {
		return (int) $this->data['pages_completed'];
	}

	public function get_started_at() {
		return $this->data['started_at'];
	}

	/**
	 * How long the current run has been in flight, in seconds.
	 *
	 * Reported in the sync activity log next to STALE_AFTER, so a run that is
	 * holding the lock can be told apart from one that has genuinely crashed.
	 *
	 * @return int|null Null when no run has ever started.
	 */
	public function get_age() {
		if ( empty( $this->data['started_at'] ) ) {
			return null;
		}
		return $this->now() - (int) $this->data['started_at'];
	}

	public function get_last_success() {
		return $this->data['last_success'];
	}

	public function get_last_error() {
		return $this->data['last_error'];
	}

	public function get_error_message() {
		return $this->data['error_message'];
	}

	public function get_last_duration() {
		return $this->data['last_duration'];
	}

	/**
	 * Human-friendly label for the current state.
	 *
	 * @return string
	 */
	public function get_label() {
		$labels = array(
			self::STATE_IDLE      => 'Idle',
			self::STATE_PREP      => 'Preparing',
			self::STATE_ACTIVE    => 'Syncing',
			self::STATE_COMPLETED => 'Completed',
			self::STATE_ERROR     => 'Error',
		);
		return isset( $labels[ $this->data['state'] ] ) ? $labels[ $this->data['state'] ] : ucfirst( (string) $this->data['state'] );
	}

	/**
	 * Snapshot of the state for display in the admin screen.
	 *
	 * @return array
	 */
	public function get_summary() {
		return array(
			'state'           => $this->data['state'],
			'label'           => $this->get_label(),
			'total_pages'     => $this->get_total_pages(),
			'pages_completed' => $this->get_pages_completed(),
			'failure_count'   => $this->get_failure_count(),
			'max_retries'     => self::MAX_RETRIES,
			'last_success'    => $this->get_last_success(),
			'last_error'      => $this->get_last_error(),
			'error_message'   => $this->get_error_message(),
			'last_duration'   => $this->get_last_duration(),
			'has_error'       => $this->has_error(),
		);
	}
}
