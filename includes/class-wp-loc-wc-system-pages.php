<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolves WooCommerce system pages (Shop, Cart, Checkout, My Account, Terms)
 * to their translation in the active language, with fallback to the original.
 */
class WP_LOC_WC_System_Pages {

    const PAGES = [ 'shop', 'cart', 'checkout', 'myaccount', 'terms' ];

    public function __construct() {
        foreach ( self::PAGES as $page ) {
            add_filter( "woocommerce_get_{$page}_page_id", [ $this, 'translate_page_id' ] );
        }

        add_action( 'admin_notices', [ $this, 'warn_missing_translations' ] );
    }

    public function translate_page_id( $page_id ) {
        $page_id = (int) $page_id;

        if ( $page_id < 1 || WP_LOC_WC_Lang::is_default_lang() ) {
            return $page_id;
        }

        return WP_LOC_WC_Lang::translated_post_id_or_self( $page_id, 'page', WP_LOC_WC_Lang::current_lang() );
    }

    /**
     * Warns on WooCommerce and Multilingual admin screens when a system page
     * has no translation in an active secondary language.
     */
    public function warn_missing_translations(): void {
        if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || ( strpos( $screen->id, 'wc-settings' ) === false && strpos( $screen->id, 'wp-loc' ) === false ) ) {
            return;
        }

        $missing = [];
        $default_lang = WP_LOC_WC_Lang::default_lang();

        foreach ( self::PAGES as $page ) {
            remove_filter( "woocommerce_get_{$page}_page_id", [ $this, 'translate_page_id' ] );
            $page_id = wc_get_page_id( $page );
            add_filter( "woocommerce_get_{$page}_page_id", [ $this, 'translate_page_id' ] );

            if ( $page_id < 1 ) {
                continue;
            }

            foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
                if ( $lang === $default_lang ) {
                    continue;
                }

                if ( WP_LOC_WC_Lang::translated_post_id( $page_id, 'page', $lang ) === null ) {
                    $missing[] = sprintf( '%s (%s)', ucfirst( $page ), strtoupper( $lang ) );
                }
            }
        }

        if ( ! $missing ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__( 'WP-LOC WooCommerce: the following shop pages have no translation:', 'wp-loc-wc' )
            . ' <strong>' . esc_html( implode( ', ', $missing ) ) . '</strong></p></div>';
    }
}
