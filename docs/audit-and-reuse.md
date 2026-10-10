# Аудит і карта повторного використання

Дата: 10.10.2026. Ревізії та хеші: [provenance](sources/provenance.json).
Clone відповідає commit PRD: `b33c8bcb725cf02f8a546273596071024fa4a7ba`.

## Upstream

Посилання на методи — у `woocommerce-gateway-wayforpay.php` цього commit.

| Місце | Спостереження | Рішення |
|---|---|---|
| `__construct`, `init_form_fields` | Єдиний merchant/key, тестові defaults, ключ у text-полі | Версія конфігурації продавця, без fallback, масковане поле |
| `isPaymentValid` | Перевіряє мерчанта/підпис, не звіряє суму/валюту зі спробою | Новий verifier і винятки №65 |
| Approved | Примусовий completed перед payment_complete | Штатне завершення без завершення доставки |
| `generate_wayforpay_form` | Reference з ID/часу, валюта магазину, старі властивості order | Збережена унікальна спроба, сума/валюта purchase, CRUD |
| Refunded | Скасовує все замовлення | Повернення конкретного надходження |
| `getSignature` | Пропускає відсутні поля | Структурна перевірка перед підписом, hash_equals |
| `check_wayforpay_response` | Немає durable ledger та координації повторів | Durable evidence і спільний lock |
| `checkResponse` | Залежить від WPSC | Не переносити цей шлях |
| IMGDIR, global functions/class | Глобальні імена | Новий namespace; legacy route — окремий адаптер |
| Дерево файлів | Немає Blocks-реалізації та доказів HPOS | Реалізувати й приймати окремо |

Факт fork не робить старий callback безпечним для нової моделі.

## Наявний проєкт Клубу

Шляхи нижче відносні до `7_Адмін/wp-plugins/ilovesad-multi-merchant-checkout` батьківського проєкту. Поточні bytes зафіксовані окремо від Git HEAD: дерево містить інші зміни. Кандидат на адаптацію не є готовою залежністю.

| Файл / сценарії | Використання | Зміна |
|---|---|---|
| `src/class-mm-payment-attempts.php` | Адаптувати InnoDB ledger, транзакції, order lock | Розділити evidence/receipts/execution; прибрати змішаний стан спроби completion_pending |
| `src/class-mm-wayforpay-gateway.php` | Адаптувати підписи, hash_equals, callback seam | Нині mismatch відхиляється до запису — потрібен №65; прибрати AccessGrants та ACK після LMS |
| `src/class-mm-order-snapshot.php` | Адаптувати серверну перевірку, захист snapshot/order-pay | Дозволити draft, штатні податки/доставку/знижки; прибрати Club endpoint/renewal залежності |
| `src/class-mm-seller-router.php` | Переписати реалізацію, використати сценарії | Nina/Yasya та ID allowlist замінити профілями/категоріями |
| `src/class-mm-cart-policy.php` | Адаптувати одного продавця | Ліміт однієї послуги винести у впровадження; додати Store API |
| `src/class-mm-iban-confirmation.php` | Адаптувати операторські дії | Ключ account+operation; прибрати Yasya-only gateway, базову вимогу платника-фізособи та LMS |
| `src/class-mm-payment-deadline-policy.php` | Адаптувати перевірки строків | 30 хв/72 год і типи послуг не переносити універсальними константами |
| `src/class-mm-operator-actions.php`, `src/class-mm-refund-revocations.php` | Референси для наступного детального аудиту | Не переносити цілком: нові права й receipt-scoped refund |
| `tests/payment-callback-test.php`, `tests/iban-confirmation-test.php`, `tests/payment-deadline-policy-test.php`, `tests/refund-revocation-test.php` | Використати сценарії при реалізації | Нові assertions, namespace і третій продавець; старі прогони не доказ форка |
| `multi-merchant-test-harness` у сусідній папці woocommerce | Адаптувати ізольовану інфраструктуру | Нові fixtures; синтетичний адаптер не production LMS |
| `class-mm-club-*`, `class-mm-access-grants.php`, Tutor/CRM | Не переносити до ядра | Окреме впровадження Клубу |

## Офіційні контракти

- [Purchase](https://wiki.wayforpay.com/view/852102): підпис запиту, callback і ACK мають різні набори полів. Повторна доставка обмежена чотирма добами. processingDate відсутній серед полів callback-підпису, тому його цілісність цим підписом не підтверджена. orderReference названий унікальним номером торговця; окремий transaction ID у наведеній схемі не задокументований.
- [Gateway API](https://developer.woocommerce.com/docs/features/payments/payment-gateway-api/): штатний gateway lifecycle.
- [Blocks](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/): окрема реєстрація методу; спільна серверна логіка.
- [HPOS](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/): замовлення через CRUD, фінансові таблиці незалежні від posts.

## Контрольні точки протоколу

| ID | Що підтвердити | Коли / власник | Консервативна поведінка |
|---|---|---|---|
| P01 | Кілька успішних списань на одному merchant+reference та їх ідентифікація | Розробник, до карткового приймання етапу 5; документація/підтримка | Нові спроби мають різні reference; суперечливі успіхи на одному ref — review |
| P02 | Підпис callback після ротації ключа | Розробник, до підтримки ротації етапу 5 | Не змінювати merchant; bounded keyring; неперевірений підпис не створює гроші |
| P03 | Семантика partial/full refund і суми callback | Розробник, до приймання refund етапу 5 | Не виводити повернуту суму з одного статусу; review або доказ ручного refund |

Ці точки не блокують каталог/storage, але блокують відповідні заяви про готовність карткового каналу. Листи підтримці та реальні платежі не виконувались.
