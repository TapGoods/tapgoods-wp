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

		// $this->loader->add_action( 'init', $this, 'tg_add_rewrites', 10, 0 );
	}



	public static function tg_get_taxonomies() {
		$tg_taxonomies = array(
			'tg_category',
			'tg_tags',
		);
		return $tg_taxonomies;
	}

	public static function tg_register_taxonomies() {

		if ( ! is_blog_installed() ) {
			return;
		}

		if ( taxonomy_exists( 'tg_category' ) ) {
			Tapgoods_Helpers::tgqm( 'exit tax exists' );
			return;
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
						'name'                       => _x( 'Categories', 'Taxonomy General Name', 'tapgoods' ),
						'singular_name'              => _x( 'Category', 'Taxonomy Singular Name', 'tapgoods' ),
						'menu_name'                  => __( 'Categories', 'tapgoods' ),
						'all_items'                  => __( 'All Categories', 'tapgoods' ),
						'parent_item'                => __( 'Parent Category', 'tapgoods' ),
						'parent_item_colon'          => __( 'Parent Category:', 'tapgoods' ),
						'new_item_name'              => __( 'New Category Name', 'tapgoods' ),
						'add_new_item'               => __( 'Add New Category', 'tapgoods' ),
						'edit_item'                  => __( 'Edit Category', 'tapgoods' ),
						'update_item'                => __( 'Update Category', 'tapgoods' ),
						'view_item'                  => __( 'View Category', 'tapgoods' ),
						'separate_items_with_commas' => __( 'Separate categories with commas', 'tapgoods' ),
						'add_or_remove_items'        => __( 'Add or remove categories', 'tapgoods' ),
						'choose_from_most_used'      => __( 'Choose from the most used', 'tapgoods' ),
						'popular_items'              => __( 'Popular Categories', 'tapgoods' ),
						'search_items'               => __( 'Search Categories', 'tapgoods' ),
						'not_found'                  => __( 'Not Found', 'tapgoods' ),
						'no_terms'                   => __( 'No items', 'tapgoods' ),
						'items_list'                 => __( 'Categories list', 'tapgoods' ),
						'items_list_navigation'      => __( 'Categories list navigation', 'tapgoods' ),
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
						'delete_terms' => false,
						'assign_terms' => false,
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
						'name'                       => _x( 'TG Tags', 'Taxonomy General Name', 'tapgoods' ),
						'singular_name'              => _x( 'Tag', 'Taxonomy Singular Name', 'tapgoods' ),
						'menu_name'                  => __( 'Tags', 'tapgoods' ),
						'all_items'                  => __( 'All Tags', 'tapgoods' ),
						'parent_item'                => __( 'Parent Category', 'tapgoods' ),
						'parent_item_colon'          => __( 'Parent Category:', 'tapgoods' ),
						'new_item_name'              => __( 'New Tag Name', 'tapgoods' ),
						'add_new_item'               => __( 'Add New Tag', 'tapgoods' ),
						'edit_item'                  => __( 'Edit Tag', 'tapgoods' ),
						'update_item'                => __( 'Update Tag', 'tapgoods' ),
						'view_item'                  => __( 'View Tag', 'tapgoods' ),
						'separate_items_with_commas' => __( 'Separate tags with commas', 'tapgoods' ),
						'add_or_remove_items'        => __( 'Add or remove tags', 'tapgoods' ),
						'choose_from_most_used'      => __( 'Choose from the most used tags', 'tapgoods' ),
						'popular_items'              => __( 'Popular Tags', 'tapgoods' ),
						'search_items'               => __( 'Search Tags', 'tapgoods' ),
						'not_found'                  => __( 'Not Found', 'tapgoods' ),
						'no_terms'                   => __( 'No items', 'tapgoods' ),
						'items_list'                 => __( 'Tags list', 'tapgoods' ),
						'items_list_navigation'      => __( 'Tags list navigation', 'tapgoods' ),
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
						'delete_terms' => false,
						'add_terms'    => false,
					),
				)
			)
		);
		do_action( 'tg_after_register_tags', $tg_tags );

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
					'label'             => _x( 'Product type', 'Taxonomy name', 'tapgoods' ),
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
					'label'             => _x( 'Product colors', 'Taxonomy name', 'tapgoods' ),
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
		$supports = array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'publicize', 'wpcom-markdown' );

		if ( 'yes' === get_option( 'tapgoods', 'no' ) ) {
			$supports[] = 'comments';
		}

		do_action( 'tapgoods_register_post_type_inventory' );
		$tg_inventory = register_post_type(
			'tg_inventory',
			apply_filters(
				'tapgoods_register_post_type_inventory',
				array(
					'label'               => __( 'Item', 'tapgoods' ),
					'labels'              => array(
						'name'                  => _x( 'Items', 'Post Type General Name', 'tapgoods' ),
						'singular_name'         => _x( 'Item', 'Post Type Singular Name', 'tapgoods' ),
						'menu_name'             => __( 'Items', 'tapgoods' ),
						'name_admin_bar'        => __( 'Inventory', 'tapgoods' ),
						'archives'              => __( 'Item Archives', 'tapgoods' ),
						'attributes'            => __( 'Item Attributes', 'tapgoods' ),
						'parent_item_colon'     => __( 'Parent Item:', 'tapgoods' ),
						'all_items'             => __( 'All Items', 'tapgoods' ),
						'add_new_item'          => __( 'Add New Item', 'tapgoods' ),
						'add_new'               => __( 'Add New', 'tapgoods' ),
						'new_item'              => __( 'New Item', 'tapgoods' ),
						'edit_item'             => __( 'Edit Item', 'tapgoods' ),
						'update_item'           => __( 'Update Item', 'tapgoods' ),
						'view_item'             => __( 'View Item', 'tapgoods' ),
						'view_items'            => __( 'View Items', 'tapgoods' ),
						'search_items'          => __( 'Search Item', 'tapgoods' ),
						'not_found'             => __( 'Not found', 'tapgoods' ),
						'not_found_in_trash'    => __( 'Not found in Trash', 'tapgoods' ),
						'featured_image'        => __( 'Featured Image', 'tapgoods' ),
						'set_featured_image'    => __( 'Set featured image', 'tapgoods' ),
						'remove_featured_image' => __( 'Remove featured image', 'tapgoods' ),
						'use_featured_image'    => __( 'Use as featured image', 'tapgoods' ),
						'insert_into_item'      => __( 'Insert into item', 'tapgoods' ),
						'uploaded_to_this_item' => __( 'Uploaded to this item', 'tapgoods' ),
						'items_list'            => __( 'Items list', 'tapgoods' ),
						'items_list_navigation' => __( 'Items list navigation', 'tapgoods' ),
						'filter_items_list'     => __( 'Filter items list', 'tapgoods' ),
					),
					'description'         => __( 'TapGoods Inventory', 'tapgoods' ),
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
				Tapgoods_Helpers::tgqm( $primary_term_id );

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

	public static function render_inventory_metabox() {
		// @TODO: move this into a partial tempalte?
		// @TODO: metaboxes for taxonomy relationships?
		?>
		<div class="inside">
			<p><strong>Foo</strong></p>
		</div>
		<?php
	}

	public static function get_tg_taxonomies() {
		$tg_taxonomies = array(
			'tg_category',
			'tg_tags',
			'tg_inventory_type',
			'tg_inventory_colors',
		);
		return $tg_taxonomies;
	}

	public function tg_add_rewrites() {
		global $wp_rewrite;
		$permastruct_args = array();
		$wp_rewrite->add_rewrite_tag( '%tg_category%', '([^&]+)', 'tg_category=' );
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
			1 => __( 'Category added.', 'tapgoods' ),
			2 => __( 'Category deleted.', 'tapgoods' ),
			3 => __( 'Category updated.', 'tapgoods' ),
			4 => __( 'Category not added.', 'tapgoods' ),
			5 => __( 'Category not updated.', 'tapgoods' ),
			6 => __( 'Categories deleted.', 'tapgoods' ),
		);

		$messages['tg_tags'] = array(
			0 => '',
			1 => __( 'Tag added.', 'tapgoods' ),
			2 => __( 'Tag deleted.', 'tapgoods' ),
			3 => __( 'Tag updated.', 'tapgoods' ),
			4 => __( 'Tag not added.', 'tapgoods' ),
			5 => __( 'Tag not updated.', 'tapgoods' ),
			6 => __( 'Tags deleted.', 'tapgoods' ),
		);
	}

	/**
	 * Flush rules if the event is queued.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( 'yes' === get_option( 'tapgoods_queue_flush_rewrite_rules' ) ) {
			update_option( 'woocommerce_queue_flush_rewrite_rules', 'no' );
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

Tapgoods_Post_Types::init();
