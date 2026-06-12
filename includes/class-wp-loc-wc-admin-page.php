<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Multilingual → WooCommerce admin page: status overview + permalink
 * base translations. Mimics the wp-loc settings page patterns
 * (nav tabs, POST-to-self, nonce, ?updated=1 redirect).
 */
class WP_LOC_WC_Admin_Page {

    const PAGE_SLUG = 'wp-loc-woocommerce';

    const TAB_STATUS = 'status';
    const TAB_PERMALINKS = 'permalinks';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 30 );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
    }

    public function register_page(): void {
        add_submenu_page(
            'wp-loc',
            __( 'WooCommerce Multilingual', 'wp-loc-wc' ),
            __( 'WooCommerce', 'wp-loc-wc' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    private function get_current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : self::TAB_STATUS;

        return in_array( $tab, [ self::TAB_STATUS, self::TAB_PERMALINKS ], true ) ? $tab : self::TAB_STATUS;
    }

    public function handle_save(): void {
        if ( ! isset( $_POST['wp_loc_wc_permalinks_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['wp_loc_wc_permalinks_nonce'], 'wp_loc_wc_save_permalinks' ) ) {
            return;
        }

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

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => self::TAB_PERMALINKS, 'updated' => 1 ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function render_page(): void {
        $tab = $this->get_current_tab();

        echo '<div class="wrap"><h1>' . esc_html__( 'WooCommerce Multilingual', 'wp-loc-wc' ) . '</h1>';

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved. Rewrite rules will refresh on the next page load.', 'wp-loc-wc' ) . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper">';

        foreach ( [ self::TAB_STATUS => __( 'Status', 'wp-loc-wc' ), self::TAB_PERMALINKS => __( 'Permalinks', 'wp-loc-wc' ) ] as $key => $label ) {
            $url = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $key ], admin_url( 'admin.php' ) );
            $class = $tab === $key ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }

        echo '</nav>';

        if ( $tab === self::TAB_PERMALINKS ) {
            $this->render_permalinks_tab();
        } else {
            $this->render_status_tab();
        }

        echo '</div>';
    }

    private function render_status_tab(): void {
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
    }

    private function render_permalinks_tab(): void {
        $defaults = WP_LOC_WC_Permalinks::default_bases();
        $default_lang = WP_LOC_WC_Lang::default_lang();
        $labels = [
            'product'  => __( 'Product base', 'wp-loc-wc' ),
            'category' => __( 'Category base', 'wp-loc-wc' ),
            'tag'      => __( 'Tag base', 'wp-loc-wc' ),
        ];

        echo '<form method="post">';
        wp_nonce_field( 'wp_loc_wc_save_permalinks', 'wp_loc_wc_permalinks_nonce' );

        echo '<p>' . esc_html__( 'Translate WC permalink bases per language. Empty field = default base under the language prefix.', 'wp-loc-wc' ) . '</p>';

        foreach ( WP_LOC_Languages::get_active_languages() as $lang => $data ) {
            if ( $lang === $default_lang ) {
                continue;
            }

            echo '<h2>' . esc_html( strtoupper( $lang ) ) . '</h2><table class="form-table">';

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

        echo '<h2>' . esc_html__( 'Options', 'wp-loc-wc' ) . '</h2><table class="form-table"><tr>';
        echo '<th scope="row">' . esc_html__( 'Legacy redirects', 'wp-loc-wc' ) . '</th><td><label>';
        echo '<input type="checkbox" name="wp_loc_wc_legacy_redirect" value="1"' . checked( (bool) get_option( WP_LOC_WC_Permalinks::LEGACY_REDIRECT_OPTION ), true, false ) . '> ';
        echo esc_html__( '301 redirect old default-base URLs to the translated base', 'wp-loc-wc' );
        echo '</label></td></tr></table>';

        submit_button();
        echo '</form>';
    }
}
