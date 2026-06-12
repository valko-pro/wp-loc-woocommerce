<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translated WC permalink bases (/product/ → /ru/tovar/).
 *
 * URL generation is object-aware: the base is replaced according to the
 * language of the linked object (not the current context), so hreflang
 * alternates and cross-language links come out correct. Parsing of the
 * translated URLs is handled by WP_LOC_WC_Rewrite_Rules.
 *
 * Off by default: with no translation saved, secondary languages keep the
 * default base under their prefix (/ru/product/...).
 */
class WP_LOC_WC_Permalinks {

    const OPTION = 'wp_loc_wc_permalink_strings';
    const LEGACY_REDIRECT_OPTION = 'wp_loc_wc_permalink_legacy_redirect';

    const BASE_KEYS = [ 'product', 'category', 'tag' ];

    public function __construct() {
        add_filter( 'post_type_link', [ $this, 'translate_product_link' ], 9, 2 );
        add_filter( 'term_link', [ $this, 'translate_term_link' ], 9, 3 );
    }

    /** @return array<string,array<string,string>> [lang => [product|category|tag => base]] */
    public static function get_strings(): array {
        $strings = get_option( self::OPTION, [] );

        return is_array( $strings ) ? $strings : [];
    }

    public static function base_for( string $lang, string $key ): string {
        $strings = self::get_strings();

        return isset( $strings[ $lang ][ $key ] ) ? trim( (string) $strings[ $lang ][ $key ], '/' ) : '';
    }

    /**
     * Default-language bases from WC settings. Placeholder bases containing
     * rewrite tags (e.g. /shop/%product_cat%) are not translatable.
     */
    public static function default_bases(): array {
        $permalinks = wc_get_permalink_structure();

        $clean = function ( $base ) {
            $base = trim( (string) $base, '/' );

            return strpos( $base, '%' ) === false ? $base : '';
        };

        return [
            'product'  => $clean( $permalinks['product_base'] ?? '' ),
            'category' => $clean( $permalinks['category_base'] ?? '' ),
            'tag'      => $clean( $permalinks['tag_base'] ?? '' ),
        ];
    }

    public function translate_product_link( $url, $post ) {
        if ( ! $post instanceof WP_Post || $post->post_type !== 'product' ) {
            return $url;
        }

        $lang = WP_LOC_DB::from_db_language_code(
            WP_LOC::instance()->db->get_element_language( (int) $post->ID, WP_LOC_DB::post_element_type( 'product' ) )
        );

        return $this->replace_base( $url, $lang, 'product' );
    }

    public function translate_term_link( $url, $term, $taxonomy ) {
        $key = $taxonomy === 'product_cat' ? 'category' : ( $taxonomy === 'product_tag' ? 'tag' : '' );

        if ( $key === '' || ! $term instanceof WP_Term ) {
            return $url;
        }

        $lang = WP_LOC_DB::from_db_language_code(
            WP_LOC::instance()->db->get_element_language( (int) $term->term_taxonomy_id, WP_LOC_DB::tax_element_type( $taxonomy ) )
        );

        return $this->replace_base( $url, $lang, $key );
    }

    private function replace_base( string $url, ?string $lang, string $key ): string {
        if ( $lang === null || $lang === WP_LOC_WC_Lang::default_lang() ) {
            return $url;
        }

        $translated = self::base_for( $lang, $key );

        if ( $translated === '' ) {
            return $url;
        }

        $default = self::default_bases()[ $key ];

        if ( $default === '' || $default === $translated ) {
            return $url;
        }

        return preg_replace( '#/' . preg_quote( $default, '#' ) . '/#', "/{$translated}/", $url, 1 );
    }
}
