# WP-LOC WooCommerce

WooCommerce multilingual integration for [WP-LOC](https://wp-loc.com/) — a lightweight, fast alternative to WPML + WooCommerce Multilingual.

## Requirements

- WordPress 6.5+
- [wp-loc](https://github.com/olegvalko-dev/wp-loc)
- WooCommerce

Independent of the `wp-loc-multicurrency` addon — either plugin works alone. Multi-currency is intentionally **not** part of this plugin; see the separate `wp-loc-multicurrency` addon.

## What it does

- **Shop basics**: products, categories, tags and all `pa_*` attributes are translatable; variations stay in sync between translated products; product data (prices and sale schedules, stock status, SKU, dimensions, tax, shipping class, featured/catalog visibility, image gallery, downloadable/external fields) is mirrored from the default-language product to its translations — only text stays per-language; programmatic updates (REST API, importers) trigger the same push; WC system pages (Shop / Cart / Checkout / My Account / Terms) resolve to the active language; the cart survives a language switch.
- **Commerce layer**: customer emails are sent in the order language (admin emails stay in the default language); coupons are translatable with a shared code; shipping and payment method labels can be translated in their native WC settings screens.
- **URLs & SEO**: translated permalink bases (`/product/` → `/ru/tovar/`), correct hreflang/canonical for products and categories, Yoast XML sitemap entries per language.

## Settings

`Multilingual → Settings → WooCommerce` tab in wp-admin (status overview + permalink translations; requires the wp-loc settings extension hooks). Shipping and gateway label translations live directly in WooCommerce → Settings → Shipping / Payments.

## WP-CLI

`wp wp-loc-wc sync-products [--dry-run] [--ids=<ids>]` — bulk-sync product data from the default-language products to their translations. Prints a per-product diff of pending field changes (only products that differ are listed); `--dry-run` reports without writing. Useful as a one-off backfill after enabling the plugin on an existing catalog or after imports.

## Architecture

The addon never patches wp-loc or WooCommerce — it only consumes their public filters and actions (`wp_loc_translatable_post_types`, `wp_loc_translatable_taxonomies`, `woocommerce_get_*_page_id`, `woocommerce_get_cart_item_from_session`, …). Classes are prefixed `WP_LOC_WC_`, one module per file in `includes/`. Order language is stored in the WPML-compatible `_wpml_language` order meta key.

## Install

Standalone plugin — clone into `web/app/plugins/wp-loc-woocommerce/` and activate. Not distributed via Composer.
