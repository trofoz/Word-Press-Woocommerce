<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Operator controls for bank receipts, payment deadlines, and review decisions. */
final class PaymentOperations
{
    private const CAPABILITY = 'manage_woocommerce';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_post_wfpms_save_payment_policy', [self::class, 'save_policy']);
        add_action('admin_post_wfpms_confirm_bank_receipt', [self::class, 'confirm_bank_receipt']);
        add_action('admin_post_wfpms_review_receipt', [self::class, 'review_receipt']);
        add_action('admin_post_wfpms_acknowledge_execution', [self::class, 'acknowledge_execution']);
    }

    public static function zero_total_enabled(): bool
    {
        return get_option('wfpms_allow_zero_total', 'no') === 'yes';
    }

    public static function timeout_seconds(string $channel): int
    {
        $key = $channel === 'iban' ? 'wfpms_iban_timeout_hours' : 'wfpms_card_timeout_hours';
        $default = $channel === 'iban' ? 72 : 24;
        $hours = absint(get_option($key, $default));
        return max(1, min(720, $hours)) * HOUR_IN_SECONDS;
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'woocommerce', __('Платежі та розгляд', 'wayforpay-multi-seller'),
            __('Платежі продавців', 'wayforpay-multi-seller'), self::CAPABILITY,
            'wfpms-payments', [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        self::authorize_page();
        $notice = isset($_GET['wfpms_payment_notice']) && is_scalar($_GET['wfpms_payment_notice'])
            ? sanitize_key(wp_unslash((string) $_GET['wfpms_payment_notice'])) : '';
        $messages = [
            'saved' => __('Політику платежів збережено.', 'wayforpay-multi-seller'),
            'receipt_saved' => __('Банківське надходження записано.', 'wayforpay-multi-seller'),
            'duplicate_receipt' => __('Це банківське надходження вже зареєстровано.', 'wayforpay-multi-seller'),
            'receipt_reviewed' => __('Надходження прийнято після перевірки; передано до штатного виконання WooCommerce.', 'wayforpay-multi-seller'),
            'execution_reviewed' => __('Стан позначено перевіреним. Автоматичного повтору виконання не було.', 'wayforpay-multi-seller'),
            'invalid_data' => __('Дані платежу неповні або некоректні.', 'wayforpay-multi-seller'),
            'operation_conflict' => __('Цей банківський ID уже прив’язано до іншого запису або його дані суперечать наявним.', 'wayforpay-multi-seller'),
            'order_unavailable' => __('Замовлення не має чинного знімка продавця або платіж не можна зарахувати автоматично.', 'wayforpay-multi-seller'),
            'save_failed' => __('Не вдалося зберегти платіжну подію. Спробуйте ще раз.', 'wayforpay-multi-seller'),
        ];
        if (isset($messages[$notice])) {
            echo '<div class="notice ' . esc_attr(in_array($notice, ['saved', 'receipt_saved', 'receipt_reviewed', 'execution_reviewed', 'duplicate_receipt'], true) ? 'notice-success' : 'notice-error') . '"><p>'
                . esc_html($messages[$notice]) . '</p></div>';
        }
        echo '<div class="wrap"><h1>' . esc_html__('Платежі та ручний розгляд', 'wayforpay-multi-seller') . '</h1>';
        self::render_policy_form();
        self::render_receipt_form();
        self::render_review_queue();
        echo '</div>';
    }

    public static function save_policy(): void
    {
        self::authorize_post('wfpms_save_payment_policy');
        $cardHours = self::post_int('card_timeout_hours');
        $ibanHours = self::post_int('iban_timeout_hours');
        if ($cardHours < 1 || $cardHours > 720 || $ibanHours < 1 || $ibanHours > 720) {
            self::redirect('invalid_data');
        }
        update_option('wfpms_card_timeout_hours', $cardHours, false);
        update_option('wfpms_iban_timeout_hours', $ibanHours, false);
        update_option('wfpms_allow_zero_total', isset($_POST['allow_zero_total']) ? 'yes' : 'no', false);
        self::redirect('saved');
    }

    public static function confirm_bank_receipt(): void
    {
        global $wpdb;
        self::authorize_post('wfpms_confirm_bank_receipt');
        $orderId = self::post_int('order_id');
        $operationId = self::post_opaque('operation_id', 191);
        $recipient = self::normalize_account(self::post_text('recipient_account', 191));
        $rawAmount = self::post_text('amount', 64);
        $currency = strtoupper(self::post_text('currency', 3));
        $occurredAt = self::post_datetime_utc('occurred_at');
        if ($orderId < 1 || $operationId === '' || $recipient === '' || $rawAmount === ''
            || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1 || $occurredAt === null) {
            self::redirect('invalid_data');
        }
        if (!PurchaseRepository::order_lock($orderId)) {
            self::redirect('save_failed');
        }
        $executePurchaseId = 0;
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                self::redirect('save_failed');
            }
            try {
                $purchase = PurchaseRepository::for_order($orderId, true);
                $order = wc_get_order($orderId);
                $snapshot = $purchase ? json_decode((string) $purchase['financial_snapshot'], true) : null;
                if ($purchase === null || !$order instanceof \WC_Order || !is_array($snapshot)) {
                    throw new \RuntimeException('order_unavailable');
                }
                $scale = (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals());
                $amount = wc_format_decimal($rawAmount, $scale, false);
                if (!is_string($amount) || preg_match('/\A\d{1,14}(?:\.\d{1,6})?\z/', $amount) !== 1
                    || preg_match('/\A0+(?:\.0+)?\z/', $amount) === 1) {
                    throw new \RuntimeException('invalid_data');
                }
                $existing = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . PurchaseRepository::receipts_table() . ' WHERE source=%s AND source_scope=%s AND external_id=%s FOR UPDATE',
                    'iban', $recipient, $operationId
                ), ARRAY_A);
                if (is_array($existing)) {
                    $same = (string) $existing['amount_raw'] === $rawAmount
                        && strtoupper((string) $existing['currency']) === $currency
                        && (string) $existing['occurred_at'] === $occurredAt;
                    if ((int) $existing['purchase_id'] !== (int) $purchase['id'] || !$same) {
                        $kind = $same ? 'bank_operation_reused' : 'bank_identity_conflict';
                        self::insert_event((int) $purchase['id'], 0, 0, $kind, [
                            'operation_id' => $operationId,
                            'account' => $recipient,
                            'existing_purchase_id' => (int) $existing['purchase_id'],
                            'existing_receipt_id' => (int) $existing['id'],
                        ], $same ? 'Банківську операцію вже зараховано до іншого замовлення.'
                            : 'Банківський ID уже існує з іншими даними.', get_current_user_id(), (int) $existing['first_event_id']);
                        $wpdb->update(PurchaseRepository::purchases_table(), [
                            'state' => in_array((string) $purchase['state'], ['paid', 'paid_review'], true) ? 'paid_review' : 'review',
                            'updated_at' => current_time('mysql', true),
                        ], ['id' => (int) $purchase['id']]);
                        if ($wpdb->query('COMMIT') === false) {
                            throw new \RuntimeException('save_failed');
                        }
                        self::redirect('operation_conflict');
                    }
                    $wpdb->query('ROLLBACK');
                    self::redirect('duplicate_receipt');
                }

                $expectedAmount = wc_format_decimal((string) $purchase['amount'], $scale, false);
                $expectedAccount = self::normalize_account((string) ($snapshot['iban'] ?? ''));
                $now = current_time('mysql', true);
                $orderOpen = in_array($order->get_status(), ['pending', 'on-hold'], true);
                $timely = !empty($purchase['expires_at'])
                    && $occurredAt <= (string) $purchase['expires_at']
                    && $now <= (string) $purchase['expires_at'];
                $prior = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
                    (int) $purchase['id']
                ));
                $accepted = hash_equals((string) $expectedAmount, $amount)
                    && $currency === (string) $purchase['currency']
                    && $recipient === $expectedAccount
                    && $orderOpen && $timely && $prior === 0
                    && (string) $purchase['state'] === 'awaiting_payment';
                $classification = $accepted ? 'accepted' : 'needs_review';
                $attemptId = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . PurchaseRepository::attempts_table() . ' WHERE purchase_id=%d AND channel=%s ORDER BY id DESC LIMIT 1',
                    (int) $purchase['id'], 'iban'
                ));
                $inserted = $wpdb->insert(PurchaseRepository::receipts_table(), [
                    'purchase_id' => (int) $purchase['id'],
                    'attempt_id' => $attemptId,
                    'source' => 'iban',
                    'source_scope' => $recipient,
                    'external_id' => $operationId,
                    'amount' => $amount,
                    'amount_raw' => $rawAmount,
                    'currency' => $currency,
                    'transaction_status' => 'OperatorConfirmed',
                    'classification' => $classification,
                    'actual_recipient' => $recipient,
                    'occurred_at' => $occurredAt,
                    'time_evidence' => 'operator',
                    'recorded_by' => get_current_user_id(),
                    'first_event_id' => 0,
                    'recorded_at' => $now,
                ]);
                if ($inserted !== 1) {
                    throw new \RuntimeException('save_failed');
                }
                $receiptId = (int) $wpdb->insert_id;
                self::insert_event((int) $purchase['id'], $attemptId, $receiptId, 'bank_receipt_confirmed', [
                    'amount' => $amount,
                    'currency' => $currency,
                    'recipient_account' => $recipient,
                    'occurred_at' => $occurredAt,
                    'classification' => $classification,
                ], 'Оператор підтвердив надходження за банківськими даними.', get_current_user_id());
                $state = $accepted ? 'paid' : (in_array((string) $purchase['state'], ['paid', 'paid_review'], true) ? 'paid_review' : 'review');
                $purchaseUpdate = [
                    'state' => $state,
                    'updated_at' => $now,
                ];
                if ($accepted) {
                    $purchaseUpdate['accepted_receipt_id'] = $receiptId;
                    $purchaseUpdate['execution_state'] = 'pending';
                    $purchaseUpdate['execution_updated_at'] = $now;
                    $executePurchaseId = (int) $purchase['id'];
                }
                if ($wpdb->update(PurchaseRepository::purchases_table(), $purchaseUpdate, ['id' => (int) $purchase['id']]) === false) {
                    throw new \RuntimeException('save_failed');
                }
                if ($attemptId > 0 && $wpdb->update(PurchaseRepository::attempts_table(), ['status' => 'confirmed'], ['id' => $attemptId]) === false) {
                    throw new \RuntimeException('save_failed');
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('save_failed');
                }
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                if ($error->getMessage() === 'order_unavailable') {
                    self::redirect('order_unavailable');
                }
                if ($error->getMessage() === 'invalid_data') {
                    self::redirect('invalid_data');
                }
                self::redirect('save_failed');
            }
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
        if ($executePurchaseId > 0) {
            PurchaseExecutionService::schedule($executePurchaseId);
        }
        self::redirect('receipt_saved');
    }

    public static function review_receipt(): void
    {
        global $wpdb;
        self::authorize_post('wfpms_review_receipt');
        $receiptId = self::post_int('receipt_id');
        $reason = self::post_text('reason', 500);
        if ($receiptId < 1 || $reason === '' || !isset($_POST['stock_reviewed'])) {
            self::redirect('invalid_data');
        }
        $receipt = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . PurchaseRepository::receipts_table() . ' WHERE id=%d', $receiptId
        ), ARRAY_A);
        if (!is_array($receipt)) {
            self::redirect('order_unavailable');
        }
        $purchase = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . PurchaseRepository::purchases_table() . ' WHERE id=%d', (int) $receipt['purchase_id']
        ), ARRAY_A);
        if (!is_array($purchase) || !PurchaseRepository::order_lock((int) $purchase['order_id'])) {
            self::redirect('save_failed');
        }
        $executePurchaseId = 0;
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                self::redirect('save_failed');
            }
            $locked = PurchaseRepository::for_order((int) $purchase['order_id'], true);
            $receipt = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . PurchaseRepository::receipts_table() . ' WHERE id=%d FOR UPDATE', $receiptId
            ), ARRAY_A);
            $order = wc_get_order((int) $purchase['order_id']);
            $snapshot = $locked ? json_decode((string) $locked['financial_snapshot'], true) : null;
            if ($locked === null || !is_array($receipt) || !$order instanceof \WC_Order || !is_array($snapshot)
                || (int) $receipt['purchase_id'] !== (int) $locked['id']
                || (string) $receipt['classification'] !== 'needs_review'
                || !in_array((string) $receipt['source'], ['iban', 'wayforpay'], true)
                || !in_array((string) $locked['state'], ['review', 'paid_review'], true)
                || !empty($locked['accepted_receipt_id'])
                || !in_array((string) $locked['execution_state'], ['none', 'pending'], true)
                || !in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                $wpdb->query('ROLLBACK');
                self::redirect('order_unavailable');
            }
            $scale = (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals());
            if (!self::receipt_matches_snapshot($receipt, $locked, $snapshot, $scale)) {
                $wpdb->query('ROLLBACK');
                self::redirect('order_unavailable');
            }
            $now = current_time('mysql', true);
            if ($wpdb->update(PurchaseRepository::receipts_table(), ['classification' => 'accepted'], ['id' => $receiptId]) === false
                || $wpdb->update(PurchaseRepository::purchases_table(), [
                    'state' => 'paid',
                    'accepted_receipt_id' => $receiptId,
                    'execution_state' => 'pending',
                    'execution_updated_at' => $now,
                    'updated_at' => $now,
                ], ['id' => (int) $locked['id']]) === false) {
                throw new \RuntimeException('save_failed');
            }
            self::insert_event((int) $locked['id'], (int) $receipt['attempt_id'], $receiptId, 'operator_accepted_receipt', [
                'receipt_id' => $receiptId,
                'stock_reviewed' => true,
            ], $reason, get_current_user_id());
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('save_failed');
            }
            $executePurchaseId = (int) $locked['id'];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            self::redirect('save_failed');
        } finally {
            PurchaseRepository::release_order_lock((int) $purchase['order_id']);
        }
        if ($executePurchaseId > 0) {
            PurchaseExecutionService::schedule($executePurchaseId);
        }
        self::redirect('receipt_reviewed');
    }

    public static function acknowledge_execution(): void
    {
        global $wpdb;
        self::authorize_post('wfpms_acknowledge_execution');
        $purchaseId = self::post_int('purchase_id');
        $reason = self::post_text('reason', 500);
        $purchase = $purchaseId > 0 ? self::purchase_by_id($purchaseId) : null;
        if ($purchase === null || $reason === '' || !PurchaseRepository::order_lock((int) $purchase['order_id'])) {
            self::redirect('invalid_data');
        }
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                self::redirect('save_failed');
            }
            $locked = PurchaseRepository::for_order((int) $purchase['order_id'], true);
            if ($locked === null || (int) $locked['id'] !== $purchaseId || (string) $locked['execution_state'] !== 'review') {
                $wpdb->query('ROLLBACK');
                self::redirect('order_unavailable');
            }
            self::insert_event($purchaseId, 0, (int) $locked['accepted_receipt_id'], 'execution_manually_reviewed', [
                'execution_error_code' => (string) $locked['execution_error_code'],
                'execution_state' => (string) $locked['execution_state'],
            ], $reason, get_current_user_id());
            $updated = $wpdb->update(PurchaseRepository::purchases_table(), [
                'execution_state' => 'reviewed',
                'execution_updated_at' => current_time('mysql', true),
            ], ['id' => $purchaseId, 'execution_state' => 'review']);
            if ($updated !== 1 || $wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('save_failed');
            }
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            self::redirect('save_failed');
        } finally {
            PurchaseRepository::release_order_lock((int) $purchase['order_id']);
        }
        self::redirect('execution_reviewed');
    }

    private static function render_policy_form(): void
    {
        echo '<h2>' . esc_html__('Політика оплати', 'wayforpay-multi-seller') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wfpms_save_payment_policy">';
        wp_nonce_field('wfpms_save_payment_policy');
        echo '<p><label>' . esc_html__('Строк карткової оплати, годин', 'wayforpay-multi-seller') . ' <input type="number" min="1" max="720" required name="card_timeout_hours" value="' . esc_attr((string) absint(get_option('wfpms_card_timeout_hours', 24))) . '"></label></p>';
        echo '<p><label>' . esc_html__('Строк переказу на IBAN, годин', 'wayforpay-multi-seller') . ' <input type="number" min="1" max="720" required name="iban_timeout_hours" value="' . esc_attr((string) absint(get_option('wfpms_iban_timeout_hours', 72))) . '"></label></p>';
        echo '<p><label><input type="checkbox" name="allow_zero_total" value="1"' . checked(self::zero_total_enabled(), true, false) . '> ' . esc_html__('Дозволити безоплатні замовлення за серверним знімком WooCommerce', 'wayforpay-multi-seller') . '</label></p>';
        submit_button(__('Зберегти політику', 'wayforpay-multi-seller'));
        echo '</form>';
    }

    private static function render_receipt_form(): void
    {
        echo '<hr><h2>' . esc_html__('Підтвердити банківське надходження', 'wayforpay-multi-seller') . '</h2>';
        echo '<p>' . esc_html__('Вводьте суму й час фактичної банківської операції. Розбіжні та пізні надходження збережуться для ручного розгляду.', 'wayforpay-multi-seller') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wfpms_confirm_bank_receipt">';
        wp_nonce_field('wfpms_confirm_bank_receipt');
        $fields = [
            'order_id' => ['Замовлення ID', 'number'], 'operation_id' => ['ID банківської операції', 'text'],
            'recipient_account' => ['Фактичний рахунок отримувача', 'text'], 'amount' => ['Фактична сума', 'text'],
            'currency' => ['Валюта', 'text'], 'occurred_at' => ['Час надходження', 'datetime-local'],
        ];
        foreach ($fields as $name => [$label, $type]) {
            echo '<p><label>' . esc_html__($label, 'wayforpay-multi-seller') . ' <input required type="' . esc_attr($type) . '" name="' . esc_attr($name) . '"'
                . ($type === 'number' ? ' min="1" step="1"' : '') . '></label></p>';
        }
        submit_button(__('Зафіксувати надходження', 'wayforpay-multi-seller'));
        echo '</form>';
    }

    private static function render_review_queue(): void
    {
        $rows = PurchaseRepository::review_queue();
        echo '<hr><h2>' . esc_html__('Покупки для ручного розгляду', 'wayforpay-multi-seller') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Замовлення', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Продавець', 'wayforpay-multi-seller') . '</th><th>' . esc_html__('Стан оплати', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Стан виконання', 'wayforpay-multi-seller') . '</th><th>' . esc_html__('Суми та надходження', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Дія', 'wayforpay-multi-seller') . '</th></tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="6">' . esc_html__('Покупок для розгляду немає.', 'wayforpay-multi-seller') . '</td></tr>';
        }
        foreach ($rows as $purchase) {
            $order = wc_get_order((int) $purchase['order_id']);
            $receipts = PurchaseRepository::receipts_for_purchase((int) $purchase['id']);
            $details = [];
            foreach ($receipts as $receipt) {
                $details[] = sprintf('%s %s · %s · %s', $receipt['amount_raw'], $receipt['currency'], $receipt['classification'], $receipt['source']);
            }
            $orderUrl = $order instanceof \WC_Order ? $order->get_edit_order_url() : '';
            echo '<tr><td>' . ($orderUrl !== '' ? '<a href="' . esc_url($orderUrl) . '">#' . esc_html((string) $purchase['order_id']) . '</a>' : '#' . esc_html((string) $purchase['order_id']))
                . '</td><td>' . esc_html((string) $purchase['internal_label']) . '</td><td>' . esc_html((string) $purchase['state'])
                . '</td><td>' . esc_html((string) $purchase['execution_state'] . ' / ' . (string) $purchase['execution_error_code'])
                . '</td><td>' . esc_html(implode('; ', $details)) . '</td><td>';
            $eligibleReceipt = self::eligible_review_receipt($purchase, $receipts, $order);
            if ($eligibleReceipt > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="wfpms_review_receipt"><input type="hidden" name="receipt_id" value="' . esc_attr((string) $eligibleReceipt) . '">';
                wp_nonce_field('wfpms_review_receipt');
                echo '<label><input required type="checkbox" name="stock_reviewed" value="1"> ' . esc_html__('Перевірив актуальність і можливість виконання', 'wayforpay-multi-seller') . '</label>'
                    . '<input required type="text" name="reason" maxlength="500" placeholder="' . esc_attr__('Підстава рішення', 'wayforpay-multi-seller') . '">'
                    . '<button class="button button-primary" type="submit">' . esc_html__('Прийняти до виконання', 'wayforpay-multi-seller') . '</button></form>';
            } elseif ((string) $purchase['execution_state'] === 'review') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="wfpms_acknowledge_execution"><input type="hidden" name="purchase_id" value="' . esc_attr((string) $purchase['id']) . '">';
                wp_nonce_field('wfpms_acknowledge_execution');
                echo '<input required type="text" name="reason" maxlength="500" placeholder="' . esc_attr__('Результат ручної перевірки', 'wayforpay-multi-seller') . '">'
                    . '<button class="button" type="submit">' . esc_html__('Позначити перевіреним без повтору', 'wayforpay-multi-seller') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param list<array<string,mixed>> $receipts */
    private static function eligible_review_receipt(array $purchase, array $receipts, $order): int
    {
        if (!$order instanceof \WC_Order || !in_array($order->get_status(), ['pending', 'on-hold'], true)
            || !in_array((string) $purchase['state'], ['review', 'paid_review'], true)
            || !in_array((string) $purchase['execution_state'], ['none', 'pending'], true)
            || !empty($purchase['accepted_receipt_id'])) {
            return 0;
        }
        foreach ($receipts as $receipt) {
            $snapshot = json_decode((string) $purchase['financial_snapshot'], true);
            $scale = is_array($snapshot) ? (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()) : wc_get_price_decimals();
            if ((string) $receipt['classification'] === 'needs_review'
                && is_array($snapshot)
                && self::receipt_matches_snapshot($receipt, $purchase, $snapshot, $scale)) {
                return (int) $receipt['id'];
            }
        }
        return 0;
    }

    private static function receipt_matches_snapshot(array $receipt, array $purchase, array $snapshot, int $scale): bool
    {
        if (!hash_equals(wc_format_decimal((string) $purchase['amount'], $scale, false), wc_format_decimal((string) $receipt['amount'], $scale, false))
            || (string) $receipt['currency'] !== (string) $purchase['currency']) {
            return false;
        }
        if ((string) $receipt['source'] === 'iban') {
            return hash_equals(
                self::normalize_account((string) ($snapshot['iban'] ?? '')),
                self::normalize_account((string) $receipt['actual_recipient'])
            );
        }
        if ((string) $receipt['source'] !== 'wayforpay'
            || (string) $receipt['transaction_status'] !== 'Approved'
            || !hash_equals((string) ($snapshot['merchant_account'] ?? ''), (string) $receipt['source_scope'])) {
            return false;
        }
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare(
            'SELECT channel,merchant_account FROM ' . PurchaseRepository::attempts_table() . ' WHERE id=%d AND purchase_id=%d',
            (int) $receipt['attempt_id'],
            (int) $purchase['id']
        ), ARRAY_A);
        return is_array($attempt) && (string) $attempt['channel'] === 'card'
            && hash_equals((string) ($snapshot['merchant_account'] ?? ''), (string) $attempt['merchant_account']);
    }

    private static function authorize_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Недостатньо прав для керування платежами.', 'wayforpay-multi-seller'), '', ['response' => 403]);
        }
    }

    private static function authorize_post(string $nonce): void
    {
        self::authorize_page();
        check_admin_referer($nonce);
    }

    private static function post_int(string $key): int
    {
        return isset($_POST[$key]) && is_scalar($_POST[$key]) ? absint(wp_unslash((string) $_POST[$key])) : 0;
    }

    private static function post_text(string $key, int $length): string
    {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return '';
        }
        $value = trim(sanitize_text_field(wp_unslash($_POST[$key])));
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function post_opaque(string $key, int $length): string
    {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return '';
        }
        $raw = trim(wp_unslash($_POST[$key]));
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1
            || (function_exists('mb_strlen') ? mb_strlen($raw) : strlen($raw)) > $length) {
            return '';
        }
        return $raw;
    }

    private static function post_datetime_utc(string $key): ?string
    {
        $raw = self::post_text($key, 32);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, wp_timezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function normalize_account(string $account): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($account)));
    }

    private static function purchase_by_id(int $purchaseId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . PurchaseRepository::purchases_table() . ' WHERE id=%d', $purchaseId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function insert_event(int $purchaseId, int $attemptId, int $receiptId, string $kind, array $evidence, string $reason, int $actorId, int $relatedEventId = 0): int
    {
        global $wpdb;
        $json = wp_json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not encode payment event.');
        }
        $dedupe = hash('sha256', serialize([$purchaseId, $attemptId, $receiptId, $kind, $actorId, $json, $reason]));
        $existingEvent = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . PurchaseRepository::events_table() . ' WHERE dedupe_key=%s',
            $dedupe
        ));
        if ($existingEvent) {
            return (int) $existingEvent;
        }
        if ($wpdb->insert(PurchaseRepository::events_table(), [
            'purchase_id' => $purchaseId,
            'attempt_id' => $attemptId,
            'receipt_id' => $receiptId,
            'dedupe_key' => $dedupe,
            'kind' => $kind,
            'actor_id' => $actorId,
            'reason' => $reason,
            'related_event_id' => $relatedEventId,
            'evidence' => $json,
            'received_at' => current_time('mysql', true),
        ]) !== 1) {
            throw new \RuntimeException('Could not persist payment event.');
        }
        $eventId = (int) $wpdb->insert_id;
        if ($receiptId > 0) {
            $wpdb->update(PurchaseRepository::receipts_table(), ['first_event_id' => $eventId], ['id' => $receiptId, 'first_event_id' => 0]);
        }
        return $eventId;
    }

    private static function redirect(string $notice): never
    {
        wp_safe_redirect(add_query_arg(['page' => 'wfpms-payments', 'wfpms_payment_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
