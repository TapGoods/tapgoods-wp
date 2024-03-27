<?php

// Public facing functionality

class Tapgoods_WP_Public {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function enqueue_styles() {

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/tapgoods-public.css', array(), $this->version, 'all' );
		wp_enqueue_style( TAPGOODS_UPLOADS . 'custom.css', array(), $this->version, 'all' );
	}

	public function enqueue_scripts() {

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/tapgoods-public.js', array( 'jquery' ), $this->version, false );
	}
}
