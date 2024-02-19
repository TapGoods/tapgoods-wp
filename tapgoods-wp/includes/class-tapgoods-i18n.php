<?php

// Loads and defines internationalization files for this plugin

class Tapgoods_WP_i18n {
	private $domain;

	public function __construct($domain)
	{
		$this->domain = $domain;
	}
	
    public function load_plugin_textdomain() {

		load_plugin_textdomain(
			$this->domain,
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}


}
