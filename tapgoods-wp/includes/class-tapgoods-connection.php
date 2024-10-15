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

		$api_connected = get_option( 'tg_api_connected', false );

		if ( ! $api_connected ) {
			return 'The last sync failed, please check your API Key and try again';
		}

		$time      = current_time( 'timestamp' ); // phpcs:ignore
		$sync_info = get_option( 'tg_last_sync_info' );

		$time_ago     = $time - $sync_info['last_sync_end'];
		$time_ago_str = tg_seconds_to_string( $time_ago );
		$duration_str = tg_seconds_to_string( $sync_info['last_sync_duration'] );

		if ( ! $sync_info ) {
			return false;
		}

		$message = 'The last sync finished ' . $time_ago_str . " ago and took {$duration_str} seconds to run";
		return $message;
	}

	
	







	public function sync_location_settings() {

		$client = $this->get_connection();

		$location_ids = get_option( 'tg_locationIds', false );
		$business_id  = get_option( 'tg_businessId', false );

		// we should already be connected and have this info, but if we don't then try to validate the key and grab the ids one more time
		if ( empty( $location_ids ) || empty( $business_id ) ) {

			$business = $this->get_business();
			if ( false === $business ) {
				return false;
			}
			$location_ids = get_option( 'tg_locationIds', false );
			$business_id  = get_option( 'tg_businessId', false );
		}

		// if this fails, something else is wrong
		if ( false === $location_ids || false === $business_id ) {
			return false;
		}

		// We only check for the transient during sync because we want fresh information and update the long-lived option value
		$location_transient = $client->transient_name( 'location_info' );

		$location_info = get_transient( $location_transient );
		$location_info = false;

		if ( false === $location_info || '' === $location_info || null === $location_info ) {

			$location_info = array();
			$extra_config  = array(
				'cache_enabled' => false,
				'bid'           => $business_id,
			);
			$client->set_config( $extra_config );

			foreach ( $location_ids as $lid ) {

				$location_details = $client->get_location_details_from_graph( $lid );

				if ( false === $location_details ) {
					return false;
				}

				$location_details['slug'] = sanitize_title( $location_details['physicalAddress']['city'] . '-' . $location_details['physicalAddress']['locale'] );
				$location_details['display_locale'] = ucwords( $location_details['physicalAddress']['city'] ) . ', ' . strtoupper( $location_details['physicalAddress']['locale'] );

				$sf_shop_settings = (array) $location_details['storefrontShopSettings'];
				$settings_count   = count( $sf_shop_settings );

				if ( $settings_count > 1 ) {
					usort(
						$sf_shop_settings,
						function ( $a, $b ) {
							return $a['id'] < $b['id'] ? 1 : -1;
						}
					);
				}
				$location_details['storefrontShopSettings'] = current( $sf_shop_settings );

				$term = $this->tg_insert_or_update_term( $location_details, 'tg_location' );

				if ( false !== $term ) {
					$this->update_location_term_meta( $term['term_id'], $location_details );
					update_term_meta( $term['term_id'], 'tg_hash', $this->hash );
				}

				$location_info[ $lid ] = $location_details;
				update_option( 'tg_location_' . $lid, $location_details );
			}

			set_transient( $location_transient, $location_info, 300 );
			update_option( 'tg_location_settings', $location_info );
		}

		return $location_info;
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
        // Iniciar la sincronización manualmente, sin cron, con un enfoque basado en pasos
        error_log('Starting manual inventory sync');
        $result = $this->sync_inventory_in_batches(false);
        return array(
            'success' => $result['success'],
            'message' => wpautop($result['message']),
        );
    }

    public function sync_inventory_in_batches($manual_trigger = false) {
        $batch_size = 50; // Ajustar el tamaño del lote
        $progress = get_option('tg_last_sync_progress', array('location_id' => null, 'current_page' => 1));
        $client = $this->get_connection();

        // Verificar si hay un bloqueo de sincronización activo
        if (get_transient('tg_sync_lock')) {
            error_log('Sync is locked, another process is running.');
            return array('success' => false, 'message' => 'Sync is currently in progress by another process.');
        }

        // Establecer un bloqueo de sincronización
        set_transient('tg_sync_lock', true, 300); // Bloqueo válido por 5 minutos

        $location_ids = $client->get_location_ids();
        if (false === $location_ids) {
            tg_write_log('Failed to retrieve location IDs');
            error_log('Failed to retrieve location IDs');
            delete_transient('tg_sync_lock'); // Eliminar el bloqueo
            return array('success' => false, 'message' => 'Failed to retrieve location IDs');
        }

        $total_synced = 0;
        $start_syncing = false;

        foreach ($location_ids as $lid) {
            if (!$start_syncing && $progress['location_id'] !== null && $progress['location_id'] !== $lid) {
                continue;
            }
            $start_syncing = true;

            $current_page = ($progress['location_id'] === $lid) ? $progress['current_page'] : 1;

            // Registrar estado de sincronización
            error_log('Starting sync for location ID: ' . $lid . ', page: ' . $current_page);

            $response = $client->get_inventories_from_graph($lid, $current_page, $batch_size);
            if (false === $response) {
                tg_write_log('Failed to retrieve inventory for location ID: ' . $lid . ' on page ' . $current_page);
                error_log('Failed to retrieve inventory for location ID: ' . $lid . ' on page ' . $current_page);
                delete_transient('tg_sync_lock'); // Eliminar el bloqueo
                return array('success' => false, 'message' => 'Failed to retrieve inventory for location ID: ' . $lid . ' on page ' . $current_page);
            }

            if (empty($response['collection'])) {
                error_log('No inventory items found for location ID: ' . $lid . ' on page ' . $current_page);
                continue;
            }

            $inventory = $response['collection'];
            foreach ($inventory as $item) {
                try {
                    // Usar transiente para bloquear el proceso de creación del ítem
                    $lock_key = 'tg_item_lock_' . $item['id'];
                    if (get_transient($lock_key)) {
                        error_log('Skipping item with tg_id ' . $item['id'] . ' because it is already being processed.');
                        continue;
                    }
                    set_transient($lock_key, true, 300); // Bloqueo válido por 5 minutos

                    // Verificar si el ítem ya existe antes de actualizarlo o crearlo
                    $existing_item = $this->get_existing_inventory_item_by_tg_id($item['id']);
                    if ($existing_item) {
                        error_log('Item exists: updating item with tg_id ' . $item['id']);
                        $this->tg_update_inventory($item, $total_synced, $existing_item->ID);
                    } else {
                        error_log('Item does not exist: creating new item with tg_id ' . $item['id']);
                        $this->tg_insert_inventory($item, $total_synced);
                    }

                    $total_synced++;
                    error_log('Successfully synced item: ' . $item['id']);

                    // Eliminar el bloqueo del ítem
                    delete_transient($lock_key);
                } catch (Exception $e) {
                    tg_write_log('Error syncing item: ' . $item['id'] . ' - ' . $e->getMessage());
                    error_log('Error syncing item: ' . $item['id'] . ' - ' . $e->getMessage());
                    delete_transient($lock_key); // Asegurarse de eliminar el bloqueo en caso de error
                }
            }

            // Guardar el progreso cada 50 ítems
            update_option('tg_last_sync_progress', array('location_id' => $lid, 'current_page' => $current_page + 1));
            error_log('Progress saved: location ID ' . $lid . ', page ' . $current_page);

            // Romper después de cada lote de 50
            delete_transient('tg_sync_lock'); // Eliminar el bloqueo temporalmente
            return array('success' => true, 'message' => 'Synced ' . $total_synced . ' items so far. Continuing...', 'continue' => true);
        }

        // Si se completa la sincronización, eliminar el progreso
        delete_option('tg_last_sync_progress');
        delete_transient('tg_sync_lock'); // Eliminar el bloqueo
        error_log('Total inventory items synced: ' . $total_synced);

        return array('success' => true, 'message' => 'Total inventory items synced: ' . $total_synced, 'continue' => false);
    }

    // Método para obtener un ítem de inventario existente por tg_id
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
            error_log('Found existing item with tg_id: ' . $tg_id);
            return $query->posts[0];
        } else {
            error_log('No existing item found with tg_id: ' . $tg_id);
            return false;
        }
    }

    // Método para insertar un nuevo ítem de inventario
    public function tg_insert_inventory($product, $tg_order) {
        $slug = str_replace('_', '-', $product['slug']);
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

        // Intentar insertar y evitar duplicados mediante 'post_title' y 'tg_id'
        $post_arr = array(
            'post_type' => 'tg_inventory',
            'post_title' => $product['name'],
            'post_name' => $slug,
            'menu_order' => $tg_order,
            'meta_input' => $meta,
            'post_status' => 'publish',
        );

        if (1 === get_option('tg_description_as_content')) {
            $post_arr['post_content'] = $product['description'];
        }

        // Verificar si un post con el mismo título y tg_id ya existe
        $existing_post = $this->get_existing_inventory_item_by_tg_id($product['id']);
        if ($existing_post) {
            error_log('Skipping creation: Item with tg_id ' . $product['id'] . ' already exists.');
            return $existing_post->ID;
        }

        $insert = wp_insert_post($post_arr, true);
        if (is_wp_error($insert)) {
            error_log('Error inserting item: ' . $product['id'] . ' - ' . $insert->get_error_message());
        } else {
            error_log('Inserted new item with tg_id: ' . $product['id']);
        }
        return $insert;
    }

    // Endpoint para forzar la ejecución manual de la sincronización
    public function manual_sync_trigger() {
        if (current_user_can('manage_options')) {
            $result = $this->sync_inventory_in_batches(true);
            wp_send_json($result);
        } else {
            wp_send_json(array('success' => false, 'message' => 'You do not have permission to access this endpoint.'));
        }
    }
}

// Crear un endpoint para ejecutar manualmente la sincronización sin usar cron
add_action('wp_ajax_tapgoods_manual_sync', [Tapgoods_Connection::get_instance(), 'manual_sync_trigger']);
add_action('wp_ajax_nopriv_tapgoods_manual_sync', function() {
    wp_send_json(array('success' => false, 'message' => 'Unauthorized request.'));
});

// JavaScript para llamar al endpoint de sincronización de manera continua y actualizar el botón
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
                                }, 1000); // Esperar 1 segundo antes de continuar con la próxima solicitud
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

                // Iniciar la sincronización al hacer clic en el botón
                $('#tg_api_sync').on('click', function() {
                    syncInventory();
                });
            })(jQuery);
        </script>
        <?php
    }
});