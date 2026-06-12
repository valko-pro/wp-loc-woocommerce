# WP-LOC WooCommerce

WooCommerce multilingual integration for [WP-LOC](https://wp-loc.com/) — a lightweight, fast alternative to WPML + WooCommerce Multilingual.

## Requires

- WordPress 6.5+
- [wp-loc](https://github.com/olegvalko-dev/wp-loc)
- WooCommerce

## What it does

- **Shop basics**: products, categories, tags and all `pa_*` attributes are translatable; variations stay in sync between translated products; WC system pages (Shop / Cart / Checkout / My Account / Terms) resolve to the active language; the cart survives a language switch.
- **Commerce layer**: customer emails are sent in the order language (admin emails stay in the default language); coupons are translatable with a shared code; shipping and payment method labels can be translated in their native WC settings screens.
- **URLs & SEO**: translated permalink bases (`/product/` → `/ru/tovar/`), correct hreflang/canonical for products and categories, Yoast XML sitemap entries per language.

Multi-currency is intentionally **not** part of this plugin — see the separate `wp-loc-multicurrency` addon. Both addons are independent of each other.

## Settings

`Multilingual → WooCommerce` in wp-admin (status overview + permalink translations). Shipping and gateway label translations live directly in WooCommerce → Settings → Shipping / Payments.

## Architecture

The addon never patches wp-loc or WooCommerce — it only consumes their public filters and actions (`wp_loc_translatable_post_types`, `wp_loc_translatable_taxonomies`, `woocommerce_get_*_page_id`, `woocommerce_get_cart_item_from_session`, …). Classes are prefixed `WP_LOC_WC_`, one module per file in `includes/`.

Order language is stored in the WPML-compatible `_wpml_language` order meta key.
