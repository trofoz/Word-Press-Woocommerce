# Термінальні події історичних платежів

## Підтверджений дефект

Прийнята перевірка фінансової підстави шукала лише `callback_Refunded`/`callback_Voided`; legacy-адаптер записує `legacy_Refunded`/`legacy_Voided`. Змінений підписаний Approved тієї самої спроби знову ставив attempt.status=approved і purchase.state=paid_review. Старий predicate дозволяв runner завершити повернену/скасовану оплату.

Власний red-доказ: [CI 38169829106](https://github.com/trofoz/Word-Press-Woocommerce/actions/runs/38169829106), test-only commit `fa544773a4f95463045066d216588abcaf6660e4`; runtime-код відповідає пакету `4b42e840`. У Classic/Blocks × HPOS off/on всі 4 jobs відтворили:

| Термінальна подія | Яка спроба | Стара фінансова перевірка | payment_complete / completed |
|---|---|---|---|
| Refunded | Прийнята | true — дефект | 1 / 1 — дефект |
| Voided | Прийнята | true — дефект | 1 / 1 — дефект |
| Refunded | Інша, зайва | true — правильний контроль | 1 / 1 |
| Voided | Інша, зайва | true — правильний контроль | 1 / 1 |

Це реальні WordPress/WooCommerce/MariaDB, без підміни `$wpdb`. Синтетичні profiles/orders — явні fixtures; всі Approved/Refunded/Voided, включно зі зміненим delayed Approved, надходять через HTTP до legacy handler. Операторське прийняття — штатна nonce-форма `review_receipt`; оригінал реально деактивовано для термінальної послідовності, потім активовано для перевірки ownership return. Повернення заблоковане за фінансовим станом, а не тільки через відсутній оригінал.

## Точкове виправлення

Єдина додаткова зміна runtime-коду відносно `4b42e840`: у `PurchaseExecutionService::is_financially_accepted()` SQL-перелік термінальних подій містить усі чотири назви. Кореляція `purchase_id` та `attempt_id` саме прийнятого receipt збережена. Термінальна подія іншої спроби не блокує чинну прийняту оплату. Підписаний повтор Approved не видаляє й не скасовує термінальний доказ.

Без зміни схем, callback payload, статусних handler-ів, шлюзів чи операторського override. Runner і відновлення pending задач використовують спільний predicate під чинними order locks. Standalone, фізичні/змішані товари → processing, лише віртуальні → completed збережені.

## Регресія й фінальний доказ

`tests/integration-http-legacy-operator.py` після повного supported migration/return cycle виконує чотири комбінації таблиці. Для кожної перевіряється: початковий Accepted+pending, реальна термінальна подія в DB, нетотожний Approved зі зміненим authCode, attempt знову approved, state paid_review, той самий accepted receipt ID і незмінна кількість надходжень.

Очікування після виправлення: accepted Refunded/Voided → financial=false, runner двічі дає 0/0, order pending, ownership return blocked; extra Refunded/Voided → financial=true, runner двічі дає 1/1, order completed. Повтор термінального й зміненого Approved не додає receipts/events, повтор runner дає 0/0. Всі попередні платіжні, checkout, IBAN, refund і concurrency сценарії лишаються у workflow.

Остаточні commit/CI jobs, фактичні green-рядки журналів, ZIP і SHA-256 — у супровідному звіті пакета та `.ci-evidence.json`. Lint не є runtime-доказом. Зовнішній sandbox тест лишається непідтвердженим.

## Передача оператору

Пакет `4b42e840` не використовувати для остаточного legacy-приймання. Чинний staging продовжує checkout і runner на прийнятому `a64053e`; legacy ownership не передавати до окремого приймання виправленого пакета. Для повторення використати ізольований runtime й чинний legacy-runbook; terminal-event сценарії не запускати на спільному з production staging. Сервер і production в цій роботі не змінюються; нових функцій немає.
