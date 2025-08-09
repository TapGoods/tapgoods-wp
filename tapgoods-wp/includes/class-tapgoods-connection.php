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

		$env = ( defined( 'TG_ENV' ) ) ? TG_ENV : getenv_docker( 'tg_env', 'tapgoods.com' );
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
			$this->client->set_config( $configs );
			return $this->client;
		}

		$this->client = new Tapgoods_API_Client( $config );
		return $this->client;
	}

	public function get_key() {

		// if the API key is defined in code, use that, or default to empty string for key input
		$api_key = ( defined( 'TAPGOODS_KEY' ) ) ? TAPGOODS_KEY : '';

		// Otherwise check the options table for a saved key
		if ( '' === $api_key ) {
			$encryption    = new Tapgoods_Encryption();
			$encrypted_key = get_option( 'tg_key' );
			$api_key       = ( $encrypted_key ) ? $encryption->tg_decrypt( $encrypted_key ) : '';
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

	public function start_sync() {
		$this->hash         = wp_hash( current_time( 'mysql' ) );
		$this->is_active    = 1;
		$this->u_sync_start = current_time( 'timestamp' );

		do_action( 'tg_start_api_sync', $this->u_sync_start );

		set_transient( 'tg_sync_active', 1, 300 );
		set_transient( 'tg_u_sync_start', $this->u_sync_start, 60 );
	}

	public function is_active() {
		if ( null === $this->is_active ) {
			$this->is_active = get_transient( 'tg_sync_active' );
		}
		return $this->is_active;
	}

	public function stop_sync( $error = false, $message = '' ) {

		$this->is_active = 0;
		set_transient( 'tg_sync_active', 0 );

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
	
		// Check if the synchronization is in progress
		if (get_transient('tg_sync_lock')) {
			return 'Sync in progress. Please wait...';
		}
	
		// Get the last synchronization information
		$sync_info = get_option('tg_last_sync_info');
	
		// Verify that $sync_info is not false and contains the required keys
		if (!$sync_info || !isset($sync_info['last_sync_end']) || !isset($sync_info['last_sync_duration'])) {
			return 'No sync information available';
		}
	
		// Calculate elapsed time and duration
		$time = current_time('timestamp'); // phpcs:ignore
		$time_ago = $time - $sync_info['last_sync_end'];
		$time_ago_str = tg_seconds_to_string($time_ago);
		$duration_str = tg_seconds_to_string($sync_info['last_sync_duration']);
	
		// Build the last synchronization message
		$message = 'The last sync finished ' . $time_ago_str . " ago and took {$duration_str} seconds to run";
	
		return $message;
	}
	
	public function sync_inventory_in_batches($manual_trigger = false) {
		$current_key = $this->get_key();
		$stored_key = get_option('tg_last_api_key');
	
		// Handle API key change
		if ($current_key !== $stored_key) {
			$this->console_log('API Key has changed. Clearing previous data...');
			$this->tg_delete_data();
			update_option('tg_last_api_key', $current_key);
		}
	
		$batch_size = 50;
		$progress = get_option('tg_last_sync_progress', array('location_id' => null, 'current_page' => 1));
		$client = $this->get_connection();
	
		// Prevent concurrent syncs
		if (get_transient('tg_sync_lock')) {
			$this->console_log('Sync is locked. Another process is running.');
			return array('success' => false, 'message' => '');
		}
	
		// Lock the sync process
		set_transient('tg_sync_lock', true, 900);
	
		$location_ids = $client->get_location_ids();
		if (false === $location_ids) {
			delete_transient('tg_sync_lock');
			$this->console_log('Failed to retrieve location IDs.');
			return array('success' => false, 'message' => '');
		}
	
		$total_items = 0;
		$start_time = current_time('timestamp');
		$existing_items = $this->get_all_existing_inventory_ids();
		$synced_items = [];
	
		foreach ($location_ids as $lid) {
			$current_page = $progress['current_page'];
			$continue_fetching = true;
	
			while ($continue_fetching) {
				$response = $client->get_inventories_from_graph($lid, $current_page, $batch_size);
				if (false === $response || empty($response['collection'])) {
					$this->console_log('No more items to fetch for location ID: ' . $lid);
					$continue_fetching = false;
					continue;
				}
	
				$inventory = $response['collection'];
				$total_items += count($inventory);
	
				foreach ($inventory as $item) {
					$this->sync_inventory_item($item, false); // Skip assigning terms initially
					$synced_items[] = $item['id'];
				}
	
				if (count($inventory) < $batch_size) {
					$continue_fetching = false;
				} else {
					$current_page++;
					update_option('tg_last_sync_progress', array('location_id' => $lid, 'current_page' => $current_page));
				}
			}
		}
	
		// Sync categories and tags after items
		$this->console_log('Syncing categories and tags...');
		$categories_synced = $this->sync_categories_from_api();
	
		if ($categories_synced) {
			$this->console_log('Assigning categories and tags to items...');
			foreach ($synced_items as $tg_id) {
				$item = $this->get_existing_inventory_item_by_tg_id($tg_id);
				if ($item) {
					$this->tg_assign_terms($item->ID);
				}
			}
		} else {
			$this->console_log('Category and tag sync failed.');
		}
	
		// Cleanup after sync
		$this->remove_missing_items_from_wordpress($existing_items, $synced_items);
		$this->remove_unused_terms('tg_category');
		$this->remove_unused_terms('tg_tags');
		$this->remove_duplicate_items();
		$this->update_sync_info($start_time);
	
		// Unlock the process
		delete_transient('tg_sync_lock');
		delete_option('tg_last_sync_progress');
	
		return array('success' => true, 'message' => '');
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

	public function remove_missing_items_from_wordpress($existing_items, $synced_items) {
		$items_to_remove = array_diff(array_keys($existing_items), $synced_items);
	
		foreach ($items_to_remove as $tg_id) {
			$post_id = $existing_items[$tg_id];
			wp_delete_post($post_id, true);
			$this->console_log('Removed missing item with tg_id: ' . $tg_id . ', post ID: ' . $post_id);
		}
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
		$this->console_log('Starting function sync_location_settings');
	
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
				$this->console_log('No business found. Exiting the function.');
				return false;
			}
	
			$location_ids = get_option('tg_locationIds', false);
			$business_id = get_option('tg_businessId', false);
	//		$this->console_log('After get_business, location_ids: ' . print_r($location_ids, true));
	//		$this->console_log('After get_business, business_id: ' . print_r($business_id, true));
		}
	
		if (false === $location_ids || false === $business_id) {
			$this->console_log('location_ids or business_id are invalid.');
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
					$this->console_log('Error fetching location details for location_id: ' . $location_id);
					continue;
				}
	
				// Guarda los datos en el transient
				set_transient($location_transient, $location_details, 300);
			}
	
			// Verifica que los datos obtenidos sean para el ID correcto
			if (!isset($location_details['id']) || $location_details['id'] != $location_id) {
				$this->console_log('Mismatch in location_id for location_details. Skipping.');
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
		$default_location = get_option('tg_default_location', false);
		if (empty($default_location) || !in_array($default_location, $location_ids)) {
			$this->console_log('Default location is not set or invalid. Setting to the first location_id.');
			$new_default_location = reset($location_ids);
			update_option('tg_default_location', $new_default_location);
			$this->console_log('Updated tg_default_location to: ' . $new_default_location);
		}
	
		$this->console_log('Completed sync_location_settings function.');
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
					$update = $this->tg_update_inventory($data, $tg_order);
	
					// Assign locations to the item
					$this->console_log('Assigning location for item: ' . $data['token']);
					$this->tg_assign_location($update, $lid);  // <-- New feature to assign location
	
					// Assign terms (categories, tags)
					$this->tg_assign_terms($update);
	
					++$count;
				}
				++$current;
			}
		}
		return $count;
	}
	
	public function tg_assign_location($post_id, $location_id) {
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
	
	

	public function tg_update_inventory($product, $tg_order) {
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
			$this->tg_assign_terms($post_id);
		} else {
			$this->console_log('Failed to assign terms: post ID is invalid');
		}
	
		return $post_id;
	}
	
	
	
	
	
	public function sync_categories_from_api() {
		$this->console_log('Starting sync_categories_from_api...');
		
		$client = $this->get_connection();
		$location_ids = $client->get_location_ids();
	
		if (false === $location_ids || is_wp_error($location_ids)) {
			$this->console_log('Failed to retrieve location IDs.');
			return false;
		}
	
		$valid_category_ids = [];
		$valid_tag_ids = [];
	
		foreach ($location_ids as $lid) {
			$categories = $client->get_categories_from_graph($lid);
	
			if (false === $categories || is_wp_error($categories)) {
				$this->console_log("Failed to fetch categories for location ID: {$lid}");
				continue;
			}
	
			foreach ($categories as $category) {
				$category_term = $this->tg_insert_or_update_term($category, 'tg_category');
				if ($category_term) {
					$valid_category_ids[] = $category_term;
				}
	
				// Process subcategories as tags.
				if (!empty($category['sfSubCategories'])) {
					foreach ($category['sfSubCategories'] as $tag) {
						$tag_term = $this->tg_insert_or_update_term($tag, 'tg_tags');
						if ($tag_term) {
							$valid_tag_ids[] = $tag_term;
						}
					}
				}
			}
		}
	
		// Remove obsolete terms.
		$this->remove_obsolete_terms('tg_category', $valid_category_ids);
		$this->remove_obsolete_terms('tg_tags', $valid_tag_ids);
	
		$this->console_log('Categories and tags synchronized successfully.');
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
	
	

	

	public function tg_insert_or_update_term($term, $tax) {
		$tg_id = $term['id']; // The unique ID of the term from the external source.
		$name = $term['name']; // The name of the term.
		$slug = str_replace('_', '-', $term['slug'] ?? sanitize_title($term['name'])); // Generate a sanitized slug.
	
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
			$this->console_log("Term exists for tg_id: $tg_id (ID: {$existing_term[0]})");
			return $existing_term[0]; // Return the existing term ID.
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
		$this->console_log("Inserted term: $name with tg_id: $tg_id and taxonomy: $tax");
	
		return $term_id; // Return the new term ID.
	}
	
	
	
	
	
	
	
	
	

	public function tg_assign_terms($post_id) {
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
	
	
	
	
	
	
	public function remove_unused_terms($taxonomy) {
		$this->console_log("Starting cleanup for unused terms in taxonomy: $taxonomy");
	
		// Fetch all terms in the taxonomy.
		$terms = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false, // Include terms even if they're not assigned to posts.
		]);
	
		if (is_wp_error($terms)) {
			$this->console_log("Error fetching terms for taxonomy: $taxonomy - " . $terms->get_error_message());
			return;
		}
	
		foreach ($terms as $term) {
			// Check if the term is assigned to any posts.
			$term_count = $term->count;
	
			if ($term_count === 0) {
				// Delete the term if it has no assignments.
				$deleted = wp_delete_term($term->term_id, $taxonomy);
	
				if (is_wp_error($deleted)) {
					$this->console_log("Error deleting term ID: {$term->term_id} - " . $deleted->get_error_message());
				} else {
					$this->console_log("Deleted unused term ID: {$term->term_id}, name: {$term->name}");
				}
			}
		}
	
		$this->console_log("Finished cleanup for taxonomy: $taxonomy");
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
	

	public function tg_async_sync_from_api( $action = 'tg_api_sync' ) {

		$url = admin_url( "admin-ajax.php?action={$action}" );

		$args = array(
			'blocking'  => false,
			'sslverify' => false,
			'timeout'   => 1,
		);

		wp_remote_get( $url, $args );
		return;
	}

	public function tg_delete_data() {
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

		$taxonomies = Tapgoods_Post_Types::tg_get_taxonomies();
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
	public function tg_remove_deleted_inventory() {

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
	public function tg_remove_deleted_terms() {

		// If there's no hash, bail
		if ( is_null( $this->hash ) ) {
			return false;
		}

		$count = 0;

		$taxonomies = Tapgoods_Post_Types::tg_get_taxonomies();
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

	public function tg_get_colors_from_rest() {
	}

	public function tg_get_departments_from_api() {
	}


	public function sync_from_api() {
		$this->console_log('Starting full sync');
	
		// Sync categories and tags
		$categories_result = $this->sync_categories_from_api();
	//	$this->console_log('Categories sync result: ' . print_r($categories_result, true));
	
		// Sync inventory
		$inventory_result = $this->sync_inventory_in_batches(false);
	//	$this->console_log('Inventory sync result: ' . print_r($inventory_result, true));
	
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
				$this->tg_insert_inventory($item);
			}
	
			// Assign categories and tags to the item.
			$this->tg_assign_terms($existing_item_by_id ? $existing_item_by_id->ID : $item['id']);
		} catch (Exception $e) {
			$this->console_log('Error syncing item: ' . $item['id'] . ' - ' . $e->getMessage());
		}
	}
	
	
	
	// Function to remove duplicates after synchronization
	public function remove_duplicate_items() {
		global $wpdb;
	
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
				$this->console_log('Removed duplicate item with post ID: ' . $post_id);
			}
		}
	
		$this->console_log('Duplicate removal process completed.');
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
			$this->tg_assign_terms($post_id); // Assign categories and tags after updating the post
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
	
	public function tg_insert_inventory($product) {
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
		$this->console_log('Manual sync:1205'); 
		$location_info = $this->sync_location_settings(true);
//		$this->console_log('Resultado de sync_location_settings en sync_from_api: ' . print_r($location_info, true));
	
		if (current_user_can('manage_options')) {
			$result = $this->sync_inventory_in_batches(true);
			wp_send_json($result);
		} else {
			wp_send_json(array('success' => false, 'message' => 'You do not have permission to access this endpoint.'));
		}
	}
	

	public function console_log($message) {
//		error_log($message); // Log to PHP error log
	}

	

}
	// Add action for synchronization via AJAX
	add_action('wp_ajax_tg_api_sync', array(Tapgoods_Connection::get_instance(), 'manual_sync_trigger'));
	
