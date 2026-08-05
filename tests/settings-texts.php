<?php
/**
 * E2E: перекладні free-text налаштування WooCommerce.
 * Запуск: wp eval-file tests/settings-texts.php
 * Тільки читання — нічого не створює і не змінює.
 */

$checks = [];

$options   = array_merge( [], ...array_values( WP_LOC_WC_Settings_Texts::TRANSLATED_OPTIONS ) );
$languages = array_keys( WP_LOC_Languages::get_active_languages() );
$default   = WP_LOC_WC_Lang::default_lang();

// 1. Кожна опція зареєстрована в мультимовному рушії wp-loc.
foreach ( $options as $option ) {
    $checks["{$option} registered as multilingual"] = WP_LOC_Options::is_multilingual( $option );
}

// 2. Екран «Accounts & Privacy» отримує поле під кожну недефолтну мову.
require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';

$field_ids = [];

foreach ( WC_Admin_Settings::get_settings_pages() as $page ) {
    if ( $page->get_id() !== 'account' ) {
        continue;
    }

    foreach ( $page->get_settings_for_section( '' ) as $field ) {
        $field_ids[] = $field['id'] ?? '';
    }
}

foreach ( $options as $option ) {
    $checks["{$option} present on the settings screen"] = in_array( $option, $field_ids, true );

    foreach ( $languages as $lang ) {
        if ( $lang === $default ) {
            continue;
        }

        $suffix = WP_LOC_DB::to_db_language_code( $lang ) ?: $lang;

        $checks["{$option}_{$suffix} field injected"] = in_array( "{$option}_{$suffix}", $field_ids, true );
    }
}

// 3. Фронт віддає значення опції як є — жоден фільтр його не перебиває.
foreach ( $languages as $lang ) {
    WP_LOC_Routing::switch_language( $lang );

    foreach ( [ 'checkout', 'registration' ] as $type ) {
        $option = "woocommerce_{$type}_privacy_policy_text";

        if ( ! in_array( $option, $options, true ) ) {
            continue;
        }

        $checks["{$lang}: {$type} privacy text comes from the option"] =
            get_option( $option ) === wc_get_privacy_policy_text( $type );
    }
}

WP_LOC_Routing::switch_language( $default );

$fail = 0;

foreach ( $checks as $label => $ok ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . " — {$label}\n";
    if ( ! $ok ) $fail++;
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
