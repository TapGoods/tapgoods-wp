<?php
/**
 * Post Types
 *
 * Registers post types and taxonomies
 *
 * @package Tapgoods\Includes\Post-Type
 * @version 0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post Types Class.
 */
class Tapgoods_Post_Types {

	public static function init() {
		// Register Taxonomy first so that URL rewrites behave as expected.
		add_action( 'init', array( __CLASS__, 'tg_register_taxonomies' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'add_meta_boxes_tg_inventory', array( __CLASS__, 'add_inventory_metabox' ) );
		add_filter( 'post_type_link', array( __CLASS__, 'tg_filter_post_type_link' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'redirect_to_custom_edit_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_custom_edit_page' ) );
		// $this->loader->add_action( 'init', $this, 'tg_add_rewrites', 10, 0 );

		// add_action( 'tg_tags_add_form_fields', array( __CLASS__, 'tg_tags_metaboxes' ) );
		add_action( 'tg_tags_edit_form', array( __CLASS__, 'tg_tags_metaboxes' ), 1, 1 );
		add_action( 'tg_category_edit_form', array( __CLASS__, 'tg_tags_metaboxes' ), 1, 1 );
		add_action( 'tg_location_edit_form', array( __CLASS__, 'tg_tags_metaboxes' ), 1, 1 );
		add_action( 'add_meta_boxes_tg_location', array( __CLASS__, 'add_taxonomy_metabox' ) );
	}
	public static function redirect_to_custom_edit_page() {
		global $pagenow;
	
		if ( $pagenow === 'post.php' && isset( $_GET['post'] ) && 'tg_inventory' === get_post_type( $_GET['post'] ) ) {
			// Redirect to the custom page.
			wp_safe_redirect( admin_url( 'admin.php?page=custom-edit-page&post=' . intval( $_GET['post'] ) ) );
			exit;
		}
	}
	
	public static function register_custom_edit_page() {
		add_submenu_page(
			'options-general.php', // Valid but irrelevant parent_slug.
			'Edit Item', // Page title.
			'', // Menu title (empty to not display it).
			'edit_posts', // Required capability.
			'custom-edit-page', // Unique slug for the page.
			array( __CLASS__, 'render_custom_edit_page' ) // Callback that renders the page.
		);
	}
	
	public static function render_custom_edit_page() {
		if ( isset( $_GET['post'] ) && 'tg_inventory' === get_post_type( $_GET['post'] ) ) {
			$post_id = intval( $_GET['post'] );
			$post = get_post( $post_id );
	
			if ( ! $post ) {
				echo 'Item not found.';
				return;
			}
	
			// Handle saving the custom field
			if ( isset( $_POST['tg_custom_description'] ) && check_admin_referer( 'save_tg_custom_description', 'tg_custom_description_nonce' ) ) {
				update_post_meta( $post_id, 'tg_custom_description', wp_kses_post( $_POST['tg_custom_description'] ) );
				echo '<div class="notice notice-success"><p>Custom description updated.</p></div>';
			}
	
			// Retrieve the current value of the custom field
			$custom_description = get_post_meta( $post_id, 'tg_custom_description', true );
	
			echo '<h1>' . esc_html( $post->post_title ) . '</h1>';
			echo '<form method="post">';
			wp_nonce_field( 'save_tg_custom_description', 'tg_custom_description_nonce' );
	
			echo '<h3>Custom Description</h3>';
			wp_editor(
				$custom_description,
				'tg_custom_description', // Unique ID for the editor
				array(
					'textarea_name' => 'tg_custom_description',
					'media_buttons' => true, // Show buttons to add media
					'textarea_rows' => 10,
					'teeny'         => false, // Use a more compact editor if true
				)
			);
	
			echo '<p><input type="submit" class="button button-primary" value="Save Description"></p>';
			echo '</form>';
	
			echo '<h3>General Information</h3>';
			echo '<div>';
			echo '<p><strong>Description:</strong> ' . esc_html( $post->post_content ) . '</p>';
	
			$meta = get_post_meta( $post_id );
			if ( ! empty( $meta ) ) {
				echo '<h3>Metadata:</h3><ul>';
				foreach ( $meta as $key => $value ) {
					echo '<li><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( maybe_unserialize( $value[0] ) ) . '</li>';
				}
				echo '</ul>';
			}
	
			echo '</div>';
		} else {
			echo '<p>Invalid or not found item.</p>';
		}
	}

	public static function tg_get_taxonomies() {
		$tg_taxonomies = array(
			'tg_category',
			'tg_tags',
			'tg_location',
			'tg_inventory_type',
			'tg_inventory_colors',
		);
		return $tg_taxonomies;
	}

	public static function tg_register_taxonomies() {

		if ( ! is_blog_installed() ) {
			return false;
		}

		if ( taxonomy_exists( 'tg_category' ) ) {
			Tapgoods_Helpers::tgqm( 'exit tax exists' );
			return false;
		}

		// Register TapGoods Categories
		do_action( 'tg_register_categories' );
		$permalinks = tg_get_permalink_structure();

		$tg_category = register_taxonomy(
			'tg_category',
			apply_filters( 'tapgoods_taxonomy_objects_tg_category', array( 'tg_inventory', 'tg_bundle', 'tg_accessory' ) ),
			apply_filters(
				'tg_category_args',
				array(
					'label'                 => 'categories',
					'labels'                => array(
						'name'                       => _x( 'Categories', 'Taxonomy General Name', 'tapgoods-wp' ),
						'singular_name'              => _x( 'Category', 'Taxonomy Singular Name', 'tapgoods-wp' ),
						'menu_name'                  => __( 'Categories', 'tapgoods-wp' ),
						'all_items'                  => __( 'All Categories', 'tapgoods-wp' ),
						'parent_item'                => __( 'Parent Category', 'tapgoods-wp' ),
						'parent_item_colon'          => __( 'Parent Category:', 'tapgoods-wp' ),
						'new_item_name'              => __( 'New Category Name', 'tapgoods-wp' ),
						'add_new_item'               => __( 'Add New Category', 'tapgoods-wp' ),
						'edit_item'                  => __( 'Edit Category', 'tapgoods-wp' ),
						'update_item'                => __( 'Update Category', 'tapgoods-wp' ),
						'view_item'                  => __( 'View Category', 'tapgoods-wp' ),
						'separate_items_with_commas' => __( 'Separate categories with commas', 'tapgoods-wp' ),
						'add_or_remove_items'        => __( 'Add or remove categories', 'tapgoods-wp' ),
						'choose_from_most_used'      => __( 'Choose from the most used', 'tapgoods-wp' ),
						'popular_items'              => __( 'Popular Categories', 'tapgoods-wp' ),
						'search_items'               => __( 'Search Categories', 'tapgoods-wp' ),
						'not_found'                  => __( 'Not Found', 'tapgoods-wp' ),
						'no_terms'                   => __( 'No items', 'tapgoods-wp' ),
						'items_list'                 => __( 'Categories list', 'tapgoods-wp' ),
						'items_list_navigation'      => __( 'Categories list navigation', 'tapgoods-wp' ),
					),
					'hierarchical'          => true,
					'public'                => true,
					'show_ui'               => true,
					'show_admin_column'     => true,
					'show_in_nav_menus'     => true,
					'show_tagcloud'         => false,
					'rewrite'               => array(
						'slug'         => $permalinks['tg_category_rewrite_slug'],
						'with_front'   => false,
						'hierarchical' => false,
					),
					'show_in_rest'          => true,
					'rest_base'             => 'tg_category',
					'rest_controller_class' => 'WP_REST_Terms_Controller',
					 'capabilities'          => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'do_not_allow' // Prevent editing
					 ),
				)
			)
		);
		do_action( 'tg_after_register_categories', $tg_category );

		// Register TapGoods Tags
		do_action( 'tg_register_tags' );
		$tg_tags = register_taxonomy(
			'tg_tags',
			apply_filters( 'tapgoods_taxonomy_objects_tg_tags', array( 'tg_inventory', ' tg_bundle' ) ),
			apply_filters(
				'tapgoods_taxonomy_args_tg_tags',
				array(
					'labels'                => array(
						'name'                       => _x( 'Tags', 'Taxonomy General Name', 'tapgoods-wp' ),
						'singular_name'              => _x( 'Tag', 'Taxonomy Singular Name', 'tapgoods-wp' ),
						'menu_name'                  => __( 'Tags', 'tapgoods-wp' ),
						'all_items'                  => __( 'All Tags', 'tapgoods-wp' ),
						'parent_item'                => __( 'Parent Category', 'tapgoods-wp' ),
						'parent_item_colon'          => __( 'Parent Category:', 'tapgoods-wp' ),
						'new_item_name'              => __( 'New Tag Name', 'tapgoods-wp' ),
						'add_new_item'               => __( 'Add New Tag', 'tapgoods-wp' ),
						'edit_item'                  => __( 'Edit Tag', 'tapgoods-wp' ),
						'update_item'                => __( 'Update Tag', 'tapgoods-wp' ),
						'view_item'                  => __( 'View Tag', 'tapgoods-wp' ),
						'separate_items_with_commas' => __( 'Separate tags with commas', 'tapgoods-wp' ),
						'add_or_remove_items'        => __( 'Add or remove tags', 'tapgoods-wp' ),
						'choose_from_most_used'      => __( 'Choose from the most used tags', 'tapgoods-wp' ),
						'popular_items'              => __( 'Popular Tags', 'tapgoods-wp' ),
						'search_items'               => __( 'Search Tags', 'tapgoods-wp' ),
						'not_found'                  => __( 'Not Found', 'tapgoods-wp' ),
						'no_terms'                   => __( 'No items', 'tapgoods-wp' ),
						'items_list'                 => __( 'Tags list', 'tapgoods-wp' ),
						'items_list_navigation'      => __( 'Tags list navigation', 'tapgoods-wp' ),
					),
					'hierarchical'          => false,
					'public'                => true,
					'show_ui'               => true,
					'show_admin_column'     => true,
					'show_in_nav_menus'     => true,
					'show_tagcloud'         => false,
					'rewrite'               => array(
						'slug'         => $permalinks['tg_tags_rewrite_slug'],
						'with_front'   => false,
						'hierarchical' => false,
					),
					'show_in_rest'          => true,
					'rest_base'             => 'tg_tag',
					'rest_controller_class' => 'WP_REST_Terms_Controller',
					'capabilities'          => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'do_not_allow', // Prevent editing
						'delete_terms' => true,
						'add_terms'    => false,
					),
				)
			)
		);
		do_action( 'tg_after_register_tags', $tg_tags );

