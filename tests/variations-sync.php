<?php
/**
 * E2E: variations sync між translated parents.
 * Запуск: wp eval-file tests/variations-sync.php
 *
 * Реальний флоу: збереження UA-товару → wp-loc auto-create створює RU-пару
 * (save_post prio 20) → аддон пушить структуру (prio 999).
 * Самодостатній: чистить артефакти, створює фікстуру, перевіряє.
 */

global $wpdb;

$db = WP_LOC::instance()->db;
$default = WP_LOC_WC_Lang::default_lang();

// 0. Cleanup: усі тестові товари (з їх trid-групами і варіаціями) та всі pa_color-терміни
$test_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_name LIKE 'test-variable%'" );

foreach ( $test_ids as $pid ) {
    $trid = $db->get_trid( (int) $pid, 'post_product' );

    $group = $trid ? $db->get_element_translations( $trid, 'post_product' ) : [];
    $group_ids = array_map( fn( $row ) => (int) $row->element_id, $group );
    $group_ids[] = (int) $pid;

    foreach ( array_unique( $group_ids ) as $gid ) {
        foreach ( get_posts( [ 'post_type' => 'product_variation', 'post_parent' => $gid, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $vid ) {
            wp_delete_post( (int) $vid, true );
        }

        wp_delete_post( $gid, true );
    }
}

$stray_terms = $wpdb->get_col( "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'pa_color'" );

foreach ( $stray_terms as $tid ) {
    wp_delete_term( (int) $tid, 'pa_color' );
}

// 1. Терміни pa_color: UA + RU пари (term auto-create вимкнений — пари вручну)
$term_map = []; // ua_term_id => ru_term_id

foreach ( [ [ 'Зелений', 'zelenyj', 'Зелёный', 'zelenyj-ru' ], [ 'Червоний', 'chervonyj', 'Красный', 'krasnyj' ] ] as [ $ua_name, $ua_slug, $ru_name, $ru_slug ] ) {
    $ua = wp_insert_term( $ua_name, 'pa_color', [ 'slug' => $ua_slug ] );
    $ua_id = (int) $ua['term_id'];
    $ua_tt = WP_LOC_Terms::get_term_taxonomy_id( $ua_id, 'pa_color' );
    $db->set_element_language( $ua_tt, 'tax_pa_color', $default );

    $ru_id = WP_LOC_Terms::get_term_translation( $ua_id, 'pa_color', 'ru' );

    if ( ! $ru_id ) {
        $ru = wp_insert_term( $ru_name, 'pa_color', [ 'slug' => $ru_slug ] );
        $ru_id = (int) $ru['term_id'];
        $ru_tt = WP_LOC_Terms::get_term_taxonomy_id( $ru_id, 'pa_color' );
        $db->set_element_language( $ru_tt, 'tax_pa_color', 'ru', $db->get_trid( $ua_tt, 'tax_pa_color' ), $default );
    }

    $term_map[ $ua_id ] = (int) $ru_id;
}

$ua_term_ids = array_keys( $term_map );

// 2. Variable-товар UA з 2 варіаціями (auto-create підхопить і створить RU-пару)
$ua_product = new WC_Product_Variable();
$ua_product->set_name( 'Тестовий варіативний' );
$ua_product->set_slug( 'test-variable-ua' );
$ua_product->set_status( 'publish' );

$attr = new WC_Product_Attribute();
$attr->set_id( wc_attribute_taxonomy_id_by_name( 'color' ) );
$attr->set_name( 'pa_color' );
$attr->set_options( $ua_term_ids );
$attr->set_visible( true );
$attr->set_variation( true );
$ua_product->set_attributes( [ $attr ] );
$ua_pid = $ua_product->save();

if ( ! $db->get_element_language( $ua_pid, 'post_product' ) ) {
    $db->set_element_language( $ua_pid, 'post_product', $default );
}

foreach ( [ [ array_map( fn( $id ) => get_term( $id, 'pa_color' )->slug, $ua_term_ids )[0], '100' ], [ array_map( fn( $id ) => get_term( $id, 'pa_color' )->slug, $ua_term_ids )[1], '120' ] ] as $i => [ $slug, $price ] ) {
    $v = new WC_Product_Variation();
    $v->set_parent_id( $ua_pid );
    $v->set_attributes( [ 'pa_color' => $slug ] );
    $v->set_regular_price( $price );
    $v->set_menu_order( $i + 1 );
    $v->save();
}

// 3. RU-пара: auto-create wp-loc або вручну (fallback)
$ru_pid = $db->get_element_translation( $ua_pid, 'post_product', 'ru' );
$via = 'auto-create';

if ( ! $ru_pid ) {
    $via = 'manual';
    $trid = $db->get_trid( $ua_pid, 'post_product' );
    $ru_pid = wp_insert_post( [ 'post_title' => 'Тестовый вариативный', 'post_name' => 'test-variable-ru', 'post_type' => 'product', 'post_status' => 'publish' ] );
    $db->set_element_language( $ru_pid, 'post_product', 'ru', $trid, $default );
}

echo "UA product {$ua_pid}, RU pair {$ru_pid} ({$via})\n\n";

// 4. Push-sync збереженням UA-товару (варіації створені після першого save)
wp_update_post( [ 'ID' => $ua_pid ] );
wp_cache_flush();

// 5. Перевірки
$expected_ru_term_ids = array_values( $term_map );
sort( $expected_ru_term_ids );
$expected_ru_slugs = array_map( fn( $id ) => get_term( $id, 'pa_color' )->slug, $expected_ru_term_ids );
sort( $expected_ru_slugs );

$ru_product = wc_get_product( $ru_pid );
$checks = [];
$checks['RU product is variable'] = $ru_product && $ru_product->is_type( 'variable' );
$ru_children = $ru_product ? $ru_product->get_children() : [];
$checks['RU has 2 variations'] = count( $ru_children ) === 2;
$checks['_product_attributes mirrored'] = get_post_meta( $ru_pid, '_product_attributes', true ) == get_post_meta( $ua_pid, '_product_attributes', true );

// Term assignment sync — функціонал wp-loc (sync_post_terms). У CLI-контексті
// wp-loc читає терміни джерела мовно-фільтрованим запитом, тому повний term-sync
// перевіряється в адмінці (E2E-чекліст); тут перевіряємо лише, що наш модуль
// не зачистив присвоєння UA-батька.
$ua_assigned = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
    "SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = 'pa_color'",
    $ua_pid
) ) );
sort( $ua_assigned );
$expected_ua_term_ids = array_keys( $term_map );
sort( $expected_ua_term_ids );
$checks['UA parent keeps its terms after push'] = $ua_assigned === $expected_ua_term_ids;

