# Gap analysis — Tier 1 baseline (чистий wp-loc, без аддон-коду)

Дата: 2026-06-12. Сайт: sadovij-raj.test (valet), WC 10.8.1, wp-loc 1.0.5, мови UA (default) + RU.
Стан на момент прогону: 6 товарів, 42 категорії, 0 variable-товарів; `product` і `product_cat`
увімкнені вручну у wp-loc Settings; `product_tag` і `pa_*` — ні.

## Сценарії

### 1. Translated taxonomy term (product_cat) — **Partial**
- `product_cat` вже у `wp_loc_translatable_taxonomies` (увімкнено вручну у Settings UI).
- **Критичний нюанс:** терміни, створені ДО ввімкнення таксономії, не мають language-рядків у
  `icl_translations` → `WP_LOC_Routing::resolve_translated_term_request()` не знаходить термін через
  `find_term_by_path_and_language()` → ставить `wp_loc_invalid_term_lang=1` → **архів категорії 404
  навіть у дефолтній мові**. Перевірено: `/product-category/uncategorized/` (6 товарів) → 404;
  після `db->set_element_language(15, 'tax_product_cat', 'ua')` → 200.
- Нові терміни отримують language-рядок автоматично (перевірено: новий термін → рядок `uk`, trid).
- RU-переклад терміна on-demand через адмінку (auto-create пари нема — by design).
- **Висновок для аддону:** activation-sync існуючих термінів (фіча #3) обов'язковий, і не тільки для
  `pa_*`, а для ВСІХ WC-таксономій — інакше магазин з існуючим контентом ламається миттєво.

### 2. Global attribute pa_* — **Broken**
- Створено атрибут `pa_color`; `wc_get_attribute_taxonomy_names()` повертає його, але у
  `wp_loc_translatable_taxonomies` його нема → терміни без language-рядків (перевірено: термін
  «Зелений» → `get_element_language()` = NULL).
- Закривається фічами #1–#3 (live-фільтр + activation sync).

### 3. Variable product variations sync — **Broken** (code inspection)
- У wp-loc нуль коду про variations/`_product_attributes`. `WP_LOC_Content` синхронізує тільки
  post-поля (status, author, parent, menu_order, template, thumbnail).
- Variable-товарів на сайті ще нема; тест-дані створимо у WC-2.

### 4. Cart sync при зміні мови — **Broken** (code inspection)
- Хуків `woocommerce_get_cart_item_from_session` / `woocommerce_add_to_cart` ні у wp-loc, ні деінде.

### 5. WC system pages — **Broken**
- `wc_get_page_id('shop')` повертає той самий ID до і після `switch_language('ru')` (5/5).
- Перекладів системних сторінок не існує; фільтрів `woocommerce_get_*_page_id` нема.

### 6. Categories listing у двох мовах — **Partial**
- UA: 200 тільки після фіксу language-рядка терміна (див. сценарій 1).
- RU: 404, поки нема RU-перекладу терміна — коректна поведінка (on-demand translation).
- wp-loc сам генерує `^ru/product-category/(.+?)/?$ → ...&lang=ru` rewrite-правила — Tier 1 тут
  нічого не винен; translated base (`/ru/kategoriya/`) — це Tier 3.

## Додаткові перевірки з плану

- **Textdomain reload при `switch_language()` — працює.** `__('Cart','woocommerce')`: ru → «Корзина»,
  ua → «Кошик». Upstream `reload_textdomains()` НЕ потрібен (підтверджено).
  ⚠️ Env-фікс: RU language pack для WC не був встановлений (рядок був англійською) —
  виконано `wp language plugin install woocommerce ru_RU`. На проді перевірити те саме.
- **Hreflang для product — працює.** Сторінка товару віддає `hreflang="uk"` + `x-default`
  (RU-альтернатива з'явиться після створення перекладу). Upstream `wp_loc_hreflang_url` поки не потрібен.
- **Language-фільтр запитів** wp-loc додає LEFT JOIN до `icl_translations` з
  `language_code = X OR element_id IS NULL` — товари без language-рядків видимі у всіх мовах
  (graceful, не ламає магазин).

## Тест-дані, створені під час аналізу (лишені для WC-2)

- Атрибут `pa_color` (id 1) + термін «Зелений» (без language-рядка — навмисно, як test-fixture для #3)
- Термін product_cat «Тестова категорія» (id 64, з language-рядком ua)
- Термін product_cat 15 (uncategorized) отримав language-рядок `ua` вручну

## Підсумок

| # | Сценарій | Статус |
|---|---|---|
| 1 | Translated product_cat | Partial (404 для legacy-термінів без language-рядків) |
| 2 | Global attribute pa_* | Broken |
| 3 | Variations sync | Broken |
| 4 | Cart sync | Broken |
| 5 | System pages | Broken |
| 6 | Categories listing | Partial |

Прогноз з `WP_LOC_WC_TIER_1.md` підтверджено, з одним новим відкриттям: сценарій 1 гірший за
прогноз — без activation-sync ламаються навіть default-language архіви існуючих термінів.
