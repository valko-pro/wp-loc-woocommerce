# Gap analysis — Tier 3 (знахідки під час реалізації)

Дата: 2026-06-12. Сценарії з WP_LOC_WC_TIER_3.md.

## Статус сценаріїв (після реалізації)

| # | Сценарій | Статус |
|---|---|---|
| 1 | Translated product base `/ru/tovar/<slug>` 200, `/ru/product/<slug>` 301 | Works |
| 2 | Translated category base `/ru/kategoriya/<slug>` 200 | Works |
| 3 | hreflang на product singular (uk + ru-RU з translated base + x-default) | Works |
| 4 | hreflang на product_cat archive | Works |
| 5 | Canonical на RU singular → `/ru/tovar/...` | Works (⚠ див. нижче) |
| 6 | Yoast XML sitemap | Не тестовано — Yoast не встановлений на сайті |

## Ключові знахідки

1. **wp-loc фільтрує `home_url` per-language → core зрізає `/ru/` з шляху ДО rewrite-матчингу.**
   Реальну роботу в веб-запитах роблять НЕпрефіксовані правила (`tovar/...`), мова приходить з
   URI-сніфа wp-loc у `request`-фільтрі. `^ru/`-префіксовані правила wp-loc — belt-and-suspenders.
   Тому аддон клонує правила в ОБОХ виглядах (див. коментар у class-wp-loc-wc-rewrite-rules.php).
   У CLI-симуляції це невидимо — там матчаться префіксовані правила.

2. **hreflang/canonical URL з translated base — zero-code у hreflang-шарі.** wp-loc будує
   alternate-URL через `get_permalink()`/`get_term_link()`, а наші object-aware фільтри
   `post_type_link`/`term_link` (prio 9, за мовою ОБ'ЄКТА, не контексту) підставляють базу.
   Upstream-фільтр `wp_loc_hreflang_url` НЕ потрібен — підтверджено.

3. **⚠ Подвійний `rel="canonical"`** на singular-сторінках (2 теги, і UA і RU) — існувало ДО
   Tier 3, не пов'язано з аддоном. Схоже wp-loc canonical-fallback + core `rel_canonical`
   виводяться обидва. **[upstream candidate wp-loc]:** прибирати core-хук при власному виводі.

4. **`get_term_link($ru_term)` у UA-контексті повертає UA-лінк пари** — wp-loc зводить
   term-лінки до поточної мови. Це не баг: реальні RU-сторінки (де контекст ru) отримують
   правильні RU-URL, що видно у hreflang.

5. **Yoast-модуль не писався** — Yoast не встановлений (`WPSEO_VERSION` undefined), перевірити
   нема на чому, а wp-loc вже має Yoast-compat шар з sitemap alternates для всіх translatable
   типів. Якщо Yoast з'явиться — спочатку gap-тест, код тільки якщо реально треба (#37–39).

## Verification (виконано)

- `wp rewrite flush` → 25 правил з `tovar` (префіксовані + ні), 5+ з `kategoriya`
- `/ru/tovar/biopreparat-trihodermin-1-l-ru/` → 200, RU title
- `/ru/product/...` → 301 → `/ru/tovar/...` (toggle on); toggle off → 200 без редіректу
- UA product: hreflang ru-RU вказує на `/ru/tovar/...`
- RU product: canonical → `/ru/tovar/...`
- `/ru/kategoriya/bez-kategorii/` → 200; legacy → 301
- Адмін-сторінка Multilingual → WooCommerce: таби Status (матриця перекладів system pages)
  і Permalinks (поля per-language з placeholder, legacy-toggle) рендеряться, save через
  nonce + deferred flush (`wp_loc_flush_rewrite_rules`)
