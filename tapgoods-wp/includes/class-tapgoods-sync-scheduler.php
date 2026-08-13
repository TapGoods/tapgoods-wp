<?php
/**
 * Action Scheduler driver for the inventory sync.
 *
 * The inventory sync is processed one BOUNDED slice at a time by
 * Tapgoods_Connection::sync_inventory_in_batches() (see that class and
 * Tapgoods_Sync_State). This class is the layer that makes those slices run
 * back-to-back via Action Scheduler instead of one slice per five-minute cron
 * tick:
 *
 *   - one Action Scheduler action (hook self::HOOK) == exactly one slice;
 *   - a slice that leaves the run IN PROGRESS enqueues the next slice, so
 *     Action Scheduler chains them and the whole sync collapses in wall-clock
 *     time (Action Scheduler re-triggers its own queue runner in batches);
 *   - a slice that reaches COMPLETED or a latched ERROR enqueues nothing, so the
 *     chain stops cleanly and never storms on top of the state machine's own
 *     retry/error latch;
 *   - the five-minute WP-Cron becomes a WATCHDOG that only re-arms the chain
 *     (Tapgoods::tapgrein_cron_exec), it no longer runs sync work itself.
 *
 * RETRY OWNERSHIP: the state machine (Tapgoods_Sync_State) owns retries. The
 * Action Scheduler action always returns normally (run_slice() swallows any
 * stray Throwable after the connection has already routed the failure through
 * the state machine), so Action Scheduler never adds its own retry on top. A
 * transient failure drops the run back to IDLE for the watchdog to re-arm; an
 * exhausted retry budget latches ERROR and NOTHING re-enqueues until an admin
 * clears it.
 *
 * GRACEFUL FALLBACK: every method is a no-op (returns false) when the `as_*()`
 * functions are unavailable, so callers can fall back to the legacy self-ping
 * driver without fataling.
 *
 * The class calls only the public Action Scheduler API and the plugin's own
 * singletons, so it is unit-testable in isolation with the `as_*()` functions
 * stubbed via Brain\Monkey.
 *
 * @package Tapgoods\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Tapgoods_Sync_Scheduler {

	/** Action Scheduler hook: one action on this hook == one bounded sync slice. */
	const HOOK = 'tapgoods_sync_slice';

	/** Action Scheduler group, so the plugin's actions are easy to find/unschedule. */
	const GROUP = 'tapgoods-sync';

	/**
	 * Whether Action Scheduler is loaded and its API is callable.
	 *
	 * Everything this class does is gated on this, so a site where the bundled
	 * library is somehow missing simply falls back to the legacy driver instead
	 * of fataling.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& ( function_exists( 'as_has_scheduled_action' ) || function_exists( 'as_next_scheduled_action' ) );
	}

	/**
	 * Register the slice handler on the Action Scheduler hook.
	 *
	 * Called from the plugin's hook wiring (Tapgoods::define_general_hooks via the
	 * loader). Registering the callback is harmless even when Action Scheduler is
	 * not loaded (the action would simply never fire).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_slice' ) );
	}

	/**
	 * Enqueue a single slice IF one is not already pending or running.
	 *
	 * This is the guarded entry point used to START or RE-ARM the chain (the
	 * manual "Sync Now" button and the cron watchdog). The `as_has_scheduled_action`
	 * / `as_next_scheduled_action` guard is what stops duplicate slices from piling
	 * up when several triggers fire close together.
	 *
	 * NOTE: this guard treats a currently-RUNNING slice as "already scheduled", so
	 * it is deliberately NOT used to chain the next slice from inside a running one
	 * (that is chain_next_slice(), which does not self-block). See run_slice().
	 *
	 * @return bool True when a slice is enqueued or one was already scheduled;
	 *              false when Action Scheduler is unavailable.
	 */
	public static function enqueue_slice() {
		if ( ! self::is_available() ) {
			return false;
		}
		if ( self::has_scheduled_slice() ) {
			return true; // Already pending or running: do not pile up duplicates.
		}
		as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		return true;
	}

	/**
	 * Enqueue the NEXT slice from inside a running slice (chaining).
	 *
	 * Unlike enqueue_slice(), this does NOT consult the pending/running guard: the
	 * caller (run_slice) is itself the running action, so the guard would always
	 * see "one is running" and refuse, breaking the chain. Exactly one successor is
	 * created per finishing slice, and slices run sequentially (Action Scheduler +
	 * the connection's run-lock), so this cannot pile up.
	 *
	 * @return bool True when the next slice is enqueued; false when unavailable.
	 */
	public static function chain_next_slice() {
		if ( ! self::is_available() ) {
			return false;
		}
		as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		return true;
	}

	/**
	 * Action Scheduler handler: run exactly ONE bounded slice, then chain.
	 *
	 * Always returns normally. The connection already routes any sync failure
	 * through the state machine (Tapgoods_Sync_State), so a stray Throwable here is
	 * unexpected; it is logged and swallowed so Action Scheduler does not layer its
	 * own retry on top of the state machine's retry/error latch (see the class
	 * docblock, "RETRY OWNERSHIP").
	 *
	 * @return void
	 */
	public static function run_slice() {
		$connection = Tapgoods_Connection::get_instance();

		try {
			// One AS action == one bounded slice. $manual is false: chaining/UX is
			// owned here, not by the connection.
			$connection->sync_inventory_in_batches( false, 'as_slice' );
		} catch ( \Throwable $e ) { // phpcs:ignore
			// Defensive only: sync_inventory_in_batches catches its own errors.
			Tapgoods_Sync_Log::get_instance()->error(
				'sync.as.slice_exception',
				array( 'message' => $e->getMessage() )
			);
		}

		self::maybe_chain( $connection );
	}

	/**
	 * Enqueue the next slice only while the run is still in progress.
	 *
	 *   - PREP / ACTIVE (is_running) => work remains => chain the next slice.
	 *   - COMPLETED                  => the run finished => stop.
	 *   - ERROR (latched)            => stop; only an admin "clear errors" (or the
	 *                                   watchdog on a retryable IDLE) restarts it.
	 *   - IDLE after a retryable failure => stop chaining; the five-minute cron
	 *                                   watchdog re-arms it. Keeping retries off the
	 *                                   immediate chain is what avoids an AS retry
	 *                                   storm on a persistently failing API.
	 *
	 * @param Tapgoods_Connection $connection Connection singleton (for its state).
	 * @return bool True when the next slice was enqueued, false otherwise.
	 */
	public static function maybe_chain( $connection = null ) {
		if ( ! self::is_available() ) {
			return false;
		}
		if ( null === $connection ) {
			$connection = Tapgoods_Connection::get_instance();
		}

		$state = $connection->sync_state();
		if ( $state->is_running() ) {
			return self::chain_next_slice();
		}

		return false;
	}

	/**
	 * Cancel all of the plugin's scheduled slices.
	 *
	 * Called on plugin deactivation so a chain in flight does not keep firing.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Whether a slice is already pending or running.
	 *
	 * @return bool
	 */
	private static function has_scheduled_slice() {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::HOOK, null, self::GROUP );
		}
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return false !== as_next_scheduled_action( self::HOOK, null, self::GROUP );
		}
		return false;
	}
}