// Create an endpoint to manually execute synchronization without using cron
add_action('wp_ajax_tapgoods_manual_sync', [Tapgoods_Connection::get_instance(), 'manual_sync_trigger']);
add_action('wp_ajax_nopriv_tapgoods_manual_sync', function() {
    wp_send_json(array('success' => false, 'message' => 'Unauthorized request.'));
});

// JavaScript to call the sync endpoint continuously and update the button
add_action('admin_footer', function() {
    if (current_user_can('manage_options')) {
        ?>
        <button id="tg_api_sync">SYNC</button>
        <span id="tapgoods_sync_status"></span>
        <script type="text/javascript">
            (function($) {
                function syncInventory() {
                    $('#tg_api_sync').prop('disabled', true).text('WORKING');
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'tapgoods_manual_sync'
                        },
                        success: function(response) {
                            $('#tapgoods_sync_status').text(response.message);
                            if (response.success && response.continue) {
                                setTimeout(function() {
                                    $('#tg_api_sync').click();
                                }, 1000); // Wait 1 second before continuing with the next request
                            } else {
                             //   console.log('Sync completed or stopped: ' + response.message);
                                $('#tg_api_sync').prop('disabled', false).text('SYNC');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error during sync: ' + error);
                            $('#tapgoods_sync_status').text('Error during sync: ' + error);
                            $('#tg_api_sync').prop('disabled', false).text('SYNC');
                        }
                    });
                }

                // Start synchronization when the button is clicked
                $('#tg_api_sync').on('click', function() {
                    syncInventory();
                });
            })(jQuery);
        </script>
        <?php
    }
});