$slugs_found = [];
$prices_found = [];
$icl_ok = (bool) $ru_children;

foreach ( $ru_children as $vid ) {
    $slugs_found[] = get_post_meta( $vid, 'attribute_pa_color', true );
    $prices_found[] = get_post_meta( $vid, '_regular_price', true );
    $icl_ok = $icl_ok && $db->get_element_language( (int) $vid, 'post_product_variation' ) === 'ru';
}

sort( $slugs_found );
sort( $prices_found );
$checks['variations linked in icl as ru'] = $icl_ok;
$checks['variation attribute slugs translated'] = $slugs_found === $expected_ru_slugs;
$checks['variation prices mirrored'] = $prices_found === [ '100', '120' ];

// 6. Pull-напрямок: збереження RU-товару не дублює варіації
wp_update_post( [ 'ID' => $ru_pid ] );
wp_cache_flush();
$checks['no duplicate variations after RU save'] = count( wc_get_product( $ru_pid )->get_children() ) === 2;

// 7. Видалення варіації у UA → зникає у RU
$ua_children = wc_get_product( $ua_pid )->get_children();
wp_delete_post( (int) $ua_children[0], true );
wp_update_post( [ 'ID' => $ua_pid ] );
wp_cache_flush();
$checks['orphan variation removed in RU'] = count( wc_get_product( $ru_pid )->get_children() ) === 1;

$fail = 0;

foreach ( $checks as $label => $ok ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . " — {$label}\n";
    if ( ! $ok ) $fail++;
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
