<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translatable coupons: the description follows the language, the coupon
 * code (post_title) is a shared identifier enforced across the pair.
 */
class WP_LOC_WC_Coupons {

    const NOTICE_TRANSIENT = 'wp_loc_wc_coupon_code_reset';

    private static $enforcing = false;

    public function __construct() {
        add_filter( 'wp_loc_translatable_post_types', [ $this, 'add_post_type' ], 20 );
        add_action( 'save_post_shop_coupon', [ $this, 'enforce_shared_code' ], 50, 2 );
        add_action( 'woocommerce_coupon_loaded', [ $this, 'localize_description' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_code_reset_notice' ] );
    }

    public function add_post_type( array $post_types ): array {
        return array_values( array_unique( array_merge( $post_types, [ 'shop_coupon' ] ) ) );
    }

    /**
     * A translated coupon must keep the default-language code: WC matches
     * coupons by code, and diverging codes would silently split the pair.
     */
    public function enforce_shared_code( int $post_id, $post ): void {
        if ( self::$enforcing || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'shop_coupon' );
        $lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( $post_id, $element_type ) );
        $default_lang = WP_LOC_WC_Lang::default_lang();

        if ( $lang === null || $lang === $default_lang ) {
            return;
        }

        $original_id = $db->get_element_translation( $post_id, $element_type, $default_lang );

        if ( ! $original_id || $original_id === $post_id ) {
            return;
        }

        $original = get_post( $original_id );

        if ( ! $original || $original->post_title === $post->post_title ) {
            return;
        }

        self::$enforcing = true;

        wp_update_post( [
            'ID'         => $post_id,
            'post_title' => $original->post_title,
            'post_name'  => sanitize_title( $original->post_title ) . '-' . $lang,
        ] );

        self::$enforcing = false;

        set_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $original->post_title, 60 );
    }

    /**
     * Show the description of the current-language translation regardless of
     * which post the coupon was resolved from (WC matches by code via raw SQL).
     */
    public function localize_description( WC_Coupon $coupon ): void {
        $coupon_id = $coupon->get_id();

        if ( ! $coupon_id || is_admin() ) {
            return;
        }

        $translated_id = WP_LOC_WC_Lang::translated_post_id( $coupon_id, 'shop_coupon', WP_LOC_WC_Lang::current_lang() );

        if ( ! $translated_id || $translated_id === $coupon_id ) {
            return;
        }

        $translated = get_post( $translated_id );

        if ( $translated && $translated->post_excerpt !== '' ) {
            $coupon->set_description( $translated->post_excerpt );
        }
    }

    public function maybe_show_code_reset_notice(): void {
        $code = get_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id() );

        if ( ! $code ) {
            return;
        }

        delete_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id() );

        echo '<div class="notice notice-warning"><p>'
            . sprintf(
                /* translators: %s: coupon code */
                esc_html__( 'Coupon code is shared across languages and was reset to "%s". Translate only the description.', 'wp-loc-wc' ),
                esc_html( $code )
            )
            . '</p></div>';
    }
}
