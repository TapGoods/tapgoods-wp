<?php

class Tapgoods_Shortcodes {
    
    // register plugin shortcodes
    private static $instance = null;
    public static $shortcodes_info;

    private function __construct() {
        $shortcodes = $this->get_shortcodes();
        foreach( $shortcodes as $shortcode => $info ) {
            if (! shortcode_exists($info['tag'])) {
                add_shortcode($info['tag'], $info['callback'] );
            }
        }
    }

    public function tg_cart_func($atts) {
        return 'cart';
    }

    public function tg_inventory_func($atts) {
        return 'inventory';
    }

    public function tg_sign_in_func($atts) {
        return 'sign in';
    }

    public function tg_sign_up_func($atts) {
        return 'sign up';
    }

    public static function get_shortcodes() {
        $shortcodes_info = [
            'Cart' => [
                'tag' => 'tg-cart',
                'callback' => 'tg_cart_func',
                'description' => 'Displays the TapGoods Cart Button',
            ],
            'Inventory' => [
                'tag' => 'tg-inventory',
                'callback' => 'tg_inventory_func',
                'description' => 'Displays the TapGoods Shop with Inventory, Categories, and Search',
            ],
            'Sign In' => [
                'tag' => 'tg-sign-in', 
                'callback' => 'tg_sign_in_func', 
                'description' => 'Displays the Sign-In button',
            ],
            'Sign Up' => [
                'tag' => 'tg-sign-up',
                'callback' => 'tg_sign_up_func',
                'description' => 'Displays the Sign-Up button',
            ],
        ];
        return $shortcodes_info;
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
