<?php
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

	private $plugin_name;
	private $version;
	private $filesystem;

	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/tapgoods-admin.css', array(), $this->version, 'all' );

		// only enqueue these styles if on our settings pages
		if ( $_GET['page'] == $this->plugin_name ) {
			wp_enqueue_style( $this->plugin_name . '-bootstrap', TAPGOODS_PLUGIN_URL . 'assets/css/tg-bootstrap.css', null, false );
			wp_enqueue_style( $this->plugin_name . '-font-heebo', 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&display=swap', null, false );
			wp_enqueue_style( 'wp-codemirror' );

		}
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		if ( $_GET['page'] == $this->plugin_name ) {

			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/tapgoods-admin.js', array( 'jquery', $this->plugin_name . '-bootstrap' ), $this->version, false );
			wp_enqueue_script( $this->plugin_name . '-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', array( 'jquery' ), false );

			// codemirror for custom css
			wp_enqueue_script( 'wp-theme-plugin-editor' );

			// codemirror settings being passed to javascript
			$type            = array(
				'type'      => 'text/css',
				'darkTheme' => 'true',
			);
			$readonly        = array( 'codemirror' => [ 'readOnly' => 'nocursor' ] );
			$editor_settings = wp_enqueue_code_editor( $type );
			$viewer_settings = wp_enqueue_code_editor( array_merge( $type + $readonly ) );
			wp_localize_script( 'jquery', 'tg_editor_settings', $editor_settings );
			wp_localize_script( 'jquery', 'tg_viewer_settings', $viewer_settings );

		}
	}

	public function tapgoods_admin_menu() {
		$page_title = 'TapGoods';
		$menu_title = 'TapGoods';
		$capability = 'manage_options';
		$menu_slug  = $this->plugin_name;
		$function   = array( $this, 'tapgoods_admin_page' );
		$icon       = TAPGOODS_PLUGIN_URL . 'assets/img/tg-icon.png';
		$icon       = '';
		add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon, 65 );

		add_submenu_page( $this->plugin_name, $page_title, 'Connection', $capability, $this->plugin_name . '#connection', $function, 1 );
		add_submenu_page( $this->plugin_name, $page_title, 'Styling', $capability, $this->plugin_name . '#styling', $function, 2 );
		add_submenu_page( $this->plugin_name, $page_title, 'Shortcodes', $capability, $this->plugin_name . '#shortcodes', $function, 3 );
	}


	// Add a link to this plugin to the action links.
	public function add_action_links( $links ) {
		$settings_link = array(
			'<a href="' . admin_url( 'admin.php?page=' . $this->plugin_name ) . '">' . __( 'Settings', $this->plugin_name ) . '</a>',
		);
		return array_merge( $settings_link, $links );
	}

	// Render the admin page
	public function tapgoods_admin_page() {
		include_once 'partials/tapgoods-admin-page.php';
	}

	// Used to print admin notices
	public static function tapgoods_admin_notice( string $message, $args = [], $return = false ) {

		$args = array_merge(
			array(
				'type'               => 'success',
				'dismissible'        => true,
				'additional_classes' => array( 'inline', 'notice-alt' ),
				'attributes'         => array( 'data-slug' => 'plugin-slug' ),
			),
			$args
		);

		// Buffer the output so we can return it wherever its needed
		if ( $return ) {
			ob_start();
		}
		wp_admin_notice( $message, $args );
		if ( $return ) {
			$output = ob_get_contents();
			ob_end_clean();
			return $output;
		}
	}
}
