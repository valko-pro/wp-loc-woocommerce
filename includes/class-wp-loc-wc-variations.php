<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mirrors product structure between translated products: attribute definitions
 * (_product_attributes), term assignments (translated where possible) and the
 * full variation set with prices/stock.
 *
 * The default-language product is the source of truth: saving it pushes the
 * structure to every translation; saving (or creating) a translation pulls
 * the structure from the default-language original. Translatable content
 * (titles, descriptions) is never touched.
 */
class WP_LOC_WC_Variations {

    private static $syncing = false;

    /** @var array<int,bool> parents already synced via variation saves this request */
    private static $synced_via_variation = [];

    public function __construct() {
        add_action( 'save_post_product', [ $this, 'handle_save' ], 999, 2 );
        add_action( 'woocommerce_save_product_variation', [ $this, 'handle_variation_save' ], 999 );
        add_filter( 'wc_product_has_unique_sku', [ $this, 'allow_translated_sku' ], 10, 3 );
    }

    /**
     * Meta keys mirrored between translated parent products.
     * Extensible by other addons (e.g. multicurrency price overrides).
     */
    public static function synced_meta_keys(): array {
        return apply_filters( 'wp_loc_wc_synced_meta_keys', [ '_product_attributes' ] );
    }

    public function handle_save( int $post_id, $post ): void {
        if ( self::$syncing || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( $post && $post->post_status === 'auto-draft' ) {
            return;
        }

        $this->sync_around( $post_id );
    }

    public function handle_variation_save( int $variation_id ): void {
        if ( self::$syncing ) {
            return;
        }

        $parent_id = wp_get_post_parent_id( $variation_id );

        // The hook fires once per variation on admin save — sync the parent once.
        if ( $parent_id && ! isset( self::$synced_via_variation[ $parent_id ] ) ) {
            self::$synced_via_variation[ $parent_id ] = true;
            $this->sync_around( $parent_id );
        }
    }

    /**
     * Push when the saved product is the default-language one,
     * pull from the default-language original otherwise.
     */
    private function sync_around( int $product_id ): void {
        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'product' );
        $lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( $product_id, $element_type ) );

        if ( $lang === null ) {
            return;
        }

        $default_lang = WP_LOC_WC_Lang::default_lang();
        self::$syncing = true;

