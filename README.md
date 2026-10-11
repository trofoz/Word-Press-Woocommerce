# WayForPay Multi-Seller for WooCommerce

Локальний форк `wayforpay/Word-Press-Woocommerce` зі збереженою історією upstream.
Етапи 1–8: аудит, seller-модель, purchase/payment core, IBAN operations, Checkout Blocks і міграційний адаптер, 10.10.2026.

**Це рання розробка, не готовий до живих платежів плагін.** Додано адмінський імпорт старого мерчанта, явну прив’язку відкритих історичних замовлень і callback adapter для таких замовлень. Базова інтеграційна матриця WordPress/WooCommerce пройшла у чотирьох CI конфігураціях; фактичний платіж у WayForPay sandbox, міграція реальних замовлень магазину та повна перевірка критеріїв PRD ще не виконані.

## Документи

- [Остаточний контракт статусів: підтверджена картка/IBAN → completed для всіх типів товарів](docs/payment-status-contract-2026-10-11.md).
- [Результати етапів 1–2](docs/stages-1-2.md).
- [Реалізація етапу 3](docs/stage-3.md).
- [Реалізація етапів 6–7 і відкриті межі](docs/stages-6-7.md).
- [Етап 8: міграція оригінального шлюзу](docs/stage-8.md).
- [Аудит і карта використання коду](docs/audit-and-reuse.md).
- [Фінансова модель та конкурентні операції](docs/financial-model.md).
- [Переходи станів](docs/state-transitions.md).
- [Копія PRD v3.2](docs/sources/PRD-v3.2.md).
- [Ревізії та SHA-256 джерел](docs/sources/provenance.json).

## Ідентичність майбутньої реалізації

| Елемент | Значення |
|---|---|
| Назва | WayForPay Multi-Seller for WooCommerce |
| Slug / text domain | `wayforpay-multi-seller` |
| PHP namespace | `WfpMultiSeller` |
| Префікс таблиць після `$wpdb->prefix` | `wfpms_` |
| Нові gateway IDs | `wfpms_card`, `wfpms_iban` |
| Новий callback | `wc-api=wfpms_wayforpay` |
| Історичний callback | upstream надсилає `wc-api=WC_wayforpay`; WooCommerce hook `wc_wayforpay`, лише явно мігровані замовлення |

Версія нового плагіна починається з `0.1.0-dev`; upstream `1.0` не є її версією.

## Git та походження

База: `b33c8bcb725cf02f8a546273596071024fa4a7ba`. GitHub-форк: `trofoz/Word-Press-Woocommerce`.
`origin` вказує на форк, `upstream` — на `wayforpay/Word-Press-Woocommerce` і доступний лише для читання.
Інтеграційна гілка: `test/stages-1-8-integration`. Workflow `.github/workflows/woo-integration.yml` запускає ізольовані WordPress/WooCommerce/ MariaDB перевірки для Classic і Blocks з HPOS та без нього.
Каталог має власний `.git`, не є submodule. Не додавати його до батьківського Git як випадковий gitlink; публікувати окремо.

Автор оригіналу: WayForPay, `support@wayforpay.com`. Атрибуція та GNU GPL v3 з вихідного заголовка збережені; див. [LICENSE](LICENSE) і [первинний README](docs/sources/upstream-README.md). Це похідний проєкт, а не заява про офіційну підтримку WayForPay.
