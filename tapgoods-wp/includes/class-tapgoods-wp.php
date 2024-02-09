<?php

class Tapgoods_WP {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        if( defined( 'TAPGOODSWP_VERSION') ) {
            $this->version = TAPGOODSWP_VERSION;
        }
        $this->plugin_name = 'tapgoods';
        
        $this->load_dependencies();
		$this->set_locale();

		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->register_shortcodes();
		
    }

    private function load_dependencies() {
        require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-loader.php';
        require_once TAPGOODS_PLUGIN_PATH . 'admin/class-tapgoods-admin.php';
		require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-i18n.php';
		require_once TAPGOODS_PLUGIN_PATH . 'admin/class-tapgoods-admin.php';
		require_once TAPGOODS_PLUGIN_PATH . 'includes/class-tapgoods-shortcodes.php';
		require_once TAPGOODS_PLUGIN_PATH . 'public/class-tapgoods-public.php';
        $this->loader = new Tapgoods_WP_Loader();
    }

	private function set_locale() {

		$tapgoods_i18n = new Tapgoods_WP_i18n();
		$this->loader->add_action( 'plugins_loaded', $tapgoods_i18n, 'load_plugin_textdomain' );

	}

    private function define_admin_hooks() {

		$plugin_admin = new Tapgoods_WP_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action('admin_menu', $plugin_admin, 'tapgoods_admin_menu');
		
        $plugin_basename = plugin_basename(plugin_dir_path(__DIR__) . $this->plugin_name . '.php');
		$this->loader->add_filter('plugin_action_links_' . $plugin_basename, $plugin_admin, 'add_action_links');

	}

	private function define_public_hooks() {

		$plugin_public = new Tapgoods_WP_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	private function register_shortcodes() {
		$shortcodes = Tapgoods_Shortcodes::get_instance();
	}

    public function init() {
        $this->loader->run();
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

}