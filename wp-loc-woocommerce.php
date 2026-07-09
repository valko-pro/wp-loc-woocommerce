<?php

/*
Plugin Name: WP-LOC WooCommerce
Plugin URI: https://wp-loc.com/
Description: WooCommerce multilingual integration for WP-LOC
Version: 0.2.0
Requires Plugins: wp-loc, woocommerce
Author: VALKO.PRO
Author URI: https://valko.pro
License: GPLv2 or later
Text Domain: wp-loc-wc
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_LOC_WC_VERSION', '0.2.0' );
define( 'WP_LOC_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_LOC_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_LOC_WC_BASENAME', plugin_basename( __FILE__ ) );

register_activation_hook( __FILE__, 'wp_loc_wc_activate' );

function wp_loc_wc_activate(): void {
    // Existing WC terms/products may predate translatable registration and lack
    // language rows; the sync runs on the next init (see WP_LOC_WC_Taxonomies).
    update_option( 'wp_loc_wc_needs_language_rows_sync', 1 );
}

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'wp-loc-wc', false, dirname( WP_LOC_WC_BASENAME ) . '/languages' );

    if ( ! class_exists( 'WP_LOC' ) || ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'WP-LOC WooCommerce requires both WP-LOC and WooCommerce to be active.', 'wp-loc-wc' )
                . '</p></div>';
        } );
        return;
    }

    require_once WP_LOC_WC_PATH . 'includes/class-wp-loc-wc.php';
    WP_LOC_WC::instance();
}, 20 );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $url = admin_url( 'admin.php?page=wp-loc-woocommerce' );
    array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'wp-loc-wc' ) . '</a>' );
    return $links;
} );
