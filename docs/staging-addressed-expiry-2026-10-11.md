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

Раннього expiry немає. До строку результат `not_due`; не змінювати expires_at, фінансові state чи SQL-selection для обходу. Якщо потрібно закрити всі вісім однією процедурою — виконати після 14.10.2026 19:04:28 UTC. До того guard може штатно блокувати загальний tick. Картку можна врегулювати окремою групою після 12.10.2026 19:04:27 UTC.

Мінімальне виправлення: `PurchaseExecutionService::expire_unpaid_purchase(id, dryRun=true, previewHash=null)` використовує чинний order mutex і транзакцію. Загальний expiry використовує той самий метод. Адресний виклик не виконує recovery чи інші покупки. Preview і apply під блокуванням перевіряють receipts=0, accepted_receipt_id=0, execution_state=none, відсутність execution_token/started_at/подій execution, неприйняту фінансову підставу, unpaid Woo pending/on-hold/cancelled і справді спливлий закріплений строк. Preview hash зв'язує покупку, події, attempts і стан Woo; apply відмовляє при зміні. Повтор з новим preview допускає already_expired без повтору події.

## Передумови оператора

1. Отримати ZIP/commit/CI/SHA-256 супровідного звіту, встановити лише на staging за окремою погодженою процедурою. Плагін у ZIP містить runtime-код; операторський файл `tools/settle-staging-purchases.php` передається окремо. Production й legacy ownership не змінювати.
2. Підтвердити синтетичне походження восьми покупок та точні Woo IDs. Зберегти staging backup і поточні runner scripts/config у захищеному каталозі. Наявні historical keys мають розшифровуватись; їх не виводити.
3. Під runner file lock установити `runner.paused` тільки для цього staging runner. Зберегти глобальні cron/async/мережеві/поштові guards. Не запускати `wp cron event run --all`, Action Scheduler queue або глобальний expiry hook для врегулювання.
4. Перевірити встановлений код через CLI і FPM, доступність SecretStore, standalone=true, legacy_owner=false, оригінал активний enabled=no. Операторський adapter вимагає WP-CLI на https://staging.ilovesad.com та `--user=<дійсний Woo admin>`.

## Точна процедура

Команди нижче виконуються оператором **всередині staging_php**, з перевіреним файлом adapter та захищеним локальним output-каталогом 0700, файлами 0600. `<admin>` і шляхи замінити фактичними значеннями. Секретів у параметрах немає. Preview JSON потрібно зберігати всередині контейнера: наступний виклик читає саме його.

```bash
umask 077
WFPMS_SETTLEMENT_IDS=13,14,15,28 WFPMS_SETTLEMENT_MODE=preview wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/card-preview.json
WFPMS_SETTLEMENT_IDS=13,14,15,28 WFPMS_SETTLEMENT_MODE=apply WFPMS_SETTLEMENT_PREVIEW_FILE=/secure/card-preview.json wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/card-apply.json
WFPMS_SETTLEMENT_IDS=16,17,29,30 WFPMS_SETTLEMENT_MODE=preview wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/iban-preview.json
WFPMS_SETTLEMENT_IDS=16,17,29,30 WFPMS_SETTLEMENT_MODE=apply WFPMS_SETTLEMENT_PREVIEW_FILE=/secure/iban-preview.json wp --path=/var/www/html --allow-root --user=<admin> eval-file /secure/settle-staging-purchases.php > /secure/iban-apply.json
```

Не запускати apply до ручного перегляду відповідного preview. Для кожного рядка потрібні eligible=true, result=preview, receipts=0, accepted_receipt_id=0, execution_state=none, execution_started_at=null, execution_events=0 та очікуваний order ID. not_due/lock_failed/error або будь-яка розбіжність — зупинка. Adapter перед будь-якою зміною перевіряє всю обрану групу; під час apply кожен ID перевіряється знову під mutex із затвердженим preview_hash. Група не є єдиною транзакцією: callback між ID може зупинити наступний ID, уже закриті покупки не відкривати й не відновлювати стару БД. Зберегти partial report і зробити новий preview.

Після apply повторити preview: state=expired, Woo cancelled, execution none, receipts=0, accepted_receipt_id=0. По SELECT порівняти before/after purchase snapshot, config/credential IDs, external_reference, payload hashes, historical config hashes і journal: лише issued attempts стають expired; declined/expired та reference, ключі, реквізити, payload зберігаються. Одна payment_deadline_expired подія на покупку. Нових payment_complete/completed/execution_done немає. Apply report описує перевірений before-стан; after доводиться окремим preview.

## Пізній Approved та захист runner

Автентичний late Approved через новий маршрут форку збережеться як needs_review receipt; purchase переходить expired→review, accepted_receipt_id=0, execution none, Woo cancelled. HTTP 200 із підписаним accept підтверджує отримання повідомлення; виконання не запускається. Дубль не створює другого receipt. Неперевірений callback не створює оплату. Ніяких синтетичних callback до наявних staging references не надсилати; цей сценарій перевіряється лише в ізольованому runtime.

Після врегулювання **залишити** умови guard: safe=false або будь-який ID≤30 awaiting+due, accepted pending чи stale running блокує tick. Expired/none самі випадають з eligible queue, тому змінювати guard/allowlist не потрібно. Якщо справжній late Approved оператор згодом прийме й execution стане pending, guard має знову блокувати автоматичне виконання старого ID до окремого адресного рішення.

Оператор перевіряє fresh preflight з `blocked_protected_ids=[]`, незмінні глобальні isolation guards, незмінні нерегульовані покупки. Після цього тільки під runner file lock прибрати `runner.paused`, перевірити кілька tick журнала. Якщо в групі лишився not_due або виник receipt/review — guard не послаблювати, runner залишає безпечне блокування. Нову allowlist не додавати. Це пропозиція оператору; завершене узгодження й фактичний staging результат мають окремий запис.

## Доказ і обмеження

Runtime-регресії додані до вже виконуваного workflow-файла `integration-wayforpay-callback.php`: card issued/declined/expired і скасований Woo IBAN; незмінність dry-run; due й not_due; збереження історії; повтор expiry; unrelated due не змінюється; late signed HTTP Approved/duplicate → один needs_review receipt й нуль completion; stale preview після реальної оплати; guards для receipt/accepted/execution/started. Synthetic fixture clock і навмисно inconsistent rows змінюються лише в disposable WP/Woo/MariaDB CI, не операторським adapter.

Початковий RED: PHP class introspection довів відсутність адресного методу; це не Woo runtime red. Остаточна матриця й журнали додаються до звіту після фактичного CI. Зовнішня sandbox-оплата не перевірена. Цей сеанс не змінює сервер, staging чи production. Не заявляє, що вісім покупок уже закриті.
