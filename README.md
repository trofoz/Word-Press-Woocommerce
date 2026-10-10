# WayForPay Multi-Seller for WooCommerce

Локальний форк `wayforpay/Word-Press-Woocommerce` зі збереженою історією upstream.
Етапи 1–3: аудит, фінансова модель, seller-профілі та маршрутизація товарів, 10.10.2026.

**Це рання розробка, не готовий до живих платежів плагін.** Уже є новий entrypoint, таблиці продавців і реквізитів, шифрування WayForPay secret, налаштування категорій і ручне призначення товарів. Шлюзи оплати, кошик, callbacks, замовлення та міграція ще не реалізовані.

## Документи

- [Результати етапів 1–2](docs/stages-1-2.md).
- [Реалізація етапу 3](docs/stage-3.md).
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

Ідентифікатори зарезервовані контрактом; payment gateways ще не зареєстровані. Версія нового плагіна починається з `0.1.0-dev`; upstream `1.0` не є її версією.

## Git та походження

База: `b33c8bcb725cf02f8a546273596071024fa4a7ba`. Робоча гілка: `feature/stage-3-seller-management`.
Remote `upstream` для читання, push URL вимкнений. GitHub-форк користувача ще не створений; `origin` відсутній.
Каталог має власний `.git`, не є submodule. Не додавати його до батьківського Git як випадковий gitlink; публікувати окремо.

Автор оригіналу: WayForPay, `support@wayforpay.com`. Атрибуція та GNU GPL v3 з вихідного заголовка збережені; див. [LICENSE](LICENSE) і [первинний README](docs/sources/upstream-README.md). Це похідний проєкт, а не заява про офіційну підтримку WayForPay.
