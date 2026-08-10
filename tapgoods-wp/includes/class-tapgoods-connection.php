<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods_Connection {

	private static $instance = null;

	private $client = null;
	protected $key;

	private $is_active      = null;
	private $hash           = null;
	private $u_sync_start   = null;
	private $u_sync_end     = null;
	private $sync_duration  = null;
	private $last_sync_time = null;

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
	 * Page through the whole inventory and write it into WordPress.
	 *
	 * @param bool        $manual_trigger Whether an administrator asked for this run.
	 * @param string|null $trigger        Which entry point started the run, for the
	 *                                    activity log. $manual_trigger cannot answer
	 *                                    that: four of the five paths into this
	 *                                    method pass false (the five-minute cron
	 *                                    self-ping, the daily tg_auto_sync_event, the
	 *                                    frontend 24h fallback and the public
	 *                                    execute_manual_sync AJAX endpoint), so every
	 *                                    caller names itself instead. Known names:
	 *                                    admin_manual, cron_selfping, cron_daily,
	 *                                    frontend_fallback, ajax_manual, ajax_nopriv,
	 *                                    admin_ajax_sync, unknown.
	 * @return array
	 */
	public function sync_inventory_in_batches($manual_trigger = false, $trigger = null) {
		$log = $this->sync_log();
		if (null === $trigger) {
			$trigger = $manual_trigger ? 'admin_manual' : 'unknown';
		}
		$log->begin_run($trigger, array('entry' => 'sync_inventory_in_batches', 'manual' => $manual_trigger ? 1 : 0));

		$current_key = $this->get_key();
		$stored_key = get_option('tg_last_api_key');

		// Handle API key change
		if ($current_key !== $stored_key) {
			// Never the key itself, only that it changed and what that cost.
			$log->warn('sync.key.changed', array('action' => 'cleared_local_data'));
			$this->tapgrein_delete_data();
			update_option('tg_last_api_key', $current_key);
		}

        $batch_size = 50;
		$client = $this->get_connection();
		$state  = $this->sync_state();

		// Prevent concurrent syncs. The state machine (PREP/ACTIVE) is the lock.
		if ($state->is_running()) {
			// This early exit performs no state transition, so it leaves no trace
			// anywhere else: without this line a site stuck behind a run that never
			// finished looks silent, which is exactly the WPB-165 report.
			$log->warn(
				'sync.run.locked',
				array(
					'reason'        => 'already_running',
					'state'         => $state->get_state(),
					'age_s'         => $state->get_age(),
					'pages'         => $state->get_pages_completed() . '/' . $state->get_total_pages(),
					'stale_after_s' => Tapgoods_Sync_State::STALE_AFTER,
				)
			);
			$log->end_run('skipped', array('state' => $state->get_state()));
			return array('success' => true, 'message' => 'Sync in progress. It can take up to 60 minutes for larger inventory. Please do not close the window until the sync is complete.', 'in_progress' => true);
		}

		// SYNC PREP: enter the state machine and figure out how many paging
		// calls the full inventory sync will require.
		$state->begin_prep();

		$location_ids = $client->get_location_ids();
		if (false === $location_ids) {
			$log->error('sync.api.error', array('op' => 'get_location_ids', 'message' => 'Failed to retrieve location information.'));
			$state->mark_error('Failed to retrieve location information.');
			$log->end_run('error', array('stage' => 'prep'));
			return array('success' => false, 'message' => 'Failed to retrieve location information.');
		}

		$log->info('sync.prep.locations', array('count' => count($location_ids)));

		$prep_started = microtime(true);
		$total_pages = $this->compute_total_sync_pages($client, $location_ids, $batch_size);
		$state->set_total_pages($total_pages);
		$log->info('sync.prep.pages', array('total_pages' => $total_pages, 'elapsed_ms' => self::elapsed_ms($prep_started)));

		// SYNC ACTIVE: page through inventory and write it into WordPress.
		$state->mark_active();

		$total_items = 0;
		$start_time = current_time('timestamp');
		$existing_items = $this->get_all_existing_inventory_ids();
		$synced_items = [];
		$removed_items = 0;
		$removed_terms = 0;
		$removed_duplicates = 0;
		$fetch_failures = 0;

		try {
			foreach ($location_ids as $lid) {
				// Reiniciar paginación por cada ubicación
				$current_page = 1;
				$continue_fetching = true;

				while ($continue_fetching) {
					$page_started = microtime(true);
					$response = $client->get_inventories_from_graph($lid, $current_page, $batch_size);
					if (false === $response) {
						// A failed fetch is NOT an empty page: every item this page
						// would have returned is about to look "missing" to the
						// removal pass below. Logged separately on purpose (WPB-165).
						++$fetch_failures;
						$log->warn(
							'sync.batch.fetch_failed',
							array(
								'location'   => $lid,
								'page'       => $current_page,
								'reason'     => 'false_response',
								'elapsed_ms' => self::elapsed_ms($page_started),
							)
						);
						$continue_fetching = false;
						continue;
					}
					if (empty($response['collection'])) {
						$log->info(
							'sync.batch.end',
							array(
								'location'   => $lid,
								'page'       => $current_page,
								'reason'     => 'empty_collection',
								'elapsed_ms' => self::elapsed_ms($page_started),
							)
						);
						$continue_fetching = false;
						continue;
					}

					$inventory = $response['collection'];
					$page_items = count($inventory);
					$total_items += $page_items;
					$state->increment_pages_completed();

					foreach ($inventory as $item) {
						$this->sync_inventory_item($item);
						$synced_items[] = $item['id'];
					}

					// `items` is this page only; `total_items` is the running total.
					$log->info(
						'sync.batch.page',
						array(
							'location'    => $lid,
							'page'        => $current_page,
							'items'       => $page_items,
							'total_items' => $total_items,
							'elapsed_ms'  => self::elapsed_ms($page_started),
						)
					);

					if ($page_items < $batch_size) {
						$continue_fetching = false;
					} else {
						$current_page++;
					}
				}
			}

			// Sync categories and tags after items. Note this is the SECOND category
			// pass when the run came in through sync_from_api(); the `pass` field
			// makes that visible. Removing the duplicate work belongs to WPB-165.
			$categories_synced = $this->sync_categories_from_api('post_inventory');

			if ($categories_synced) {
				$assigned = 0;
				foreach ($synced_items as $tg_id) {
					$item = $this->get_existing_inventory_item_by_tg_id($tg_id);
					if ($item) {
						$this->tapgrein_assign_terms($item->ID);
						++$assigned;
					}
				}
				$log->info('sync.categories.assigned', array('items' => $assigned, 'of' => count($synced_items)));
			}

			// Cleanup after sync
			$removed_items = $this->remove_missing_items_from_wordpress($existing_items, $synced_items);
			$removed_terms = $this->remove_unused_terms('tg_category');
			$removed_terms += $this->remove_unused_terms('tg_tags');
			$removed_duplicates = $this->remove_duplicate_items();
			$this->update_sync_info($start_time);
		} catch (Exception $e) {
			$log->error('sync.error', array('stage' => 'inventory', 'message' => $e->getMessage()));
			$will_retry = $state->mark_error($e->getMessage());
			$log->warn(
				'sync.retry',
				array(
					'will_retry' => $will_retry ? 1 : 0,
					'failures'   => $state->get_failure_count(),
					'state'      => $state->get_state(),
				)
			);
			$log->end_run(
				'error',
				array(
					'items' => $total_items,
					'pages' => $state->get_pages_completed() . '/' . $state->get_total_pages(),
					'stage' => 'inventory',
				)
			);
			return array('success' => false, 'message' => 'Sync failed: ' . $e->getMessage());
		}

		// SYNC COMPLETED
		$state->mark_completed();

		// The single run-end line. Everything here is observed, not reported: page
		// counts come from the state machine, not from a return value.
		$log->end_run(
			'ok',
			array(
				'items'              => $total_items,
				'pages'              => $state->get_pages_completed() . '/' . $state->get_total_pages(),
				'state'              => $state->get_state(),
				'fetch_failures'     => $fetch_failures,
				'items_removed'      => $removed_items,
				'terms_removed'      => $removed_terms,
				'duplicates_removed' => $removed_duplicates,
			)
		);

		// Check if anything was actually synced
		if ($total_items === 0) {
			return array('success' => true, 'message' => 'Everything is up to date. Nothing to sync.');
		}

		return array('success' => true, 'message' => '');
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
		$terms = get_terms(array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		));
	
		if (empty($terms) || is_wp_error($terms)) {
			$this->console_log("No terms found for taxonomy: $taxonomy.");
			return false;
		}
	
		return true;
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
				$log->error('sync.location.error', array('reason' => 'no_business', 'elapsed_ms' => self::elapsed_ms($settings_started)));
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
					$log->warn('sync.location.fetch_failed', array('location' => $location_id));
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
	 * @param string $pass Which pass this is, for the activity log. A run entered
	 *                     through sync_from_api() does this twice per run
	 *                     ('pre_inventory' then 'post_inventory'); the duplicated
	 *                     work is pre-existing and belongs to WPB-165.
	 * @return bool
	 */
	public function sync_categories_from_api($pass = 'standalone') {
		$log = $this->sync_log();
		$started = microtime(true);
		$log->info('sync.categories.start', array('pass' => $pass));

		$client = $this->get_connection();
		$location_ids = $client->get_location_ids();

		if (false === $location_ids || is_wp_error($location_ids)) {
			$log->error('sync.api.error', array('op' => 'get_location_ids', 'stage' => 'categories', 'pass' => $pass));
			$log->info('sync.categories.done', array('pass' => $pass, 'ok' => 0, 'elapsed_ms' => self::elapsed_ms($started)));
			return false;
		}

		$valid_category_ids = [];
		$valid_tag_ids = [];

		foreach ($location_ids as $lid) {
			$categories = $client->get_categories_from_graph($lid);

			if (false === $categories || is_wp_error($categories)) {
				$log->warn('sync.categories.fetch_failed', array('location' => $lid, 'pass' => $pass));
				continue;
			}

			foreach ($categories as $category) {
				$category_term_id = $this->tg_insert_or_update_term($category, 'tg_category');
				if ($category_term_id) {
					$valid_category_ids[] = $category_term_id;

					// Process subcategories as tags and link them to their parent category
					if (!empty($category['sfSubCategories'])) {
						foreach ($category['sfSubCategories'] as $tag) {
							// Pass the parent category term ID to establish the relationship
							$tag_term_id = $this->tg_insert_or_update_term($tag, 'tg_tags', $category_term_id);
							if ($tag_term_id) {
								$valid_tag_ids[] = $tag_term_id;
							}
						}
					}
				}
			}
		}
	
		// Remove obsolete terms.
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
	
	
	
	
	
	public function remove_obsolete_terms($taxonomy, $valid_ids) {
		$existing_terms = get_terms(array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		));
	
		foreach ($existing_terms as $term_id) {
			if (!in_array($term_id, $valid_ids)) {
				wp_delete_term($term_id, $taxonomy);
				$this->console_log("Removed obsolete term ID: {$term_id} from taxonomy: {$taxonomy}");
			}
		}
	}
	
	
	
	
	public function remove_missing_terms($taxonomy, $valid_ids) {
		$existing_terms = get_terms(array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		));
	
		foreach ($existing_terms as $term_id) {
			$tg_id = get_term_meta($term_id, 'tg_id', true);
			$tg_hash = get_term_meta($term_id, 'tg_hash', true);
	
			// Si el término tiene un tg_id o tg_hash válido, no lo elimines
			if (in_array($tg_id, $valid_ids) || $tg_hash === $this->hash) {
				continue;
			}
	
			wp_delete_term($term_id, $taxonomy);
			$this->console_log("Removed term ID: {$term_id} from taxonomy: {$taxonomy}");
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

		// Fetch all terms in the taxonomy.
		$terms = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false, // Include terms even if they're not assigned to posts.
		]);

		if (is_wp_error($terms)) {
			$log->error('sync.cleanup.terms_error', array('taxonomy' => $taxonomy, 'message' => $terms->get_error_message()));
			return 0;
		}

		$removed = 0;

		foreach ($terms as $term) {
			// Check if the term is assigned to any posts.
			$term_count = $term->count;

			if ($term_count === 0) {
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

		$log->info('sync.cleanup.terms_removed', array('taxonomy' => $taxonomy, 'count' => $removed, 'total' => count($terms)));

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
	 * Full sync: categories then inventory. The cron self-ping entry point.
	 *
	 * @param string $trigger Which entry point started the run (activity log).
	 * @return array
	 */
	public function sync_from_api( $trigger = 'unknown' ) {
		$log = $this->sync_log();
		$log->begin_run($trigger, array('entry' => 'sync_from_api'));

		// Sync categories and tags. sync_inventory_in_batches() does this again
		// below; both passes are logged with a `pass` field so the duplicated work
		// is visible. Removing it belongs to WPB-165.
		$categories_result = $this->sync_categories_from_api('pre_inventory');

		// Sync inventory
		$inventory_result = $this->sync_inventory_in_batches(false, $trigger);

		// This method returns success unconditionally (see below), so the log must
		// not believe it. Compare against what was actually observed and record the
		// disagreement: that contrast is the WPB-165 signal.
		$state       = $this->sync_state();
		$observed_ok = ( ! empty($inventory_result['success']) && ! $state->has_error() );

		if ( ! $observed_ok ) {
			$log->warn(
				'sync.result.mismatch',
				array(
					'reported' => 'success',
					'observed' => $state->has_error() ? 'error' : 'failed',
					'state'    => $state->get_state(),
					'message'  => $state->get_error_message(),
				)
			);
		}

		$log->end_run(
			$observed_ok ? 'ok' : 'error',
			array('categories_pre' => $categories_result ? 1 : 0)
		);

		return array(
			'success' => true,
			'message' => 'Sync completed successfully.',
		);
	}
	
	public function sync_inventory_item($item) {
		try {
			$existing_item_by_id = $this->get_existing_inventory_item_by_tg_id($item['id']);
			
			if ($existing_item_by_id) {
				$this->console_log('Updating item with tg_id: ' . $item['id'] . ' (' . $item['name'] . ')');
				$this->update_inventory_item($existing_item_by_id->ID, $item);
			} else {
				$this->console_log('Inserting new item: ' . $item['name']);
				$this->tapgrein_insert_inventory($item);
			}
	
			// Assign categories and tags to the item.
			$this->tapgrein_assign_terms($existing_item_by_id ? $existing_item_by_id->ID : $item['id']);
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
				array('location_settings' => $location_info ? 1 : 0)
			);

			// Ensure we're sending the correct response format
			if ($result['success']) {
				wp_send_json_success($result['message']);
			} else {
				wp_send_json_error($result['message']);
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
			return;
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