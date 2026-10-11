# Адресне прострочення старих синтетичних покупок staging

## Причина й межі зміни

Пакет 8014a899 не має адресного preview/apply для прострочення. `expire_due()` додатково відновлює paid/paid_review/free pending execution та обробляє stalled running у всьому магазині. Запуск загального hook із винятком для восьми ID у runner guard не є адресним врегулюванням.

За staging inventory від 11.10.2026 покупки нижче мають awaiting_payment/none, accepted_receipt_id=0. IBAN уже WC-cancelled; card pending, попередні спроби declined/expired. Це історичний inventory, не свіже підтвердження receipts=0. Перед застосуванням обов'язкова нова перевірка.

| Канал | Purchase → Woo order | Незмінний строк UTC |
|---|---|---|
| card | 13 → 47521 | 12.10.2026 18:13:00 |
| card | 14 → 47522 | 12.10.2026 18:13:01 |
| card | 15 → 47523 | 12.10.2026 18:23:16 |
| card | 28 → 47536 | 12.10.2026 19:04:27 |
| IBAN | 16 → 47524 | 14.10.2026 18:28:15 |
| IBAN | 17 → 47525 | 14.10.2026 18:30:14 |
| IBAN | 29 → 47537 | 14.10.2026 19:04:27 |
| IBAN | 30 → 47538 | 14.10.2026 19:04:28 |

Раннього expiry немає. До строку результат `not_due`; не змінювати expires_at, фінансові state чи SQL-selection для обходу. Усі строки наведено в UTC; перед кожною дією звірити поточний час WordPress і свіжий expires_at конкретної покупки.

### Хвилі врегулювання

Оператор має бути доступний на наведені строки. Кожна хвиля — окремі свіжі preview → ручний перегляд → apply → after-preview → preflight → відновлення runner. **Не чекати спливу строку всієї карткової чи IBAN-групи.** Для ID13/14 та ID29/30 дозволено об'єднати дві сусідні секунди в одну хвилю, дочекавшись другого строку.

| Хвиля | Найраніший час початку, UTC | WFPMS_SETTLEMENT_IDS | Наступний строк захищеної покупки |
|---|---|---|---|
| 1 | 12.10.2026 18:13:01 | `13,14` | ID15 — 12.10 18:23:16 |
| 2 | 12.10.2026 18:23:16 | `15` | ID28 — 12.10 19:04:27 |
| 3 | 12.10.2026 19:04:27 | `28` | ID16 — 14.10 18:28:15 |
| 4 | 14.10.2026 18:28:15 | `16` | ID17 — 14.10 18:30:14 |
| 5 | 14.10.2026 18:30:14 | `17` | ID29 — 14.10 19:04:27 |
| 6 | 14.10.2026 19:04:28 | `29,30` | У цих восьми покупок наступного строку немає |

Guard блокує весь tick, щойно **хоч один** захищений awaiting_payment стає due. Зупинка для ID13 може початися о18:13:00, за секунду до першої об'єднаної хвилі. Після його та ID14 закриття guard перестає бачити їх кандидатами; ID15 та решта ще не due й tick не блокують. Аналогічно після кожної наступної хвилі.

Тому нові задачі можуть оброблятися між хвилями 1→2, 2→3, 3→4, 4→5 та 5→6. Зокрема, після хвилі3 runner має працювати до першого IBAN-строку 14жовтня. Не залишати `runner.paused` на цей майже дводенний інтервал і не збирати всі вісім ID в один apply: майбутній not_due відхилить усю обрану групу ще до змін.

Якщо оператор запізнився або під час preview/apply сплив наступний строк, зробити нову хвилю **лише з уже due ID із погоджених восьми**. Наприклад, після12.10 19:04:27 можна адресно врегулювати `13,14,15,28`, не включаючи ще не due IBAN. Перед відновленням runner обробити наступний due ID або зупинитися через фінансову розбіжність; порожня черга кандидата для попередньої хвилі сама по собі не дозволяє зняти pause.

Це операторський розклад, не автоматичний планувальник. Він усуває очікування останньої дати за успішного своєчасного врегулювання. Без доступного оператора або за фінансової розбіжності безперервну обробку нових задач цей guard не гарантує; його безпечне блокування зберігається.

