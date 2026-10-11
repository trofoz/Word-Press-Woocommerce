<?php

use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\PurchaseRepository;

// Disposable DB only: seed durable accepted evidence, then exercise real admin refund and WC execution.
global $wpdb;
$scenario = getenv('WFPMS_REFUND_EXECUTION_CASE') ?: '';
if (!in_array($scenario, ['card', 'iban_no_attempt', 'paid_review', 'extra_receipt', 'partial', 'recovery',
    'recovery_refunded', 'recovery_partial', 'recovery_voided', 'recovery_cancelled', 'concurrency_prepare'], true)) {
    throw new RuntimeException('Unknown refund/execution regression scenario.');
}
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 0));
$repeat = getenv('WFPMS_REFUND_EXECUTION_REPEAT') === '1';
if ($repeat) {
    $fixture = get_option('wfpms_refund_execution_repeat_' . $scenario, []);
    $orderId = (int) ($fixture['order_id'] ?? 0);
    $purchase = PurchaseRepository::for_order($orderId);
    if ($purchase === null) {
        throw new RuntimeException('Repeat refund fixture is missing.');
    }
    $purchaseId = (int) $purchase['id'];
    $refundReceiptId = (int) $fixture['post']['receipt_id'];
} else {
    $card = $scenario === 'card';
    $baseProductId = (int) get_option($card ? 'wfpms_integration_manual_product_id' : 'wfpms_integration_iban_product_id', 0);
    $routing = ProductRouting::resolve($baseProductId);
    if (!$routing['ok'] || !current_user_can('manage_woocommerce')) {
        throw new RuntimeException('Refund/execution fixture is unavailable.');
    }
    $product = new WC_Product_Simple();
    $product->set_name('Refund before execution: ' . $scenario);
    $product->set_regular_price('10.00');
    $product->set_virtual(true);
    $product->save();
    update_post_meta((int) $product->get_id(), ProductRouting::META_SELLER_ID, (int) $routing['seller_id']);
    $order = wc_create_order();
    $order->add_product($product, 1);
    $order->set_payment_method($card ? 'wfpms_card' : 'wfpms_iban');
    $order->calculate_totals();
    $order->save();
    $orderId = (int) $order->get_id();
    $gateway = WC()->payment_gateways()->payment_gateways()[$order->get_payment_method()];
    $result = $gateway->process_payment($orderId);
    $purchase = PurchaseRepository::for_order($orderId);
    if (($result['result'] ?? '') !== 'success' || $purchase === null) {
        throw new RuntimeException('Cannot issue regression purchase.');
    }
    $purchaseId = (int) $purchase['id'];
    $attemptId = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . PurchaseRepository::attempts_table() . ' WHERE purchase_id=%d ORDER BY id DESC LIMIT 1', $purchaseId
    ));
    if ($wpdb->update(PurchaseRepository::attempts_table(), ['status' => $card ? 'approved' : 'confirmed'], ['id' => $attemptId]) === false) {
        throw new RuntimeException('Could not seed accepted attempt.');
    }
    $seedReceipt = static function (string $classification, string $externalId, int $receiptAttemptId) use ($wpdb, $purchase, $purchaseId, $card): int {
        $snapshot = json_decode((string) $purchase['financial_snapshot'], true);
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(PurchaseRepository::receipts_table(), [
            'purchase_id' => $purchaseId, 'attempt_id' => $receiptAttemptId,
            'source' => $card ? 'wayforpay' : 'iban', 'source_scope' => 'regression-' . $purchaseId,
            'external_id' => $externalId, 'amount' => (string) $purchase['amount'], 'amount_raw' => (string) $purchase['amount'],
            'currency' => (string) $purchase['currency'], 'transaction_status' => $card ? 'Approved' : 'OperatorConfirmed',
            'classification' => $classification, 'actual_recipient' => (string) ($snapshot['iban'] ?? ''),
            'occurred_at' => $now, 'time_evidence' => 'integration_fixture', 'recorded_by' => get_current_user_id(),
            'first_event_id' => 0, 'recorded_at' => $now,
        ]);
        if ($inserted !== 1) {
            throw new RuntimeException('Could not seed receipt: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    };
    $acceptedId = $seedReceipt('accepted', 'accepted-' . $purchaseId, $scenario === 'iban_no_attempt' ? 0 : $attemptId);
    $extraId = 0;
    if (in_array($scenario, ['paid_review', 'extra_receipt', 'recovery', 'recovery_refunded', 'recovery_partial',
        'recovery_voided', 'recovery_cancelled', 'concurrency_prepare'], true)) {
        $extraId = $seedReceipt('needs_review', 'extra-' . $purchaseId, 0);
    }
    if ($wpdb->update(PurchaseRepository::purchases_table(), [
        'state' => $extraId > 0 ? 'paid_review' : 'paid', 'accepted_receipt_id' => $acceptedId,
        'execution_state' => 'pending', 'execution_updated_at' => current_time('mysql', true),
    ], ['id' => $purchaseId]) !== 1) {
        throw new RuntimeException('Could not seed pending financial acceptance.');
    }
    wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
    if ($scenario === 'concurrency_prepare') {
        PurchaseExecutionService::expire_due();
        if (!wp_next_scheduled('wfpms_process_execution', [$purchaseId])) {
            throw new RuntimeException('Concurrent runner fixture has no recovered task.');
        }
        update_option('wfpms_execution_concurrency_fixture', ['purchase_id' => $purchaseId, 'order_id' => $orderId], false);
        update_option('wfpms_execution_concurrency_completed', '0', false);
        update_option('wfpms_execution_concurrency_payment', '0', false);
        update_option('wfpms_execution_concurrency_ready', '0', false);
        echo "PASS: prepared concurrent paid_review execution.\n";
        return;
    }
}
$completed = 0;
$paymentCompletions = 0;
add_action('woocommerce_order_status_completed', static function (int $id) use ($orderId, &$completed): void {
    if ($id === $orderId) { $completed++; }
});
add_action('woocommerce_payment_complete', static function (int $id) use ($orderId, &$paymentCompletions): void {
    if ($id === $orderId) { $paymentCompletions++; }
});
if (in_array($scenario, ['recovery', 'recovery_voided', 'recovery_cancelled'], true)) {
    $allowed = $scenario === 'recovery';
    if ($scenario === 'recovery_voided') {
        $wpdb->update(PurchaseRepository::attempts_table(), ['status' => 'voided'], ['id' => $attemptId]);
    } elseif ($scenario === 'recovery_cancelled') {
        $order->update_status('cancelled');
    }
    PurchaseExecutionService::expire_due();
    PurchaseExecutionService::expire_due();
    $scheduled = wp_next_scheduled('wfpms_process_execution', [$purchaseId]);
    if (($scheduled !== false) !== $allowed) {
        throw new RuntimeException('FAIL P2: unexpected recovery scheduling for ' . $scenario);
    }
    do_action('wfpms_process_execution', $purchaseId);
    do_action('wfpms_process_execution', $purchaseId);
    $fresh = PurchaseRepository::for_order($orderId);
    if ($completed !== ($allowed ? 1 : 0) || $paymentCompletions !== ($allowed ? 1 : 0)
        || ($allowed ? wc_get_order($orderId)->get_status() !== 'completed' : wc_get_order($orderId)->is_paid())
        || (string) $fresh['execution_state'] !== ($allowed ? 'done' : 'review')) {
        throw new RuntimeException('FAIL P2: incorrect recovered execution for ' . $scenario);
    }
    wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
    echo 'PASS: refund/execution ' . $scenario . ': recovered=' . (int) $allowed . '; completed=' . $completed . "\n";
    return;
}
if (!$repeat) {
    $refundReceiptId = $scenario === 'extra_receipt' ? $extraId : $acceptedId;
    $_POST = [
        '_wpnonce' => wp_create_nonce('wfpms_record_refund'), 'receipt_id' => (string) $refundReceiptId,
        'refund_id' => 'refund-before-execution-' . $purchaseId,
        'amount' => in_array($scenario, ['partial', 'recovery_partial'], true) ? '5.00' : '10.00',
        'currency' => (string) $purchase['currency'], 'occurred_at' => (new DateTimeImmutable('now', wp_timezone()))->format('Y-m-d\TH:i'),
        'proof_reference' => 'test-bank-confirmation-' . $purchaseId, 'reason' => 'Verified full refund before execution regression',
    ];
    update_option('wfpms_refund_execution_repeat_' . $scenario, ['order_id' => $orderId, 'post' => $_POST], false);
} else {
    $_POST = $fixture['post'];
    $_POST['_wpnonce'] = wp_create_nonce('wfpms_record_refund');
}
$_REQUEST = $_POST;
register_shutdown_function(static function () use ($wpdb, $scenario, $purchaseId, $orderId, $refundReceiptId, $repeat, &$completed, &$paymentCompletions): void {
    $refundCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE receipt_id=%d AND kind=%s', $refundReceiptId, 'refund_recorded'
    ));
    if (in_array($scenario, ['recovery_refunded', 'recovery_partial'], true)) {
        // Model a pre-existing pending backlog; new refund recording revokes execution atomically.
        $wpdb->update(PurchaseRepository::purchases_table(), ['state' => 'paid_review', 'execution_state' => 'pending'], ['id' => $purchaseId]);
        wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
        PurchaseExecutionService::expire_due();
        if (wp_next_scheduled('wfpms_process_execution', [$purchaseId]) !== false) {
            fwrite(STDERR, 'FAIL P2: recovery scheduled a refunded or insufficient purchase: ' . $scenario . "\n");
            exit(1);
        }
    }
    PurchaseExecutionService::run($purchaseId);
    PurchaseExecutionService::run($purchaseId);
    $fresh = PurchaseRepository::for_order($orderId);
    $allowed = $scenario === 'extra_receipt';
    $accepted = $fresh !== null && PurchaseExecutionService::is_financially_accepted($fresh);
    $order = wc_get_order($orderId);
    $ok = $refundCount === 1 && $accepted === $allowed && $completed === ($allowed && !$repeat ? 1 : 0)
        && $paymentCompletions === ($allowed && !$repeat ? 1 : 0) && $order instanceof WC_Order
        && ($allowed ? $order->get_status() === 'completed' : !$order->is_paid());
    wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
    if (!$ok) {
        fwrite(STDERR, 'FAIL P1: refund/execution ' . $scenario . ': ' . wp_json_encode([
            'refund_count' => $refundCount, 'financially_accepted' => $accepted,
            'order_status' => $order ? $order->get_status() : null, 'execution_state' => $fresh['execution_state'] ?? null,
            'completed' => $completed, 'payment_completions' => $paymentCompletions,
        ]) . "\n");
        exit(1);
    }
    if (!$repeat) {
        putenv('WFPMS_REFUND_EXECUTION_REPEAT=1');
        $result = WP_CLI::runcommand('eval-file ' . escapeshellarg(getenv('GITHUB_WORKSPACE') . '/tests/integration-refund-execution.php'),
            ['launch' => true, 'return' => 'all', 'exit_error' => false]);
        putenv('WFPMS_REFUND_EXECUTION_REPEAT');
        if ((int) $result->return_code !== 0) {
            fwrite(STDERR, 'FAIL: repeated actual refund handler or runner failed: ' . $scenario . "\n" . $result->stderr);
            exit(1);
        }
    }
    echo 'PASS: refund/execution ' . $scenario . ($repeat ? ' repeat' : ' with repeated refund/runner')
        . ': completed=' . $completed . '; payment completions=' . $paymentCompletions . "\n";
});
PaymentOperations::record_refund();
