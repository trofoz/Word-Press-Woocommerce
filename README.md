# WayForPay Multi-Seller for WooCommerce

Локальний форк `wayforpay/Word-Press-Woocommerce` зі збереженою історією upstream.
Етапи 1–7: аудит, seller-модель, профілі, purchase/payment core, IBAN operations і Checkout Blocks, 10.10.2026.

**Це рання розробка, не готовий до живих платежів плагін.** Реалізовані seller routing, purchase snapshots, картковий/IBAN gateway та базовий Checkout Blocks adapter. Інтеграційне приймання у WordPress/WooCommerce і WayForPay sandbox, міграція історичних замовлень та повна перевірка критеріїв PRD ще не виконані.

## Документи

- [Результати етапів 1–2](docs/stages-1-2.md).
- [Реалізація етапу 3](docs/stage-3.md).
- [Реалізація етапів 6–7 і відкриті межі](docs/stages-6-7.md).
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
| Новий callback | `wc-api=wfpms_callback` |
| Історичний callback | `wc-api=WC_wayforpay`, лише підтверджено мігровані замовлення |

Версія нового плагіна починається з `0.1.0-dev`; upstream `1.0` не є її версією.

## Git та походження

База: `b33c8bcb725cf02f8a546273596071024fa4a7ba`. Робоча гілка: `feature/stage-3-seller-management`.
Remote `upstream` для читання, push URL вимкнений. GitHub-форк користувача ще не створений; `origin` відсутній.
Каталог має власний `.git`, не є submodule. Не додавати його до батьківського Git як випадковий gitlink; публікувати окремо.

Автор оригіналу: WayForPay, `support@wayforpay.com`. Атрибуція та GNU GPL v3 з вихідного заголовка збережені; див. [LICENSE](LICENSE) і [первинний README](docs/sources/upstream-README.md). Це похідний проєкт, а не заява про офіційну підтримку WayForPay.