Мінімальне виправлення: `PurchaseExecutionService::expire_unpaid_purchase(id, dryRun=true, previewHash=null)` використовує чинний order mutex і транзакцію. Загальний expiry використовує той самий метод. Адресний виклик не виконує recovery чи інші покупки. Preview і apply під блокуванням перевіряють receipts=0, accepted_receipt_id=0, execution_state=none, відсутність execution_token/started_at/подій execution, неприйняту фінансову підставу, unpaid Woo pending/on-hold/cancelled і справді спливлий закріплений строк. Preview hash зв'язує покупку, події, attempts і стан Woo; apply відмовляє при зміні. Повтор з новим preview допускає already_expired без повтору події.

## Передумови оператора

1. Отримати ZIP/commit/CI/SHA-256 супровідного звіту, встановити лише на staging за окремою погодженою процедурою. Плагін у ZIP містить runtime-код; операторський файл `tools/settle-staging-purchases.php` передається окремо. Production й legacy ownership не змінювати.
2. Підтвердити синтетичне походження восьми покупок та точні Woo IDs. Зберегти staging backup і поточні runner scripts/config у захищеному каталозі. Наявні historical keys мають розшифровуватись; їх не виводити.
3. До першої хвилі зафіксувати початковий стан `runner.paused`. Під runner file lock установлювати тимчасову pause **окремо на кожну хвилю**, тільки для цього staging runner. Якщо pause уже існує від іншої операції, не знімати її без узгодження з її оператором. Зберегти глобальні cron/async/мережеві/поштові guards. Не запускати `wp cron event run --all`, Action Scheduler queue або глобальний expiry hook для врегулювання.
4. Перевірити встановлений код через CLI і FPM, доступність SecretStore, standalone=true, legacy_owner=false, оригінал активний enabled=no. Операторський adapter вимагає WP-CLI на https://staging.ilovesad.com та `--user=<дійсний Woo admin>`.

## Точна процедура

Команди нижче показують хвилю1. Вони виконуються оператором **всередині staging_php**, з перевіреним файлом adapter та захищеним локальним output-каталогом 0700, файлами 0600. `<admin>` і шляхи замінити фактичними значеннями. Для хвиль2–6 підставляти точний список ID з таблиці й окремі імена `wave-2-preview.json`/`wave-2-apply.json` тощо; порядок ID в apply має збігатися з preview. Секретів у параметрах немає. Preview JSON потрібно зберігати всередині контейнера: наступний виклик читає саме його. Preview до строку може бути інформаційним; для apply потрібен новий придатний preview після строку.

```bash
umask 077
WFPMS_SETTLEMENT_IDS=13,14 WFPMS_SETTLEMENT_MODE=preview wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/wave-1-preview.json
WFPMS_SETTLEMENT_IDS=13,14 WFPMS_SETTLEMENT_MODE=apply WFPMS_SETTLEMENT_PREVIEW_FILE=/secure/wave-1-preview.json wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/wave-1-apply.json
WFPMS_SETTLEMENT_IDS=13,14 WFPMS_SETTLEMENT_MODE=preview wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/wave-1-after.json
```

Не запускати apply до ручного перегляду відповідного preview. Для кожного рядка потрібні eligible=true, result=preview, receipts=0, accepted_receipt_id=0, execution_state=none, execution_started_at=null, execution_events=0 та очікуваний order ID. not_due/lock_failed/error або будь-яка розбіжність — зупинка. Adapter перед будь-якою зміною перевіряє всю обрану групу; під час apply кожен ID перевіряється знову під mutex із затвердженим preview_hash. Група не є єдиною транзакцією: callback між ID може зупинити наступний ID, уже закриті покупки не відкривати й не відновлювати стару БД. Зберегти partial report і зробити новий preview.

Після apply повторити preview: state=expired, Woo cancelled, execution none, receipts=0, accepted_receipt_id=0. По SELECT порівняти before/after purchase snapshot, config/credential IDs, external_reference, payload hashes, historical config hashes і journal: лише issued attempts стають expired; declined/expired та reference, ключі, реквізити, payload зберігаються. Одна payment_deadline_expired подія на покупку. Нових payment_complete/completed/execution_done немає. Apply report описує перевірений before-стан; after доводиться окремим preview.

## Пізній Approved та захист runner

