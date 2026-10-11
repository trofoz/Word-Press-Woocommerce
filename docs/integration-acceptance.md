# План інтеграційного приймання етапів 1–8

Цей список відділяє локальні контрактні тести від перевірок, які потребують WordPress, WooCommerce, БД або WayForPay sandbox. Моки не зараховуються як проходження інтеграційного критерію.

## Перевірено локально

| Перевірка | Результат | Команда / доказ |
|---|---|---|
| ID простого товару та варіації | PASS | `php tests/unit-test.php` |
| Санітизація та фільтрація сторонніх gateway | PASS | `php tests/unit-test.php` |
| Реєстрація Checkout Blocks integration і джерело назви продавця | PASS (mock) | `php tests/blocks-contract-test.php` |
| JS реєстрація методів оплати та seller label | PASS (mock) | Node VM mock, виконаний під час приймання |
| PHP синтаксис | PASS | `find . -name '*.php' -type f -print0 | xargs -0 -n1 php -l` |
| JS синтаксис | PASS | `node --check assets/js/payment-methods.js` і `node --check assets/js/checkout-seller-label.js` |
| Whitespace у diff | PASS | `git diff --check HEAD` |

## Автоматизований WooCommerce runtime

Workflow `.github/workflows/woo-integration.yml` створює окрему MariaDB для кожної комбінації Classic/Blocks і HPOS вимкнено/увімкнено. На WordPress 7.0 та WooCommerce 11.1.0 він перевіряє активацію форку й оригінального WayForPay-плагіна (upstream commit `b33c8bcb725cf02f8a546273596071024fa4a7ba`), створення таблиць, шлюзи, три профілі продавців, маршрутизацію товарів, callback, IBAN надходження, міграцію історичного замовлення й оновлення схеми.

