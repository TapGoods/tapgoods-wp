<?php

class Tapgoods_WP {

	private static $instance = null;
	protected $loader;
	protected $plugin_name;
	protected $version;
	protected $shortcodes;
	private $plugin_admin;

	private function __construct() {
		$this->version     = TAPGOODSWP_VERSION;
		$this->plugin_name = 'tapgoods';
		$this->load_dependencies();
		$this->set_locale( $this->plugin_name );
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __clone() {}
	private function __wakeup() {}

	private function load_dependencies() {
		$includes = [
			'includes/class-tapgoods-loader.php',       // Class for adding actions and filers
			'admin/class-tapgoods-admin.php',           // Class for WP Admin features
			'includes/class-tapgoods-i18n.php',         // Loads text domain for localization
			'includes/class-tapgoods-shortcodes.php',   // Registers Shortcodes
			'public/class-tapgoods-public.php',         // Class for frontend features
			'includes/class-tapgoods-filesystem.php',   // Filesystem utility class
			'includes/class-tapgoods-helpers.php',      // Filesystem utility class
		];

		foreach ( $includes as $file ) {
			require_once TAPGOODS_PLUGIN_PATH . $file;
		}

		$this->loader = new Tapgoods_WP_Loader();
	}

	private function set_locale( $domain ) {
		$tapgoods_i18n = new Tapgoods_WP_i18n( $domain );
		$this->loader->add_action( 'plugins_loaded', $tapgoods_i18n, 'load_plugin_textdomain' );
	}

	private function define_admin_hooks() {

		$this->plugin_admin = new Tapgoods_WP_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $this->plugin_admin, 'tapgoods_admin_menu' );

		$plugin_basename = plugin_basename( plugin_dir_path( __DIR__ ) . $this->plugin_name . '.php' );
		$this->loader->add_filter( 'plugin_action_links_' . $plugin_basename, $this->plugin_admin, 'add_action_links' );
	}

	private function define_public_hooks() {

		$plugin_public = new Tapgoods_WP_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
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
