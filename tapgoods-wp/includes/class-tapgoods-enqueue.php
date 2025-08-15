<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * TapGoods Enqueue Manager
 * 
 * Clase para manejar correctamente todos los scripts y estilos del plugin
 * según las mejores prácticas de WordPress
 *
 * @package Tapgoods\Includes\Enqueue
 * @version 1.0.0
 */
class Tapgoods_Enqueue {

    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Public scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_styles'));
        
        // Dynamic styles
        add_action('wp_head', array($this, 'output_dynamic_styles'));
        add_action('admin_head', array($this, 'output_admin_dynamic_styles'));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        
        // Enqueue for TapGoods admin pages
        if ($this->is_tapgrein_admin_page($screen, $hook)) {
            
            // Enqueue complete admin script
            wp_enqueue_script(
                'tapgoods-admin-complete',
                plugin_dir_url(dirname(__FILE__)) . 'admin/js/tapgoods-admin-complete.js',
                array('jquery', 'wp-color-picker'),
                TAPGOODSWP_VERSION,
                true
            );

            // Localize script with necessary data
            wp_localize_script('tapgoods-admin-complete', 'tg_admin_vars', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'sync_nonce' => wp_create_nonce('tapgrein_sync_nonce'),
                'is_mobile' => wp_is_mobile(),
                'plugin_url' => plugin_dir_url(dirname(__FILE__))
            ));

