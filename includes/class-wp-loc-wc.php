<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_WC {

    private static $instance = null;

    public $taxonomies;
    public $system_pages;
    public $cart;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_includes();
        $this->init_modules();
    }

    private function load_includes() {
        $includes = [
            'class-wp-loc-wc-lang',
            'class-wp-loc-wc-taxonomies',
            'class-wp-loc-wc-system-pages',
            'class-wp-loc-wc-cart',
        ];

        foreach ( $includes as $file ) {
            require_once WP_LOC_WC_PATH . "includes/{$file}.php";
        }
    }

    private function init_modules() {
        $this->taxonomies   = new WP_LOC_WC_Taxonomies();
        $this->system_pages = new WP_LOC_WC_System_Pages();
        $this->cart         = new WP_LOC_WC_Cart();
    }
}
