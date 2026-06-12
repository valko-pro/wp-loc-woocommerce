<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_WC {

    private static $instance = null;

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
        ];

        foreach ( $includes as $file ) {
            require_once WP_LOC_WC_PATH . "includes/{$file}.php";
        }
    }

    private function init_modules() {
    }
}
