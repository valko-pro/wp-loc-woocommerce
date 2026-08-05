<?php
/**
 * E2E: перекладні free-text налаштування WooCommerce через перемикач мови.
 * Запуск: wp eval-file tests/settings-texts.php
 * Тільки читання — нічого не створює і не змінює.
 */

$checks = [];

$options   = array_merge( [], ...array_values( WP_LOC_WC_Settings_Texts::TRANSLATED_OPTIONS ) );
$languages = array_keys( WP_LOC_Languages::get_active_languages() );
$default   = WP_LOC_Languages::get_default_language();

// 1. Кожна опція зареєстрована в мультимовному рушії wp-loc.
foreach ( $options as $option ) {
    $checks["{$option} registered as multilingual"] = WP_LOC_Options::is_multilingual( $option );
}

// 2. Екран WC опт-іниться у мовну підміну значень, і на ньому немає дублів.
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';

$checks['WC settings screen opts into localized options'] =
    apply_filters( 'wp_loc_is_localized_options_screen', false, WP_Screen::get( WP_LOC_WC_Settings_Texts::SCREEN_ID ) ) === true;

$checks['unrelated screen stays untouched'] =
    apply_filters( 'wp_loc_is_localized_options_screen', false, WP_Screen::get( 'edit-post' ) ) === false;

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

        $checks["no duplicated {$option}_{$suffix} field"] = ! in_array( "{$option}_{$suffix}", $field_ids, true );
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
