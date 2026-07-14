<?php
/**
 * E2E: sync не-текстової мети простого товару між translated parents.
 * Запуск: wp eval-file web/app/plugins/wp-loc-woocommerce/tests/product-meta-sync.php
 *
 * Реальний флоу: збереження UA-товару → wp-loc auto-create створює RU-пару
 * (save_post prio 20) → аддон пушить мету/visibility (prio 999).
 * Самодостатній: чистить артефакти, створює фікстуру, перевіряє.
 */

global $wpdb;

$db = WP_LOC::instance()->db;
$default = WP_LOC_WC_Lang::default_lang();

// 0. Cleanup: тестові товари з їх trid-групами
$test_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_name LIKE 'test-simple-meta%'" );

foreach ( $test_ids as $pid ) {
    $trid = $db->get_trid( (int) $pid, 'post_product' );

    $group = $trid ? $db->get_element_translations( $trid, 'post_product' ) : [];
    $group_ids = array_map( fn( $row ) => (int) $row->element_id, $group );
    $group_ids[] = (int) $pid;

    foreach ( array_unique( $group_ids ) as $gid ) {
        wp_delete_post( $gid, true );
    }
}

// 1. UA simple-товар: ціни, знижка, sku, вага, featured, галерея
$ua = new WC_Product_Simple();
$ua->set_name( 'Тестовий простий' );
$ua->set_slug( 'test-simple-meta-ua' );
$ua->set_status( 'publish' );
$ua->set_regular_price( '250' );
$ua->set_sale_price( '199' );
$ua->set_sku( 'TSM-SYNC-1' );
$ua->set_weight( '1.5' );
$ua->set_stock_status( 'instock' );
$ua->set_featured( true );
$ua_pid = $ua->save();

// Галерея: реальні attachment не потрібні, звіряємо дзеркалення сирого значення
update_post_meta( $ua_pid, '_product_image_gallery', '11,12' );

if ( ! $db->get_element_language( $ua_pid, 'post_product' ) ) {
    $db->set_element_language( $ua_pid, 'post_product', $default );
}

// 2. RU-пара: auto-create wp-loc або вручну (fallback)
$ru_pid = $db->get_element_translation( $ua_pid, 'post_product', 'ru' );
$via = 'auto-create';

if ( ! $ru_pid ) {
    $via = 'manual';
    $trid = $db->get_trid( $ua_pid, 'post_product' );
    $ru_pid = wp_insert_post( [ 'post_title' => 'Тестовый простой', 'post_content' => 'RU описание', 'post_name' => 'test-simple-meta-ru', 'post_type' => 'product', 'post_status' => 'publish' ] );
    $db->set_element_language( $ru_pid, 'post_product', 'ru', $trid, $default );
} else {
    // auto-create копіює назву — виставляємо ru-назву і ru-опис, щоб перевірити недоторканність текстів
    wp_update_post( [ 'ID' => (int) $ru_pid, 'post_title' => 'Тестовый простой', 'post_content' => 'RU описание' ] );
}

$ru_pid = (int) $ru_pid;
echo "UA product {$ua_pid}, RU pair {$ru_pid} ({$via})\n\n";

// 3. Push-sync збереженням UA-товару
wp_update_post( [ 'ID' => $ua_pid ] );
wp_cache_flush();

// 4. Перевірки push
$checks = [];
$checks['_regular_price mirrored'] = get_post_meta( $ru_pid, '_regular_price', true ) === '250';
$checks['_sale_price mirrored'] = get_post_meta( $ru_pid, '_sale_price', true ) === '199';
$checks['_price active on RU'] = get_post_meta( $ru_pid, '_price', true ) === '199';
$checks['_sku mirrored'] = get_post_meta( $ru_pid, '_sku', true ) === 'TSM-SYNC-1';
$checks['_weight mirrored'] = get_post_meta( $ru_pid, '_weight', true ) === '1.5';
$checks['gallery mirrored'] = get_post_meta( $ru_pid, '_product_image_gallery', true ) === '11,12';
$checks['featured mirrored'] = wc_get_product( $ru_pid )->is_featured() === true;
$checks['RU title untouched'] = get_post( $ru_pid )->post_title === 'Тестовый простой';
$checks['UA title untouched'] = get_post( $ua_pid )->post_title === 'Тестовий простий';
$checks['RU description untouched'] = get_post( $ru_pid )->post_content === 'RU описание';

