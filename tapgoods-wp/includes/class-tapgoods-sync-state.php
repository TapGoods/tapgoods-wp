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
		);
	}

	private function load() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	private function save() {
		$this->data['updated_at'] = $this->now();
		update_option( self::OPTION, $this->data );
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
		$this->save();
		return $this;
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
		if ( empty( $this->data['started_at'] ) ) {
			return false;
		}
		return ( $this->now() - (int) $this->data['started_at'] ) > self::STALE_AFTER;
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
