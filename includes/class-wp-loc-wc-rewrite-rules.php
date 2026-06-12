<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rewrite rules for translated WC permalink bases.
 *
 * Two rule sets are cloned per language with a translated base:
 *
 * - unprefixed (tovar/... → same query as product/...): wp-loc filters
 *   home_url per language, so core strips the /ru/ prefix from the request
 *   path BEFORE rule matching and the unprefixed rules do the real work
 *   (language comes from wp-loc's URI sniffing in the request filter);
 * - prefixed (^ru/tovar/... → ...&lang=ru): for contexts where the home
 *   path does not strip the prefix (mirrors wp-loc's own belt-and-suspenders
 *   duplication at priority 10; this filter runs at 20).
 *
 * Original-base rules stay valid; with the legacy-redirect toggle on, old
 * URLs 301 to the translated base.
 */
class WP_LOC_WC_Rewrite_Rules {

    public function __construct() {
        add_filter( 'rewrite_rules_array', [ $this, 'add_translated_base_rules' ], 20 );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_legacy_base' ], 5 );
    }

    public function add_translated_base_rules( array $rules ): array {
        $defaults = WP_LOC_WC_Permalinks::default_bases();
        $translated_rules = [];

        foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
            if ( $lang === WP_LOC_WC_Lang::default_lang() ) {
                continue;
            }

            foreach ( WP_LOC_WC_Permalinks::BASE_KEYS as $key ) {
                $translated = WP_LOC_WC_Permalinks::base_for( $lang, $key );
                $default = $defaults[ $key ];

                if ( $translated === '' || $default === '' || $translated === $default ) {
                    continue;
                }

                $prefix = "^{$lang}/{$default}/";

                foreach ( $rules as $regex => $query ) {
                    if ( str_starts_with( $regex, $prefix ) ) {
                        $translated_rules[ "^{$lang}/{$translated}/" . substr( $regex, strlen( $prefix ) ) ] = $query;
                    } elseif ( str_starts_with( $regex, "{$default}/" ) ) {
                        $translated_rules[ "{$translated}/" . substr( $regex, strlen( "{$default}/" ) ) ] = $query;
                    }
                }
            }
        }

        return $translated_rules + $rules;
    }

    /**
     * 301 from the default base under a language prefix (/ru/product/...) to
     * the translated base (/ru/tovar/...) when a translation is configured.
     */
    public function maybe_redirect_legacy_base(): void {
        if ( is_admin() || ! get_option( WP_LOC_WC_Permalinks::LEGACY_REDIRECT_OPTION ) ) {
            return;
        }

        $lang = WP_LOC_WC_Lang::current_lang();

        if ( $lang === WP_LOC_WC_Lang::default_lang() ) {
            return;
        }

        $path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $defaults = WP_LOC_WC_Permalinks::default_bases();

        foreach ( WP_LOC_WC_Permalinks::BASE_KEYS as $key ) {
            $translated = WP_LOC_WC_Permalinks::base_for( $lang, $key );
            $default = $defaults[ $key ];

            if ( $translated === '' || $default === '' || $translated === $default ) {
                continue;
            }

            $legacy_prefix = "/{$lang}/{$default}/";

            if ( str_starts_with( $path, $legacy_prefix ) ) {
                $target = "/{$lang}/{$translated}/" . substr( $path, strlen( $legacy_prefix ) );
                $query = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY );

                wp_safe_redirect( home_url( $target . ( $query ? "?{$query}" : '' ) ), 301 );
                exit;
            }
        }
    }
}
