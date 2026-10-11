# P1/P2 фінансового виконання — runtime-приймання

## Межа

Ціль: `wayforpay-multi-seller` та оригінальний WayForPay / legacy-перехід. Universal, нові інтеграції Tutor/FluentCRM і зміни сервера не входять. Оплачений склад лише віртуальних товарів → `completed`; фізичний або змішаний → штатний `processing`.

## Відтворення до виправлення

Production-код `4018f98c87a0dfb3a79541eb2bfa4838b93fc397`; test-only commit `6334a163e818ff41985bc6077794d114158e9d3f`; [CI 38160351726](https://github.com/trofoz/Word-Press-Woocommerce/actions/runs/38160351726). У всіх 4 jobs Classic/Blocks × HPOS off/on:

- Повне ручне повернення → картка, IBAN з `attempt_id=0`, `paid_review` помилково отримали `completed=1`, `payment_complete=1`.
- Повернення зайвого receipt зберегло чинну прийняту оплату й завершення один раз — позитивна контрольна перевірка.
- Відновлення не знайшло задачу для `paid_review + pending`.

Це реальні WC orders і таблиці MariaDB у disposable WordPress, без підміни `$wpdb`. Прийняті фінансові докази в новому вузькому тесті є явними fixture-записами; перевірка підписаного HTTP callback і реального IBAN confirm handler зберігається в попередніх runtime-тестах. Ручне повернення виконує справжній nonce/capability handler `PaymentOperations::record_refund()`, виконання — справжній runner / `WC_Order::payment_complete()`.

Два незалежні red-сигнали підтвердили конкретні пропуски у фінансовій перевірці й SQL відновлення. Додаткові спекулятивні гіпотези не потрібні. Локальний WSL без Docker та з малою вільною пам’яттю не використовується для WordPress runtime; тестовий CI-хост ізольований, staging/production не змінені.

## Виправлення

1. `is_financially_accepted()` для `paid` і `paid_review` перевіряє прийнятий receipt, активність його спроби та залишок після receipt-scoped `refund_recorded`. `attempt_id=0` не обходить перевірку залишку. Грошові суми обчислюються існуючими точними рядковими операціями; floats не використовуються.
2. `record_refund()` під тим самим order lock і SQL-транзакцією записує фінансовий факт та відкликає pending-виконання, якщо прийняте надходження більше не покриває зобов’язання. `execution_state=review`, error `refunded_before_execution`; факт прийнятої оплати, gross сума й refund evidence не переписуються.
3. Refund іншого receipt не зменшує залишок прийнятого. Неприйняті/часткові receipt не агрегуються автоматично й не дають нової фінансової підстави.
4. `expire_due()` знаходить також `paid_review + pending`; під order lock повторно читає стан, фінансову підставу і Woo status перед відновленням. Невалідні/скасовані pending задачі переводяться в review, не лишаються нескінченним ballast для пакета з 100 записів. Безоплатна покупка, яку Woo вже оплатив штатно, лишається придатною для фіксації виконання.
5. Worker, зовнішній `payment_complete()` guard і refund handler використовують спільний контракт чинної оплати. Повернення після вже завершеної покупки не повторює hooks і не додає нової інтеграції відкликання доступу.

## Регресійні сценарії

Workflow реально запускає `integration-refund-execution.php` для картки, IBAN без attempt, `paid_review`, повернення зайвого receipt, часткового повернення, відновлення чинної оплати та заборони відновлення для поверненої, недостатньої, voided і скасованої покупки.

Повернення повторно реєструється через справжній handler в окремому PHP процесі з тим самим operation ID/evidence; refund event лишається один, повторні runner calls не додають завершень. Для `paid_review` два PHP workers стартують через barrier; атомарні DB-лічильники подій і durable execution event мають показати по одному завершенню.

Старий тест lost-schedule тепер містить accepted receipt, замість одного непідтвердженого `state=paid`. Fixture зберігається в disposable DB і прибирається перед наступними сценаріями; продукційні правила не послаблені заради тесту.

Фінальний commit, CI jobs/log evidence, ZIP і SHA-256 передаються в супровідному файлі пакета. Sandbox/реальна оплата не підтверджені цією синтетичною матрицею.
