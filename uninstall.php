<?php

/**
 * WP-LOC WooCommerce Uninstall
 *
 * Removes addon options when deleted via WordPress admin.
 * Order meta written by the addon (_wpml_language) is kept on purpose —
 * it is order audit data and WPML-compatible.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp\_loc\_wc\_%'" );

flush_rewrite_rules();
