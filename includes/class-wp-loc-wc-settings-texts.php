<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Multilingual free-text WooCommerce settings.
 *
 * Registers selected settings fields with the wp-loc multilingual options
 * engine and opts the WooCommerce settings screen into its admin-language
 * handling, so the native field shows and saves the value of the language
 * picked in the admin bar — no duplicated per-language fields.
 *
 * Clearing a translation falls back to the default-language value.
 */
class WP_LOC_WC_Settings_Texts {

    const SCREEN_ID = 'woocommerce_page_wc-settings';

    /**
     * Translated option ids, keyed by the WC settings page id they live on.
     */
    const TRANSLATED_OPTIONS = [
        'account' => [
            'woocommerce_registration_privacy_policy_text',
            'woocommerce_checkout_privacy_policy_text',
        ],
    ];

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
        add_filter( 'wp_loc_is_localized_options_screen', [ $this, 'localize_settings_screen' ], 10, 2 );
    }

    public function register(): void {
        foreach ( self::translated_options() as $options ) {
            foreach ( $options as $option ) {
                do_action( 'wp_loc_multilingual_options', $option );
            }
        }
    }

    /**
     * Option ids to translate, keyed by WC settings page id.
     */
    private static function translated_options(): array {
        return apply_filters( 'wp_loc_wc_translated_settings_options', self::TRANSLATED_OPTIONS );
    }

    /**
     * Opt the WooCommerce settings screen into admin-language option values.
     */
    public function localize_settings_screen( $is_settings, $screen ): bool {
        return (bool) $is_settings || ( $screen->id ?? '' ) === self::SCREEN_ID;
    }
}
