<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Runs WooCommerce's native payment completion once, after durable acceptance. */
final class PurchaseExecutionService
{
    public static function boot(): void
    {
        add_action('init', [self::class, 'ensure_schedule']);
        add_action('wfpms_process_execution', [self::class, 'run'], 10, 1);
        add_action('wfpms_expire_purchases', [self::class, 'expire_due']);
        add_action('woocommerce_payment_complete', [self::class, 'observe_woo_payment_complete'], 10, 1);
    }

    public static function schedule(int $purchaseId): void
    {
        if ($purchaseId > 0 && !wp_next_scheduled('wfpms_process_execution', [$purchaseId])) {
            wp_schedule_single_event(time() + 1, 'wfpms_process_execution', [$purchaseId]);
        }
    }

    public static function ensure_schedule(): void
    {
        if (!wp_next_scheduled('wfpms_expire_purchases')) {
            wp_schedule_event(time() + 60, 'hourly', 'wfpms_expire_purchases');
        }
    }

    public static function observe_woo_payment_complete(int $orderId): void
    {
        global $wpdb;
        $purchase = PurchaseRepository::for_order($orderId);
        if ($purchase === null || (string) $purchase['state'] !== 'free'
            || !in_array((string) $purchase['execution_state'], ['pending', 'running'], true)) {
            return;
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            return;
        }
        $updated = $wpdb->update(PurchaseRepository::purchases_table(), [
            'execution_state' => 'done',
            'execution_updated_at' => current_time('mysql', true),
            'execution_error_code' => '',
        ], [
            'id' => (int) $purchase['id'],
            'state' => 'free',
            'execution_state' => (string) $purchase['execution_state'],
        ]);
        if ($updated !== false && self::append_event((int) $purchase['id'], 'execution_done', [
            'source' => 'woocommerce_payment_complete',
            'free_basis' => (string) $purchase['free_basis'],
        ], 'free-complete:' . (int) $purchase['id'])
            && $wpdb->query('COMMIT') !== false) {
            return;
        }
        $wpdb->query('ROLLBACK');
    }

