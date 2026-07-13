<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI: bulk-sync product data from default-language products to their
 * translations, with a transparent per-product diff report.
 */
class WP_LOC_WC_CLI {

    /**
     * Variation meta keys compared in the report (the sync itself copies
     * all variation meta — see WP_LOC_WC_Variations::copy_variation_meta()).
     */
    private const VARIATION_REPORT_KEYS = [
        '_regular_price',
        '_sale_price',
        '_sale_price_dates_from',
        '_sale_price_dates_to',
        '_stock_status',
    ];

    /**
     * Sync product data (prices, stock, dimensions, visibility, …) from the
     * default-language products to their translations.
     *
     * Only products with pending differences are listed; each line shows the
     * exact field changes that will be (or were) applied.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report the differences without writing anything.
     *
     * [--ids=<ids>]
     * : Comma-separated default-language product ids to limit the run.
     *
     * ## EXAMPLES
     *
     *     wp wp-loc-wc sync-products --dry-run
     *     wp wp-loc-wc sync-products --ids=12,34
     *
     * @subcommand sync-products
     */
    public function sync_products( $args, $assoc_args ) {
        $dry_run = ! empty( $assoc_args['dry-run'] );
        $only_ids = [];

        if ( ! empty( $assoc_args['ids'] ) ) {
            $only_ids = array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) );
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'product' );
        $default_lang = WP_LOC_WC_Lang::default_lang();

        $checked = 0;
        $updated = 0;
        $skipped = 0;

        $batch_size = 100;
        $offset = 0;

        do {
            $ids = $only_ids ?: get_posts( [
                'post_type'        => 'product',
                'post_status'      => 'any',
                'fields'           => 'ids',
                'numberposts'      => $batch_size,
                'offset'           => $offset,
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => true,
            ] );

            foreach ( $ids as $product_id ) {
                $product_id = (int) $product_id;
                $lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( $product_id, $element_type ) );

                if ( $lang !== $default_lang ) {
                    continue; // translations are handled via their source
                }

                $checked++;
                $source = wc_get_product( $product_id );

                if ( ! $source ) {
                    continue;
                }

                $trid = $db->get_trid( $product_id, $element_type );
                $diff_lines = [];
                $has_translation = false;

                foreach ( $db->get_element_translations( (int) $trid, $element_type ) as $row_lang => $row ) {
                    $target_id = (int) $row->element_id;

                    if ( $target_id === $product_id ) {
                        continue;
                    }

                    $has_translation = true;
                    $target_lang = WP_LOC_DB::from_db_language_code( $row_lang ) ?? $row_lang;
                    $diffs = $this->target_diffs( $source, $target_id, $target_lang );

                    if ( $diffs ) {
                        $diff_lines[] = "#{$product_id} «" . $source->get_name() . "» → {$target_lang} #{$target_id}: " . implode( ', ', $diffs );
                    }
                }

                if ( ! $has_translation ) {
                    $skipped++;
                    continue;
                }

                if ( $diff_lines ) {
                    foreach ( $diff_lines as $line ) {
                        WP_CLI::log( $line );
                    }

                    if ( ! $dry_run ) {
                        WP_LOC_WC::instance()->variations->sync_product( $product_id );
                    }

                    $updated++;
                }
            }

            $offset += $batch_size;

            // Keep long runs from ballooning memory.
            wp_cache_flush();
        } while ( ! $only_ids && count( $ids ) === $batch_size );

        $verb = $dry_run ? 'need sync' : 'synced';
        WP_CLI::success( "checked {$checked}, {$verb} {$updated}, no translation {$skipped}" . ( $dry_run ? ' (dry-run, nothing written)' : '' ) );
    }

    /**
     * Human-readable field differences between a source product and one
     * translation: synced meta keys, term-backed props, variation set.
     *
     * @return string[]
     */
    private function target_diffs( WC_Product $source, int $target_id, string $target_lang ): array {
        $diffs = [];
        $source_id = $source->get_id();

        foreach ( WP_LOC_WC_Variations::synced_meta_keys() as $meta_key ) {
            $source_value = get_post_meta( $source_id, $meta_key, true );
            $target_value = get_post_meta( $target_id, $meta_key, true );

            if ( $this->values_equal( $source_value, $target_value ) ) {
                continue;
            }

            $diffs[] = "{$meta_key} " . $this->format_value( $target_value ) . ' → ' . $this->format_value( $source_value );
        }

        $target = wc_get_product( $target_id );

        if ( $target ) {
            $prop_pairs = [
                'featured'           => [ $source->is_featured() ? 'yes' : 'no', $target->is_featured() ? 'yes' : 'no' ],
                'catalog_visibility' => [ $source->get_catalog_visibility(), $target->get_catalog_visibility() ],
                'shipping_class_id'  => [ (string) $source->get_shipping_class_id(), (string) $target->get_shipping_class_id() ],
            ];

            foreach ( $prop_pairs as $label => [ $source_value, $target_value ] ) {
                if ( $source_value !== $target_value ) {
                    $diffs[] = "{$label} \"{$target_value}\" → \"{$source_value}\"";
                }
            }

            $diffs = array_merge( $diffs, $this->variation_diffs( $source, $target, $target_lang ) );
        }

        return $diffs;
    }

    /** @return string[] */
    private function variation_diffs( WC_Product $source, WC_Product $target, string $target_lang ): array {
        if ( ! $source->is_type( 'variable' ) ) {
            return [];
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'product_variation' );
        $diffs = [];

        $source_children = $source->get_children();
        $target_children = $target->is_type( 'variable' ) ? $target->get_children() : [];

        if ( count( $source_children ) !== count( $target_children ) ) {
            $diffs[] = 'variations ' . count( $target_children ) . ' → ' . count( $source_children );
        }

        foreach ( $source_children as $source_vid ) {
            $target_vid = $db->get_element_translation( (int) $source_vid, $element_type, $target_lang );

            if ( ! $target_vid ) {
                continue; // covered by the count line above
            }

            foreach ( self::VARIATION_REPORT_KEYS as $meta_key ) {
                $source_value = get_post_meta( (int) $source_vid, $meta_key, true );
                $target_value = get_post_meta( (int) $target_vid, $meta_key, true );

                if ( (string) $source_value !== (string) $target_value ) {
                    $diffs[] = "variation #{$source_vid} {$meta_key} " . $this->format_value( $target_value ) . ' → ' . $this->format_value( $source_value );
                }
            }
        }

        return $diffs;
    }

    private function values_equal( $a, $b ): bool {
        if ( is_array( $a ) || is_array( $b ) ) {
            return maybe_serialize( $a ) === maybe_serialize( $b );
        }

        return (string) $a === (string) $b;
    }

    private function format_value( $value ): string {
        if ( is_array( $value ) ) {
            return '(structured)';
        }

        $value = (string) $value;

        if ( mb_strlen( $value ) > 40 ) {
            $value = mb_substr( $value, 0, 37 ) . '…';
        }

        return '"' . $value . '"';
    }
}
