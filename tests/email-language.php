<?php
/**
 * E2E: customer email мовою замовлення, admin email — default-мовою.
 * Запуск: wp eval-file tests/email-language.php
 * Створює RU-замовлення, тригерить processing-email, перехоплює wp_mail.
 */

$checks = [];
$mail_log = [];

add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mail_log ) {
    $mail_log[] = $atts;
    return true; // не відправляти реально
}, 10, 2 );

// 1. RU-замовлення з товаром 36
$order = wc_create_order();
$order->add_product( wc_get_product( 36 ), 1 );
$order->set_billing_email( 'test-customer@example.com' );
$order->set_billing_first_name( 'Тест' );
$order->update_meta_data( WP_LOC_WC_Order_Language::META_KEY, 'ru' );
$order->calculate_totals();
$order->save();

$checks['order language = ru'] = WP_LOC_WC_Order_Language::get( $order ) === 'ru';

// 2. Customer processing email
$mailer = WC()->mailer();
$emails = $mailer->get_emails();
$processing = $emails['WC_Email_Customer_Processing_Order'] ?? null;
$new_order = $emails['WC_Email_New_Order'] ?? null;

$mail_log = [];
$processing->trigger( $order->get_id(), $order );
$customer_mail = $mail_log[0] ?? null;

$checks['customer email sent'] = (bool) $customer_mail;
$checks['customer subject is RU'] = $customer_mail && (bool) preg_match( '/[ёыэъ]|ваш заказ|Спасибо|обрабатывается/iu', $customer_mail['subject'] . ' ' . $customer_mail['message'] );
$checks['lang restored after customer email'] = WP_LOC_WC_Lang::current_lang() === WP_LOC_WC_Lang::default_lang();

// 3. Admin new order email — default-мовою (UA)
$mail_log = [];
$new_order->trigger( $order->get_id(), $order );
$admin_mail = $mail_log[0] ?? null;

$checks['admin email sent'] = (bool) $admin_mail;
$checks['admin email not RU'] = $admin_mail && ! preg_match( '/[ёыэъ]/u', $admin_mail['message'] );
$checks['lang restored after admin email'] = WP_LOC_WC_Lang::current_lang() === WP_LOC_WC_Lang::default_lang();

// 4. UA-замовлення → customer email UA
$order_ua = wc_create_order();
$order_ua->add_product( wc_get_product( 36 ), 1 );
$order_ua->set_billing_email( 'test-customer-ua@example.com' );
$order_ua->update_meta_data( WP_LOC_WC_Order_Language::META_KEY, 'ua' );
$order_ua->calculate_totals();
$order_ua->save();

$mail_log = [];
$processing->trigger( $order_ua->get_id(), $order_ua );
$ua_mail = $mail_log[0] ?? null;
$checks['UA customer email not RU'] = $ua_mail && ! preg_match( '/[ёыэъ]/u', $ua_mail['message'] );

// cleanup
$order->delete( true );
$order_ua->delete( true );

$fail = 0;

foreach ( $checks as $label => $ok ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . " — {$label}\n";
    if ( ! $ok ) $fail++;
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
