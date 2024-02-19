<?php

class Tapgoods_Shortcodes {
    
    // register plugin shortcodes
    private static $instance = null;
    public static $shortcodes_info;

    private function __construct() {

        $shortcodes = self::get_shortcodes();
        $this->register_shortcodes( $shortcodes );
    }

    private function register_shortcodes( $shortcodes ) {
        
        foreach( $shortcodes as $shortcode => $info ) {
            $template = self::get_template( $shortcode );
            if (! shortcode_exists( $shortcode )) {
                if( is_file( $template ) && file_exists( $template ) ){
                    add_shortcode( $shortcode, [ $this, $shortcode ] );
                }
            }
        }    
    }

    public function __call( $tag, $args) {

        if ( array_key_exists( $tag, self::get_shortcodes() ) ) {

            return $this->genericHandler( self::get_template($tag), $args );
        }
    }

    private static function get_template($tag) {

        return TAPGOODS_PLUGIN_PATH . 'public/partials/' . str_replace('_','-', $tag) . '.php';
    }

    // This function receives the arguments passed to the shortcode callback and loads the PHP template from /public/partials
    protected function genericHandler( $template, $args ) {

        $tag = $args[2];
        $content = ("" !== $args[1]) ? $args[1] : false;
        $atts = Tapgoods_Shortcodes::get_atts($tag);
        $atts = shortcode_atts( $atts, $args[0], $tag);

        ob_start();
		include $template;
		return do_shortcode(ob_get_clean());
    }

    public static function get_shortcodes() {
       
        $path = TAPGOODS_PLUGIN_PATH . '/includes/shortcodes.json';
        $json = Tapgoods_Filesystem::get_file($path);
        $shortcodes = json_decode($json, true);
        return $shortcodes;
    }

    public static function get_atts($shortcode) {

        $shortcodes = self::get_shortcodes();
        $atts = [];
        foreach ($shortcodes[$shortcode]['atts'] as $att => $data) {
            $atts[$att] = $data['default']; 
        }
        return $atts;
    }

    public function __clone() { }

    public function __wakeup() { }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }


}
