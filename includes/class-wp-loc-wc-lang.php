<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Thin static wrapper around the wp-loc language API.
 *
 * All language lookups in this addon go through this class so the
 * wp-loc surface is referenced in exactly one place.
 */
class WP_LOC_WC_Lang {

    public static function current_lang(): string {
        return WP_LOC_Routing::get_current_lang();
    }

    public static function default_lang(): string {
        return WP_LOC_Languages::get_default_language();
    }

    public static function is_default_lang(): bool {
        return self::current_lang() === self::default_lang();
    }

    /**
     * Translated post ID for a target language, or null when no translation exists.
     */
    public static function translated_post_id( int $post_id, string $post_type, string $lang ): ?int {
        return WP_LOC::instance()->db->get_element_translation(
            $post_id,
            WP_LOC_DB::post_element_type( $post_type ),
            $lang
        );
    }

    /**
     * Translated post ID with fallback to the given ID when no translation exists.
     */
    public static function translated_post_id_or_self( int $post_id, string $post_type, string $lang ): int {
        return self::translated_post_id( $post_id, $post_type, $lang ) ?? $post_id;
    }

    /**
     * Translated term ID for a target language, or null when no translation exists.
     * Delegates to WP_LOC_Terms which maps term_id <-> term_taxonomy_id (WPML convention).
     */
    public static function translated_term_id( int $term_id, string $taxonomy, string $lang ): ?int {
        return WP_LOC_Terms::get_term_translation( $term_id, $taxonomy, $lang );
    }
}
