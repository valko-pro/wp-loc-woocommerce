<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persists the storefront language on the order (WPML-compatible meta key)
 * so emails and integrations can address the customer in their language.
 */
class WP_LOC_WC_Order_Language {

    const META_KEY = '_wpml_language';

    public function __construct() {
        add_action( 'woocommerce_checkout_create_order', [ $this, 'store_language' ] );
    }

    public function store_language( $order ): void {
        $order->update_meta_data( self::META_KEY, WP_LOC_WC_Lang::current_lang() );
    }

    /**
     * Order language with fallback to the default language for legacy orders.
     */
    public static function get( WC_Order $order ): string {
        $lang = (string) $order->get_meta( self::META_KEY );

        if ( $lang && array_key_exists( $lang, WP_LOC_Languages::get_active_languages() ) ) {
            return $lang;
        }

        return WP_LOC_WC_Lang::default_lang();
    }
}
