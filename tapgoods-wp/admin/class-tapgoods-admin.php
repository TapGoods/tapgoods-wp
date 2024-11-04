<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Tapgoods
 * @subpackage Tapgoods/admin
 * @author     Jeremy Benson <jeremy.benson@tapgoods.com>
 */
class Tapgoods_Admin {

	private $plugin_name;
	private $version;
	private $filesystem;

	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function conditional_includes() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		switch ( $screen->id ) {
			case 'options-permalink':
				include __DIR__ . '/class-tapgoods-admin-permalinks.php';
				break;
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles( $hook ) {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/tapgoods-admin.css', array(), $this->version, 'all' );

		// only enqueue these styles if on our settings pages
		if ( 'toplevel_page_tapgoods' === $hook ) {
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
	public function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_tapgoods' === $hook ) {

			wp_enqueue_script( $this->plugin_name . '-admin', plugin_dir_url( __FILE__ ) . 'js/tapgoods-admin.js', array( 'jquery', $this->plugin_name . '-bootstrap' ), $this->version, false );
			wp_localize_script(
				$this->plugin_name . '-admin',
				'tg_ajax',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				)
			);

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

	/**
	 * Ajax function to encrypt and save API key
	 *
	 * @return void
	 */
	public static function tg_update_connection() {

		check_ajax_referer( 'save', '_tgnonce_connection' );
		// if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		// 	die();
		// }

		$api_key = '';
		if ( isset( $_REQUEST['tapgoods_api_key'] ) ) {
			$encryption        = new Tapgoods_Encryption();
			$submitted_api_key = sanitize_text_field( wp_unslash( $_REQUEST['tapgoods_api_key'] ) );
			$api_key           = $encryption->tg_encrypt( $submitted_api_key );
		}

		$success = update_option( 'tg_key', $api_key );


		$client = Tapgoods_Connection::get_instance();

		try {
			// setting the second param to true will cause this to fail (for testing).
			$response = $client->test_connection( $submitted_api_key );
		} catch ( error $e ) {
			self::connection_failed();
		}
		// $response = true;

		if ( false === $response || is_wp_error( $response ) ) {
			self::connection_failed();
		}

		if ( $success ) {
			update_option( 'tg_api_connected', true );
			$notice = Tapgoods_Admin::tapgoods_admin_notice( __( 'Company Key Updated.', 'tapgoods' ), [], false );
			wp_send_json_success( $notice );
		}

		die();
	}

	private static function connection_failed() {
		tg_write_log( 'test_connection failed' );
		update_option( 'tg_api_connected', false );
		$args = array(
			'type' => 'error',
		);
		$env1 = ( defined( 'TG_ENV' ) ) ? TG_ENV : getenv_docker( 'tg_env', 'tapgoods.com' );

		$notice = Tapgoods_Admin::tapgoods_admin_notice( __( 'Unable to Connect,, ' . $env1 . ' make sure your API Key is entered correctly.', 'tapgoods' ), $args, false );
		wp_send_json_error( $notice );
		die();
	}

	public static function tg_api_sync() {
		// check_ajax_referer( 'save', '_tgnonce_connection' );

		$client   = Tapgoods_Connection::get_instance();
		$response = $client->sync_from_api();

		// tg_write_log( $response );
		if ( true === $response['success'] ) {
			wp_send_json_success( $response['message'] );
		} else {
			wp_send_json_error( $response['message'] );
		}
		die();
	}

	public function tg_save_advanced() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! isset( $_REQUEST['_tgnonce_advanced'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_tgnonce_advanced'] ) ), 'save' ) ) {
			return false;
		}

		Tapgoods_Helpers::tgqm( 'tg_save_advanced' );
		Tapgoods_Helpers::tgqm( '$_REQUEST:' );
		Tapgoods_Helpers::tgqm( $_REQUEST );

		Tapgoods_Helpers::tgqm( '$_POST:' );
		Tapgoods_Helpers::tgqm( $_POST );
	}

	public function tg_save_dev() {

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! isset( $_REQUEST['_tgnonce_dev'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_tgnonce_dev'] ) ), 'save' ) ) {
			return false;
		}

		Tapgoods_Helpers::tgqm( 'tg_save_dev' );
		Tapgoods_Helpers::tgqm( '$_REQUEST:' );
		Tapgoods_Helpers::tgqm( $_REQUEST );

		Tapgoods_Helpers::tgqm( '$_POST:' );
		Tapgoods_Helpers::tgqm( $_POST );
	}

	public function tg_save_styles( $input_submit ) {
		// exit if we're not handling a post request.
		if ( empty( $_POST ) ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$action     = 'save';
		$nonce_name = '_tgnonce_css';

		if ( isset( $_REQUEST[ $nonce_name ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_name ] ) ), $action ) ) {

			// Allow saving the file even if the content is empty
			$custom_css = ( isset( $_REQUEST['tg-custom-css'] ) ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['tg-custom-css'] ) ) : '';
		
			// Now we save even if the content is empty
			$success = Tapgoods_Filesystem::put_file( $input_submit, $custom_css, TAPGOODS_PLUGIN_PATH . '/public/css/tapgoods-custom.css' , $action, $nonce_name );
		
			return $success;
		}
		
	}

	public function taxonomy_intercept() {
		$screen = get_current_screen();
		if ( 'edit-tg_category' !== $screen->id || 'edit-tg_tags' !== $screen->id ) {
			return;
		}
		require_once TAPGOODS_PLUGIN_PATH . '/includes/tg_edit-tags.php';
	}

	public function tax_args_filter( $args, $taxonomy ) {
		return $args;
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

		add_submenu_page( $this->plugin_name, $page_title . ' Connection Settings', 'Connection', $capability, $this->plugin_name, $function, 1 );
		add_submenu_page( $this->plugin_name, $page_title . ' Styling Settings', 'Styling', $capability, $this->plugin_name . '#styling', $function, 2 );
		add_submenu_page( $this->plugin_name, $page_title . ' Shortcodes', 'Shortcodes', $capability, $this->plugin_name . '#shortcodes', $function, 3 );
	    add_submenu_page( $this->plugin_name, $page_title . ' Advanced Options', 'Advanced Options', $capability, $this->plugin_name . '#options', $function, 4 );
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
	public static function tapgoods_admin_notice( string $message, $args = [], $output = true ) {

		$args = array_merge(
			array(
				'type'               => 'success', // Available types: error, success, warning, info.
				'dismissible'        => true,
				'additional_classes' => array( 'inline', 'notice-alt' ),
				'attributes'         => array( 'data-slug' => 'plugin-slug' ),
			),
			$args
		);

		// Buffer the output so we can return it wherever its needed
		if ( ! $output ) {
			ob_start();
		}
		wp_admin_notice( $message, $args );
		if ( ! $output ) {
			$notice = ob_get_contents();
			ob_end_clean();
			return $notice;
		}
	}
}
