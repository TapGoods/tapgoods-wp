<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Tapgoods_WP
 * @subpackage Tapgoods_WP/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Tapgoods_WP
 * @subpackage Tapgoods_WP/admin
 * @author     Jeremy Benson <jeremy.benson@tapgoods.com>
 */

class Tapgoods_WP_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/tapgoods-admin.css', array(), $this->version, 'all' );

		// only enqueue these styles if on our settings pages
		if($_GET['page'] == $this->plugin_name) {
			wp_enqueue_style( $this->plugin_name . '-bootstrap', TAPGOODS_PLUGIN_URL . 'assets/css/tg-bootstrap.css', null, false); 
			wp_enqueue_style( $this->plugin_name . '-font-heebo', 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&display=swap', null, false); 
			wp_enqueue_style('wp-codemirror');

		}
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		if($_GET['page'] == $this->plugin_name) {
			
			$type = array('type' => 'text/css', 'darkTheme' => 'true');
			$readonly = array('codemirror' => ['readOnly' => 'nocursor' ] );
			
			$editor_settings = wp_enqueue_code_editor( $type );
			$viewer_settings = wp_enqueue_code_editor( array_merge($type + $readonly) );
			
			wp_localize_script('jquery', 'tg_editor_settings', $editor_settings);
			wp_localize_script('jquery', 'tg_viewer_settings', $viewer_settings);

			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/tapgoods-admin.js', array( 'jquery', $this->plugin_name . '-bootstrap' ), $this->version, false );
			wp_enqueue_script( $this->plugin_name . '-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', array('jquery'), false);
			wp_enqueue_script('wp-theme-plugin-editor');

		}
	}

    public function tapgoods_admin_menu() {
		$page_title = "TapGoods";
		$menu_title = "TapGoods";
		$capability = "manage_options";
		$menu_slug  = $this->plugin_name;
		$function   = array($this, 'tapgoods_admin_page');
		$icon       = TAPGOODS_PLUGIN_URL . 'assets/img/tg-icon.png'; 
		$icon       = '';
		add_menu_page($page_title, $menu_title, $capability, $menu_slug, $function, $icon, 65);

		add_submenu_page($this->plugin_name, $page_title, "Connection",$capability, $this->plugin_name . '#connection', $function, 1);
		add_submenu_page($this->plugin_name, $page_title, "Styling",$capability, $this->plugin_name . '#styling', $function, 2);
		add_submenu_page($this->plugin_name, $page_title, "Shortcodes",$capability, $this->plugin_name . '#shortcodes', $function, 3);
	}

	/**
	 * Add a link to this plugin to the action links.
	 */
	public function add_action_links($links) {
		$settings_link = array(
			'<a href="' . admin_url('admin.php?page=' . $this->plugin_name) . '">' . __('Settings', $this->plugin_name) . '</a>'
		);
		return array_merge($settings_link, $links);
	}

	/**
	 * Render the admin page.
	 */
	public function tapgoods_admin_page() {
		include_once('partials/tapgoods-admin-page.php');
	}

	public static function tapgoods_admin_get_file($path) {
		global $wp_filesystem;
		include_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		
		return $wp_filesystem->get_contents( $path );
	}

	public static function tapgoods_admin_notice($message, $args = []) {
		
		$args = array_merge($args, array(
			'type'               => 'success',
			'dismissible'        => true,
			'additional_classes' => array( 'inline', 'notice-alt' ),
			'attributes'         => array( 'data-slug' => 'plugin-slug' )
		));
		
		ob_start();
		wp_admin_notice( __( $message, 'tapgoods' ), $args);
		$output = ob_get_contents();
		ob_end_clean();
		return $output;
	}

	public static function tapgoods_admin_put_file($submit, $contents, $filepath, $nonce) {
		if (empty($_POST)) return false;
		check_admin_referer($nonce);

		$method = '';
		$form_fields = array($submit, $contents);
		$url = wp_nonce_url('options.php?page=tapgoods','tapgoods-save-custom-css');
		if (false === ($creds = request_filesystem_credentials($url, $method, false, false, $form_fields, true) ) ) {
			return true;
		}
		if ( ! WP_Filesystem($creds) ) {
			// our credentials were no good, ask the user for them again
			request_filesystem_credentials($url, $method, true, false, $form_fields, true);
			return true;
		}

		//echo "<pre>"; var_dump($upload_dir); die();

		global $wp_filesystem;
		include_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$folder_exists = $wp_filesystem->exists(TAPGOODS_UPLOADS);
		if ( ! $folder_exists ) {
			$make_folder = $wp_filesystem->mkdir($tg_uploads);
			echo "couldn't make folder";
			if( ! $make_folder ) return false;
		}
		
		$success = $wp_filesystem->put_contents( $filepath, $contents, FS_CHMOD_FILE);
		if( ! $success  ) {
			echo 'error saving file!';
		}
		return $success;
	}

}