Автентичний late Approved через новий маршрут форку збережеться як needs_review receipt; purchase переходить expired→review, accepted_receipt_id=0, execution none, Woo cancelled. HTTP 200 із підписаним accept підтверджує отримання повідомлення; виконання не запускається. Дубль не створює другого receipt. Неперевірений callback не створює оплату. Ніяких синтетичних callback до наявних staging references не надсилати; цей сценарій перевіряється лише в ізольованому runtime.

Після врегулювання **залишити** умови guard: safe=false або будь-який ID≤30 awaiting+due, accepted pending чи stale running блокує tick. Expired/none самі випадають з eligible queue, тому змінювати guard/allowlist не потрібно. Якщо справжній late Approved оператор згодом прийме й execution стане pending, guard має знову блокувати автоматичне виконання старого ID до окремого адресного рішення.

### Порядок роботи з guard для кожної хвилі

1. На **staging-хості від імені ops** взяти чинний `/home/ops/.local/state/wfpms-staging/runner.lock` через `flock --exclusive`. Дочекатися завершення активного tick. Під цим lock зафіксувати стан pause та створити `/home/ops/.local/state/wfpms-staging/runner.paused`, якщо його ще немає. Відпустити host lock; наступні ticks штатно завершуватимуться через локальну pause. Контейнерний каталог `/secure` не є місцем host lock.
2. Виконати preview/apply/after-preview **тільки для поточної due-хвилі**. Callback продовжує оброблятися; від гонки з ним захищають order mutex, повторна перевірка й preview_hash. Старі references і ключі не змінювати. За часткового результату зберегти звіт; не відкривати вже expired покупки й не повторювати stale apply.
3. Знову взяти той самий host lock. Виконати чинний read-only preflight; перевірити **одночасно** `safe=true` і `blocked_protected_ids=[]`, after-стани хвилі та збереження нерегульованих покупок. Приклад виклику preflight на хості (output зберігати з umask077):

   ```bash
   docker exec -i staging_php wp --path=/var/www/html --allow-root eval-file - < /home/ops/.local/state/wfpms-staging/preflight.php
   ```

4. Якщо preflight називає інший із восьми ID, який щойно став due, зробити для нього новий preview/apply до зняття pause. Якщо blocked ID поза погодженими вісьмома або має оплату/pending/running/іншу розбіжність, зупинити цю процедуру й передати оператору його діагностику. Не додавати allowlist і не послаблювати guard.
5. За успішних перевірок під host lock прибрати **лише pause, створену цією хвилею**, і відпустити lock. Не залишати її до наступної хвилі. Перевірити наступні штатні ticks: isolation_safe має лишатися true, result — без blocked/native_error; зберегти журнал та стан черги. Успішний tick без готових нових задач доводить доступність runner, а фактичне виконання нової задачі підтверджується її execution_done/Woo-переходом, якщо така задача була готова.
6. До наступного строку runner працює з чинним guard. Факт закриття поточної хвилі не дає дозволу на раннє закриття майбутніх ID.

`not_due` у майбутньої покупки саме по собі не блокує tick. Таку покупку виключити з поточного apply й залишити до її строку. Receipt або стан review забороняє її адресне прострочення за цією процедурою, але **не означає автоматично**, що треба тримати весь runner paused: рішення про відновлення визначають чинний preflight (`safe=true`, порожній blocked list) та after-перевірка вже виконаних дій. Не знімати pause, якщо preflight усе ще бачить due-awaiting або pending/stale-running захищений ID. Це порядок для оператора; підтвердження отримання та фактичні результати хвиль мають окремий запис.

## Доказ і обмеження

Runtime-регресії додані до вже виконуваного workflow-файла `integration-wayforpay-callback.php`: card issued/declined/expired і скасований Woo IBAN; незмінність dry-run; due й not_due; збереження історії; повтор expiry; unrelated due не змінюється; late signed HTTP Approved/duplicate → один needs_review receipt й нуль completion; stale preview після реальної оплати; guards для receipt/accepted/execution/started. Synthetic fixture clock і навмисно inconsistent rows змінюються лише в disposable WP/Woo/MariaDB CI, не операторським adapter.

Початковий RED: PHP class introspection довів відсутність адресного методу; це не Woo runtime red. Остаточна матриця й журнали додаються до звіту після фактичного CI. Зовнішня sandbox-оплата не перевірена. Цей сеанс не змінює сервер, staging чи production. Не заявляє, що вісім покупок уже закриті.