Останній результат: **PASS, 4/4 jobs**, [Actions run 38120753520](https://github.com/trofoz/Word-Press-Woocommerce/actions/runs/38120753520), commit `4461252`. На PHP 8.3, WordPress 7.0, WooCommerce 11.1.0 і MariaDB 11.4 пройшли Classic і Blocks з HPOS та без нього. Перевірено HTTP add-to-cart/Store API для одного й змішаного продавців, Classic і Store API checkout із створенням неоплачених IBAN-замовлень, відбір замовлень за продавцем та список помилок прив’язки товарів, реальні `order-pay` URL із замороженими реквізитами після зміни активної конфігурації, співіснування й повторну активацію оригінального шлюзу в обох порядках, legacy migration, підписані callback-и, точний retry та окреме друге Approved-підтвердження, key rotation, IBAN accepted/duplicate/conflict, прострочену форму, v3 migration та v4→v5 backfill `provider_reference`. Callback підписував тестовий harness; реальний платіж, hosted payment form і браузерна автоматизація не запускалися. Sandbox job був skipped.

## Окремий WayForPay sandbox запуск

Workflow має ручний параметр `run_wayforpay_sandbox`. Для одноразового запуску через push у тестову гілку commit-повідомлення також може містити маркер `[run-wfp-sandbox]`. Окремий job створює одну тимчасову БД, тестового продавця, товар і замовлення на UAH 10; шифрує опублікований sandbox credential випадковим ключем лише на час runner; формує HTTPS `serviceUrl` і `returnUrl`; відкриває hosted payment form; читає підписаний `CHECK_STATUS`. Якщо статус `Declined` або залишається `InProcessing` після очікування, тест вимагає, щоб WooCommerce-замовлення залишалось неоплаченим і невиконаним. Якщо статус `Approved`, він вимагає підписаний callback, прийняту квитанцію та одноразове завершення замовлення.

Для callback використовується одноразовий Cloudflare Quick Tunnel до локального allowlist-проксі. Проксі пропускає тільки одноразовий URL платіжної форми одного тестового замовлення, точний WayForPay callback `wc-api=wfpms_wayforpay` і сторінку повернення цього замовлення. Інші маршрути WordPress не експонуються. Логи й artifacts не містять merchant secret, URL платежу чи callback body; процеси зупиняються в `always()` cleanup. Зовнішній тунель тимчасовий і живе лише під час job.

Офіційні тестові реквізити merchant завантажуються в runner без запису в репозиторій або GitHub secrets ([WayForPay sandbox docs](https://wiki.wayforpay.com/view/852472)). Публічний тестовий режим картки описано в інструкції Weblium: тестові картки можуть лишати операцію незавершеною або відхиленою; успішна оплата в описаній інструкції потребує реальної картки й коштів ([Weblium test mode](https://help.weblium.com/uk/article/pidklyuchennya-wayforpay-u-yakosti-platizhnoyi-sistemi-14ymjfz/)). WayForPay визначає `InProcessing` як незавершену операцію, для якої треба повторити `CHECK_STATUS` ([WayForPay response codes](https://wiki.wayforpay.com/en/view/852131)). Тому статус `InProcessing` проходить лише перевірку безпечного очікування, а не успішну оплату. Позитивний callback/fulfillment сценарій залишається неперевіреним; його можна провести лише окремим погодженим способом без реального списання.

Фактичний результат: **PASS, 5/5 jobs**, [Actions run 38074053083](https://github.com/trofoz/Word-Press-Woocommerce/actions/runs/38074053083), commit `9db582b`. WayForPay повернув підписаний `InProcessing` / reason code `1131`. Тест підтвердив, що замовлення залишилося `awaiting_payment`, не було оплачено, не мало прийнятої квитанції, а `execution_state` лишився `none`. Це приймає безпечний pending стан; позитивний callback/fulfillment шлях цим sandbox запуском не перевірений.

## Потрібен окремий тестовий магазин

Зафіксувати точні версії PHP, WordPress, WooCommerce, MariaDB/MySQL і upstream WayForPay до запуску. Прогнати чотири незалежні варіанти:

| Checkout | HPOS | Основні перевірки |
|---|---|---|
| Classic | вимкнено | змішаний кошик, шлюзи, seller label, order-pay, admin order column/filter |
| Classic | увімкнено | ті самі правила; відсутність прямого доступу до posts order storage |
| Blocks | вимкнено | Store API validation/finalize, gateway registration, seller label, order data |
| Blocks | увімкнено | повний сценарій Blocks та HPOS одночасно |

У кожному варіанті перевірити щонайменше три продавці з різними мерчантами та IBAN; простий і варіативний товар; відсутнє, ручне й категорійне призначення; конфлікт категорій; сторонній gateway у allowlist і поза ним; купон/податок/доставка/округлення; нове й повторне order-pay замовлення.

## Грошові та міграційні сценарії

- У sandbox провести успішну оплату для кожного з трьох мерчантів; перевірити неправильні підпис, merchant, суму й валюту, повторний callback, дві різні успішні спроби та success після строку/скасування.
- Згенерувати картковий callback паралельно двічі; підтвердити одну прийняту операцію, збереження повторного фінансового факту та одноразове виконання WooCommerce.
- Для IBAN перевірити точне надходження, дубль ID на тому самому рахунку, повтор ID для іншого замовлення, недоплату, переплату, іншу валюту/рахунок і пізнє надходження.
- Перевірити межі збою між durable receipt і `payment_complete()`, завислий стан виконання та відсутність автоматичного повторного запуску сторонніх хуків.
- На копії БД перевірити `dbDelta` з чистої інсталяції та оновлення попередніх schema version без втрати даних.
- З upstream-плагіном перевірити початкову та передану callback-маршрутизацію, міграцію відкритого замовлення, відхилення неправильного/оплаченого/закритого замовлення й операційне прийняття тільки точного legacy receipt.
- Перевірити повторну активацію upstream і форку, щоб callback залишався в одного визначеного власника й шлюзи не дублювалися.

Безплатіжна HTTP/runtime матриця `38120753520` покриває змішаний кошик, Classic/Blocks order creation, адмінський фільтр і аудит, обидва порядки активації плагінів та повторну оплату через справжні WooCommerce `order-pay` URL. Вона не замінює браузерне тестування тем/UX і не покриває всі стани оплат із розділу «Грошові та міграційні сценарії».

## Відкриті release gates

- P01: письмово підтвердити у WayForPay ідентифікацію кількох списань за одним `orderReference`.
- P02: визначити підтримуваний порядок ротації ключа та перевірити callback під час періоду перекриття.
- P03: погодити ручну процедуру повернень і звірки refund evidence.
- Розкласти універсальні критерії №1–65 на test ID, записати результат і доказ кожного запуску. Поточний runtime не дозволяє зарахувати їх як пройдені.
- Критерії Клубу C01–C15 приймаються окремо після підключення фактичних категорій, продуктів, WayForPay акаунтів, доставки, фіскалізації та системи видачі доступу.

## Поточне блокування середовища

Поточний контейнер не має `wp`, Docker/Podman, MySQL-сервера або PHP MySQL-драйвера; локальні порти 80, 8080, 3306 і 3307 закриті. Тому runtime запуск виконується окремим workflow на тимчасовій GitHub-hosted VM з disposable DB. Harness у батьківському репозиторії лишається окремим і тестує інший плагін.

Репозиторій `trofoz/Word-Press-Woocommerce` і гілка `test/stages-1-8-integration` створені; CLI авторизований. Найновіша runtime матриця — **4/4 jobs PASS**, [Actions run 38120753520](https://github.com/trofoz/Word-Press-Woocommerce/actions/runs/38120753520). Платіж пропущено за рішенням користувача; це не є підтвердженням повного проходження 65 критеріїв чи readiness до production.
