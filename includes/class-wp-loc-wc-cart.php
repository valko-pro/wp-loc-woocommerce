<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keeps the cart usable across language switches.
 *
 * - On add to cart, simple products are normalized to the default-language ID,
 *   so the same product added from two languages merges into one line.
 * - On session load, every item is presented in the current language with
 *   fallback to the stored ID when no translation exists.
 *
 * Known limitation: a variation added from two different languages produces two
 * cart lines (cart keys include language-specific attribute slugs).
 */
class WP_LOC_WC_Cart {

    private $notified_untranslated = false;

    public function __construct() {
        add_filter( 'woocommerce_add_to_cart_product_id', [ $this, 'normalize_product_id' ] );
        add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'translate_cart_item' ] );
    }

    /**
     * Canonical cart key: simple products are stored under their
     * default-language ID. Variations are left untouched — swapping only the
     * parent would break the parent/variation pair validation in WC_Cart.
     */
    public function normalize_product_id( $product_id ) {
        $product_id = (int) $product_id;

        if ( $product_id < 1 || ! empty( $_REQUEST['variation_id'] ) ) {
            return $product_id;
        }

        if ( WP_LOC_WC_Lang::is_default_lang() ) {
            return $product_id;
        }

        return WP_LOC_WC_Lang::translated_post_id_or_self( $product_id, 'product', WP_LOC_WC_Lang::default_lang() );
    }

    /**
     * Substitute product/variation IDs with the current-language pair.
     */
    public function translate_cart_item( $session_data ) {
        if ( ! is_array( $session_data ) || empty( $session_data['product_id'] ) ) {
            return $session_data;
        }

        $lang = WP_LOC_WC_Lang::current_lang();
        $stored_product_id = (int) $session_data['product_id'];
        $stored_lang = WP_LOC::instance()->db->get_element_language( $stored_product_id, WP_LOC_DB::post_element_type( 'product' ) );

        if ( $stored_lang === null || WP_LOC_DB::from_db_language_code( $stored_lang ) === $lang ) {
            return $session_data;
        }

        $translated_product_id = WP_LOC_WC_Lang::translated_post_id( $stored_product_id, 'product', $lang );

        if ( $translated_product_id === null ) {
            $this->notify_untranslated();
            return $session_data;
        }

        $translated_variation_id = 0;

        if ( ! empty( $session_data['variation_id'] ) ) {
            $translated_variation_id = WP_LOC_WC_Lang::translated_post_id( (int) $session_data['variation_id'], 'product_variation', $lang ) ?? 0;

            if ( ! $translated_variation_id ) {
                // Variation pair missing (sync incomplete) — keep the stored item intact.
                $this->notify_untranslated();
                return $session_data;
            }
        }

        $product = wc_get_product( $translated_variation_id ?: $translated_product_id );

        if ( ! $product || ! $product->exists() ) {
            return $session_data;
        }

        $session_data['product_id'] = $translated_product_id;

        if ( $translated_variation_id ) {
            $session_data['variation_id'] = $translated_variation_id;
        }

        $session_data['data'] = $product;

        return $session_data;
    }

    private function notify_untranslated(): void {
        if ( $this->notified_untranslated || is_admin() || ! function_exists( 'wc_add_notice' ) || ! WC()->session ) {
            return;
        }

        $this->notified_untranslated = true;

        if ( ! wc_has_notice( __( 'Some cart items are not available in this language and are shown in their original language.', 'wp-loc-wc' ), 'notice' ) ) {
            wc_add_notice( __( 'Some cart items are not available in this language and are shown in their original language.', 'wp-loc-wc' ), 'notice' );
        }
    }
}
