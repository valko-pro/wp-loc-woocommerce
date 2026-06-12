# Gap analysis — Tier 2 (знахідки під час реалізації)

Дата: 2026-06-12. Сценарії з WP_LOC_WC_TIER_2.md: всі 5 були Broken/Partial до реалізації,
закриті модулями order-language / emails / coupons / shipping-labels / gateway-labels.

## Ключові знахідки

1. **WC_Email::trigger() викликає setup_locale() ДО присвоєння `$email->object`** — фільтри
   `woocommerce_allow_switching_email_locale` спрацьовують зарано, мову замовлення там не
   визначити. Рішення: перемикання у `woocommerce_email_recipient_{id}` (object вже є,
   subject/content ще не рендерились), restore у `woocommerce_allow_restoring_email_locale`
   (викликається наприкінці кожного trigger незалежно від відправки).

2. **`WP_LOC_Routing::switch_language()` перезавантажує textdomain-и** — підтверджено
   (RU: «Корзина», UA: «Кошик»). Upstream `reload_textdomains()` НЕ потрібен.
   ⚠️ Env: RU language pack для WC не був встановлений локально —
   `wp language plugin install woocommerce ru_RU`. Перевірити на проді.

3. **wp-loc bug (пофікшено upstream, гілка `fix/media-attachment-filter-type-error`):**
   строгий `int $attachment_id` у callback фільтра `wp_get_attachment_image_src` валить
   TypeError для товарів без зображення у WC-email (core не кастить аргументи фільтрів).

4. **Storage перекладів shipping/gateway** — НЕ окремий option-шар (как планував tier-док),
   а нативні settings-поля методів/гейтвеїв (`title_<lang>` через
   `woocommerce_shipping_instance_form_fields_{id}` / `woocommerce_settings_api_form_fields_{id}`).
   WC застосовує ці фільтри при читанні `get_form_fields()` — реєстрація на init 20 встигає.
   `WP_LOC_WC_String_Helper` через це не потрібен взагалі — менше коду, нуль нових options.

5. **Custom email subjects** (введені в WC Settings) не перекладаються — використовуються
   verbatim для всіх мов. Дефолтні subjects ідуть через `__()` і перекладаються .mo-файлами.
   Якщо знадобляться per-language subjects — додамо `subject_<lang>` поля тим самим патерном.

6. **Shipping-методи без `title` у instance-формі** (vp-nova-poshta) — поля перекладу не
   інжектяться, label лишається з textdomain-у самого плагіна. Це коректна поведінка.

## Verification

- `wp eval-file tests/email-language.php` — ALL PASS (RU customer email російською,
  admin email — UA, мова відновлюється після кожного trigger).
- Купони: shared code enforcement (код RU-перекладу скидається до оригінального + admin
  notice), опис підміняється мовою контексту через `woocommerce_coupon_loaded`.
- Labels: `flat_rate` title_ru → RU-кошик показує переклад; COD title/description/instructions
  перекладаються через `woocommerce_gateway_title`/`_description` фільтри + мутацію
  instructions у `woocommerce_available_payment_gateways`.