$lookup = $wpdb->get_row( $wpdb->prepare(
    "SELECT min_price, max_price, onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
    $ru_pid
) );
$checks['lookup min_price = 199'] = $lookup && (float) $lookup->min_price === 199.0;
$checks['lookup max_price = 199'] = $lookup && (float) $lookup->max_price === 199.0;
$checks['lookup onsale = 1'] = $lookup && (int) $lookup->onsale === 1;

// 5. Steady-state price-only edit: the lookup staleness bug.
//
// Products with manage_stock=true and an explicit non-standard tax_class do NOT
// have any cycling props (tax_class stable, _stock stable) — so after full
// alignment the four existing CRUD setters (featured/visibility/stock_status/
// shipping_class) detect zero changes on a price-only edit.  Without the fix,
// updated_props is empty → WC never calls update_lookup_table → the lookup row
// stays stale even though _sale_price/_price meta were updated correctly.
//
// Fixture: fresh product with zero-rate tax + managed stock (10 units) so all
// meta are stable after the first full sync.
$ua2 = new WC_Product_Simple();
$ua2->set_name( 'Тестовий стабільний' );
$ua2->set_slug( 'test-simple-meta-stable-ua' );
$ua2->set_status( 'publish' );
$ua2->set_regular_price( '250' );
$ua2->set_sale_price( '199' );
$ua2->set_tax_class( 'zero-rate' );
$ua2->set_manage_stock( true );
$ua2->set_stock_quantity( 10 );
$ua2->set_stock_status( 'instock' );
$ua2_pid = $ua2->save();

if ( ! $db->get_element_language( $ua2_pid, 'post_product' ) ) {
    $db->set_element_language( $ua2_pid, 'post_product', $default );
}

$trid2 = $db->get_trid( $ua2_pid, 'post_product' );
$ru2_pid = wp_insert_post( [ 'post_title' => 'Тестовый стабильный', 'post_name' => 'test-simple-meta-stable-ru', 'post_type' => 'product', 'post_status' => 'publish' ] );
$db->set_element_language( $ru2_pid, 'post_product', 'ru', $trid2, $default );
$ru2_pid = (int) $ru2_pid;

// Multiple syncs to fully stabilize all meta (stock_quantity, tax_class, etc.)
for ( $i = 0; $i < 5; $i++ ) {
    wp_update_post( [ 'ID' => $ua2_pid ] );
    wp_cache_flush();
}

// Now change ONLY the sale price on UA2 — only a CRUD save, no wp_update_post workaround;
// the woocommerce_update_product hook must carry the sync.
$ua2_p = wc_get_product( $ua2_pid );
$ua2_p->set_sale_price( '149' );
$ua2_p->save();
wp_cache_flush();

$checks['sale change pushed (stable fixture)'] = get_post_meta( $ru2_pid, '_sale_price', true ) === '149';

// Without the fix: min_price still 199 (stale). With the fix: 149.
$lookup2 = $wpdb->get_row( $wpdb->prepare(
    "SELECT min_price, max_price, onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
    $ru2_pid
) );
$checks['lookup min_price = 149 after sale change'] = $lookup2 && (float) $lookup2->min_price === 149.0;
$checks['lookup max_price = 149 after sale change'] = $lookup2 && (float) $lookup2->max_price === 149.0;
$checks['lookup onsale = 1 after sale change'] = $lookup2 && (int) $lookup2->onsale === 1;

// Cleanup stable fixture
wp_delete_post( $ua2_pid, true );
wp_delete_post( $ru2_pid, true );

// 6. Pull-напрямок: диверсія в RU-мету → збереження RU повертає значення з UA
$ua_p = wc_get_product( $ua_pid );
$ua_p->set_sale_price( '149' );
$ua_p->save();
wp_update_post( [ 'ID' => $ua_pid ] );
wp_cache_flush();

update_post_meta( $ru_pid, '_sale_price', '999' );
wp_update_post( [ 'ID' => $ru_pid ] );
wp_cache_flush();
$checks['RU save pulls from UA'] = get_post_meta( $ru_pid, '_sale_price', true ) === '149';

// 7. Статус наявності: outofstock дзеркалиться разом із visibility-термом.
// Only a CRUD save — the woocommerce_update_product hook must carry the sync.
$ua_p = wc_get_product( $ua_pid );
$ua_p->set_stock_status( 'outofstock' );
$ua_p->save();
wp_cache_flush();
$ru_p = wc_get_product( $ru_pid );
$checks['stock status mirrored'] = $ru_p && $ru_p->get_stock_status() === 'outofstock';
$checks['outofstock visibility term on RU'] = has_term( 'outofstock', 'product_visibility', $ru_pid );

$fail = 0;

foreach ( $checks as $label => $ok ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . " — {$label}\n";
    if ( ! $ok ) $fail++;
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
