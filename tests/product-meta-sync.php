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
    $ru_pid = wp_insert_post( [ 'post_title' => 'Тестовый простой', 'post_name' => 'test-simple-meta-ru', 'post_type' => 'product', 'post_status' => 'publish' ] );
    $db->set_element_language( $ru_pid, 'post_product', 'ru', $trid, $default );
} else {
    // auto-create копіює назву — виставляємо ru-назву, щоб перевірити недоторканність текстів
    wp_update_post( [ 'ID' => (int) $ru_pid, 'post_title' => 'Тестовый простой' ] );
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

$lookup = $wpdb->get_row( $wpdb->prepare(
    "SELECT min_price, max_price, onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
    $ru_pid
) );
$checks['lookup min_price = 199'] = $lookup && (float) $lookup->min_price === 199.0;
$checks['lookup onsale = 1'] = $lookup && (int) $lookup->onsale === 1;

// 5. Зміна знижки на UA → пуш у RU
// WC CRUD save оновлює тільки мету і не тригерить save_post_product коли не змінюються
// post-level поля (так само як і в адмінці без submit форми). wp_update_post
// дублює поведінку адмін-сейву і запускає повний цикл sync.
$ua_p = wc_get_product( $ua_pid );
$ua_p->set_sale_price( '149' );
$ua_p->save();
wp_update_post( [ 'ID' => $ua_pid ] );
wp_cache_flush();
$checks['sale change pushed'] = get_post_meta( $ru_pid, '_sale_price', true ) === '149';

// 6. Pull-напрямок: диверсія в RU-мету → збереження RU повертає значення з UA
update_post_meta( $ru_pid, '_sale_price', '999' );
wp_update_post( [ 'ID' => $ru_pid ] );
wp_cache_flush();
$checks['RU save pulls from UA'] = get_post_meta( $ru_pid, '_sale_price', true ) === '149';

// 7. Статус наявності: outofstock дзеркалиться разом із visibility-термом
$ua_p = wc_get_product( $ua_pid );
$ua_p->set_stock_status( 'outofstock' );
$ua_p->save();
wp_update_post( [ 'ID' => $ua_pid ] );
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
