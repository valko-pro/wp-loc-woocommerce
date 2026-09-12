<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "WooCommerce" tab on the Multilingual → Settings screen: status overview
 * + permalink base translations. Rendered and saved through the wp-loc
 * settings extension hooks (the form, nonce and redirect belong to wp-loc).
 */
class WP_LOC_WC_Admin_Page {

    const TAB = 'woocommerce';

    public function __construct() {
        add_filter( 'wp_loc_settings_tabs', [ $this, 'register_tab' ] );
        add_action( 'wp_loc_settings_render_' . self::TAB, [ $this, 'render_tab' ] );
        add_action( 'wp_loc_settings_save_' . self::TAB, [ $this, 'handle_save' ] );
    }

    public function register_tab( array $tabs ): array {
        $tabs[ self::TAB ] = __( 'WooCommerce', 'wp-loc-wc' );

        return $tabs;
    }

    public function handle_save(): void {
        $default_lang = WP_LOC_WC_Lang::default_lang();
        $raw = isset( $_POST['wp_loc_wc_permalinks'] ) && is_array( $_POST['wp_loc_wc_permalinks'] ) ? $_POST['wp_loc_wc_permalinks'] : [];
        $strings = [];

        foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
            if ( $lang === $default_lang || empty( $raw[ $lang ] ) || ! is_array( $raw[ $lang ] ) ) {
                continue;
            }

            foreach ( WP_LOC_WC_Permalinks::BASE_KEYS as $key ) {
                $value = sanitize_title( trim( (string) ( $raw[ $lang ][ $key ] ?? '' ), '/' ) );

                if ( $value !== '' ) {
                    $strings[ $lang ][ $key ] = $value;
                }
            }
        }

        update_option( WP_LOC_WC_Permalinks::OPTION, $strings );
        update_option( WP_LOC_WC_Permalinks::LEGACY_REDIRECT_OPTION, empty( $_POST['wp_loc_wc_legacy_redirect'] ) ? 0 : 1 );

        // Deferred flush on the next init — the same mechanism wp-loc uses.
        update_option( 'wp_loc_flush_rewrite_rules', true );
    }

    public function render_tab(): void {
        echo '<div class="wp-loc-settings-section">';
        $this->render_status();
        $this->render_permalinks();
        echo '</div>';
    }

    private function render_status(): void {
        $post_types = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        $taxonomies = apply_filters( 'wp_loc_translatable_taxonomies', [ 'category', 'post_tag' ] );

        echo '<h2>' . esc_html__( 'Translatable WooCommerce content', 'wp-loc-wc' ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'Post types:', 'wp-loc-wc' ) . '</strong> ' . esc_html( implode( ', ', $post_types ) ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Taxonomies:', 'wp-loc-wc' ) . '</strong> ' . esc_html( implode( ', ', $taxonomies ) ) . '</p>';

        echo '<h2>' . esc_html__( 'Shop pages translations', 'wp-loc-wc' ) . '</h2>';
        echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Page', 'wp-loc-wc' ) . '</th>';

        $default_lang = WP_LOC_WC_Lang::default_lang();
        $languages = WP_LOC_Languages::get_active_languages();

        foreach ( $languages as $lang => $data ) {
            echo '<th>' . esc_html( strtoupper( $lang ) ) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ( WP_LOC_WC_System_Pages::PAGES as $page ) {
            $page_id = (int) get_option( "woocommerce_{$page}_page_id" );

            echo '<tr><td>' . esc_html( ucfirst( $page ) ) . '</td>';

            foreach ( $languages as $lang => $data ) {
                if ( $page_id < 1 ) {
                    echo '<td>—</td>';
                    continue;
                }

                $translated = $lang === $default_lang
                    ? $page_id
                    : WP_LOC_WC_Lang::translated_post_id( $page_id, 'page', $lang );

                echo $translated
                    ? '<td><a href="' . esc_url( get_edit_post_link( $translated ) ) . '">✓</a></td>'
                    : '<td style="color:#b32d2e">✕</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Store system pages (Shop, Cart, Checkout, My Account, Terms) are resolved to their translated version for the active language automatically — if a translation is missing the original page is used as a fallback.', 'wp-loc-wc' ) . '</p>';
    }

    private function render_permalinks(): void {
        $defaults = WP_LOC_WC_Permalinks::default_bases();
        $default_lang = WP_LOC_WC_Lang::default_lang();
        $labels = [
            'product'  => __( 'Product base', 'wp-loc-wc' ),
            'category' => __( 'Category base', 'wp-loc-wc' ),
            'tag'      => __( 'Tag base', 'wp-loc-wc' ),
        ];

        echo '<h2>' . esc_html__( 'Permalinks', 'wp-loc-wc' ) . '</h2>';
        echo '<p>' . esc_html__( 'Translate WC permalink bases per language. Empty field = default base under the language prefix. Rewrite rules refresh on the next page load after saving.', 'wp-loc-wc' ) . '</p>';

        foreach ( WP_LOC_Languages::get_active_languages() as $lang => $data ) {
            if ( $lang === $default_lang ) {
                continue;
            }

            echo '<h3>' . esc_html( strtoupper( $lang ) ) . '</h3><table class="form-table">';

            foreach ( WP_LOC_WC_Permalinks::BASE_KEYS as $key ) {
                $value = WP_LOC_WC_Permalinks::base_for( $lang, $key );

                echo '<tr><th scope="row"><label>' . esc_html( $labels[ $key ] ) . '</label></th><td>';
                echo '<code>/' . esc_html( $lang ) . '/</code> ';
                echo '<input type="text" name="wp_loc_wc_permalinks[' . esc_attr( $lang ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $defaults[ $key ] ) . '" class="regular-text">';

                if ( $defaults[ $key ] === '' ) {
                    echo '<p class="description">' . esc_html__( 'The default base contains rewrite tags and cannot be translated.', 'wp-loc-wc' ) . '</p>';
                }

                echo '</td></tr>';
            }

            echo '</table>';
        }

        echo '<table class="form-table"><tr>';
        echo '<th scope="row">' . esc_html__( 'Legacy redirects', 'wp-loc-wc' ) . '</th><td><label>';
        echo '<input type="checkbox" name="wp_loc_wc_legacy_redirect" value="1"' . checked( (bool) get_option( WP_LOC_WC_Permalinks::LEGACY_REDIRECT_OPTION ), true, false ) . '> ';
        echo esc_html__( '301 redirect old default-base URLs to the translated base', 'wp-loc-wc' );
        echo '</label></td></tr></table>';
    }
}
