<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Multilingual free-text WooCommerce settings.
 *
 * Injects a per-language copy of selected settings fields into the native WC
 * settings screens, so translations are edited next to the original and saved
 * as `{option}_{lang}` options — the exact location the wp-loc multilingual
 * options engine reads from when serving a non-default language.
 */
class WP_LOC_WC_Settings_Texts {

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
    }

    public function register(): void {
        foreach ( self::translated_options() as $page_id => $options ) {
            foreach ( $options as $option ) {
                do_action( 'wp_loc_multilingual_options', $option );
            }

            add_filter( "woocommerce_get_settings_{$page_id}", [ $this, 'add_translation_fields' ] );
        }
    }

    /**
     * Option ids to translate, keyed by WC settings page id.
     */
    private static function translated_options(): array {
        return apply_filters( 'wp_loc_wc_translated_settings_options', self::TRANSLATED_OPTIONS );
    }

    public function add_translation_fields( array $settings ): array {
        $translated   = array_merge( [], ...array_values( self::translated_options() ) );
        $default_lang = WP_LOC_WC_Lang::default_lang();
        $with_translations = [];

        foreach ( $settings as $field ) {
            $with_translations[] = $field;

            $option_id = $field['id'] ?? '';

            if ( ! in_array( $option_id, $translated, true ) ) {
                continue;
            }

            foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
                if ( $lang === $default_lang ) {
                    continue;
                }

                $lang_field             = $field;
                $lang_field['id']       = self::localized_option_name( $option_id, $lang );
                $lang_field['title']    = sprintf( '%s (%s)', $field['title'] ?? $option_id, strtoupper( $lang ) );
                $lang_field['desc_tip'] = __( 'Translation shown to customers browsing in this language. Leave empty to fall back to the text above.', 'wp-loc-wc' );
                $lang_field['default']  = '';

                $with_translations[] = $lang_field;
            }
        }

        return $with_translations;
    }

    /**
     * Option name holding the translation, matching WP_LOC_Options suffix logic.
     */
    private static function localized_option_name( string $option, string $lang ): string {
        $suffix = WP_LOC_DB::to_db_language_code( $lang ) ?: $lang;

        return "{$option}_{$suffix}";
    }
}
