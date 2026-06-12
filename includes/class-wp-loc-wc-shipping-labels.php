<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translated shipping method labels.
 *
 * Per-language title fields are injected into each shipping method's native
 * instance settings form (zone method modal), so translations are stored in
 * the instance option itself — no parallel storage layer.
 */
class WP_LOC_WC_Shipping_Labels {

    public function __construct() {
        add_filter( 'woocommerce_package_rates', [ $this, 'translate_labels' ] );
        add_action( 'init', [ $this, 'register_form_field_filters' ], 20 );
    }

    public function register_form_field_filters(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
            return;
        }

        foreach ( WC()->shipping()->get_shipping_method_class_names() as $method_id => $class_name ) {
            add_filter( "woocommerce_shipping_instance_form_fields_{$method_id}", [ $this, 'add_translation_fields' ] );
        }
    }

    public function add_translation_fields( array $fields ): array {
        if ( ! isset( $fields['title'] ) ) {
            return $fields;
        }

        $default_lang = WP_LOC_WC_Lang::default_lang();
        $with_translations = [];

        foreach ( $fields as $key => $field ) {
            $with_translations[ $key ] = $field;

            if ( $key !== 'title' ) {
                continue;
            }

            foreach ( WP_LOC_Languages::get_active_languages() as $lang => $data ) {
                if ( $lang === $default_lang ) {
                    continue;
                }

                $with_translations[ "title_{$lang}" ] = [
                    'title'       => sprintf( '%s (%s)', $field['title'] ?? __( 'Title', 'wp-loc-wc' ), strtoupper( $lang ) ),
                    'type'        => 'text',
                    'description' => __( 'Translation shown to customers browsing in this language.', 'wp-loc-wc' ),
                    'desc_tip'    => true,
                    'default'     => '',
                ];
            }
        }

        return $with_translations;
    }

    public function translate_labels( array $rates ): array {
        if ( WP_LOC_WC_Lang::is_default_lang() ) {
            return $rates;
        }

        $lang = WP_LOC_WC_Lang::current_lang();

        foreach ( $rates as $rate ) {
            $translated = $this->instance_setting( $rate->get_method_id(), (int) $rate->get_instance_id(), "title_{$lang}" );

            if ( $translated !== '' ) {
                $rate->set_label( $translated );
            }
        }

        return $rates;
    }

    private function instance_setting( string $method_id, int $instance_id, string $key ): string {
        if ( ! $instance_id ) {
            return '';
        }

        $settings = get_option( "woocommerce_{$method_id}_{$instance_id}_settings", [] );

        return is_array( $settings ) && isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
    }
}
