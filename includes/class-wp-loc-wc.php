<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_WC {

    private static $instance = null;

    public $taxonomies;
    public $system_pages;
    public $cart;
    public $variations;
    public $order_language;
    public $emails;
    public $coupons;
    public $shipping_labels;
    public $gateway_labels;

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
            'class-wp-loc-wc-variations',
            'class-wp-loc-wc-order-language',
            'class-wp-loc-wc-emails',
            'class-wp-loc-wc-coupons',
            'class-wp-loc-wc-shipping-labels',
            'class-wp-loc-wc-gateway-labels',
        ];

        foreach ( $includes as $file ) {
            require_once WP_LOC_WC_PATH . "includes/{$file}.php";
        }
    }

    private function init_modules() {
        $this->taxonomies   = new WP_LOC_WC_Taxonomies();
        $this->system_pages = new WP_LOC_WC_System_Pages();
        $this->cart         = new WP_LOC_WC_Cart();
        $this->variations   = new WP_LOC_WC_Variations();

        $this->order_language  = new WP_LOC_WC_Order_Language();
        $this->emails          = new WP_LOC_WC_Emails();
        $this->coupons         = new WP_LOC_WC_Coupons();
        $this->shipping_labels = new WP_LOC_WC_Shipping_Labels();
        $this->gateway_labels  = new WP_LOC_WC_Gateway_Labels();
    }
}