		do_action( 'tg_register_location' );
		$tg_location = register_taxonomy(
			'tg_location',
			apply_filters( 'tapgoods_taxonomy_objects_location', array( 'tg_inventory' ) ),
			apply_filters(
				'tapgoods_taxonomy_args_location',
				array(
					'hierarchical'      => false,
					'show_ui'           => false,
					'show_in_nav_menus' => false,
					'query_var'         => is_admin(),
					'rewrite'           => false,
					'public'            => false,
					'label'             => _x( 'Locations', 'Taxonomy name', 'tapgoods-wp' ),
					'labels'            => array(
						'name'                       => _x( 'TG Locations', 'Taxonomy General Name', 'tapgoods-wp' ),
						'singular_name'              => _x( 'Location', 'Taxonomy Singular Name', 'tapgoods-wp' ),
						'menu_name'                  => __( 'Locations', 'tapgoods-wp' ),
						'all_items'                  => __( 'All Locations', 'tapgoods-wp' ),
						'parent_item'                => __( 'Parent Location', 'tapgoods-wp' ),
						'parent_item_colon'          => __( 'Parent Location:', 'tapgoods-wp' ),
						'new_item_name'              => __( 'New Location Name', 'tapgoods-wp' ),
						'add_new_item'               => __( 'Add New Location', 'tapgoods-wp' ),
						'edit_item'                  => __( 'Edit Location', 'tapgoods-wp' ),
						'update_item'                => __( 'Update Location', 'tapgoods-wp' ),
						'view_item'                  => __( 'View Location', 'tapgoods-wp' ),
						'separate_items_with_commas' => __( 'Separate Locations with commas', 'tapgoods-wp' ),
						'add_or_remove_items'        => __( 'Add or remove Locations', 'tapgoods-wp' ),
						'choose_from_most_used'      => __( 'Choose from the most used Locations', 'tapgoods-wp' ),
						'popular_items'              => __( 'Popular Locations', 'tapgoods-wp' ),
						'search_items'               => __( 'Search Locations', 'tapgoods-wp' ),
						'not_found'                  => __( 'Not Found', 'tapgoods-wp' ),
						'no_terms'                   => __( 'No Locations', 'tapgoods-wp' ),
						'items_list'                 => __( 'Locations list', 'tapgoods-wp' ),
						'items_list_navigation'      => __( 'Locations list navigation', 'tapgoods-wp' ),
					),
					'capabilities'          => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'do_not_allow' // Prevent editing
					 ),
				)
			)
		);
		do_action( 'tg_after_register_location', $tg_location );

		do_action( 'tg_register_product_type' );
		$tg_inventory_type = register_taxonomy(
			'tg_inventory_type',
			apply_filters( 'tapgoods_taxonomy_objects_inventory_type', array( 'inventory' ) ),
			apply_filters(
				'tapgoods_taxonomy_args_inventory_type',
				array(
					'hierarchical'      => false,
					'show_ui'           => false,
					'show_in_nav_menus' => false,
					'query_var'         => is_admin(),
					'rewrite'           => false,
					'public'            => false,
					'label'             => _x( 'Product type', 'Taxonomy name', 'tapgoods-wp' ),
				)
			)
		);
		do_action( 'tg_after_register_inventory_type', $tg_inventory_type );

		do_action( 'tg_register_inventory_colors' );
		$tg_inventory_colors = register_taxonomy(
			'tg_inventory_colors',
			apply_filters( 'tapgoods_taxonomy_objects_inventory_colors', array( 'inventory' ) ),
			apply_filters(
				'tapgoods_taxonomy_args_inventory_colors',
				array(
					'hierarchical'      => false,
					'show_ui'           => false,
					'show_in_nav_menus' => false,
					'query_var'         => is_admin(),
					'rewrite'           => false,
					'public'            => false,
					'label'             => _x( 'Product colors', 'Taxonomy name', 'tapgoods-wp' ),
				)
			)
		);
		do_action( 'tg_after_register_inventory_colors', $tg_inventory_colors );
	}

	public static function register_post_types() {
		if ( ! is_blog_installed() || post_type_exists( 'tg_inventory' ) ) {
			return;
		}

		$permalinks = tg_get_permalink_structure();
		$supports = array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'publicize', 'wpcom-markdown', 'page-attributes' );

		if ( 'yes' === get_option( 'tapgoods', 'no' ) ) {
			$supports[] = 'comments';
		}

		do_action( 'tapgoods_register_post_type_inventory' );
		$tg_inventory = register_post_type(
			'tg_inventory',
			apply_filters(
				'tapgoods_register_post_type_inventory',
				array(
					'label'               => __( 'Item', 'tapgoods-wp' ),
					'labels'              => array(
						'name'                  => _x( 'Items', 'Post Type General Name', 'tapgoods-wp' ),
						'singular_name'         => _x( 'Item', 'Post Type Singular Name', 'tapgoods-wp' ),
						'menu_name'             => __( 'Items', 'tapgoods-wp' ),
						'name_admin_bar'        => __( 'Inventory', 'tapgoods-wp' ),
						'archives'              => __( 'Item Archives', 'tapgoods-wp' ),
						'attributes'            => __( 'Item Attributes', 'tapgoods-wp' ),
						'parent_item_colon'     => __( 'Parent Item:', 'tapgoods-wp' ),
						'all_items'             => __( 'All Items', 'tapgoods-wp' ),
						'add_new_item'          => __( 'Add New Item', 'tapgoods-wp' ),
						'add_new'               => __( 'Add New', 'tapgoods-wp' ),
						'new_item'              => __( 'New Item', 'tapgoods-wp' ),
						'edit_item'             => __( 'Edit Item', 'tapgoods-wp' ),
						'update_item'           => __( 'Update Item', 'tapgoods-wp' ),
						'view_item'             => __( 'View Item', 'tapgoods-wp' ),
						'view_items'            => __( 'View Items', 'tapgoods-wp' ),
						'search_items'          => __( 'Search Item', 'tapgoods-wp' ),
						'not_found'             => __( 'Not found', 'tapgoods-wp' ),
						'not_found_in_trash'    => __( 'Not found in Trash', 'tapgoods-wp' ),
						'featured_image'        => __( 'Featured Image', 'tapgoods-wp' ),
						'set_featured_image'    => __( 'Set featured image', 'tapgoods-wp' ),
						'remove_featured_image' => __( 'Remove featured image', 'tapgoods-wp' ),
						'use_featured_image'    => __( 'Use as featured image', 'tapgoods-wp' ),
						'insert_into_item'      => __( 'Insert into item', 'tapgoods-wp' ),
						'uploaded_to_this_item' => __( 'Uploaded to this item', 'tapgoods-wp' ),
						'items_list'            => __( 'Items list', 'tapgoods-wp' ),
						'items_list_navigation' => __( 'Items list navigation', 'tapgoods-wp' ),
						'filter_items_list'     => __( 'Filter items list', 'tapgoods-wp' ),
					),
					'description'         => __( 'TapGoods Inventory', 'tapgoods-wp' ),
					'supports'            => $supports,
					'taxonomies'          => array( 'tg_category', 'tg_tags' ),
					'hierarchical'        => false,
					'public'              => true,
					'show_ui'             => true,
					'show_in_menu'        => true,
					'menu_position'       => 65,
					'menu_icon'           => 'dashicons-screenoptions',
					'show_in_admin_bar'   => true,
					'show_in_nav_menus'   => true,
					'can_export'          => true,
					'has_archive'         => true,
					'exclude_from_search' => false,
					'publicly_queryable'  => true,
					'query_var'           => 'inventory',
					'rewrite'             => $permalinks['tg_inventory_rewrite_slug'] ? array(
						'slug'       => $permalinks['tg_inventory_rewrite_slug'],
						'with_front' => false,
						'feeds'      => false,
					) : false,
					'capability_type'     => 'page',
					'capabilities'        => array(
						'create_posts' => false,
					),
					'map_meta_cap'        => true,
					'show_in_rest'        => true,
				)
			)
		);
		do_action( 'tapgoods_after_register_post_type_inventory', $tg_inventory );

		// Move to fix rewrites
		// add_rewrite_rule( '^shop/([^/]*)?$', 'index.php?inventory=$matches[1]&tg_category=$matches[1]', 'top' );
	}

	
	
	public static function tg_filter_post_type_link( $link, $post ) {
		if ( 'tg_inventory' === $post->post_type ) {
			// Try Yoast Primary Category First
			if ( function_exists( 'yoast_get_primary_term_id' ) ) {
				$primary_term_id = yoast_get_primary_term_id( 'tg_category', $post->ID );
				// Tapgoods_Helpers::tgqm( $primary_term_id );

				if ( false !== $primary_term_id ) {
					$term = get_term( $primary_term_id );
					if ( ! is_wp_error( $term ) && ! empty( $term ) ) {
						$link = str_replace( '%tg_category%', $term->slug, $link );
					} else {
						$link = str_replace( '%tg_category%/', '', $link );
					}
				}
			}

			$cats = get_the_terms( $post->ID, 'tg_category' );
			if ( false !== $cats && ! is_wp_error( $cats ) ) {
				$link = str_replace( '%tg_category%', current( $cats )->slug, $link );
			} else {
				$link = str_replace( '%tg_category%/', '', $link );
			}
		}
		return $link;
	}

	public static function add_inventory_metabox() {
		add_meta_box(
			'inventory_info',
			'Inventory Information',
			__CLASS__ . '::render_inventory_metabox',
			null,
			'advanced'
		);
	}

	public static function add_taxonomy_metabox() {
		add_meta_box(
			'taxonomy_info',
			'TapGoods Fields',
			__CLASS__ . '::tg_tags_metaboxes',
			'edit-tg_location',
			'normal',
			'low',
		);
	}

	public static function render_inventory_metabox() {
		// @TODO: move this into a partial tempalte?
		// @TODO: metaboxes for taxonomy relationships?

		global $post;
		$meta = get_post_meta( $post->ID );
		$tg_meta = array_filter( $meta, array( 'Tapgoods_Post_Types', 'tg_filter_postmeta' ), ARRAY_FILTER_USE_KEY );

		// Tapgoods_Helpers::tgpp( $tg_meta );

		?>
		<div class="container inside">
			<h4><strong>TapGoods Data</strong></h4>
			<?php
			foreach ( $tg_meta as $k => $values ) {
				if ( null === $values[0] ) {
					continue;
				}
				echo self::tg_handle_postmeta( $k, $values ); //phpcs:ignore
			}
			?>
		</div>
		<?php
	}

	public static function tg_get_inventory_meta() {
		global $post;
		$meta = get_post_meta( $post->ID );
		$tg_meta = array_filter( $meta, array( 'Tapgoods_Post_Types', 'tg_filter_postmeta' ), ARRAY_FILTER_USE_KEY );
	}

	public static function tg_filter_postmeta( $key ) {
		if ( 0 === strpos( $key, 'tg_' ) ) {
			return true;
		}
		return false;
	}

	public static function tg_handle_postmeta( $k, $values ) {
		if ( null === $values[0] ) {
			return false;
		}
		$output = '';
		$skip   = false;
		ob_start();
		echo "<p><strong>" . esc_html( $k ) . ": </strong></p>";
		foreach ( $values as $v ) {
			$value = maybe_unserialize( $v );
			if ( is_array( $value ) && empty( $value ) ) {
				$skip = true;
				continue;
			}
			if ( 'string' === gettype( $value ) ) {
				if ( '' === $value ) {
					$skip = true;
					continue;
				}
				echo $value . '</p>'; //phpcs:ignore
				continue;
			}
			if ( 'integer' === gettype( $value ) ) {
				echo $value . '</p>'; //phpcs:ignore
				continue;
			}
			if ( 'tg_pictures' === $k ) {
				echo '</p>';
				self::tg_print_image_preview( $value );
				continue;
			}
			if ( is_array( $value ) && ! empty( $value ) ) {
				echo '</p>';
				self::print_array_list_recursive( $value );
				continue;
			}
		}
		?>
		<?php
		if ( $skip ) {
			ob_end_clean();
			return false;
		}
		return ob_get_clean();
	}

	public static function print_array_list_recursive( $arr, $printout = true ) {
		if ( ! is_array( $arr ) || empty( $arr ) ) {
			return false;
		}
		ob_start();
		echo '<ul>';
		foreach ( $arr as $k => $v ) {
			if ( is_array( $v ) && ! empty( $v ) ) {
				self::print_array_list_recursive( $v, $printout );
			} else {
				echo '<li>' . $k . ': ' . $v . '</li>'; //phpcs:ignore
			}
		}
		echo '</ul>';
		if ( ! $printout ) {
			return ob_get_clean();
		}
		echo ob_get_clean(); //phpcs:ignore
	}

	public static function tg_print_image_preview( $images ) {
		foreach ( $images as $img ) {
			echo '<li><a href="' . esc_url( $img['url'] ) . '" target="_blank"><img src="' . esc_url( $img['url'] ) . '" height="200"></a></li>';
			echo '<p>URL: ' . esc_url( $img['url'] ) . '</p></li>';
		}
	}

	public function tg_add_rewrites() {
		global $wp_rewrite;
		$permastruct_args = array();
		$wp_rewrite->add_rewrite_tag( '%tg_category%', '([^&]+)', 'tg_category=' );
	}

	public static function tg_tags_metaboxes( $term ) {
		$meta = get_term_meta( $term->term_id );
		do_meta_boxes( 'edit-tg_location', 'normal', $term );
		?>
		<div class="metabox">
			<?php // Tapgoods_Helpers::tgpp( $meta ); ?>
			<ul>
			<?php foreach ( $meta as $k => $v ) : ?>
				<?php echo esc_html( self::tg_handle_postmeta( $k, $v ) ); ?>
			<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Customize taxonomies update messages.
	 *
	 * @param array $messages The list of available messages.
	 * @since 0.1.0
	 * @return bool
	 */
	public static function updated_term_messages( $messages ) {
		$messages['tg_category'] = array(
			0 => '',
			1 => __( 'Category added.', 'tapgoods-wp' ),
			2 => __( 'Category deleted.', 'tapgoods-wp' ),
			3 => __( 'Category updated.', 'tapgoods-wp' ),
			4 => __( 'Category not added.', 'tapgoods-wp' ),
			5 => __( 'Category not updated.', 'tapgoods-wp' ),
			6 => __( 'Categories deleted.', 'tapgoods-wp' ),
		);

		$messages['tg_tags'] = array(
			0 => '',
			1 => __( 'Tag added.', 'tapgoods-wp' ),
			2 => __( 'Tag deleted.', 'tapgoods-wp' ),
			3 => __( 'Tag updated.', 'tapgoods-wp' ),
			4 => __( 'Tag not added.', 'tapgoods-wp' ),
			5 => __( 'Tag not updated.', 'tapgoods-wp' ),
			6 => __( 'Tags deleted.', 'tapgoods-wp' ),
		);

		$messages['tg_location'] = array(
			0 => '',
			1 => __( 'Location added.', 'tapgoods-wp' ),
			2 => __( 'Location deleted.', 'tapgoods-wp' ),
			3 => __( 'Location updated.', 'tapgoods-wp' ),
			4 => __( 'Location not added.', 'tapgoods-wp' ),
			5 => __( 'Location not updated.', 'tapgoods-wp' ),
			6 => __( 'Locations deleted.', 'tapgoods-wp' ),
		);
	}

	/**
	 * Flush rules if the event is queued.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( 'yes' === get_option( 'tapgoods_queue_flush_rewrite_rules' ) ) {
			update_option( 'tapgoods_queue_flush_rewrite_rules', 'no' );
			self::flush_rewrite_rules();
		}
	}

	/**
	 * Flush rewrite rules.
	 */
	public static function flush_rewrite_rules() {
		flush_rewrite_rules();
	}

	/**
	 * Add Product Support to Jetpack Omnisearch.
	 */
	public static function support_jetpack_omnisearch() {
		if ( class_exists( 'Jetpack_Omnisearch_Posts' ) ) {
			new Jetpack_Omnisearch_Posts( 'tg_inventory' );
		}
	}
}