            // Inline scripts for specific functionality
            $this->add_admin_inline_scripts($screen, $hook);
        }
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        $screen = get_current_screen();
        
        if ($this->is_tapgrein_admin_page($screen, $hook)) {
            
            // Enqueue complete styles (includes all inline styles refactored)
            wp_enqueue_style(
                'tapgoods-complete-styles',
                plugin_dir_url(dirname(__FILE__)) . 'assets/css/tapgoods-complete-styles.css',
                array(),
                TAPGOODSWP_VERSION
            );
            
            // Add dynamic admin styles
            $this->add_admin_inline_styles();
        }
    }

    /**
     * Enqueue public scripts
     */
    public function enqueue_public_scripts() {
        
        // Debug: Log enqueue attempts
        error_log('TapGoods: enqueue_public_scripts called');
        error_log('TapGoods: is_tax(tg_tags): ' . (is_tax('tg_tags') ? 'true' : 'false'));
        error_log('TapGoods: should_enqueue_public_scripts(): ' . ($this->should_enqueue_public_scripts() ? 'true' : 'false'));
        
        // Always enqueue on frontend to support builders like Elementor that lazy-load content
        if ($this->should_enqueue_public_scripts()) {
            
            // Enqueue global styles first (contains Inter font definitions)
            wp_enqueue_style(
                'tapgoods-global-styles',
                plugin_dir_url(dirname(__FILE__)) . 'public/css/global-styles.css',
                array(),
                TAPGOODSWP_VERSION,
                'all'
            );
            
            // Enqueue complete public script
            wp_enqueue_script(
                'tapgoods-public-complete',
                plugin_dir_url(dirname(__FILE__)) . 'public/js/tapgoods-public-complete.js',
                array('jquery'),
                TAPGOODSWP_VERSION . '-search-fix',
                true
            );

            // Localize script with necessary data
            wp_localize_script('tapgoods-public-complete', 'tg_public_vars', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'default_location' => get_option('tg_default_location'),
                'plugin_url' => plugin_dir_url(dirname(__FILE__))
            ));

            // Add inline scripts only when shortcodes are present to avoid duplicate init
            $this->add_public_inline_scripts();
        }
    }

    /**
     * Enqueue public styles
     */
    public function enqueue_public_styles() {
        
        if ($this->should_enqueue_public_scripts()) {
            
            // Enqueue complete styles (includes all inline styles refactored)
            wp_enqueue_style(
                'tapgoods-complete-styles',
                plugin_dir_url(dirname(__FILE__)) . 'assets/css/tapgoods-complete-styles.css',
                array(),
                TAPGOODSWP_VERSION
            );
        }
    }

    /**
     * Output dynamic styles for frontend
     */
    public function output_dynamic_styles() {
        if ($this->should_enqueue_public_scripts()) {
            
            // Add location styles
            $location_styles = $this->get_location_styles();
            if (!empty($location_styles)) {
                wp_add_inline_style('tapgoods-complete-styles', $location_styles);
            }
        }
    }

    /**
     * Output dynamic styles for admin
     */
    public function output_admin_dynamic_styles() {
        $screen = get_current_screen();
        
        if ($screen && $screen->post_type === 'tg_inventory') {
            $admin_styles = $this->get_admin_post_type_styles();
            wp_add_inline_style('tapgoods-inline-styles', $admin_styles);
        }
    }

    /**
     * Check if current page is a TapGoods admin page
     */
    private function is_tapgrein_admin_page($screen, $hook) {
        if (!$screen) return false;
        
        $tapgoods_screens = array(
            'edit-tg_inventory',
            'tg_inventory',
            'edit-tg_category',
            'edit-tg_tags', 
            'edit-tg_location',
            'tapgoods_page_tapgoods-options',
            'tapgoods_page_tapgoods-connection',
            'tapgoods_page_tapgoods-status'
        );
        
        return in_array($screen->id, $tapgoods_screens) || 
               (isset($screen->post_type) && $screen->post_type === 'tg_inventory') ||
               strpos($hook, 'tapgoods') !== false;
    }

    /**
     * Check if we should enqueue public scripts
     */
    private function should_enqueue_public_scripts() {
        // For now, always enqueue to ensure compatibility
        // This can be optimized later once we verify everything works
        return true;
        
        /*
        global $post;
        
        // Check if we're on a page that uses TapGoods shortcodes or templates
        if (is_singular('tg_inventory') || 
            is_tax('tg_category') || 
            is_tax('tg_tags') || 
            is_tax('tg_location')) {
            return true;
        }
        
        // Check for shortcodes in post content
        if ($post && (has_shortcode($post->post_content, 'tg_inventory') ||
            has_shortcode($post->post_content, 'tg_search') ||
            has_shortcode($post->post_content, 'tg_cart'))) {
            return true;
        }
        
        return false;
        */
    }

    /**
     * Add admin inline scripts
     */
    private function add_admin_inline_scripts($screen, $hook) {
        
        // Tag focus script - only for tag edit pages and not mobile
        if (strpos($screen->id, 'edit-tg_') !== false && !wp_is_mobile()) {
            wp_add_inline_script('tapgoods-admin-inline', 'initTagFocus();');
        }
    }

    /**
     * Add public inline scripts
     */
    private function add_public_inline_scripts() {
        global $post;
        
        // Location selector functionality - for pages with shortcodes
        if ($this->page_has_tapgoods_shortcode()) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_location_selector_inline_script());
        }
        
        // Cart functionality
        if ($this->page_has_shortcode('tapgoods-cart')) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_cart_inline_script());
        }
        
        // Search functionality
        if ($this->page_has_shortcode(['tapgoods-search', 'tapgoods-inventory'])) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_search_inline_script());
        }
        
        // Inventory grid functionality
        if ($this->page_has_shortcode(['tapgoods-inventory', 'tapgoods-inventory-grid']) || $this->page_has_tapgoods_shortcode()) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_inventory_grid_inline_script());
        }
        
        // Product single functionality
        if ($this->page_has_shortcode('tapgoods-product-single') || is_singular('tg_inventory')) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_product_single_inline_script());
        }
        
        // Filter functionality  
        if ($this->page_has_shortcode('tapgoods-filter')) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_filter_inline_script());
        }
        
        // Thank you page functionality
        if ($this->page_has_shortcode('tapgoods-thankyou')) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_thankyou_inline_script());
        }
        
        // Sign in/up functionality
        if ($this->page_has_shortcode(['tapgoods-sign-in', 'tapgoods-sign-up'])) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_signin_inline_script());
        }
        
        // Tag results functionality
        if ($this->page_has_shortcode('tapgoods-tag-results') || is_tax('tg_tags')) {
            wp_add_inline_script('tapgoods-public-complete', $this->get_tag_results_inline_script());
        }
    }
    
    /**
     * Check if current page has specific TapGoods shortcode
     */
    private function page_has_shortcode($shortcode_names = null) {
        global $post;
        
        if (!$post || !$post->post_content) {
            return false;
        }
        
        if (is_null($shortcode_names)) {
            // Check for any TapGoods shortcode
            return $this->page_has_tapgoods_shortcode();
        }
        
        if (is_string($shortcode_names)) {
            $shortcode_names = [$shortcode_names];
        }
        
        foreach ($shortcode_names as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if page has any TapGoods shortcode
     */
    private function page_has_tapgoods_shortcode() {
        global $post;
        
        if (!$post || !$post->post_content) {
            return false;
        }
        
        $tapgoods_shortcodes = [
            'tapgoods-cart', 'tapgoods-inventory', 'tapgoods-location-select',
            'tapgoods-inventory-grid', 'tapgoods-filter', 'tapgoods-search',
            'tapgoods-product-single', 'tapgoods-search-results',
            'tapgoods-sign-in', 'tapgoods-sign-up', 'tapgoods-tag-results',
            'tapgoods-thankyou'
        ];
        
        foreach ($tapgoods_shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get location selector inline script
     */
    private function get_location_selector_inline_script() {
        $default_location = get_option('tg_default_location');
        return "
        // Ensure tg_public_vars is available for location selector
        if (typeof tg_public_vars === 'undefined') {
            window.tg_public_vars = {
                ajaxurl: '" . esc_js(admin_url('admin-ajax.php')) . "',
                default_location: '" . esc_js($default_location) . "',
                plugin_url: '" . esc_js(plugin_dir_url(dirname(__FILE__))) . "'
            };
        }
        
        // Initialize location selector when page loads
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initLocationSelector === 'function') {
                initLocationSelector();
            }
        });
        ";
    }
    
    /**
     * Get cart inline script
     */
    private function get_cart_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initCartHandlers === 'function') {
                initCartHandlers();
            }
        });
        ";
    }
    
    /**
     * Get search inline script
     */
    private function get_search_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initSearchHandlers === 'function') {
                initSearchHandlers();
            }
        });
        ";
    }
    
    /**
     * Get inventory grid inline script
     */
    private function get_inventory_grid_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initInventoryGrid === 'function') {
                initInventoryGrid();
            }
        });
        ";
    }
    
    /**
     * Get product single inline script
     */
    private function get_product_single_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initProductSingle === 'function') {
                initProductSingle();
            }
        });
        ";
    }
    
    /**
     * Get filter inline script
     */
    private function get_filter_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initFilterHandlers === 'function') {
                initFilterHandlers();
            }
        });
        ";
    }
    
    /**
     * Get thank you page inline script
     */
    private function get_thankyou_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initThankYouPage === 'function') {
                initThankYouPage();
            }
        });
        ";
    }
    
    /**
     * Get sign in/up inline script
     */
    private function get_signin_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initSignInHandlers === 'function') {
                initSignInHandlers();
            }
            if (typeof initSignUpHandlers === 'function') {
                initSignUpHandlers();
            }
        });
        ";
    }
    
    /**
     * Get tag results inline script
     */
    private function get_tag_results_inline_script() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof initTagResults === 'function') {
                initTagResults();
            }
        });
        ";
    }

    /**
     * Add admin inline styles
     */
    private function add_admin_inline_styles() {
        // Add post type specific styles for tg_inventory admin pages
        if (get_current_screen() && strpos(get_current_screen()->id, 'tg_inventory') !== false) {
            wp_add_inline_style('tapgoods-complete-styles', $this->get_admin_post_type_styles());
        }
        
        // Add connection page styles
        if (get_current_screen() && strpos(get_current_screen()->id, 'tapgoods_page_tapgoods-connection') !== false) {
            wp_add_inline_style('tapgoods-complete-styles', $this->get_admin_connection_styles());
        }
    }
    
    /**
     * Get admin connection styles
     */
    private function get_admin_connection_styles() {
        return '
            .tapgoods-success {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
                padding: 15px;
                margin: 10px 0;
                border-radius: 4px;
                display: block;
            }
            
            .overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                display: none;
                justify-content: center;
                align-items: center;
                z-index: 10000;
            }
            
            .overlay .popup {
                background: white;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                max-width: 400px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            }
        ';
    }

    /**
     * Get location styles
     */
    private function get_location_styles() {
        if (function_exists('tapgrein_location_styles')) {
            return tapgrein_location_styles();
        }
        return '';
    }

    /**
     * Get admin post type styles
     */
    private function get_admin_post_type_styles() {
        return '
            /* Ensure the title field is visible */
            #titlediv {
                margin-bottom: 20px;
            }
            
            /* Style for Custom Description */
            #tg_custom_description {
                margin-bottom: 0;
            }
            
            #tg_custom_description .inside {
                padding-top: 10px;
            }
            
            /* Style for Inventory Information */
            #inventory_info {
                margin-top: 20px;
            }
            
            /* Visually move Yoast to the bottom */
            #wpseo_meta {
                margin-top: 30px;
                order: 999;
            }
            
            /* Improve general spacing */
            .postbox {
                margin-bottom: 20px;
            }
            
            /* Hide main content editor if it exists */
            #postdivrich {
                display: none;
            }
        ';
    }
}

// Initialize the enqueue manager
Tapgoods_Enqueue::get_instance();