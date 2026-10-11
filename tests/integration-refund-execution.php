<?php

use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\PurchaseRepository;

// Disposable DB only: seed durable accepted evidence, then exercise real admin refund and WC execution.
global $wpdb;
$scenario = getenv('WFPMS_REFUND_EXECUTION_CASE') ?: '';
if (!in_array($scenario, ['card', 'iban_no_attempt', 'paid_review', 'extra_receipt', 'recovery'], true)) {
    throw new RuntimeException('Unknown refund/execution regression scenario.');
}
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 0));
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
if (in_array($scenario, ['paid_review', 'extra_receipt', 'recovery'], true)) {
    $extraId = $seedReceipt('needs_review', 'extra-' . $purchaseId, 0);
}
if ($wpdb->update(PurchaseRepository::purchases_table(), [
    'state' => $extraId > 0 ? 'paid_review' : 'paid', 'accepted_receipt_id' => $acceptedId,
    'execution_state' => 'pending', 'execution_updated_at' => current_time('mysql', true),
], ['id' => $purchaseId]) !== 1) {
    throw new RuntimeException('Could not seed pending financial acceptance.');
}
wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
$completed = 0;
$paymentCompletions = 0;
add_action('woocommerce_order_status_completed', static function (int $id) use ($orderId, &$completed): void {
    if ($id === $orderId) { $completed++; }
});
add_action('woocommerce_payment_complete', static function (int $id) use ($orderId, &$paymentCompletions): void {
    if ($id === $orderId) { $paymentCompletions++; }
});
if ($scenario === 'recovery') {
    PurchaseExecutionService::expire_due();
    $scheduled = wp_next_scheduled('wfpms_process_execution', [$purchaseId]);
    if ($scheduled === false) {
        throw new RuntimeException('FAIL P2: paid_review + pending has no recovered execution task.');
    }
    do_action('wfpms_process_execution', $purchaseId);
    do_action('wfpms_process_execution', $purchaseId);
    $fresh = PurchaseRepository::for_order($orderId);
    if (wc_get_order($orderId)->get_status() !== 'completed' || $completed !== 1 || $paymentCompletions !== 1
        || (string) $fresh['execution_state'] !== 'done') {
        throw new RuntimeException('FAIL P2: recovered execution did not complete exactly once.');
    }
    wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
    echo "PASS: refund/execution recovery: paid_review task recovered and completed once.\n";
    return;
}
$refundReceiptId = $scenario === 'extra_receipt' ? $extraId : $acceptedId;
$_POST = [
    '_wpnonce' => wp_create_nonce('wfpms_record_refund'), 'receipt_id' => (string) $refundReceiptId,
    'refund_id' => 'refund-before-execution-' . $purchaseId, 'amount' => '10.00',
    'currency' => (string) $purchase['currency'], 'occurred_at' => (new DateTimeImmutable('now', wp_timezone()))->format('Y-m-d\TH:i'),
    'proof_reference' => 'test-bank-confirmation-' . $purchaseId, 'reason' => 'Verified full refund before execution regression',
];
$_REQUEST = $_POST;
register_shutdown_function(static function () use ($wpdb, $scenario, $purchaseId, $orderId, $refundReceiptId, &$completed, &$paymentCompletions): void {
    $refundCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE receipt_id=%d AND kind=%s', $refundReceiptId, 'refund_recorded'
    ));
    PurchaseExecutionService::run($purchaseId);
    PurchaseExecutionService::run($purchaseId);
    $fresh = PurchaseRepository::for_order($orderId);
    $allowed = $scenario === 'extra_receipt';
    $accepted = $fresh !== null && PurchaseExecutionService::is_financially_accepted($fresh);
    $order = wc_get_order($orderId);
    $ok = $refundCount === 1 && $accepted === $allowed && $completed === ($allowed ? 1 : 0)
        && $paymentCompletions === ($allowed ? 1 : 0) && $order instanceof WC_Order
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
    echo 'PASS: refund/execution ' . $scenario . ': completed=' . $completed . '; payment completions=' . $paymentCompletions . "\n";
});
PaymentOperations::record_refund();
