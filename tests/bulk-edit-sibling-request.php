<?php
/**
 * E2E: a bulk edit must not reach translated copies through the shared request.
 * Run: wp eval-file web/app/plugins/wp-loc-woocommerce/tests/bulk-edit-sibling-request.php
 *
 * WooCommerce applies a bulk edit on every save_post that sees its nonce in
 * $_REQUEST. wp-loc saves the copies inside the same request, so a relative
 * change (+10%) would land on them a second time.
 */

$db = WP_LOC::instance()->db;
$default = WP_LOC_WC_Lang::default_lang();
$nonce_key = 'woocommerce_quick_edit_nonce';

$source = new WC_Product_Simple();
$source->set_name( 'Test bulk sibling' );
$source->set_slug( 'test-bulk-sibling-ua' );
$source->set_status( 'publish' );
$source->set_regular_price( '100' );
$source_id = $source->save();

if ( ! $db->get_element_language( $source_id, 'post_product' ) ) {
    $db->set_element_language( $source_id, 'post_product', $default );
}

$copy_id = $db->get_element_translation( $source_id, 'post_product', 'ru' );

if ( ! $copy_id ) {
    $copy_id = wp_insert_post( [
        'post_title'  => 'Test bulk sibling RU',
        'post_name'   => 'test-bulk-sibling-ru',
        'post_type'   => 'product',
        'post_status' => 'publish',
    ] );
    $db->set_element_language( $copy_id, 'post_product', 'ru', $db->get_trid( $source_id, 'post_product' ), $default );
}

$seen = [];
$spy = function ( $post_id ) use ( &$seen, $nonce_key ) {
    $seen[ (int) $post_id ] = isset( $_REQUEST[ $nonce_key ] );
};

add_action( 'save_post', $spy, 5 );
$_REQUEST[ $nonce_key ] = 'test';
$_REQUEST['wp_loc_test_keep'] = 'kept';

wp_update_post( [ 'ID' => $source_id, 'menu_order' => 7 ] );

remove_action( 'save_post', $spy, 5 );
$nonce_restored = isset( $_REQUEST[ $nonce_key ] );
$other_kept = ( $_REQUEST['wp_loc_test_keep'] ?? '' ) === 'kept';

// An edit made on the translation has to reach the source, or the source pushes the old price back.
$from_copy = [];
$spy_copy = function ( $post_id ) use ( &$from_copy, $nonce_key ) {
    $from_copy[ (int) $post_id ] = isset( $_REQUEST[ $nonce_key ] );
};

add_action( 'save_post', $spy_copy, 5 );
wp_update_post( [ 'ID' => $copy_id, 'menu_order' => 8 ] );
$source_sees_copy_edit = ( $from_copy[ $source_id ] ?? null ) === true;

// Unless the source was selected too: then it is edited on its own turn.
$from_copy = [];
$_REQUEST['post'] = [ (string) $source_id, (string) $copy_id ];
wp_update_post( [ 'ID' => $copy_id, 'menu_order' => 9 ] );
$selected_source_hidden = ( $from_copy[ $source_id ] ?? null ) === false;
remove_action( 'save_post', $spy_copy, 5 );

unset( $_REQUEST[ $nonce_key ], $_REQUEST['wp_loc_test_keep'], $_REQUEST['post'] );

$checks = [
    'source save sees the nonce'    => ( $seen[ $source_id ] ?? null ) === true,
    'copy was saved in the request' => array_key_exists( (int) $copy_id, $seen ),
    'copy save does not see nonce'  => ( $seen[ (int) $copy_id ] ?? null ) === false,
    'nonce restored afterwards'     => $nonce_restored,
    'other request keys untouched'  => $other_kept,
    'source sees edit from copy'    => $source_sees_copy_edit,
    'selected source stays hidden'  => $selected_source_hidden,
];

$trid = $db->get_trid( $source_id, 'post_product' );

foreach ( $db->get_element_translations( (int) $trid, 'post_product' ) as $row ) {
    wp_delete_post( (int) $row->element_id, true );
}

$failed = 0;

foreach ( $checks as $label => $passed ) {
    echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    $failed += $passed ? 0 : 1;
}

echo $failed ? "FAILED: $failed\n" : "ALL PASSED\n";
