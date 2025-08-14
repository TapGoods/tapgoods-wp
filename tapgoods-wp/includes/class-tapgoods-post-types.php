<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Post Types
 *
 * Registers post types and taxonomies
 *
 * @package Tapgoods\Includes\Post-Type
 * @version 0.1.0
 */

class Tapgoods_Post_Types {

	    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

	public static function init() {
    // Register Taxonomy first so that URL rewrites behave as expected.
    add_action( 'init', array( __CLASS__, 'tg_register_taxonomies' ), 5 );
    add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
    
    // Metaboxes
    add_action( 'add_meta_boxes', array( __CLASS__, 'add_all_custom_metaboxes' ), 5 );
    add_action( 'save_post_tg_inventory', array( __CLASS__, 'save_custom_description' ) );
    
    // Reorder metaboxes after all have been registered
    add_action( 'add_meta_boxes', array( __CLASS__, 'reorder_yoast_metabox' ), 999 );
    
    // Force classic editor for `tg_inventory`
    add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_gutenberg' ), 10, 2 );
    
    add_filter( 'post_type_link', array( __CLASS__, 'tg_filter_post_type_link' ), 10, 2 );

    // Taxonomies
    add_action( 'tg_tags_edit_form', array( __CLASS__, 'tg_tags_metaboxes' ), 1, 1 );
    add_action( 'tg_category_edit_form', array( __CLASS__, 'tg_tags_metaboxes' ), 1, 1 );
    add_action( 'tg_location_edit_form', array( __CLASS__, 'tg_tags_metaboxes' ), 1, 1 );
    add_action( 'add_meta_boxes_tg_location', array( __CLASS__, 'add_taxonomy_metabox' ) );
	}


	// Function to move Yoast metabox to the bottom
	public static function reorder_yoast_metabox() {
		global $wp_meta_boxes;
		
		// Only proceed if we are editing a tg_inventory post type
		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== 'tg_inventory') {
			return;
		}
		
