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
        add_action( 'woocommerce_update_product', [ $this, 'handle_crud_update' ], 999 );
        add_action( 'woocommerce_update_product_variation', [ $this, 'handle_crud_variation_update' ], 999 );
        add_filter( 'wc_product_has_unique_sku', [ $this, 'allow_translated_sku' ], 10, 3 );
    }

    /**
     * Meta keys mirrored between translated parent products — everything
     * that is data rather than translatable text (WPML-like default set).
     * Extensible by other addons (e.g. multicurrency price overrides).
     */
    public static function synced_meta_keys(): array {
        return apply_filters( 'wp_loc_wc_synced_meta_keys', [
            '_product_attributes',
            // Prices.
            '_regular_price',
            '_sale_price',
            '_sale_price_dates_from',
            '_sale_price_dates_to',
            '_price',
            // Inventory.
            '_manage_stock',
            '_stock',
            '_stock_status',
            '_backorders',
            '_low_stock_amount',
            '_sold_individually',
            // Shipping.
            '_weight',
            '_length',
            '_width',
            '_height',
            // Tax.
            '_tax_status',
            '_tax_class',
            // Virtual / downloadable / external.
            '_virtual',
            '_downloadable',
            '_downloadable_files',
            '_download_limit',
            '_download_expiry',
            '_product_url',
            // Misc.
            '_sku',
            '_product_image_gallery',
        ] );
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

    /**
     * Public entry point for bulk tools (WP-CLI backfill): run the usual
     * push/pull sync for a single product id.
     */
    public function sync_product( int $product_id ): void {
        $this->sync_around( $product_id );
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
     * CRUD-layer trigger: WC fires this on programmatic saves (REST API,
     * importers, stock updates) that never go through save_post.
     *
     * Push-only: order-time stock reduction on a translation also lands here,
     * and a pull would overwrite the freshly reduced stock with the source
     * value. Translations still pull via save_post (admin edits).
     *
     * On admin saves this fires in addition to handle_save() — the double
     * sync is accepted: deduping would break the new-product flow, where
     * wp-loc auto-creates the translation later in the same save_post chain.
     *
     * @param int|mixed $product_id
     */
    public function handle_crud_update( $product_id ): void {
        if ( self::$syncing ) {
            return;
        }

        $product_id = (int) $product_id;
        $db = WP_LOC::instance()->db;
        $lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( $product_id, WP_LOC_DB::post_element_type( 'product' ) ) );

        if ( $lang === null ) {
            return; // no wp-loc language row — nothing to sync against
        }

        if ( $lang !== WP_LOC_WC_Lang::default_lang() ) {
            return;
        }

        $this->sync_around( $product_id );
    }

    /**
     * CRUD-layer trigger for variation saves (REST API, importers, stock
     * updates). Push-only, checked against the parent's language: order-time
     * stock reduction on a translated variation also fires this hook, and a
     * pull would overwrite the freshly reduced stock with the source value.
     *
     * Uses the same $synced_via_variation dedup map as handle_variation_save()
     * to avoid syncing the same parent multiple times in one request.
     * The shared $synced_via_variation map runs the sync once per parent per
     * request; later variations changed in the same request are still covered
     * because every variation save() queues the parent for WooCommerce's deferred
     * product sync at shutdown, whose parent save() re-enters handle_crud_update()
     * after the guard is released.
     *
     * @param int|mixed $variation_id
     */
    public function handle_crud_variation_update( $variation_id ): void {
        if ( self::$syncing ) {
            return;
        }

        $parent_id = (int) wp_get_post_parent_id( (int) $variation_id );

        if ( ! $parent_id ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $parent_lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( $parent_id, WP_LOC_DB::post_element_type( 'product' ) ) );

        if ( $parent_lang === null ) {
            return; // no wp-loc language row — nothing to sync against
        }

        if ( $parent_lang !== WP_LOC_WC_Lang::default_lang() ) {
            return;
        }

        if ( ! isset( self::$synced_via_variation[ $parent_id ] ) ) {
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

        if ( $source->is_type( 'variable' ) ) {
            // Variable path: raw meta copy first, then variation sync, then re-read
            // and save via CRUD for term-backed props.
            $this->copy_synced_meta( $source_id, $target_id );

            // Term assignments (categories, tags, pa_*) are synced by wp-loc itself
            // (WP_LOC_Content::sync_post_terms, save_post prio 30) with translation
            // mapping — this module only mirrors what wp-loc does not know about.

            wp_set_object_terms( $target_id, 'variable', 'product_type' );
            // Invalidate the WC product-type cache so that the re-read below sees
            // 'variable', not the 'simple' value that was cached before the term
            // was changed. wc_delete_product_transients() does not touch this cache.
            WC_Cache_Helper::invalidate_cache_group( 'product_' . $target_id );
            $this->sync_variations( $source, $target_id, $target_lang );
            WC_Product_Variable::sync( $target_id );

            // Re-read after variation sync: the original $target object still has
            // type=simple from the initial load and would overwrite the product_type
            // term when saved. A fresh WC_Product_Variable object saves correctly.
            $target = wc_get_product( $target_id );

            if ( ! $target ) {
                return;
            }

            $target->set_featured( $source->is_featured() );
            $target->set_catalog_visibility( $source->get_catalog_visibility() );
            $target->set_stock_status( $source->get_stock_status() );
            $target->set_shipping_class_id( $source->get_shipping_class_id() );
            $target->save();
        } else {
            // Non-variable path: push all lookup-backed props through CRUD setters
            // BEFORE the raw meta copy.  WooCommerce refreshes wc_product_meta_lookup
            // only for props that appear in updated_props, which only happens when the
            // CRUD layer detects a real DB change.  If we copy the meta first the
            // raw-write pre-empts the CRUD write (same value already in DB → MySQL
            // returns 0 rows → no updated_props → no lookup refresh).  Staging the
            // setters here — while the target DB still holds the old values — ensures
            // the CRUD save() always sees genuine changes and refreshes the lookup.
            // Recursion is blocked by self::$syncing.
            $target->set_featured( $source->is_featured() );
            $target->set_catalog_visibility( $source->get_catalog_visibility() );
            $target->set_stock_status( $source->get_stock_status() );
            $target->set_shipping_class_id( $source->get_shipping_class_id() );
            $target->set_regular_price( $source->get_regular_price( 'edit' ) );
            $target->set_sale_price( $source->get_sale_price( 'edit' ) );
            $target->set_date_on_sale_from( $source->get_date_on_sale_from( 'edit' ) );
            $target->set_date_on_sale_to( $source->get_date_on_sale_to( 'edit' ) );
            $target->set_sku( $source->get_sku( 'edit' ) );
            $target->set_manage_stock( $source->get_manage_stock( 'edit' ) );
            $target->set_stock_quantity( $source->get_stock_quantity( 'edit' ) );
            $target->set_tax_status( $source->get_tax_status( 'edit' ) );
            $target->set_tax_class( $source->get_tax_class( 'edit' ) );
            $target->set_virtual( $source->get_virtual( 'edit' ) );
            $target->set_downloadable( $source->get_downloadable( 'edit' ) );
            $target->save();

            // Raw meta copy after save() so that keys not covered by the CRUD setters
            // (e.g. _product_image_gallery, _product_attributes) are also mirrored.
            // For the keys the CRUD layer already wrote, these are no-ops.
            $this->copy_synced_meta( $source_id, $target_id );
        }
    }

    /** Copy all synced meta keys from source to target as raw post meta. */
    private function copy_synced_meta( int $source_id, int $target_id ): void {
        foreach ( self::synced_meta_keys() as $meta_key ) {
            /**
             * Filters a synced meta value before it is written to the translation,
             * e.g. to keep translated texts inside custom _product_attributes.
             */
            $value = apply_filters( 'wp_loc_wc_synced_meta_value', get_post_meta( $source_id, $meta_key, true ), $meta_key, $source_id, $target_id );

            if ( $value === '' || $value === false ) {
                delete_post_meta( $target_id, $meta_key );
            } else {
                update_post_meta( $target_id, $meta_key, $value );
            }
        }
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

            // CRUD-first: push lookup-backed props through the WC object layer
            // BEFORE copy_variation_meta() writes raw meta.  The DB still holds
            // old (or missing) values at this point, so the CRUD save() sees real
            // changes and updates / creates the wc_product_meta_lookup row.
            $src_v = wc_get_product( $source_vid );
            $tgt_v = wc_get_product( (int) $target_vid );

            if ( $src_v && $tgt_v ) {
                $tgt_v->set_regular_price( $src_v->get_regular_price( 'edit' ) );
                $tgt_v->set_sale_price( $src_v->get_sale_price( 'edit' ) );
                $tgt_v->set_date_on_sale_from( $src_v->get_date_on_sale_from( 'edit' ) );
                $tgt_v->set_date_on_sale_to( $src_v->get_date_on_sale_to( 'edit' ) );
                $tgt_v->set_sku( $src_v->get_sku( 'edit' ) );
                $tgt_v->set_manage_stock( $src_v->get_manage_stock( 'edit' ) );
                $tgt_v->set_stock_quantity( $src_v->get_stock_quantity( 'edit' ) );
                $tgt_v->set_stock_status( $src_v->get_stock_status( 'edit' ) );
                $tgt_v->set_tax_status( $src_v->get_tax_status( 'edit' ) );
                $tgt_v->set_tax_class( $src_v->get_tax_class( 'edit' ) );
                $tgt_v->set_virtual( $src_v->get_virtual( 'edit' ) );
                $tgt_v->set_downloadable( $src_v->get_downloadable( 'edit' ) );
                $tgt_v->save();
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
     * Enable/disable WP-LOC's term-language filters (get_term p1 + terms_clauses
     * p10). In WP-CLI/cron (is_admin() false) they are active and remap the
     * attribute-term lookups in copy_variation_meta() back to the default
     * language, so the variation meta would keep the source slug instead of the
     * translated one. Mirrors WP_LOC_Content::set_term_language_filters().
     */
    private function set_term_language_filters( bool $enabled ): void {
        $terms_filter = ( class_exists( 'WP_LOC' ) && isset( WP_LOC::instance()->terms ) )
            ? WP_LOC::instance()->terms
            : null;

        if ( ! $terms_filter ) {
            return;
        }

        if ( $enabled ) {
            add_filter( 'get_term', [ $terms_filter, 'adjust_term_to_current_language' ], 1, 2 );
            add_filter( 'terms_clauses', [ $terms_filter, 'filter_terms_clauses' ], 10, 3 );
        } else {
            remove_filter( 'get_term', [ $terms_filter, 'adjust_term_to_current_language' ], 1 );
            remove_filter( 'terms_clauses', [ $terms_filter, 'filter_terms_clauses' ], 10 );
        }
    }

    /**
     * Copy all variation meta; attribute_pa_* slugs are mapped to the
     * translated term slug for the target language.
     */
    private function copy_variation_meta( int $source_vid, int $target_vid, string $target_lang ): void {
        $meta = get_post_meta( $source_vid );

        $this->set_term_language_filters( false );

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

        $this->set_term_language_filters( true );
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
