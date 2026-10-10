<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Durable purchase, attempt, receipt and signed-event storage. */
final class PurchaseRepository
{
    public static function purchases_table(): string { global $wpdb; return $wpdb->prefix . 'wfpms_purchases'; }
    public static function attempts_table(): string { global $wpdb; return $wpdb->prefix . 'wfpms_attempts'; }
    public static function receipts_table(): string { global $wpdb; return $wpdb->prefix . 'wfpms_receipts'; }
    public static function events_table(): string { global $wpdb; return $wpdb->prefix . 'wfpms_events'; }

    public static function for_order(int $orderId, bool $forUpdate = false): ?array
    {
        global $wpdb;
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::purchases_table() . ' WHERE order_id=%d' . $suffix,
            $orderId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function attempt_by_reference(string $reference): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT a.*,p.order_id,p.seller_id,p.state AS purchase_state,p.locked_at,p.snapshot_hash '
            . 'FROM ' . self::attempts_table() . ' a INNER JOIN ' . self::purchases_table()
            . ' p ON p.id=a.purchase_id WHERE a.external_reference=%s',
            $reference
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public static function attempts_for_purchase(int $purchaseId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::attempts_table() . ' WHERE purchase_id=%d ORDER BY id DESC',
            $purchaseId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function active_attempt_channel(int $purchaseId): ?string
    {
        global $wpdb;
        $channel = $wpdb->get_var($wpdb->prepare(
            'SELECT channel FROM ' . self::attempts_table() . ' WHERE purchase_id=%d AND status=%s ORDER BY id DESC LIMIT 1',
            $purchaseId,
            'issued'
        ));
        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    /** @return list<array<string,mixed>> */
    public static function receipts_for_purchase(int $purchaseId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::receipts_table() . ' WHERE purchase_id=%d ORDER BY id DESC',
            $purchaseId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    public static function review_queue(int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT p.*,s.internal_label FROM ' . self::purchases_table() . ' p '
            . 'LEFT JOIN ' . SellerRepository::sellers_table() . ' s ON s.id=p.seller_id '
            . "WHERE p.state IN ('review','paid_review') OR p.execution_state='review' "
            . 'ORDER BY p.updated_at ASC LIMIT ' . $limit;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function mark_free(int $purchaseId, string $basis): bool
    {
        global $wpdb;
        return $wpdb->update(self::purchases_table(), [
            'state' => 'free',
            'locked_at' => current_time('mysql', true),
            'free_basis' => $basis,
            'execution_state' => 'pending',
            'execution_updated_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $purchaseId, 'state' => 'ready']) !== false;
    }

    public static function order_lock(int $orderId): bool
    {
        global $wpdb;
        $key = 'wfpms_' . substr(hash('sha256', get_current_blog_id() . ':' . $orderId), 0, 48);
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $key)) === 1;
    }

    public static function release_order_lock(int $orderId): void
    {
        global $wpdb;
        $key = 'wfpms_' . substr(hash('sha256', get_current_blog_id() . ':' . $orderId), 0, 48);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }

    /** Caller holds the order advisory lock. @param array<string,mixed> $snapshot */
    public static function finalize(int $orderId, int $sellerId, int $configId, array $snapshot): array
    {
        global $wpdb;
        $json = wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not encode the purchase snapshot.');
        }
        $hash = hash('sha256', $json);
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Could not start purchase transaction.');
        }
        try {
            $purchase = self::for_order($orderId, true);
            $now = current_time('mysql', true);
            $seller = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . SellerRepository::sellers_table() . ' WHERE id=%d FOR UPDATE',
                $sellerId
            ), ARRAY_A);
            $config = $wpdb->get_row($wpdb->prepare(
                'SELECT id,seller_id,recipient_name,tax_id,iban,bank_name,merchant_account,merchant_domain '
                . 'FROM ' . SellerRepository::configs_table() . ' WHERE id=%d FOR UPDATE',
                $configId
            ), ARRAY_A);
            if (!is_array($seller) || !is_array($config) || (int) $config['seller_id'] !== $sellerId
                || (string) $config['recipient_name'] !== (string) $snapshot['legal_name']
                || (string) $config['tax_id'] !== (string) $snapshot['tax_id']
                || (string) $config['iban'] !== (string) $snapshot['iban']
                || (string) $config['bank_name'] !== (string) $snapshot['bank_name']
                || (string) $config['merchant_account'] !== (string) $snapshot['merchant_account']
                || (string) $config['merchant_domain'] !== (string) $snapshot['merchant_domain']) {
                throw new \RuntimeException('Seller or payment configuration changed during purchase finalization.');
            }
            if ($purchase === null) {
                if ((int) $seller['active'] !== 1 || (int) $seller['current_config_id'] !== $configId
                    || (string) $seller['internal_label'] !== (string) $snapshot['seller_label']) {
                    throw new \RuntimeException('The seller configuration changed during checkout. Please retry.');
                }
                $ok = $wpdb->insert(self::purchases_table(), [
                    'order_id' => $orderId,
                    'seller_id' => $sellerId,
                    'config_id' => $configId,
                    'financial_snapshot' => $json,
                    'snapshot_hash' => $hash,
                    'amount' => $snapshot['amount'],
                    'currency' => $snapshot['currency'],
                    'state' => 'ready',
                    'locked_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($ok !== 1) {
                    throw new \RuntimeException('Could not create purchase record.');
                }
                $marked = $wpdb->query($wpdb->prepare(
                    'UPDATE ' . SellerRepository::sellers_table() . ' SET used_at=COALESCE(used_at,%s) WHERE id=%d',
                    $now,
                    $sellerId
                ));
                if ($marked === false) {
                    throw new \RuntimeException('Could not mark seller profile as used.');
                }
                $purchase = self::for_order($orderId, true);
            } else {
                if ((int) $purchase['seller_id'] !== $sellerId) {
                    throw new \RuntimeException('The seller for this order is already fixed.');
                }
                if (!empty($purchase['locked_at'])) {
                    if (!hash_equals((string) $purchase['snapshot_hash'], $hash)) {
                        throw new \RuntimeException('Financial terms are locked after payment begins.');
                    }
                } else {
                    if ((int) $purchase['config_id'] !== $configId) {
                        // The recipient stays pinned once the order has been finalized.
                        $configId = (int) $purchase['config_id'];
                    }
                    $updated = $wpdb->update(self::purchases_table(), [
                        'config_id' => $configId,
                        'financial_snapshot' => $json,
                        'snapshot_hash' => $hash,
                        'amount' => $snapshot['amount'],
                        'currency' => $snapshot['currency'],
                        'updated_at' => $now,
                    ], ['id' => (int) $purchase['id']]);
                    if ($updated === false) {
                        throw new \RuntimeException('Could not refresh purchase snapshot.');
                    }
                    $purchase = self::for_order($orderId, true);
                }
            }
            if ($wpdb->query('COMMIT') === false || $purchase === null) {
                throw new \RuntimeException('Could not commit purchase snapshot.');
            }
            return $purchase;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /** Caller holds order lock and transaction. @param array<string,mixed> $attempt */
    public static function insert_attempt(array $attempt): int
    {
        global $wpdb;
        $inserted = $wpdb->insert(self::attempts_table(), $attempt);
        if ($inserted !== 1) {
            throw new \RuntimeException('Could not save payment attempt.');
        }
        return (int) $wpdb->insert_id;
    }

    /** Returns an existing receipt ID on duplicate, otherwise the new ID. @param array<string,mixed> $receipt */
    public static function insert_receipt(array $receipt): int
    {
        global $wpdb;
        $inserted = $wpdb->insert(self::receipts_table(), $receipt);
        if ($inserted === 1) {
            return (int) $wpdb->insert_id;
        }
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::receipts_table() . ' WHERE source=%s AND source_scope=%s AND external_id=%s',
            $receipt['source'],
            $receipt['source_scope'],
            $receipt['external_id']
        ));
        if ($existing) {
            return (int) $existing;
        }
        throw new \RuntimeException('Could not persist payment receipt.');
    }
}
