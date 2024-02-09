<?php

// Loads and defines internationalization files for this plugin

class Tapgoods_WP_i18n {
	
    public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'tapgoods-wp',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}


}
