<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translated payment gateway labels (title, description, instructions).
 *
 * Per-language fields are injected into each gateway's native settings form,
 * so translations live in the gateway's own settings option.
 */
class WP_LOC_WC_Gateway_Labels {

    const TRANSLATED_FIELDS = [ 'title', 'description', 'instructions' ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_form_field_filters' ], 20 );
        add_filter( 'woocommerce_gateway_title', [ $this, 'translate_title' ], 10, 2 );
        add_filter( 'woocommerce_gateway_description', [ $this, 'translate_description' ], 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'translate_instructions' ], 20 );
    }

    public function register_form_field_filters(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return;
        }

        foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
            add_filter( "woocommerce_settings_api_form_fields_{$gateway->id}", [ $this, 'add_translation_fields' ] );
        }
    }

    public function add_translation_fields( array $fields ): array {
        $default_lang = WP_LOC_WC_Lang::default_lang();
        $with_translations = [];

        foreach ( $fields as $key => $field ) {
            $with_translations[ $key ] = $field;

            if ( ! in_array( $key, self::TRANSLATED_FIELDS, true ) ) {
                continue;
            }

            foreach ( WP_LOC_Languages::get_active_languages() as $lang => $data ) {
                if ( $lang === $default_lang ) {
                    continue;
                }

                $with_translations[ "{$key}_{$lang}" ] = [
                    'title'       => sprintf( '%s (%s)', $field['title'] ?? ucfirst( $key ), strtoupper( $lang ) ),
                    'type'        => $field['type'] === 'textarea' ? 'textarea' : 'text',
                    'description' => __( 'Translation shown to customers browsing in this language.', 'wp-loc-wc' ),
                    'desc_tip'    => true,
                    'default'     => '',
                ];
            }
        }

        return $with_translations;
    }

    public function translate_title( $title, $gateway_id ) {
        return $this->translated_setting( (string) $gateway_id, 'title' ) ?: $title;
    }

    public function translate_description( $description, $gateway_id ) {
        return $this->translated_setting( (string) $gateway_id, 'description' ) ?: $description;
    }

    /**
     * Instructions have no dedicated filter — mutate the public property on
     * the gateway objects served to the checkout.
     */
    public function translate_instructions( array $gateways ): array {
        if ( WP_LOC_WC_Lang::is_default_lang() ) {
            return $gateways;
        }

        foreach ( $gateways as $gateway ) {
            if ( ! property_exists( $gateway, 'instructions' ) ) {
                continue;
            }

            $translated = $this->translated_setting( $gateway->id, 'instructions' );

            if ( $translated !== '' ) {
                $gateway->instructions = $translated;
            }
        }

        return $gateways;
    }

    private function translated_setting( string $gateway_id, string $field ): string {
        if ( WP_LOC_WC_Lang::is_default_lang() ) {
            return '';
        }

        $lang = WP_LOC_WC_Lang::current_lang();
        $settings = get_option( "woocommerce_{$gateway_id}_settings", [] );

        return is_array( $settings ) && isset( $settings[ "{$field}_{$lang}" ] ) ? (string) $settings[ "{$field}_{$lang}" ] : '';
    }
}
