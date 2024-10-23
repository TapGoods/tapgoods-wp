<?php

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
			return 'The last sync failed, please check your API Key and try again';
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
		$batch_size = 50;
		$progress = get_option('tg_last_sync_progress', array('location_id' => null, 'current_page' => 1));
		$client = $this->get_connection();
	
		// Force the removal of the previous lock
		delete_transient('tg_sync_lock');
	
		if (get_transient('tg_sync_lock')) {
			$this->console_log('Sync is locked, another process is running.');
			return array('success' => false, 'message' => 'Sync is currently in progress by another process.');
		}
	
		// Set the lock to prevent multiple executions
		set_transient('tg_sync_lock', true, 900);
		$this->console_log('Sync lock set for 15 minutes.');
	
		$location_ids = $client->get_location_ids();
		if (false === $location_ids) {
			error_log('Failed to retrieve location IDs');
			$this->console_log('Failed to retrieve location IDs.');
			delete_transient('tg_sync_lock');
			return array('success' => false, 'message' => 'Failed to retrieve location IDs');
		}
	
		$total_items = 0;
		$start_time = current_time('timestamp'); // Log the start of the synchronization
	
		// Get all existing tg_ids in WordPress before synchronization
		$existing_items = $this->get_all_existing_inventory_ids();
		$synced_items = [];
	
		foreach ($location_ids as $lid) {
			$current_page = 1;
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
				$this->console_log('Fetched ' . count($inventory) . ' items from location ID: ' . $lid . ', page: ' . $current_page);
	
				foreach ($inventory as $item) {
					$this->sync_inventory_item($item);
					$synced_items[] = $item['id']; // Save the IDs of synchronized items
				}
	
				if (count($inventory) < $batch_size) {
					$continue_fetching = false;
				} else {
					$current_page++;
					update_option('tg_last_sync_progress', array('location_id' => $lid, 'current_page' => $current_page));
					$this->console_log('Progress saved for location ID: ' . $lid . ', page: ' . $current_page);
				}
			}
		}
	
		// Remove items that are no longer in the endpoint
		$this->remove_missing_items_from_wordpress($existing_items, $synced_items);
	
		// Call the function to remove duplicates
		$this->remove_duplicate_items();
	
		// Save synchronization information
		$this->update_sync_info($start_time);
	
		// Update the final message in the database
		update_option('tg_last_sync_info', array(
			'last_sync_end' => current_time('timestamp'),
			'last_sync_duration' => current_time('timestamp') - $start_time
		));
	
		// Release the lock
		delete_transient('tg_sync_lock');
		delete_option('tg_last_sync_progress');
	
		$this->console_log('Sync process completed.');
		return array('success' => true, 'message' => '');
	}
	
	
	public function get_all_existing_inventory_ids() {
		global $wpdb;
	
		$query = "
			SELECT post_id, meta_value as tg_id 
			FROM {$wpdb->postmeta}
			WHERE meta_key = 'tg_id'
		";
	
		$results = $wpdb->get_results($query, ARRAY_A);
	
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
		$location_info = $this->sync_location_settings();
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

		$client       = $this->get_connection();
		$location_ids = $client->get_location_ids();

		if ( false === $location_ids ) {
			return false;
		}

		$count   = 0;
		foreach ( $location_ids as $lid ) {

			$current = 1;
			// We have to use the rest endpoint for now b/c the GQL endpoint doesn't respect popularity sort_order
			// the first request is just to get the 'meta' object to determine number of pages
			$response = $client->get_inventories_from_graph( $lid );

			if ( false === $response ) {
				return false;
			}

			$meta     = $response['metadata'];
			$tg_order = 0;

			while ( $meta['totalPages'] >= $current ) {

				$response = $client->get_inventories_from_graph( $lid, $current );

				if ( false === $response ) {
					return false;
				}

				$meta      = $response['metadata'];
				$inventory = $response['collection'];
				$inv_count = count( $inventory );

				foreach ( $inventory as $data ) {
					++$tg_order;

					// For now we just need the token so we can fetch each product individually and get all the information
					$token = $data['token'];
					$type  = $data['productType'];

					$update = $this->tg_update_inventory( $data, $tg_order );

					$this->tg_assign_terms( $update );

					++$count;
				}
				++$current;
			}
		}
		return $count;
	}

	public function tg_update_inventory( $product, $tg_order ) {

		// Search for an existing post by meta_key: token
		$args = array(
			'post_type'      => 'tg_inventory',
			'posts_per_page' => 1,
			'meta_key'       => 'tg_token', //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $product['token'], //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);

		$query = new WP_Query( $args );

		// If we have the post we just need the id otherwise the ID is 0
		$post_id = false;
		if ( count( $query->posts ) > 0 ) {
			$post_id = $query->posts[0]->ID;
		}

		$slug = str_replace( '_', '-', $product['slug'] );

		$meta    = array();
		$exclude = array( 'businessInfo', 'location', 'suppliers', 'slug' );
		foreach ( $product as $k => $v ) {

			if ( in_array( $k, $exclude, true ) ) {
				continue;
			}

			if ( null === $v ) {
				continue;
			}

			$key = 'tg_' . $k;

			$meta[ $key ] = $v;
		}
		$meta['tg_hash'] = $this->hash;

		$post_arr = array(
			'post_type'   => 'tg_inventory',
			'post_title'  => $product['name'],
			'post_name'   => $slug,
			'menu_order'  => $tg_order,
			'meta_input'  => $meta,
			'post_status' => 'publish',
		);

		// @TODO: Implement post content option setting, we can just use the shortcodes for now
		if ( 1 === get_option( 'tg_description_as_content' ) ) {
			$post_arr['post_content'] = $product['description'];
		}

		// If we're creating new posts use wp_insert_post
		if ( false === $post_id ) {
			$insert = wp_insert_post( $post_arr, true );
			return $insert;
		}

		// Otherwise, set the ID and use wp_update_post
		$post_arr['ID'] = $post_id;
		$update         = wp_update_post( $post_arr, true );
		return $update;
	}

	public function sync_categories_from_api() {

		$config = array(
			'cache_enabled' => true,
		);

		$client       = $this->get_connection( $config );
		$location_ids = $client->get_location_ids();

		if ( false === $location_ids ) {
			return false;
		}

		$cat_count = 0;
		$tag_count = 0;

		foreach ( $location_ids as $lid ) {
			$data = $client->get_categories_from_graph( $lid );

			// if we couldn't get the categories bail out b/c we cannot proceed
			if ( false === $data ) {
				return false;
			}

			update_option( "tg_categories_{$lid}", $data );

			foreach ( $data as $category ) {

				// setup category fields
				$tg_id = $category['id'];
				$subs  = $category['sfSubCategories'];
				$tax   = 'tg_category';

				$term = $this->tg_insert_or_update_term( $category, $tax );

				if ( false === $term ) {
					continue;
				}

				update_term_meta( $term['term_id'], 'tg_id', $tg_id );
				update_term_meta( $term['term_id'], 'tg_subcategories', $subs );
				update_term_meta( $term['term_id'], 'tg_hash', $this->hash );

				++$cat_count;

				if ( is_array( $subs ) && count( $subs ) > 0 ) {
					// tg_write_log( 'updating term: ' . $category['name'] );
					// tg_write_log( $subs );

					foreach ( $subs as $tag ) {

						$wp_tag = $this->tg_insert_or_update_term( $tag, 'tg_tags' );
						if ( false !== $wp_tag ) {
							update_term_meta( $wp_tag['term_id'], 'tg_id', $tag['id'] );
							update_term_meta( $wp_tag['term_id'], 'tg_sfCategories', $tag['sfCategories'] );
							// update_term_meta( $wp_tag['term_id'], 'tg_category_wp_id', $term['term_id'], false );
							// update_term_meta( $wp_tag['term_id'], 'tg_category_id', $tg_id, false );
							update_term_meta( $wp_tag['term_id'], 'tg_hash', $this->hash );
							++$tag_count;
						}
					}
				}
			}
		}
		return array(
			'cat_count' => $cat_count,
			'tag_count' => $tag_count,
		);
	}

	public function tg_insert_or_update_term( $term, $tax ) {

		// setup category fields
		$tg_id     = $term['id'];
		$name      = $term['name'];
		$slug      = ( array_key_exists( 'slug', $term ) ) ? str_replace( '_', '-', $term['slug'] ) : sanitize_title( $term['name'] );
		$term_args = array(
			'slug'        => $slug,
			// 'description' => $tg_id,
		);

		// find existing term by meta tg_id
		$query_args = array(
			'taxonomy'     => $tax,
			'hide_empty'   => false,
			'meta_key'     => 'tg_id',
			'meta_value'   => $tg_id,
			'meta_compare' => '=',
			'number'       => 1,
		);
		$term_query = get_terms( $query_args );

		if ( is_wp_error( $term_query ) ) {
			return false;
		}

		// update existing term or create new term
		if ( 0 === count( $term_query ) ) {
			// the term wasn't found, we want to insert a new term
			$wp_term = wp_insert_term( $name, $tax, $term_args );

			if ( is_wp_error( $wp_term ) ) {
				return false;
			}
		}

		if ( count( $term_query ) > 0 ) {

			$term_args['name'] = $name;

			$wp_term = wp_update_term( $term_query[0]->term_id, $tax, $term_args );

			if ( is_wp_error( $wp_term ) ) {
				return false;
			}
		}

		return $wp_term;
	}

	public function tg_assign_terms( $post_id ) {

		$tg_location    = get_post_meta( $post_id, 'tg_locationId', true );
		$wp_location_id = get_terms(
			array(
				'taxonomy'     => 'tg_location',
				'hide_empty'   => false,
				'number'       => 1,
				'meta_key'     => 'tg_id',
				'meta_value'   => $tg_location,
				'meta_compare' => '=',
				'fields'       => 'ids',
			)
		);

		wp_set_post_terms( $post_id, $wp_location_id, 'tg_location' );
		

		$tg_categories = get_post_meta( $post_id, 'tg_sfCategories', true );
		$category_ids  = array();

		if ( is_array( $tg_categories ) && count( $tg_categories ) > 0 ) {
			foreach ( $tg_categories as $category ) {
				$query_args = array(
					'taxonomy'     => 'tg_category',
					'hide_empty'   => false,
					'meta_key'     => 'tg_id',
					'meta_value'   => $category['id'],
					'meta_compare' => '=',
					'number'       => 1,
					'fields'       => 'ids',
				);
				$category   = get_terms( $query_args );

				if ( is_wp_error( $category ) || 0 === count( $category ) ) {
					continue;
				}

				if ( count( $category ) > 0 ) {
					$category_ids[] = $category[0];
				}

				if ( count( $category_ids ) > 0 ) {
					wp_set_post_terms( $post_id, $category_ids, 'tg_category' );
				}
			}
		}

		$tg_tags = get_post_meta( $post_id, 'tg_sfSubCategories', true );

		if ( is_array( $tg_tags ) && count( $tg_tags ) > 0 ) {

			$tag_str = implode( ', ', wp_list_pluck( $tg_tags, 'name' ) );

			$tag_ids = array();
			foreach ( $tg_tags as $tag ) {
				$query_args = array(
					'taxonomy'     => 'tg_tags',
					'hide_empty'   => false,
					'meta_key'     => 'tg_id',
					'meta_value'   => $tag['id'],
					'meta_compare' => '=',
					'number'       => 1,
					'fields'       => 'ids',
				);
				$tag        = get_terms( $query_args );

				if ( is_wp_error( $tag ) || 0 === count( $tag ) ) {
					continue;
				}

				if ( is_array( $tag ) && count( $tag ) > 0 ) {
					$tag_ids[] = $tag[0];
				}

				if ( count( $tag_ids ) > 0 ) {
					wp_set_post_terms( $post_id, $tag_ids, 'tg_tags' );
				}
			}
		}
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
        // Start manual synchronization without cron, using a step-based approach
        error_log('Starting manual inventory sync');
        $result = $this->sync_inventory_in_batches(false);
        return array(
            'success' => $result['success'],
            'message' => wpautop($result['message']),
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
		} catch (Exception $e) {
			$this->console_log('Error syncing item: ' . $item['id'] . ' - ' . $e->getMessage());
		}
	}
	
	
	// Function to remove duplicates after synchronization
	public function remove_duplicate_items() {
		global $wpdb;
	
		$duplicates_query = "
			SELECT meta_value AS tg_id, COUNT(*) as count, MIN(post_id) as keep_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = 'tg_id'
			GROUP BY meta_value
			HAVING count > 1
		";
	
		$duplicates = $wpdb->get_results($duplicates_query);
	
		foreach ($duplicates as $duplicate) {
			// Get duplicate post IDs (excluding the one we want to keep)
			$duplicate_ids_query = "
				SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = 'tg_id' AND meta_value = %s AND post_id != %d
			";
			$duplicate_ids = $wpdb->get_col($wpdb->prepare($duplicate_ids_query, $duplicate->tg_id, $duplicate->keep_id));
	
			// Delete the duplicate posts
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
		$post_arr = array(
			'ID' => $post_id,
			'post_title' => $item['name'],
			'meta_input' => $this->prepare_meta_input($item)
		);
		wp_update_post($post_arr);
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
		global $wpdb;
	
		// Direct verification in the database to prevent duplicates
		$existing_item_id = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tg_id' AND meta_value = %s LIMIT 1",
			$product['id']
		));
	
		if ($existing_item_id) {
			$this->console_log('Skipping creation: Item with tg_id ' . $product['id'] . ' already exists.');
			return $existing_item_id; // If it already exists, return the ID of the existing item
		}
	
		// Prepare the slug and metadata
		$slug = str_replace('_', '-', $product['slug']);
		$meta = $this->prepare_meta_input($product);
		$post_arr = array(
			'post_type' => 'tg_inventory',
			'post_title' => $product['name'],
			'post_name' => wp_unique_post_slug($slug, 0, 'publish', 'tg_inventory', 0),
			'meta_input' => $meta,
			'post_status' => 'publish',
		);
	
		if (1 === get_option('tg_description_as_content')) {
			$post_arr['post_content'] = $product['description'];
		}
	
		// Attempt to insert the item using `wp_insert_post`
		$insert = wp_insert_post($post_arr, true);
		
		// Check for errors during the insertion
		if (is_wp_error($insert)) {
			$this->console_log('Error inserting item: ' . $product['id'] . ' - ' . $insert->get_error_message());
			return false;
		}
	
		// Save the 'tg_id' as metadata
		update_post_meta($insert, 'tg_id', $product['id']);
	
		$this->console_log('Inserted new item with tg_id: ' . $product['id']);
		return $insert;
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
	
	public function console_log($message) {
		add_action('admin_footer', function() use ($message) {
			echo "<script>console.log('{$message}');</script>";
		});
	}
	
	public function manual_sync_trigger() {
		if (current_user_can('manage_options')) {
			$result = $this->sync_inventory_in_batches(true);
			wp_send_json($result);
		} else {
			wp_send_json(array('success' => false, 'message' => 'You do not have permission to access this endpoint.'));
		}
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
                            console.log(response.message);
                            $('#tapgoods_sync_status').text(response.message);
                            if (response.success && response.continue) {
                                setTimeout(function() {
                                    $('#tg_api_sync').click();
                                }, 1000); // Wait 1 second before continuing with the next request
                            } else {
                                console.log('Sync completed or stopped: ' + response.message);
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