		// Find and move the Yoast metabox
		if (isset($wp_meta_boxes['tg_inventory']['normal']['high']['wpseo_meta'])) {
			$yoast_box = $wp_meta_boxes['tg_inventory']['normal']['high']['wpseo_meta'];
			unset($wp_meta_boxes['tg_inventory']['normal']['high']['wpseo_meta']);
			
			// Move it to low priority so it appears at the bottom
			if (!isset($wp_meta_boxes['tg_inventory']['normal']['low'])) {
				$wp_meta_boxes['tg_inventory']['normal']['low'] = array();
			}
			$wp_meta_boxes['tg_inventory']['normal']['low']['wpseo_meta'] = $yoast_box;
		}
	}

	// Disable Gutenberg for tg_inventory post type
	public static function disable_gutenberg( $use_block_editor, $post_type ) {
		if ( $post_type === 'tg_inventory' ) {
			return false;
		}
		return $use_block_editor;
	}


	// Add CSS styles to improve appearance - now handled by Tapgoods_Enqueue
	public static function add_admin_styles() {
		// Styles are now handled by Tapgoods_Enqueue class using wp_add_inline_style()
		// This method is kept for backward compatibility but styles moved to central system
	}

	// Function to add all custom metaboxes at once
	public static function add_all_custom_metaboxes() {
		// Custom Description – High priority
		add_meta_box(
			'tg_custom_description',
			__('Custom Description', 'tapgoods'),
			array(__CLASS__, 'render_custom_description_metabox'),
			'tg_inventory',
			'normal',
			'high'
		);
		
		// Inventory Information – High priority
		add_meta_box(
			'inventory_info',
			__('Inventory Information', 'tapgoods'),
			array(__CLASS__, 'render_inventory_metabox'),
			'tg_inventory',
			'normal',
			'high'
		);
	}

	// Hide the default editor for inventory items
	public static function hide_default_editor() {
		$post_id = isset($_GET['post']) ? $_GET['post'] : (isset($_POST['post_ID']) ? $_POST['post_ID'] : false);
		if (!$post_id) return;
		
		$post_type = get_post_type($post_id);
		if ($post_type === 'tg_inventory') {
			remove_post_type_support('tg_inventory', 'editor');
		}
	}

	// Update the custom description metabox to appear right after title
	public static function add_custom_description_metabox() {
		add_meta_box(
			'tg_custom_description',
			__('Custom Description', 'tapgoods'),
			array(__CLASS__, 'render_custom_description_metabox'),
			'tg_inventory',
			'normal',
			'high' // High priority to appear at top
		);
	}
		
	// Update the inventory metabox priority
	public static function add_inventory_metabox() {
		add_meta_box(
			'inventory_info',
			'Inventory Information',
			array(__CLASS__, 'render_inventory_metabox'),
			'tg_inventory', // Changed from null to specific post type
			'normal', // Changed from 'advanced' to 'normal'
			'high' // High priority
		);
	}

	public static function register_custom_edit_page() {
		add_submenu_page(
			'options-general.php', // Valid but irrelevant parent_slug.
			'Edit Item', // Page title.
			'', // Menu title (empty to not display it).
			'edit_posts', // Required capability.
			'custom-edit-page', // Unique slug for the page.
			array( __CLASS__, 'tapgrein_render_custom_edit_page' ) // Callback that renders the page.
		);
	}
	
	// Function to reorder metaboxes
	public static function reorder_metaboxes() {
		global $wp_meta_boxes;
		
		if (!isset($wp_meta_boxes['tg_inventory'])) {
			return;
		}
		
		// Store Yoast metabox if it exists
		$yoast_metabox = null;
		if (isset($wp_meta_boxes['tg_inventory']['normal']['high']['wpseo_meta'])) {
			$yoast_metabox = $wp_meta_boxes['tg_inventory']['normal']['high']['wpseo_meta'];
			unset($wp_meta_boxes['tg_inventory']['normal']['high']['wpseo_meta']);
		}
		
		// Re-add Yoast with lower priority so it appears after our metaboxes
		if ($yoast_metabox) {
			if (!isset($wp_meta_boxes['tg_inventory']['normal']['core'])) {
				$wp_meta_boxes['tg_inventory']['normal']['core'] = array();
			}
			$wp_meta_boxes['tg_inventory']['normal']['core']['wpseo_meta'] = $yoast_metabox;
		}
	}

	// Enhanced render function for custom description with better styling
	public static function render_custom_description_metabox($post) {
		wp_nonce_field('save_tg_custom_description', 'tg_custom_description_nonce');
		
		// Get the custom description
		$custom_description = get_post_meta($post->ID, 'tg_custom_description', true);
		
		// Debug: check what's stored
		if (empty($custom_description)) {
			// If it's empty, try pulling from post_content
			$custom_description = $post->post_content;
		}
		?>
		<div class="tg-custom-description-wrapper">
			<p class="description"><?php _e('Use this field for the custom description that will be displayed on the frontend.', 'tapgoods'); ?></p>
			<?php
			// Editor settings
			$editor_settings = array(
				'textarea_name' => 'tg_custom_description',
				'textarea_rows' => 15,
				'drag_drop_upload' => true,
				'tabfocus_elements' => 'content-html,save-post',
				'editor_height' => 360,
				'tinymce' => array(
					'resize' => true,
					'wp_autoresize_on' => true,
					'add_unload_trigger' => false,
				),
				'wpautop' => true,
				'media_buttons' => true,
				'quicktags' => true,
				'teeny' => false
			);
			
			// Unique ID for the editor
			$editor_id = 'tgcustomdescription';
			
			wp_editor($custom_description, $editor_id, $editor_settings);
			?>
		</div>
		
		<?php
		// Script now handled by Tapgoods_Enqueue class
		// No inline script needed here
		?>
		<?php
	}


	// Enhanced render function for inventory metabox with better styling
	public static function render_inventory_metabox() {
		global $post;
		$meta = get_post_meta( $post->ID );
		$tg_meta = array_filter( $meta, array( 'Tapgoods_Post_Types', 'tg_filter_postmeta' ), ARRAY_FILTER_USE_KEY );

		?>
		<div class="tg-inventory-info">
			<?php
			if (!empty($tg_meta)) {
				echo '<table class="widefat fixed striped">';
				echo '<thead><tr><th class="tapgoods-table-field-column">' . __('Field', 'tapgoods') . '</th><th>' . __('Value', 'tapgoods') . '</th></tr></thead>';
				echo '<tbody>';
				
				foreach ( $tg_meta as $key => $values ) {
					if ( null === $values[0] || $key === 'tg_custom_description' ) {
						continue;
					}
					
					echo '<tr>';
					echo '<td><strong>' . esc_html(str_replace('tg_', '', $key)) . '</strong></td>';
					echo '<td>';
					
					$value = maybe_unserialize($values[0]);
					if (is_array($value)) {
						if ($key === 'tg_pictures' && !empty($value)) {
							// Show images
							foreach ($value as $img) {
								if (isset($img['imgixUrl'])) {
									echo '<img src="' . esc_url($img['imgixUrl']) . '?w=100&h=100&fit=crop" class="tapgoods-image-gallery" />';
								}
							}
						} else {
							echo '<pre class="tapgoods-json-display">' . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
						}
					} else {
						echo esc_html($value);
					}
					
					echo '</td>';
					echo '</tr>';
				}
				
				echo '</tbody></table>';
			} else {
				echo '<p>' . __('No inventory data available.', 'tapgoods') . '</p>';
			}
			?>
		</div>
		
		<style>
			.tg-inventory-info table {
				margin-top: 10px;
			}
			.tg-inventory-info td {
				padding: 8px 10px;
			}
			.tg-inventory-info pre {
				background: #f0f0f0;
				padding: 5px;
				border-radius: 3px;
			}
		</style>
		<?php
	}

	// Function to save the custom description
	public static function save_custom_description($post_id) {
		// Security checks
		if (!isset($_POST['tg_custom_description_nonce'])) {
			return;
		}
		
		if (!wp_verify_nonce($_POST['tg_custom_description_nonce'], 'save_tg_custom_description')) {
			return;
		}
		
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}
		
		// Save the custom description
		if (isset($_POST['tg_custom_description'])) {
			$content = wp_kses_post($_POST['tg_custom_description']);
			update_post_meta($post_id, 'tg_custom_description', $content);
			
			// Optional: Also update post_content to keep in sync
			remove_action('save_post_tg_inventory', array(__CLASS__, 'save_custom_description'));
			wp_update_post(array(
				'ID' => $post_id,
				'post_content' => $content
			));
			add_action('save_post_tg_inventory', array(__CLASS__, 'save_custom_description'));
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
		$permalinks = tapgrein_get_permalink_structure();

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
						'name'                       => _x( 'Tags', 'Taxonomy General Name', 'tapgoods' ),
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
					'label'             => _x( 'Locations', 'Taxonomy name', 'tapgoods' ),
					'labels'            => array(
						'name'                       => _x( 'TG Locations', 'Taxonomy General Name', 'tapgoods' ),
						'singular_name'              => _x( 'Location', 'Taxonomy Singular Name', 'tapgoods' ),
						'menu_name'                  => __( 'Locations', 'tapgoods' ),
						'all_items'                  => __( 'All Locations', 'tapgoods' ),
						'parent_item'                => __( 'Parent Location', 'tapgoods' ),
						'parent_item_colon'          => __( 'Parent Location:', 'tapgoods' ),
						'new_item_name'              => __( 'New Location Name', 'tapgoods' ),
						'add_new_item'               => __( 'Add New Location', 'tapgoods' ),
						'edit_item'                  => __( 'Edit Location', 'tapgoods' ),
						'update_item'                => __( 'Update Location', 'tapgoods' ),
						'view_item'                  => __( 'View Location', 'tapgoods' ),
						'separate_items_with_commas' => __( 'Separate Locations with commas', 'tapgoods' ),
						'add_or_remove_items'        => __( 'Add or remove Locations', 'tapgoods' ),
						'choose_from_most_used'      => __( 'Choose from the most used Locations', 'tapgoods' ),
						'popular_items'              => __( 'Popular Locations', 'tapgoods' ),
						'search_items'               => __( 'Search Locations', 'tapgoods' ),
						'not_found'                  => __( 'Not Found', 'tapgoods' ),
						'no_terms'                   => __( 'No Locations', 'tapgoods' ),
						'items_list'                 => __( 'Locations list', 'tapgoods' ),
						'items_list_navigation'      => __( 'Locations list navigation', 'tapgoods' ),
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

		$permalinks = tapgrein_get_permalink_structure();
		// IMPORTANTE: Elimina 'custom-fields' de supports para ocultar esa caja gigante
		$supports = array( 'title', 'excerpt', 'thumbnail', 'publicize', 'wpcom-markdown', 'page-attributes' );
		// NO incluyas 'editor' aquí si quieres ocultarlo

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
					'supports'            => $supports, // Sin 'editor' ni 'custom-fields'
					'taxonomies'          => array( 'tg_category', 'tg_tags' ),
					'hierarchical'        => false,
					'public'              => true,
					'show_ui'             => true,
					'show_in_menu'        => true,
					'show_in_rest'        => true,
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
					'capability_type'     => 'post',
					'capabilities' => array(
						'create_posts' => 'do_not_allow',
						'edit_posts' => 'edit_posts',
						'edit_others_posts' => 'edit_posts',
						'publish_posts' => 'edit_posts',
						'read_private_posts' => 'edit_posts',
					),
					'map_meta_cap'        => true,
				)
			)
		);
		do_action( 'tapgoods_after_register_post_type_inventory', $tg_inventory );
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
				echo '<li>' . esc_html( $k ) . ': ' . esc_html( $v ) . '</li>';
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
			if ( isset( $img['id'] ) ) {
				echo '<li><a href="' . esc_url( $img['url'] ) . '" target="_blank">';
				echo wp_get_attachment_image( $img['id'], 'medium', false, ['height' => '200'] );
				echo '</a></li>';
				echo '<p>URL: ' . esc_url( $img['url'] ) . '</p>';
			}
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

		$messages['tg_location'] = array(
			0 => '',
			1 => __( 'Location added.', 'tapgoods' ),
			2 => __( 'Location deleted.', 'tapgoods' ),
			3 => __( 'Location updated.', 'tapgoods' ),
			4 => __( 'Location not added.', 'tapgoods' ),
			5 => __( 'Location not updated.', 'tapgoods' ),
			6 => __( 'Locations deleted.', 'tapgoods' ),
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

function tapgrein_modify_tg_inventory_items_per_page( $per_page, $post_type ) {
    if ( 'tg_inventory' === $post_type ) {
        // Check if a parameter is passed via URL, otherwise use 20 as the default value.
        $per_page = isset( $_GET['items_per_page'] ) ? (int) $_GET['items_per_page'] : 20;
    }
    return $per_page;
}
add_filter( 'edit_posts_per_page', 'tapgrein_modify_tg_inventory_items_per_page', 10, 2 );

// Add 'Location' column to admin table for 'tg_inventory' before 'Date' column
add_filter('manage_tg_inventory_posts_columns', 'tapgrein_add_location_column_to_inventory');
function tapgrein_add_location_column_to_inventory($columns) {
    $new_columns = [];

    // Rearrange columns and insert 'Location' before 'Date'
    foreach ($columns as $key => $value) {
        if ($key === 'date') {
            $new_columns['location'] = __('Location', 'tapgoods');
        }
        $new_columns[$key] = $value;
    }

    return $new_columns;
}


// Fill the 'Location' column with the ID and name of the specific location of the item
add_action('manage_tg_inventory_posts_custom_column', 'tapgrein_fill_location_column_in_inventory', 10, 2);
function tapgrein_fill_location_column_in_inventory($column, $post_id) {
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

        //error_log("Location Data for ID {$location_id}: " . print_r($location_data, true));

        // Verify that the information has been obtained correctly
        if (!empty($location_data)) {
            $location_name = isset($location_data['fullName']) ? $location_data['fullName'] : "Location {$location_id}";
            echo esc_html("{$location_id} - {$location_name}");
        } else {
            echo esc_html__( 'Location details not found', 'tapgoods' );
        }
    }



}



Tapgoods_Post_Types::init();


add_action('admin_head', array('Tapgoods_Post_Types', 'add_admin_styles'));