    public static function run(int $purchaseId): void
    {
        global $wpdb;
        $purchase = self::purchase_by_id($purchaseId);
        if ($purchase === null || !in_array((string) $purchase['state'], ['paid', 'free'], true)) {
            return;
        }
        $orderId = (int) $purchase['order_id'];
        if (!PurchaseRepository::order_lock($orderId)) {
            self::mark_execution_review($purchaseId, 'order_lock_failed');
            return;
        }

        $token = wp_generate_uuid4();
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                self::mark_execution_review($purchaseId, 'database_unavailable');
                return;
            }
            $locked = PurchaseRepository::for_order($orderId, true);
            if ($locked === null || (int) $locked['id'] !== $purchaseId
                || !in_array((string) $locked['state'], ['paid', 'free'], true)
                || (string) $locked['execution_state'] !== 'pending') {
                $wpdb->query('ROLLBACK');
                return;
            }
            $claimed = $wpdb->update(PurchaseRepository::purchases_table(), [
                'execution_state' => 'running',
                'execution_token' => $token,
                'execution_started_at' => current_time('mysql', true),
                'execution_updated_at' => current_time('mysql', true),
                'execution_error_code' => '',
            ], ['id' => $purchaseId, 'execution_state' => 'pending']);
            if ($claimed !== 1 || $wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return;
            }

            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                self::finish_execution($purchaseId, $token, 'review', 'order_missing');
                return;
            }
            if ((string) $locked['state'] === 'free' && $order->is_paid()) {
                self::finish_execution($purchaseId, $token, 'done', '');
                return;
            }
            if ($order->is_paid() || !in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                self::finish_execution($purchaseId, $token, 'review', 'order_not_payable');
                return;
            }

            try {
                $order->payment_complete();
            } catch (\Throwable $error) {
                self::finish_execution($purchaseId, $token, 'review', 'payment_complete_exception');
                return;
            }
            $freshOrder = wc_get_order($orderId);
            if ($freshOrder instanceof \WC_Order && $freshOrder->is_paid()) {
                self::finish_execution($purchaseId, $token, 'done', '');
                return;
            }
            self::finish_execution($purchaseId, $token, 'review', 'payment_complete_unconfirmed');
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
    }

    public static function expire_due(): void
    {
        global $wpdb;
        $pending = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . PurchaseRepository::purchases_table()
            . ' WHERE state IN (%s,%s) AND execution_state=%s ORDER BY updated_at ASC LIMIT 100',
            'paid', 'free', 'pending'
        ));
        foreach (is_array($pending) ? $pending : [] as $pendingId) {
            self::schedule((int) $pendingId);
        }
        $stalled = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . PurchaseRepository::purchases_table()
            . ' WHERE execution_state=%s AND execution_started_at IS NOT NULL AND execution_started_at<=%s LIMIT 100',
            'running', gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 900)
        ));
        foreach (is_array($stalled) ? $stalled : [] as $stalledId) {
            self::mark_execution_review((int) $stalledId, 'execution_stalled');
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id,order_id,state FROM ' . PurchaseRepository::purchases_table()
            . ' WHERE expires_at IS NOT NULL AND expires_at<=%s AND state IN (%s,%s) ORDER BY expires_at ASC LIMIT 100',
            current_time('mysql', true), 'awaiting_payment', 'expired'
        ), ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $orderId = (int) $row['order_id'];
            if (!PurchaseRepository::order_lock($orderId)) {
                continue;
            }
            try {
                if ($wpdb->query('START TRANSACTION') === false) {
                    continue;
                }
                $purchase = PurchaseRepository::for_order($orderId, true);
                if ($purchase === null || (int) $purchase['id'] !== (int) $row['id']
                    || !in_array((string) $purchase['state'], ['awaiting_payment', 'expired'], true)
                    || empty($purchase['expires_at']) || (string) $purchase['expires_at'] > current_time('mysql', true)) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }
                if ((string) $purchase['state'] === 'awaiting_payment') {
                    if ($wpdb->update(PurchaseRepository::purchases_table(), [
                        'state' => 'expired',
                        'updated_at' => current_time('mysql', true),
                    ], ['id' => (int) $purchase['id']]) === false) {
                        $wpdb->query('ROLLBACK');
                        continue;
                    }
                    $attemptsUpdated = $wpdb->query($wpdb->prepare(
                        'UPDATE ' . PurchaseRepository::attempts_table() . ' SET status=%s WHERE purchase_id=%d AND status=%s',
                        'expired', (int) $purchase['id'], 'issued'
                    ));
                    if ($attemptsUpdated === false || !self::append_event((int) $purchase['id'], 'payment_deadline_expired', [
                        'expires_at' => (string) $purchase['expires_at'],
                    ], 'deadline:' . (int) $purchase['id'] . ':' . (string) $purchase['expires_at'])) {
                        $wpdb->query('ROLLBACK');
                        continue;
                    }
                }
                if ($wpdb->query('COMMIT') === false) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }

                $order = wc_get_order($orderId);
                if ($order instanceof \WC_Order && in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                    try {
                        $order->update_status('cancelled', __('Строк очікування оплати минув.', 'wayforpay-multi-seller'), true);
                    } catch (\Throwable $error) {
                        self::mark_execution_review((int) $purchase['id'], 'expiry_cancel_failed');
                    }
                }
            } finally {
                PurchaseRepository::release_order_lock($orderId);
            }
        }
    }

    private static function purchase_by_id(int $purchaseId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . PurchaseRepository::purchases_table() . ' WHERE id=%d', $purchaseId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function mark_execution_review(int $purchaseId, string $errorCode): void
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return;
        }
        $purchase = self::purchase_by_id($purchaseId);
        if ($purchase === null || !in_array((string) $purchase['execution_state'], ['none', 'pending', 'running'], true)) {
            $wpdb->query('ROLLBACK');
            return;
        }
        $updated = $wpdb->update(PurchaseRepository::purchases_table(), [
            'execution_state' => 'review',
            'execution_updated_at' => current_time('mysql', true),
            'execution_error_code' => sanitize_key($errorCode),
        ], ['id' => $purchaseId]);
        if ($updated !== false && self::append_event($purchaseId, 'execution_needs_review', [
            'error_code' => sanitize_key($errorCode),
            'previous_state' => (string) $purchase['execution_state'],
        ], 'execution-review:' . $purchaseId . ':' . sanitize_key($errorCode) . ':' . (string) $purchase['execution_token'])
            && $wpdb->query('COMMIT') !== false) {
            return;
        }
        $wpdb->query('ROLLBACK');
    }

    private static function finish_execution(int $purchaseId, string $token, string $state, string $errorCode): void
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return;
        }
        $updated = $wpdb->update(PurchaseRepository::purchases_table(), [
            'execution_state' => $state,
            'execution_updated_at' => current_time('mysql', true),
            'execution_error_code' => sanitize_key($errorCode),
        ], ['id' => $purchaseId, 'execution_state' => 'running', 'execution_token' => $token]);
        if ($updated === 1 && self::append_event($purchaseId, 'execution_' . $state, [
            'error_code' => sanitize_key($errorCode),
            'execution_token' => $token,
        ], 'execution:' . $purchaseId . ':' . $token . ':' . $state)
            && $wpdb->query('COMMIT') !== false) {
            return;
        }
        $wpdb->query('ROLLBACK');
    }

    private static function append_event(int $purchaseId, string $kind, array $evidence, string $identity): bool
    {
        global $wpdb;
        $json = wp_json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $dedupe = hash('sha256', $identity);
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . PurchaseRepository::events_table() . ' WHERE dedupe_key=%s', $dedupe
        ));
        if ($existing) {
            return true;
        }
        $receiptId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT accepted_receipt_id FROM ' . PurchaseRepository::purchases_table() . ' WHERE id=%d', $purchaseId
        ));
        return $wpdb->insert(PurchaseRepository::events_table(), [
            'purchase_id' => $purchaseId,
            'attempt_id' => 0,
            'receipt_id' => $receiptId,
            'dedupe_key' => $dedupe,
            'kind' => $kind,
            'actor_id' => null,
            'reason' => '',
            'related_event_id' => 0,
            'evidence' => $json,
            'received_at' => current_time('mysql', true),
        ]) === 1;
    }
}