// Function to modify the number of 'tg_inventory' items displayed per page in the admin list view
// The number of items per page can be controlled via a URL parameter 'items_per_page', defaulting to 20 if not set.

function modify_tg_inventory_items_per_page( $per_page, $post_type ) {
    if ( 'tg_inventory' === $post_type ) {
        // Check if a parameter is passed via URL, otherwise use 20 as the default value.
        $per_page = isset( $_GET['items_per_page'] ) ? (int) $_GET['items_per_page'] : 20;
    }
    return $per_page;
}
add_filter( 'edit_posts_per_page', 'modify_tg_inventory_items_per_page', 10, 2 );

// Add 'Location' column to admin table for 'tg_inventory' before 'Date' column
add_filter('manage_tg_inventory_posts_columns', 'add_location_column_to_inventory');
function add_location_column_to_inventory($columns) {
    $new_columns = [];

    // Rearrange columns and insert 'Location' before 'Date'
    foreach ($columns as $key => $value) {
        if ($key === 'date') {
            $new_columns['location'] = __('Location', 'tapgoods-wp');
        }
        $new_columns[$key] = $value;
    }

    return $new_columns;
}


// Fill the 'Location' column with the ID and name of the specific location of the item
add_action('manage_tg_inventory_posts_custom_column', 'fill_location_column_in_inventory', 10, 2);
function fill_location_column_in_inventory($column, $post_id) {
    if ($column === 'location') {
        // Get the ID of the location associated with the current item
        $location_id = get_post_meta($post_id, 'tg_locationId', true);

        // If location_id is empty, assign a default ID (eg. 0)
        if (empty($location_id)) {
            $location_id = 0; // Default ID
        }

        // Debugging log
       // error_log("Post ID: {$post_id}, Location ID retrieved: " . print_r($location_id, true));

        // Get location details from 'tg_location_ID'
        $location_data = maybe_unserialize(get_option("tg_location_{$location_id}"));

        // Log para verificar datos de la ubicación
        //error_log("Location Data for ID {$location_id}: " . print_r($location_data, true));

        // Verify that the information has been obtained correctly
        if (!empty($location_data)) {
            $location_name = isset($location_data['fullName']) ? $location_data['fullName'] : "Location {$location_id}";
            echo esc_html("{$location_id} - {$location_name}");
        } else {
            echo esc_html__( 'Location details not found', 'tapgoods-wp' );
        }
    }
}







Tapgoods_Post_Types::init();
