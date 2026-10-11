<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Explicit migration and callback adapter for the original WooCommerce WayForPay gateway. */
final class LegacyMigration
{
    private const OPTION = 'woocommerce_wayforpay_settings';
    private const IMPORT_OPTION = 'wfpms_legacy_import';
    private const CALLBACK_OWNER_OPTION = 'wfpms_legacy_callback_owner';
    private const TRANSITION_OPTION = 'wfpms_legacy_transition_enabled';
    private const STANDALONE_OPTION = 'wfpms_standalone_mode_enabled';
    private const CALLBACK_ACTION = 'wc_wayforpay';

    public static function migrations_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wfpms_legacy_migrations';
    }

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_post_wfpms_import_legacy_merchant', [self::class, 'import_merchant']);
        add_action('admin_post_wfpms_claim_legacy_callback', [self::class, 'claim_callback_route']);
        add_action('admin_post_wfpms_release_legacy_callback', [self::class, 'release_callback_route']);
        add_action('admin_post_wfpms_migrate_legacy_order', [self::class, 'migrate_order']);
        add_action('admin_post_wfpms_enable_legacy_transition', [self::class, 'enable_transition']);
        add_action('admin_post_wfpms_enable_standalone_mode', [self::class, 'enable_standalone_mode']);
        add_action('admin_notices', [self::class, 'admin_notice']);
        // This priority lets the fork own the historical route before the upstream handler.
        add_action('woocommerce_api_' . self::CALLBACK_ACTION, [self::class, 'handle_callback'], 0);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'filter_gateways'], 1000);
    }

    /** @return array{merchant_account:string,secret_key:string}|null */
    public static function legacy_settings(): ?array
    {
        $settings = get_option(self::OPTION);
        if (!is_array($settings)) {
            return null;
        }
        $account = isset($settings['merchant_account']) && is_scalar($settings['merchant_account'])
            ? trim((string) $settings['merchant_account']) : '';
        $secret = isset($settings['secret_key']) && is_scalar($settings['secret_key'])
            ? (string) $settings['secret_key'] : '';
        return $account !== '' && $secret !== ''
            ? ['merchant_account' => $account, 'secret_key' => $secret]
            : null;
    }

    public static function for_order(int $orderId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::migrations_table() . ' WHERE order_id=%d',
            $orderId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function imported_config_for_seller(int $sellerId): ?array
    {
        $import = get_option(self::IMPORT_OPTION, []);
        $legacy = self::legacy_settings();
        if (!is_array($import) || $legacy === null || (int) ($import['seller_id'] ?? 0) !== $sellerId) {
            return null;
        }
        $config = SellerRepository::config((int) ($import['config_id'] ?? 0));
        if ($config === null || (int) $config['seller_id'] !== $sellerId
            || !hash_equals((string) $legacy['merchant_account'], (string) $config['merchant_account'])
            || SecretStore::decrypt((string) $config['secret_ciphertext']) === null
            || !hash_equals($legacy['secret_key'], (string) SecretStore::decrypt((string) $config['secret_ciphertext']))) {
            return null;
        }
        return $config;
    }

    public static function is_transition_enabled(): bool
    {
        return self::is_legacy_transition_enabled() || self::is_standalone_enabled();
    }

    public static function is_legacy_transition_enabled(): bool
    {
        return get_option(self::TRANSITION_OPTION, 'no') === 'yes';
    }

    public static function is_standalone_enabled(): bool
    {
        return get_option(self::STANDALONE_OPTION, 'no') === 'yes';
    }

    public static function payments_enabled_for_order(int $orderId): bool
    {
        if (self::is_transition_enabled()) {
            return true;
        }
        if ($orderId < 1 || !self::owns_callback_route()) {
            return false;
        }
        $mapping = self::for_order($orderId);
        $purchase = PurchaseRepository::for_order($orderId);
        $order = wc_get_order($orderId);
        return $mapping !== null && $purchase !== null && $order instanceof \WC_Order
            && (int) $mapping['purchase_id'] === (int) $purchase['id']
            && (int) $mapping['seller_id'] === (int) $purchase['seller_id']
            && (int) $mapping['config_id'] === (int) $purchase['config_id']
            && $order->get_payment_method() === 'wayforpay'
            && !$order->is_paid() && in_array($order->get_status(), ['pending', 'on-hold'], true);
    }

    public static function owns_callback_route(): bool
    {
        return get_option(self::CALLBACK_OWNER_OPTION, 'no') === 'yes';
    }

    private static function upstream_callback_is_registered(): bool
    {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) {
            return false;
        }
        $gateway = WC()->payment_gateways()->payment_gateways()['wayforpay'] ?? null;
        return $gateway instanceof \WC_Payment_Gateway
            && has_action('woocommerce_api_' . self::CALLBACK_ACTION, [$gateway, 'check_wayforpay_response']) !== false;
    }

    /** @return list<int>|null IDs that need mapping, or null when WooCommerce cannot return a complete order list. */
    public static function unmigrated_open_order_ids(): ?array
    {
        global $wpdb;
        $page = 1;
        $unmapped = [];
        do {
            $result = wc_get_orders([
                'payment_method' => 'wayforpay',
                'status' => ['pending', 'on-hold'],
                'limit' => 100,
                'page' => $page,
                'paginate' => true,
                'orderby' => 'date',
                'order' => 'ASC',
                'return' => 'objects',
            ]);
            if (!is_object($result) || !isset($result->orders) || !is_array($result->orders)) {
                return null;
            }
            $orderIds = [];
            foreach ($result->orders as $order) {
                if (!$order instanceof \WC_Order) {
                    return null;
                }
                $orderIds[] = (int) $order->get_id();
            }
            if ($orderIds !== []) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '%d'));
                $mappedIds = $wpdb->get_col($wpdb->prepare(
                    'SELECT m.order_id FROM ' . self::migrations_table() . ' m INNER JOIN '
                    . PurchaseRepository::purchases_table() . ' p ON p.id=m.purchase_id AND p.order_id=m.order_id'
                    . ' AND p.seller_id=m.seller_id AND p.config_id=m.config_id WHERE m.order_id IN (' . $placeholders . ')',
                    ...$orderIds
                ));
                if (!is_array($mappedIds)) {
                    return null;
                }
                $mappedSet = array_fill_keys(array_map('intval', $mappedIds), true);
                foreach ($orderIds as $orderId) {
                    if (!isset($mappedSet[$orderId])) {
                        $unmapped[] = $orderId;
                    }
                }
            }
            $maxPages = max(1, (int) ($result->max_num_pages ?? 1));
            $page++;
        } while ($page <= $maxPages);

        return $unmapped;
    }

    public static function transition_is_ready(): bool
    {
        $import = get_option(self::IMPORT_OPTION, []);
        $config = is_array($import) ? SellerRepository::config((int) ($import['config_id'] ?? 0)) : null;
        return self::upstream_callback_is_registered()
            && self::owns_callback_route()
            && $config !== null
            && (int) $config['seller_id'] === (int) ($import['seller_id'] ?? 0)
            && self::imported_config_for_seller((int) ($import['seller_id'] ?? 0)) !== null
            && self::unmigrated_open_order_ids() === [];
    }

    public static function filter_gateways(array $gateways): array
    {
        if (self::is_standalone_enabled() && !self::is_legacy_transition_enabled()) {
            return $gateways;
        }
        if (!self::is_transition_enabled()) {
            if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
                $orderId = absint(get_query_var('order-pay'));
                if (self::payments_enabled_for_order($orderId)) {
                    unset($gateways['wayforpay']);
                    return $gateways;
                }
            }
            unset($gateways['wfpms_card'], $gateways['wfpms_iban']);
            return $gateways;
        }
        unset($gateways['wayforpay']);
        return $gateways;
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Перехід зі старого WayForPay', 'wayforpay-multi-seller'),
            __('Міграція WayForPay', 'wayforpay-multi-seller'),
            'manage_woocommerce',
            'wfpms-legacy-migration',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage payment migration.', 'wayforpay-multi-seller'), 403);
        }
        $notice = isset($_GET['wfpms_migration_notice']) && is_scalar($_GET['wfpms_migration_notice'])
            ? sanitize_key(wp_unslash((string) $_GET['wfpms_migration_notice'])) : '';
        $messages = [
            'imported' => __('Параметри мерчанта імпортовано в конфігурацію продавця. Старі налаштування збережено.', 'wayforpay-multi-seller'),
            'already_imported' => __('Ці самі параметри вже імпортовано до цього продавця.', 'wayforpay-multi-seller'),
            'order_migrated' => __('Замовлення прив’язано до продавця та зафіксовано фінансовий знімок.', 'wayforpay-multi-seller'),
            'callback_owned' => __('Форк обробляє callback мігрованих замовлень, а інші передає оригінальному шлюзу. Чинний checkout працює до завершення переходу.', 'wayforpay-multi-seller'),
            'callback_released' => __('Історичний callback повернено оригіналу. Нові оплати форку працюють у standalone режимі; фінансові записи збережено.', 'wayforpay-multi-seller'),
            'callback_release_blocked' => __('Повернення callback заблоковано: перевірте підтвердження дії, завершення всіх прив’язаних покупок і відповідність історичного мерчанта та ключа налаштуванням оригіналу. Невирішені покупки залишаються у форку.', 'wayforpay-multi-seller'),
            'transition_enabled' => __('Нові платежі форку увімкнено; невідомі форку старі callback передаються оригінальному шлюзу, який має лишатися активним.', 'wayforpay-multi-seller'),
            'standalone_enabled' => __('Standalone режим увімкнено. Нові оплати використовують профілі продавців; старі способи оплати лишаються доступними для товарів без продавця.', 'wayforpay-multi-seller'),
            'standalone_blocked' => __('Standalone режим недоступний, доки форк володіє legacy callback або завершений legacy-перехід.', 'wayforpay-multi-seller'),
            'settings_missing' => __('Не знайдено повні налаштування оригінального шлюзу (merchant_account, secret_key).', 'wayforpay-multi-seller'),
            'seller_required' => __('Оберіть продавця з перевіреною юридичною особою та реквізитами.', 'wayforpay-multi-seller'),
            'domain_required' => __('Вкажіть домен мерчанта й підтвердьте його в кабінеті WayForPay.', 'wayforpay-multi-seller'),
            'import_failed' => __('Не вдалося імпортувати параметри. Перевірте ключ шифрування та конфігурацію продавця.', 'wayforpay-multi-seller'),
            'order_failed' => __('Замовлення не мігровано. Перевірте його статус, спосіб оплати, продавця та мерчанта.', 'wayforpay-multi-seller'),
            'transition_blocked' => __('Спершу імпортуйте мерчанта у профіль продавця.', 'wayforpay-multi-seller'),
            'callback_unavailable' => __('Оригінальний WayForPay gateway або його callback handler не зареєстрований. Переконайтеся, що плагін активний, і повторіть.', 'wayforpay-multi-seller'),
            'transition_orders_unmigrated' => __('Перехід не завершено: спершу прив’яжіть до продавців усі відкриті неоплачені замовлення старого шлюзу. Перехід залишився вимкненим.', 'wayforpay-multi-seller'),
        ];
        if (isset($messages[$notice])) {
            $success = in_array($notice, ['imported', 'already_imported', 'order_migrated', 'callback_owned', 'callback_released', 'transition_enabled', 'standalone_enabled'], true);
            echo '<div class="notice ' . esc_attr($success ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }

        $settings = self::legacy_settings();
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $domain = is_string($host) ? strtolower($host) : '';
        $import = get_option(self::IMPORT_OPTION, []);
        echo '<div class="wrap"><h1>' . esc_html__('Міграція оригінального WayForPay', 'wayforpay-multi-seller') . '</h1>';
        echo '<p>' . esc_html__('Перенесення виконується окремо: спершу мерчант у вибраний профіль, потім явне призначення продавця кожному незавершеному старому замовленню. Секрет у попередньому перегляді не показується.', 'wayforpay-multi-seller') . '</p>';
        echo '<h2>' . esc_html__('Попередній перегляд', 'wayforpay-multi-seller') . '</h2>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        echo '<tr><th>' . esc_html__('Джерело', 'wayforpay-multi-seller') . '</th><td><code>' . esc_html(self::OPTION) . '</code></td></tr>';
        echo '<tr><th>merchantAccount</th><td>' . esc_html((string) ($settings['merchant_account'] ?? '—')) . '</td></tr>';
        echo '<tr><th>secret_key</th><td>' . esc_html($settings === null ? __('Відсутній', 'wayforpay-multi-seller') : __('Налаштований; значення приховано', 'wayforpay-multi-seller')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Оригінальний gateway', 'wayforpay-multi-seller') . '</th><td>'
            . esc_html(class_exists('WC_wayforpay') ? __('Плагін завантажено', 'wayforpay-multi-seller') : __('Клас не завантажено', 'wayforpay-multi-seller'))
            . '</td></tr>';
        echo '<tr><th>' . esc_html__('Домен за home_url()', 'wayforpay-multi-seller') . '</th><td>' . esc_html($domain !== '' ? $domain : '—') . '</td></tr>';
        $transitionLabel = self::is_transition_enabled()
            ? (self::is_standalone_enabled()
                ? __('Standalone режим активний для нових оплат', 'wayforpay-multi-seller')
                : __('Форк активний для нових оплат після переходу', 'wayforpay-multi-seller'))
            : (self::owns_callback_route()
                ? __('Перехід підготовлено: чинний шлюз і checkout збережені до фінального перемикання', 'wayforpay-multi-seller')
                : __('Режим налаштування: чинний шлюз і checkout збережені', 'wayforpay-multi-seller'));
        echo '<tr><th>' . esc_html__('Стан переходу', 'wayforpay-multi-seller') . '</th><td>'
            . esc_html($transitionLabel) . '</td></tr></tbody></table>';
        echo '<p>' . esc_html__('Власник історичного маршруту', 'wayforpay-multi-seller') . ': <strong>'
            . esc_html(self::owns_callback_route() ? __('Форк', 'wayforpay-multi-seller') : __('Оригінальний WayForPay', 'wayforpay-multi-seller'))
            . '</strong>; <code>/?wc-api=WC_wayforpay</code>. '
            . esc_html(self::upstream_callback_is_registered() ? __('Обробник оригіналу зареєстрований.', 'wayforpay-multi-seller') : __('Обробник оригіналу відсутній.', 'wayforpay-multi-seller')) . '</p>';
        echo '<p>' . esc_html__('Діагностика відхилених історичних повідомлень: WooCommerce → Статус → Журнали, джерело wfpms-legacy. Журнал не підтверджує надходження коштів.', 'wayforpay-multi-seller') . '</p>';

        if (self::is_standalone_enabled()) {
            echo '<h2>' . esc_html__('Standalone режим', 'wayforpay-multi-seller') . '</h2>';
            echo '<p>' . esc_html__('Нові оплати працюють через профілі продавців. Legacy-перехід налаштовується окремо й не потребує вимкнення standalone режиму.', 'wayforpay-multi-seller') . '</p>';
        }
        if (!self::is_transition_enabled() && !self::owns_callback_route()) {
            echo '<h2>' . esc_html__('Самостійний запуск', 'wayforpay-multi-seller') . '</h2>';
            echo '<p>' . esc_html__('Увімкніть окремий standalone режим, щоб приймати нові оплати через профілі продавців без імпорту старого мерчанта. Налаштування не змінюють власника історичного callback: старий маршрут залишається оригінальному плагіну.', 'wayforpay-multi-seller') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wfpms_enable_standalone_mode">';
            wp_nonce_field('wfpms_enable_standalone_mode');
            echo '<p><label><input type="checkbox" name="confirm_standalone" value="1" required> '
                . esc_html__('Підтверджую запуск нових оплат через профілі продавців.', 'wayforpay-multi-seller') . '</label></p>';
            submit_button(__('Увімкнути standalone режим', 'wayforpay-multi-seller'));
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Імпорт мерчанта у профіль продавця', 'wayforpay-multi-seller') . '</h2>';
        if ($settings === null) {
            echo '<p>' . esc_html__('Оригінальні налаштування не знайдено.', 'wayforpay-multi-seller') . '</p>';
        } else {
            echo '<p>' . esc_html__('Перевірте, що цей merchantAccount належить саме обраному продавцю. Домен автоматично взято з home_url(); звірте його з кабінетом WayForPay.', 'wayforpay-multi-seller') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wfpms_import_legacy_merchant">';
            wp_nonce_field('wfpms_import_legacy_merchant');
            echo '<p><label>' . esc_html__('Продавець', 'wayforpay-multi-seller') . ' <select name="seller_id" required><option value="">' . esc_html__('Оберіть профіль', 'wayforpay-multi-seller') . '</option>';
            foreach (SellerRepository::all(false) as $seller) {
                echo '<option value="' . esc_attr((string) $seller['id']) . '">' . esc_html((string) $seller['internal_label'] . ' — ' . (string) $seller['legal_name']) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>' . esc_html__('Домен мерчанта', 'wayforpay-multi-seller') . ' <input class="regular-text" name="merchant_domain" required maxlength="191" value="' . esc_attr($domain) . '"></label></p>';
            echo '<p><label><input type="checkbox" name="confirm_recipient" value="1" required> ' . esc_html__('Підтверджую, що мерчант належить цьому продавцю, а домен звірено з WayForPay.', 'wayforpay-multi-seller') . '</label></p>';
            submit_button(__('Попередній перегляд та імпорт', 'wayforpay-multi-seller'));
            echo '</form>';
        }

        self::render_legacy_orders($settings);
        echo '<hr><h2>' . esc_html__('Перехід callback та нових оплат', 'wayforpay-multi-seller') . '</h2>';
        if (!self::owns_callback_route()) {
            echo '<p>' . esc_html__('На етапі підготовки форк прийматиме callback лише для явно мігрованих замовлень, а callback інших замовлень передаватиме оригінальному плагіну. Чинний checkout залишиться доступним; нові шлюзи форку з’являться після фінального перемикання.', 'wayforpay-multi-seller') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wfpms_claim_legacy_callback">';
            wp_nonce_field('wfpms_claim_legacy_callback');
            echo '<p><label><input type="checkbox" name="confirm_callback_owner" value="1" required> ' . esc_html__('Підтверджую, що переглянув відкриті старі замовлення та готовий передати обробку їхніх callback форку.', 'wayforpay-multi-seller') . '</label></p>';
            submit_button(__('Підготувати обробку callback форком', 'wayforpay-multi-seller'));
            echo '</form>';
        } elseif (!self::is_legacy_transition_enabled()) {
            echo '<p>' . esc_html__('Чинний шлюз продовжує обробляти замовлення без міграційного запису; callback мігрованих замовлень приймає форк. Прив’яжіть потрібні відкриті замовлення, перевірте профілі й лише тоді завершіть перемикання.', 'wayforpay-multi-seller') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wfpms_enable_legacy_transition">';
            wp_nonce_field('wfpms_enable_legacy_transition');
            echo '<p><label><input type="checkbox" name="confirm_transition" value="1" required> ' . esc_html__('Підтверджую завершення перевірки конфліктів і старих замовлень.', 'wayforpay-multi-seller') . '</label></p>';
            submit_button(__('Увімкнути оплати форку', 'wayforpay-multi-seller'));
            echo '</form>';
        } else {
            echo '<p>' . esc_html__('Перехід завершений. Оригінальний gateway не показується покупцям. Callback мігрованих замовлень обробляє форк, а інші чинні старі reference передаються оригінальному gateway. Залишайте оригінальний плагін WayForPay активним до завершення всіх старих платежів.', 'wayforpay-multi-seller') . '</p>';
        }
        if (self::owns_callback_route()) {
            echo '<hr><h2>' . esc_html__('Повернення історичного callback оригіналу', 'wayforpay-multi-seller') . '</h2>';
            echo '<p>' . esc_html__('Спершу активуйте оригінальний плагін. Повернення дозволено лише після прийняття й виконання всіх прив’язаних покупок, із відповідними історичними ключами в оригіналі. Нові шлюзи й записи форку зберігаються; режим нових оплат стає standalone.', 'wayforpay-multi-seller') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wfpms_release_legacy_callback">';
            wp_nonce_field('wfpms_release_legacy_callback');
            echo '<p><label><input type="checkbox" name="confirm_release" value="1" required> '
                . esc_html__('Підтверджую повернення callback перевіреному обробнику оригіналу.', 'wayforpay-multi-seller') . '</label></p>';
            submit_button(__('Повернути callback оригіналу', 'wayforpay-multi-seller'));
            echo '</form>';
        }
        echo '</div>';
    }

    private static function render_legacy_orders(?array $settings): void
    {
        $page = max(1, isset($_GET['wfpms_legacy_paged']) ? absint($_GET['wfpms_legacy_paged']) : 1);
        $result = wc_get_orders([
            'payment_method' => 'wayforpay',
            'status' => ['pending', 'on-hold'],
            'limit' => 100,
            'page' => $page,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'objects',
        ]);
        $orders = is_object($result) && isset($result->orders) && is_array($result->orders) ? $result->orders : [];
        $totalPages = is_object($result) ? max(1, (int) ($result->max_num_pages ?? 1)) : 1;
        $import = get_option(self::IMPORT_OPTION, []);
        $sellerId = is_array($import) ? (int) ($import['seller_id'] ?? 0) : 0;
        $seller = $sellerId > 0 ? SellerRepository::find($sellerId) : null;
        $importedConfig = $sellerId > 0 ? self::imported_config_for_seller($sellerId) : null;
        echo '<h2>' . esc_html__('Незавершені замовлення старого шлюзу', 'wayforpay-multi-seller') . '</h2>';
        echo '<p>' . esc_html__('Показано до 100 неоплачених замовлень у статусі очікування. Поточні категорії не використовуються як доказ продавця.', 'wayforpay-multi-seller') . '</p>';
        $unmappedOrders = self::unmigrated_open_order_ids();
        $unmappedCount = is_array($unmappedOrders) ? count($unmappedOrders) : null;
        if ($unmappedCount === null) {
            echo '<p class="notice notice-error inline">' . esc_html__('Не вдалося перевірити всі відкриті замовлення. Фінальне перемикання заблоковано.', 'wayforpay-multi-seller') . '</p>';
        } elseif ($unmappedCount > 0) {
            echo '<p class="notice notice-warning inline">' . esc_html(sprintf(
                _n('%d відкрите замовлення ще не прив’язане. Фінальне перемикання заблоковано.', '%d відкритих замовлень ще не прив’язані. Фінальне перемикання заблоковано.', $unmappedCount, 'wayforpay-multi-seller'),
                $unmappedCount
            )) . '</p>';
        }
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Замовлення', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Сума', 'wayforpay-multi-seller') . '</th><th>' . esc_html__('Стан прив’язки', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Продавець із тим самим мерчантом', 'wayforpay-multi-seller') . '</th></tr></thead><tbody>';
        if ($orders === []) {
            echo '<tr><td colspan="5">' . esc_html__('Немає відкритих неоплачених замовлень.', 'wayforpay-multi-seller') . '</td></tr>';
        }
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) continue;
            $migration = self::for_order((int) $order->get_id());
            echo '<tr><td>' . esc_html((string) $order->get_id()) . '</td><td>' . esc_html((string) $order->get_order_number())
                . '</td><td>' . esc_html($order->get_currency() . ' ' . $order->get_total()) . '</td><td>'
                . esc_html($migration === null ? __('Не прив’язано', 'wayforpay-multi-seller') : sprintf(__('Продавець #%d; повторний callback лише через історичний маршрут', 'wayforpay-multi-seller'), (int) $migration['seller_id']))
                . '</td><td>';
            if ($migration === null && $settings !== null && self::owns_callback_route()) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wfpms_migrate_legacy_order">'
                    . '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '">';
                wp_nonce_field('wfpms_migrate_legacy_order_' . (int) $order->get_id());
                echo '<select name="seller_id" required><option value="">' . esc_html__('Оберіть продавця', 'wayforpay-multi-seller') . '</option>';
                if ($seller !== null && $importedConfig !== null && (int) $importedConfig['card_enabled'] === 1
                    && hash_equals((string) $settings['merchant_account'], (string) $importedConfig['merchant_account'])) {
                    echo '<option value="' . esc_attr((string) $seller['id']) . '">' . esc_html((string) $seller['internal_label'] . ' — ' . (string) $seller['legal_name'] . ' (' . (string) $seller['tax_id'] . ')') . '</option>';
                }
                echo '</select> <button class="button" type="submit">' . esc_html__('Прив’язати та зафіксувати', 'wayforpay-multi-seller') . '</button></form>';
            } elseif ($migration === null && !self::owns_callback_route()) {
                echo esc_html__('Спершу передайте історичний callback форку.', 'wayforpay-multi-seller');
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if ($totalPages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                'base' => add_query_arg(['page' => 'wfpms-legacy-migration', 'wfpms_legacy_paged' => '%#%'], admin_url('admin.php')),
                'format' => '', 'current' => $page, 'total' => $totalPages,
            ])) . '</div></div>';
        }
    }

    public static function import_merchant(): void
    {
        self::authorize_post('wfpms_import_legacy_merchant');
        $settings = self::legacy_settings();
        $sellerId = isset($_POST['seller_id']) ? absint($_POST['seller_id']) : 0;
        $domain = self::normalize_domain(isset($_POST['merchant_domain']) && is_scalar($_POST['merchant_domain'])
            ? (string) wp_unslash($_POST['merchant_domain']) : '');
        if ($settings === null) self::redirect('settings_missing');
        if ($sellerId < 1 || !isset($_POST['confirm_recipient'])) self::redirect('seller_required');
        if ($domain === '') self::redirect('domain_required');
        if (!SecretStore::available()) self::redirect('import_failed');
        $seller = SellerRepository::find($sellerId);
        $config = SellerRepository::current_config($sellerId);
        if ($seller === null || $config === null || (int) $seller['active'] !== 1) self::redirect('seller_required');
        $fingerprint = hash_hmac('sha256', $settings['merchant_account'] . "\0" . $settings['secret_key'], wp_salt('auth'));
        $previous = get_option(self::IMPORT_OPTION, []);
        if (is_array($previous) && (int) ($previous['seller_id'] ?? 0) === $sellerId
            && hash_equals((string) ($previous['fingerprint'] ?? ''), $fingerprint)
            && hash_equals((string) ($previous['merchant_domain'] ?? ''), $domain)) {
            self::redirect('already_imported');
        }
        try {
            $configuration = [
                'recipient_name' => (string) $config['recipient_name'],
                'iban' => (string) $config['iban'],
                'bank_name' => (string) $config['bank_name'],
                'purpose_template' => (string) $config['purpose_template'],
                'merchant_account' => $settings['merchant_account'],
                'merchant_domain' => $domain,
                'card_enabled' => 1,
                'iban_enabled' => (int) $config['iban_enabled'],
                'operator_checked' => false,
                'secret_plaintext' => $settings['secret_key'],
            ];
            $configId = SellerRepository::save($sellerId, [
                'internal_label' => (string) $seller['internal_label'],
                'legal_name' => (string) $seller['legal_name'],
                'tax_id' => (string) $seller['tax_id'],
            ], $configuration, SecretStore::encrypt($settings['secret_key']), get_current_user_id());
            $current = SellerRepository::current_config($configId);
            if ($current === null) throw new \RuntimeException('Imported configuration was not activated.');
            update_option(self::IMPORT_OPTION, [
                'seller_id' => $sellerId,
                'config_id' => (int) $current['id'],
                'merchant_account' => $settings['merchant_account'],
                'merchant_domain' => $domain,
                'fingerprint' => $fingerprint,
                'imported_by' => get_current_user_id(),
                'imported_at' => current_time('mysql', true),
            ], false);
            self::redirect('imported');
        } catch (\Throwable $error) {
            error_log('WFPMS legacy merchant import failed: ' . sanitize_text_field($error->getMessage()));
            self::redirect('import_failed');
        }
    }

    public static function migrate_order(): void
    {
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        self::authorize_post('wfpms_migrate_legacy_order_' . $orderId);
        $sellerId = isset($_POST['seller_id']) ? absint($_POST['seller_id']) : 0;
        // Order zero is reserved for serializing operator changes to legacy route ownership.
        if (!PurchaseRepository::order_lock(0)) self::redirect('order_failed');
        $notice = 'order_failed';
        try {
            OrderPurchaseService::migrate_legacy_order($orderId, $sellerId, get_current_user_id());
            $notice = 'order_migrated';
        } catch (\Throwable $error) {
            error_log('WFPMS legacy order migration failed: ' . sanitize_text_field($error->getMessage()));
        } finally {
            PurchaseRepository::release_order_lock(0);
        }
        self::redirect($notice);
    }

    public static function claim_callback_route(): void
    {
        self::authorize_post('wfpms_claim_legacy_callback');
        $import = get_option(self::IMPORT_OPTION, []);
        $sellerId = is_array($import) ? (int) ($import['seller_id'] ?? 0) : 0;
        if (!isset($_POST['confirm_callback_owner']) || $sellerId < 1
            || self::imported_config_for_seller($sellerId) === null) {
            self::redirect('transition_blocked');
        }
        if (!self::upstream_callback_is_registered()) {
            self::redirect('callback_unavailable');
        }
        if (!PurchaseRepository::order_lock(0)) self::redirect('transition_blocked');
        try {
            update_option(self::CALLBACK_OWNER_OPTION, 'yes', false);
        } finally {
            PurchaseRepository::release_order_lock(0);
        }
        self::redirect('callback_owned');
    }

    /** Return only settled mapped purchases to upstream's native completion rules. */
    public static function release_callback_route(): void
    {
        global $wpdb;
        self::authorize_post('wfpms_release_legacy_callback');
        if (!isset($_POST['confirm_release'])) self::redirect('callback_release_blocked');
        if (!self::upstream_callback_is_registered()) self::redirect('callback_unavailable');
        if (!PurchaseRepository::order_lock(0)) self::redirect('callback_release_blocked');
        $lockedOrders = [];
        $notice = 'callback_release_blocked';
        try {
            $settings = self::legacy_settings();
            $mappings = $wpdb->get_results('SELECT * FROM ' . self::migrations_table() . ' ORDER BY order_id', ARRAY_A);
            if (!is_array($mappings) || $wpdb->last_error !== '' || $settings === null) {
                throw new \RuntimeException('Legacy configuration or mappings unavailable.');
            }
            foreach ($mappings as $mapping) {
                $orderId = (int) $mapping['order_id'];
                if (!PurchaseRepository::order_lock($orderId)) throw new \RuntimeException('Legacy order busy.');
                $lockedOrders[] = $orderId;
                $purchase = PurchaseRepository::for_order($orderId);
                $order = wc_get_order($orderId);
                $config = SellerRepository::config((int) $mapping['config_id']);
                $secret = $config ? SecretStore::decrypt((string) $config['secret_ciphertext']) : null;
                if ($purchase === null || (int) $purchase['id'] !== (int) $mapping['purchase_id']
                    || (int) $purchase['config_id'] !== (int) $mapping['config_id']
                    || (int) $purchase['seller_id'] !== (int) $mapping['seller_id']
                    || (string) $purchase['state'] !== 'paid' || (string) $purchase['execution_state'] !== 'done'
                    || !PurchaseExecutionService::is_financially_accepted($purchase)
                    || !$order instanceof \WC_Order || !$order->is_paid()
                    || $config === null || $secret === null
                    || (int) $config['seller_id'] !== (int) $mapping['seller_id']
                    || !hash_equals((string) $config['merchant_account'], $settings['merchant_account'])
                    || !hash_equals($secret, $settings['secret_key'])) {
                    throw new \RuntimeException('Unsettled mapped purchase or incompatible upstream credential.');
                }
            }
            // Preserve availability of new fork payments; never erase imports, mappings, or money records.
            if (self::is_transition_enabled()) update_option(self::STANDALONE_OPTION, 'yes', false);
            update_option(self::TRANSITION_OPTION, 'no', false);
            update_option(self::CALLBACK_OWNER_OPTION, 'no', false);
            wc_get_logger()->info('callback_owner_restored', ['source' => 'wfpms-legacy', 'actor_id' => get_current_user_id()]);
            $notice = 'callback_released';
        } catch (\Throwable $error) {
            wc_get_logger()->warning('callback_ownership_return_blocked', ['source' => 'wfpms-legacy', 'actor_id' => get_current_user_id()]);
        } finally {
            foreach (array_reverse($lockedOrders) as $orderId) PurchaseRepository::release_order_lock($orderId);
            PurchaseRepository::release_order_lock(0);
        }
        self::redirect($notice);
    }

    public static function enable_transition(): void
    {
        self::authorize_post('wfpms_enable_legacy_transition');
        if (!isset($_POST['confirm_transition'])) {
            self::redirect('transition_blocked');
        }
        if (!PurchaseRepository::order_lock(0)) self::redirect('transition_blocked');
        $notice = 'transition_blocked';
        try {
            if (!self::upstream_callback_is_registered()) {
                $notice = 'callback_unavailable';
            } elseif (self::transition_is_ready()) {
                update_option(self::TRANSITION_OPTION, 'yes', false);
                $notice = 'transition_enabled';
            } elseif (self::unmigrated_open_order_ids()) {
                $notice = 'transition_orders_unmigrated';
            }
        } finally {
            PurchaseRepository::release_order_lock(0);
        }
        self::redirect($notice);
    }

    public static function enable_standalone_mode(): void
    {
        self::authorize_post('wfpms_enable_standalone_mode');
        if (!isset($_POST['confirm_standalone']) || !self::standalone_activation_allowed()) {
            self::redirect('standalone_blocked');
        }
        update_option(self::STANDALONE_OPTION, 'yes', false);
        self::redirect('standalone_enabled');
    }

    /** Standalone starts only new fork payments and must never take an already-owned legacy route. */
    public static function standalone_activation_allowed(): bool
    {
        return !self::owns_callback_route() && !self::is_legacy_transition_enabled();
    }

    public static function admin_notice(): void
    {
        if (!current_user_can('manage_woocommerce') || (!self::legacy_gateway_is_configured() && !class_exists('WC_wayforpay'))
            || self::is_transition_enabled()) return;
        $url = add_query_arg('page', 'wfpms-legacy-migration', admin_url('admin.php'));
        $message = self::owns_callback_route()
            ? __('Перехід готується: чинний checkout працює, а нові способи форку ще вимкнені до фінального перемикання.', 'wayforpay-multi-seller')
            : __('Знайдено налаштування оригінального WayForPay. Чинний checkout працює, поки ви налаштовуєте продавців і товари.', 'wayforpay-multi-seller');
        echo '<div class="notice notice-warning"><p>'
            . esc_html($message)
            . ' <a href="' . esc_url($url) . '">' . esc_html__('Відкрити міграцію', 'wayforpay-multi-seller') . '</a></p></div>';
    }

    private static function legacy_gateway_is_configured(): bool
    {
        $settings = self::legacy_settings();
        return $settings !== null;
    }

    public static function handle_callback(): void
    {
        if (!self::owns_callback_route()) {
            return;
        }
        global $wpdb;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            status_header(405);
            exit;
        }
        $body = file_get_contents('php://input', false, null, 0, 65537);
        if (is_string($body) && strlen($body) > 65536) {
            status_header(413);
            exit;
        }
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['orderReference']) || !is_scalar($data['orderReference'])) {
            status_header(400);
            exit;
        }
        $reference = (string) $data['orderReference'];
        if (preg_match('/\A([1-9][0-9]*)(?:_woo_w4p_[1-9][0-9]*)?\z/', $reference, $matches) !== 1) {
            self::log_callback_rejection('unsupported_reference', 0, $reference);
            status_header(400);
            exit;
        }
        $orderId = (int) $matches[1];
        $mapping = self::for_order($orderId);
        if ($mapping === null) {
            // Preserve the upstream callback path for every legacy order without a fork mapping.
            if (!self::upstream_callback_is_registered()) {
                self::log_callback_rejection('unmapped_reference_no_upstream', $orderId, $reference);
                status_header(503);
                exit;
            }
            return;
        }
        $required = ['merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode', 'merchantSignature'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field])) {
                status_header(400);
                exit;
            }
        }
        foreach (['merchantAccount' => 191, 'orderReference' => 191, 'authCode' => 191, 'cardPan' => 191,
            'transactionStatus' => 64, 'reasonCode' => 191, 'merchantSignature' => 128, 'amount' => 191, 'currency' => 16] as $field => $maxLength) {
            if (strlen((string) $data[$field]) > $maxLength) {
                status_header(400);
                exit;
            }
        }
        $purchase = PurchaseRepository::for_order($orderId);
        $order = wc_get_order($orderId);
        $config = SellerRepository::config((int) $mapping['config_id']);
        $secret = $config === null ? null : SecretStore::decrypt((string) $config['secret_ciphertext']);
        if ($config === null || $secret === null) {
            self::log_callback_rejection('historical_config_unavailable', $orderId, $reference);
        }
        if ($purchase === null || !$order instanceof \WC_Order
            || $order->get_payment_method() !== 'wayforpay'
            || (int) $purchase['id'] !== (int) $mapping['purchase_id']
            || (int) $purchase['seller_id'] !== (int) $mapping['seller_id']
            || (int) $purchase['config_id'] !== (int) $mapping['config_id']
            || $config === null || $secret === null
            || !hash_equals((string) $config['merchant_account'], (string) $data['merchantAccount'])
            || !self::valid_legacy_signature($data, $secret)) {
            status_header(400);
            exit;
        }
        if (!PurchaseRepository::order_lock($orderId)) {
            status_header(503);
            exit;
        }
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                status_header(503);
                exit;
            }
            try {
                $purchase = PurchaseRepository::for_order($orderId, true);
                $mapping = self::for_order($orderId);
                if ($purchase === null || $mapping === null || (int) $purchase['id'] !== (int) $mapping['purchase_id']) {
                    throw new \RuntimeException('Legacy mapping changed during callback.');
                }
                $attempt = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . PurchaseRepository::attempts_table() . ' WHERE external_reference=%s FOR UPDATE',
                    $reference
                ), ARRAY_A);
                $snapshot = json_decode((string) $purchase['financial_snapshot'], true);
                if (!is_array($snapshot)) throw new \RuntimeException('Legacy snapshot is unavailable.');
                $now = current_time('mysql', true);
                if (!is_array($attempt)) {
                    if ($wpdb->insert(PurchaseRepository::attempts_table(), [
                        'purchase_id' => (int) $purchase['id'],
                        'channel' => 'card',
                        'merchant_account' => (string) $config['merchant_account'],
                        'external_reference' => $reference,
                        'config_id' => (int) $config['id'],
                        'credential_key_id' => '',
                        'expected_amount' => (string) $purchase['amount'],
                        'amount_scale' => (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()),
                        'currency' => (string) $purchase['currency'],
                        'payload' => '{}',
                        'status' => 'historical',
                        'issued_at' => $now,
                        'expires_at' => null,
                    ]) !== 1) throw new \RuntimeException('Could not persist historical attempt reference.');
                    $attemptId = (int) $wpdb->insert_id;
                } else {
                    if ((int) $attempt['purchase_id'] !== (int) $purchase['id'] || (int) $attempt['config_id'] !== (int) $config['id']) {
                        throw new \RuntimeException('Historical reference is mapped to a different payment.');
                    }
                    $attemptId = (int) $attempt['id'];
                }
                $fields = array_map('strval', [
                    $data['merchantAccount'], $reference, $data['amount'], $data['currency'],
                    $data['authCode'], $data['cardPan'], $data['transactionStatus'], $data['reasonCode'],
                ]);
                $eventKey = hash('sha256', implode(';', $fields));
                $existingEvent = $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . PurchaseRepository::events_table() . ' WHERE dedupe_key=%s', $eventKey
                ));
                if (!$existingEvent) {
                    $status = (string) $data['transactionStatus'];
                    $scale = (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals());
                    $amount = wc_format_decimal((string) $data['amount'], $scale, false);
                    $validAmount = is_string($amount) && preg_match('/\A\d{1,14}(?:\.\d{1,6})?\z/', $amount) === 1;
                    $receiptId = 0;
                    $classification = 'status_only';
                    $eventKind = 'legacy_' . substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $status), 0, 24);
                    if ($status === 'Approved') {
                        $classification = 'needs_review';
                        $priorReceipt = $wpdb->get_row($wpdb->prepare(
                            'SELECT * FROM ' . PurchaseRepository::receipts_table()
                            . ' WHERE source=%s AND source_scope=%s AND external_id=%s FOR UPDATE',
                            'wayforpay', (string) $config['merchant_account'], $reference
                        ), ARRAY_A);
                        if (is_array($priorReceipt)) {
                            $receiptId = (int) $priorReceipt['id'];
                            $eventKind = 'legacy_reference_conflict';
                        } elseif ($wpdb->insert(PurchaseRepository::receipts_table(), [
                                'purchase_id' => (int) $purchase['id'],
                                'attempt_id' => $attemptId,
                                'source' => 'wayforpay',
                                'source_scope' => (string) $config['merchant_account'],
                                'external_id' => $reference,
                                'provider_reference' => $reference,
                                'amount' => $validAmount ? $amount : '0',
                                'amount_raw' => substr((string) $data['amount'], 0, 191),
                                'currency' => preg_match('/\A[A-Z]{3}\z/', strtoupper((string) $data['currency'])) === 1 ? strtoupper((string) $data['currency']) : 'XXX',
                                'transaction_status' => $status,
                                'classification' => $classification,
                                'time_evidence' => 'provider_callback',
                                'recorded_at' => $now,
                            ]) !== 1) {
                            throw new \RuntimeException('Could not persist historical payment receipt.');
                        } else {
                            $receiptId = (int) $wpdb->insert_id;
                        }
                        $prior = (int) $wpdb->get_var($wpdb->prepare(
                            'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
                            (int) $purchase['id']
                        ));
                        $newState = $eventKind === 'legacy_reference_conflict' || $prior > 1 || !empty($purchase['accepted_receipt_id']) ? 'paid_review' : 'review';
                        if ($wpdb->update(PurchaseRepository::purchases_table(), [
                            'state' => $newState,
                            'updated_at' => $now,
                        ], ['id' => (int) $purchase['id']]) === false) throw new \RuntimeException('Could not flag historical payment for review.');
                        if ($wpdb->update(PurchaseRepository::attempts_table(), ['status' => 'approved'], ['id' => $attemptId]) === false) {
                            throw new \RuntimeException('Could not update historical attempt status.');
                        }
                    } else {
                        $safeStatus = preg_replace('/[^A-Za-z0-9_-]/', '', $status);
                        if ($wpdb->update(PurchaseRepository::attempts_table(), ['status' => strtolower(substr((string) $safeStatus, 0, 24))], ['id' => $attemptId]) === false) {
                            throw new \RuntimeException('Could not update historical attempt status.');
                        }
                    }
                    $evidence = wp_json_encode([
                        'transaction_status' => substr((string) $data['transactionStatus'], 0, 64),
                        'amount' => $validAmount ? $amount : '',
                        'amount_received' => substr((string) $data['amount'], 0, 64),
                        'currency' => substr(strtoupper((string) $data['currency']), 0, 3),
                        'classification' => $classification,
                        'legacy_reference' => $reference,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (!is_string($evidence) || $wpdb->insert(PurchaseRepository::events_table(), [
                        'purchase_id' => (int) $purchase['id'],
                        'attempt_id' => $attemptId,
                        'receipt_id' => $receiptId,
                        'dedupe_key' => $eventKey,
                        'kind' => $eventKind,
                        'evidence' => $evidence,
                        'received_at' => $now,
                    ]) !== 1) throw new \RuntimeException('Could not persist historical callback event.');
                    if ($receiptId > 0 && $wpdb->update(PurchaseRepository::receipts_table(), [
                        'first_event_id' => (int) $wpdb->insert_id,
                    ], ['id' => $receiptId, 'first_event_id' => 0], ['%d'], ['%d', '%d']) === false) {
                        throw new \RuntimeException('Could not link the historical receipt event.');
                    }
                }
                if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Could not commit historical callback.');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                status_header(503);
                exit;
            }
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
        $time = time();
        $ack = ['orderReference' => $reference, 'status' => 'accept', 'time' => $time];
        $ack['signature'] = hash_hmac('md5', $reference . ';accept;' . $time, $secret);
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($ack);
        exit;
    }

    private static function valid_legacy_signature(array $data, string $secret): bool
    {
        $fields = array_map('strval', [
            $data['merchantAccount'], $data['orderReference'], $data['amount'], $data['currency'],
            $data['authCode'], $data['cardPan'], $data['transactionStatus'], $data['reasonCode'],
        ]);
        return hash_equals(hash_hmac('md5', implode(';', $fields), $secret), strtolower((string) $data['merchantSignature']));
    }

    private static function log_callback_rejection(string $reason, int $orderId, string $reference): void
    {
        wc_get_logger()->warning($reason, [
            'source' => 'wfpms-legacy', 'order_id' => $orderId,
            'reference_hash' => hash('sha256', $reference),
        ]);
    }

    private static function normalize_domain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || str_contains($domain, '://') || str_contains($domain, '/') || str_contains($domain, ':')) return '';
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $domain : '';
    }

    private static function authorize_post(string $action): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage payment migration.', 'wayforpay-multi-seller'), 403);
        }
        check_admin_referer($action);
    }

    private static function redirect(string $notice): never
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'wfpms-legacy-migration',
            'wfpms_migration_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }
}
