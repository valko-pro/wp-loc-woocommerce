<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers WooCommerce post types and taxonomies as translatable in wp-loc
 * and backfills language rows for content created before that registration.
 *
 * Attribute taxonomies (pa_*) are read live on every filter call, so
 * attributes added later are covered without any cache invalidation.
 */
class WP_LOC_WC_Taxonomies {

    const SYNC_FLAG_OPTION = 'wp_loc_wc_needs_language_rows_sync';

    public function __construct() {
        add_filter( 'wp_loc_translatable_post_types', [ $this, 'add_post_types' ], 20 );
        add_filter( 'wp_loc_translatable_taxonomies', [ $this, 'add_taxonomies' ], 20 );

        // New attribute taxonomy: its terms (none yet) need no backfill, but a
        // recreated attribute can adopt existing terms — schedule a sync to be safe.
        add_action( 'woocommerce_attribute_added', [ $this, 'schedule_language_rows_sync' ] );

        add_action( 'init', [ $this, 'maybe_sync_language_rows' ], 20 );
    }

    public function add_post_types( array $post_types ): array {
        return array_values( array_unique( array_merge( $post_types, [ 'product' ] ) ) );
    }

    public function add_taxonomies( array $taxonomies ): array {
        $wc_taxonomies = array_merge(
            [ 'product_cat', 'product_tag' ],
            function_exists( 'wc_get_attribute_taxonomy_names' ) ? wc_get_attribute_taxonomy_names() : []
        );

        return array_values( array_unique( array_merge( $taxonomies, $wc_taxonomies ) ) );
    }

    public function schedule_language_rows_sync(): void {
        update_option( self::SYNC_FLAG_OPTION, 1 );
    }

    public function maybe_sync_language_rows(): void {
        if ( ! get_option( self::SYNC_FLAG_OPTION ) ) {
            return;
        }

        delete_option( self::SYNC_FLAG_OPTION );
        self::sync_language_rows();
    }

    /**
     * Assign the default language to every WC term and product that has no
     * language row yet. Without a row wp-loc cannot resolve the term by path
     * and the archive 404s even in the default language (see gap-analysis-t1.md).
     */
    public static function sync_language_rows(): int {
        global $wpdb;

        $db = WP_LOC::instance()->db;
        $default_lang = WP_LOC_Languages::get_default_language();
        $icl_table = $db->get_table();
        $synced = 0;

        $taxonomies = array_merge(
            [ 'product_cat', 'product_tag' ],
            function_exists( 'wc_get_attribute_taxonomy_names' ) ? wc_get_attribute_taxonomy_names() : []
        );

        foreach ( $taxonomies as $taxonomy ) {
            $element_type = WP_LOC_DB::tax_element_type( $taxonomy );

            $term_taxonomy_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT tt.term_taxonomy_id
                 FROM {$wpdb->term_taxonomy} tt
                 LEFT JOIN {$icl_table} icl
                   ON icl.element_id = tt.term_taxonomy_id AND icl.element_type = %s
                 WHERE tt.taxonomy = %s AND icl.translation_id IS NULL",
                $element_type,
                $taxonomy
            ) );

            foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
                $db->set_element_language( (int) $term_taxonomy_id, $element_type, $default_lang );
                $synced++;
            }
        }

        foreach ( [ 'product' ] as $post_type ) {
            $element_type = WP_LOC_DB::post_element_type( $post_type );

            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$icl_table} icl
                   ON icl.element_id = p.ID AND icl.element_type = %s
                 WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft', 'trash')
                   AND icl.translation_id IS NULL",
                $element_type,
                $post_type
            ) );

            foreach ( $post_ids as $post_id ) {
                $db->set_element_language( (int) $post_id, $element_type, $default_lang );
                $synced++;
            }
        }

        return $synced;
    }
}
