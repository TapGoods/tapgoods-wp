<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Tapgoods {

	private static $instance = null;
	protected $loader;
	protected $plugin_name;
	protected $version;
	protected $shortcodes;
	private $plugin_admin;
	private $tapgoods_taxonomy;

	private function __construct() {
		$this->version     = TAPGOODSWP_VERSION;
		$this->plugin_name = 'tapgoods';
		$this->load_dependencies();
		$this->set_locale( $this->plugin_name );
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_general_hooks();
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __clone() {}
	public function __wakeup() {}

	private function load_dependencies() {
		$includes = [
			'includes/class-tapgoods-loader.php',         // Class for adding actions and filers
			'admin/class-tapgoods-admin.php',             // Class for WP Admin features
			'includes/class-tapgoods-i18n.php',           // Loads text domain for localization
			'includes/tapgoods-core-functions.php',       // Core functions for admin and public
			'includes/tapgoods-formatting-functions.php', // Core functions for admin and public
			'includes/class-tapgoods-shortcodes.php',     // Registers Shortcodes
			'includes/class-tapgoods-post-types.php',     // Regusters Taxonomies and Post Types
			'public/class-tapgoods-public.php',           // Class for frontend features
			'includes/class-tapgoods-encryption.php',     // Class for encryption/decryption methods
			'includes/class-tapgoods-connection.php',     // API Connection Controller
			'includes/class-tapgoods-api-exception.php',  // API Exception Classes
			'includes/class-tapgoods-api-request.php',    // API Request Class
			'includes/class-tapgoods-api-client.php',     // API Client Class
			'includes/class-tapgoods-api-response.php',   // API Response Class
			'includes/class-tapgoods-filesystem.php',     // Filesystem utility class
			'includes/class-tapgoods-helpers.php',        // Filesystem utility class
			'includes/class-tapgoods-enqueue.php',        // Enqueue manager class
		];

		foreach ( $includes as $file ) {
			require_once TAPGOODS_PLUGIN_PATH . $file;
		}

		$this->loader = new Tapgoods_Loader();
	}

	private function set_locale( $domain ) {
		$tapgoods_i18n = new Tapgoods_i18n( $domain );
		$this->loader->add_action( 'plugins_loaded', $tapgoods_i18n, 'load_plugin_textdomain' );
	}

	private function define_admin_hooks() {
		$this->plugin_admin = new Tapgoods_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'current_screen', $this->plugin_admin, 'conditional_includes', 10, 0 );
		// Re-enabled temporarily to ensure functionality works
		$this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $this->plugin_admin, 'tapgrein_admin_menu' );
		$this->loader->add_action( 'load-edit-tags.php', $this->plugin_admin, 'taxonomy_intercept' );

		$plugin_basename = plugin_basename( plugin_dir_path( __DIR__ ) . $this->plugin_name . '.php' );
		$this->loader->add_filter( 'plugin_action_links_' . $plugin_basename, $this->plugin_admin, 'add_action_links' );
		$this->loader->add_filter( 'register_taxonomy_args', $this->plugin_admin, 'tax_args_filter', 10, 2 );

		$this->loader->add_filter( 'available_permalink_structure_tags', $this, 'tapgrein_add_available_tags', 10, 1 );

		$this->loader->add_action( 'wp_ajax_tapgrein_update_connection', $this->plugin_admin, 'tapgrein_update_connection', 10, 1 );
		$this->loader->add_action( 'wp_ajax_tapgrein_api_sync', $this->plugin_admin, 'tapgrein_api_sync', 10, 1 );
		$this->loader->add_action( 'wp_ajax_nopriv_tapgrein_api_sync', $this->plugin_admin, 'tapgrein_api_sync', 10, 1 );

		$this->loader->add_action( 'tg_save_custom_css', $this->plugin_admin, 'tapgrein_save_styles', 10, 1 );
		$this->loader->add_action( 'tg_save_advanced', $this->plugin_admin, 'tg_save_advanced', 10, 0 );
		$this->loader->add_action( 'tapgrein_save_dev', $this->plugin_admin, 'tapgrein_save_dev', 10, 0 );
	}

	private function define_public_hooks() {

		$plugin_public = new Tapgoods_Public( $this->get_plugin_name(), $this->get_version() );

		// Re-enabled temporarily to ensure functionality works
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

		add_action( 'parse_request', 'tapgrein_parse_request', 10, 1 );
		add_action( 'parse_request', 'tapgrein_empty_cart', 20, 1 );
		add_filter( 'query_vars', 'tapgrein_custom_query_vars' );

		$this->loader->add_action( 'wp_ajax_tapgrein_search', $plugin_public, 'tapgrein_search', 10, 1 );
		$this->loader->add_action( 'wp_ajax_nopriv_tapgrein_search', $plugin_public, 'tapgrein_search', 10, 1 );

		$this->loader->add_filter( 'the_content', $plugin_public, 'load_single_content', 10, 2 );

		$this->loader->add_filter( 'body_class', $plugin_public, 'tapgrein_add_body_classes', 10, 1 );

		$this->loader->add_action( 'wp_ajax_get_product_cart_url', $plugin_public, 'get_product_url', 10, 1 );
		$this->loader->add_action( 'wp_ajax_nopriv_get_product_cart_url', $plugin_public, 'get_product_url', 10, 1 );

		$this->loader->add_action( 'wp_ajax_get_product_availability', $plugin_public, 'get_available_period', 10, 1 );
		$this->loader->add_action( 'wp_ajax_nopriv_get_product_availability', $plugin_public, 'get_available_period', 10, 1 );
		
		$this->loader->add_filter( 'render_block', $this, 'tapgrein_disable_autop_blocks', 10, 2 );
	}

	private function define_general_hooks() {
		if ( defined( 'TAPGOODS_DEV' ) && TAPGOODS_DEV ) {
			// development environement hooks
		}
		$this->loader->add_filter( 'cron_schedules', $this, 'tapgrein_add_cron_interval', 10, 1 );
		$this->loader->add_action( 'tapgoods_cron_hook', $this, 'tapgrein_cron_exec' );
		$this->loader->add_action( 'init', $this, 'tapgrein_cron_setup', 10, 0 );

	}

	public function tapgrein_add_cron_interval( $schedules ) {
		$schedules['five_minutes'] = array(
			'interval' => 300, // time in seconds
			'display'  => 'Every Five Minutes',
		);
		return $schedules;
	}

	public function tapgrein_cron_setup() {
		if ( ! wp_next_scheduled( 'tapgoods_cron_hook' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'tapgoods_cron_hook' );
		}
	}

	public function tapgrein_cron_exec() {
		tapgrein_write_log( 'tapgrein_cron_exec running at: ' . current_time( 'mysql' ) );

		if ( '1' === get_option( 'tg_api_connected', 0 ) ) {
			$connection = Tapgoods_Connection::get_instance();
			$sync       = $connection->tapgrein_async_sync_from_api();
		}
	}

	public function tapgrein_disable_autop_blocks( $block_content, $block ) {
		remove_filter( 'the_content', 'wpautop' );
		if ( 'core/shortcode' === $block['blockName'] ) {
			remove_filter( 'the_content', 'wpautop' );
		} elseif ( ! has_filter( 'the_content', 'wpautop' ) ) {
			// add_filter( 'the_content', 'wpautop' );
		}
		return $block_content;
	}

	public function tapgrein_add_available_tags( $available_tags ) {

		$tg_tags = array(
			'tg_inventory_base' => '',
			'tg_category'       => '',
			'tg_tags'           => '',
			'tg_location'       => '',
		);

		$new_tags = array_merge( $available_tags, $tg_tags );
		return $new_tags;
	}

	public function get_admin() {
		return $this->plugin_admin;
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	// The reference to the class that orchestrates the hooks with the plugin.
	public function get_loader() {
		return $this->loader;
	}

	public function get_version() {
		return $this->version;
	}

	public function init() {
		$this->loader->run();
		$this->shortcodes = Tapgoods_Shortcodes::get_instance();
	}
}