        try {
            if ( $lang === $default_lang ) {
                $trid = $db->get_trid( $product_id, $element_type );

                foreach ( $db->get_element_translations( (int) $trid, $element_type ) as $row_lang => $row ) {
                    $target_id = (int) $row->element_id;

                    if ( $target_id !== $product_id ) {
                        $this->sync_structure( $product_id, $target_id, WP_LOC_DB::from_db_language_code( $row_lang ) ?? $row_lang );
                    }
                }
            } else {
                $source_id = WP_LOC_WC_Lang::translated_post_id( $product_id, 'product', $default_lang );

                if ( $source_id !== null ) {
                    $this->sync_structure( $source_id, $product_id, $lang );
                }
            }
        } finally {
            self::$syncing = false;
        }
    }

    private function sync_structure( int $source_id, int $target_id, string $target_lang ): void {
        $source = wc_get_product( $source_id );
        $target = wc_get_product( $target_id );

        if ( ! $source || ! $target ) {
            return;
        }

        foreach ( self::synced_meta_keys() as $meta_key ) {
            $value = get_post_meta( $source_id, $meta_key, true );

            if ( $value === '' || $value === false ) {
                delete_post_meta( $target_id, $meta_key );
            } else {
                update_post_meta( $target_id, $meta_key, $value );
            }
        }

        // Term assignments (categories, tags, pa_*) are synced by wp-loc itself
        // (WP_LOC_Content::sync_post_terms, save_post prio 30) with translation
        // mapping — this module only mirrors what wp-loc does not know about.

        if ( $source->is_type( 'variable' ) ) {
            wp_set_object_terms( $target_id, 'variable', 'product_type' );
            $this->sync_variations( $source, $target_id, $target_lang );
            WC_Product_Variable::sync( $target_id );
        }

        wc_delete_product_transients( $target_id );
    }

    private function sync_variations( WC_Product $source, int $target_id, string $target_lang ): void {
        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'product_variation' );
        $default_lang = WP_LOC_WC_Lang::default_lang();
        $mirrored = [];

        foreach ( $source->get_children() as $source_vid ) {
            $source_vid = (int) $source_vid;
            $source_post = get_post( $source_vid );

            if ( ! $source_post ) {
                continue;
            }

            $trid = $db->get_trid( $source_vid, $element_type );

            if ( ! $trid ) {
                $db->set_element_language( $source_vid, $element_type, $default_lang );
                $trid = $db->get_trid( $source_vid, $element_type );
            }

            $target_vid = $db->get_element_translation( $source_vid, $element_type, $target_lang );

            if ( $target_vid && get_post_type( $target_vid ) !== 'product_variation' ) {
                $target_vid = null;
            }

            if ( ! $target_vid ) {
                $target_vid = wp_insert_post( [
                    'post_title'  => $source_post->post_title,
                    'post_name'   => "product-{$target_id}-variation-{$source_post->menu_order}",
                    'post_type'   => 'product_variation',
                    'post_status' => $source_post->post_status,
                    'post_parent' => $target_id,
                    'menu_order'  => $source_post->menu_order,
                ] );

                if ( ! $target_vid || is_wp_error( $target_vid ) ) {
                    continue;
                }

                $db->set_element_language( (int) $target_vid, $element_type, $target_lang, $trid, $default_lang );
            } else {
                wp_update_post( [
                    'ID'          => (int) $target_vid,
                    'post_status' => $source_post->post_status,
                    'post_parent' => $target_id,
                    'menu_order'  => $source_post->menu_order,
                ] );
            }

            $this->copy_variation_meta( $source_vid, (int) $target_vid, $target_lang );
            $mirrored[] = (int) $target_vid;
        }

        // Remove target variations whose source counterpart is gone.
        $orphans = get_posts( [
            'post_type'      => 'product_variation',
            'post_parent'    => $target_id,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'exclude'        => $mirrored,
        ] );

        foreach ( $orphans as $orphan_id ) {
            // Only mirrors we own (registered as the target language) may be removed.
            // A variation registered in another language ended up under this parent by
            // mistake (e.g. a mis-directed parent sync) — deleting it would destroy
            // the source product's data.
            $orphan_lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( (int) $orphan_id, $element_type ) );

            if ( $orphan_lang !== $target_lang ) {
                continue;
            }

            wp_delete_post( (int) $orphan_id, true );
        }
    }

    /**
     * Copy all variation meta; attribute_pa_* slugs are mapped to the
     * translated term slug for the target language.
     */
    private function copy_variation_meta( int $source_vid, int $target_vid, string $target_lang ): void {
        $meta = get_post_meta( $source_vid );

        foreach ( $meta as $key => $values ) {
            if ( strpos( $key, '_wp_loc' ) === 0 ) {
                continue;
            }

            $value = maybe_unserialize( $values[0] ?? '' );

            if ( strpos( $key, 'attribute_' ) === 0 && is_string( $value ) && $value !== '' ) {
                $taxonomy = substr( $key, strlen( 'attribute_' ) );

                if ( taxonomy_exists( $taxonomy ) ) {
                    $term = get_term_by( 'slug', $value, $taxonomy );

                    if ( $term instanceof WP_Term ) {
                        $translated_id = WP_LOC_Terms::get_term_translation( (int) $term->term_id, $taxonomy, $target_lang );
                        $translated = $translated_id ? get_term( $translated_id, $taxonomy ) : null;

                        if ( $translated instanceof WP_Term ) {
                            $value = $translated->slug;
                        }
                    }
                }
            }

            update_post_meta( $target_vid, $key, $value );
        }
    }

    /**
     * Translations of a product/variation share the source's SKU (this module
     * mirrors it verbatim), so WooCommerce's uniqueness check would reject any
     * later SKU edit as a duplicate. Treat the SKU as unique when every other
     * post carrying it is a translation (same trid group) of the checked post.
     *
     * @param bool   $sku_found  Whether WC found the SKU on another product.
     * @param int    $product_id Product/variation being validated.
     * @param string $sku        The SKU value.
     * @return bool
     */
    public function allow_translated_sku( $sku_found, $product_id, $sku ) {
        if ( ! $sku_found || $sku === '' ) {
            return $sku_found;
        }

        global $wpdb;

        $holders = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
             WHERE pm.meta_value = %s
               AND p.ID <> %d
               AND p.post_type IN ( 'product', 'product_variation' )
               AND p.post_status != 'trash'",
            $sku,
            $product_id
        ) );

        if ( empty( $holders ) ) {
            return $sku_found;
        }

        $siblings = $this->translation_ids( (int) $product_id );

        foreach ( $holders as $holder_id ) {
            if ( ! in_array( (int) $holder_id, $siblings, true ) ) {
                return $sku_found;
            }
        }

        return false;
    }

    /** All post ids in the trid group of the given post (including itself). */
    private function translation_ids( int $post_id ): array {
        $post_type = get_post_type( $post_id );

        if ( ! in_array( $post_type, [ 'product', 'product_variation' ], true ) ) {
            return [];
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $trid = $db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            return [];
        }

        $ids = [];

        foreach ( $db->get_element_translations( (int) $trid, $element_type ) as $row ) {
            $ids[] = (int) $row->element_id;
        }

        return $ids;
    }
}
