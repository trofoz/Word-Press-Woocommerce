<?php

use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\PurchaseRepository;

global $wpdb;
$scenario = getenv('WFPMS_REFUND_CASE') ?: '';
$orderId = (int) get_option('wfpms_integration_iban_order_id', 0);
$purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
$receiptId = $purchase === null ? 0 : (int) $purchase['accepted_receipt_id'];
$receipt = $receiptId > 0 ? $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . PurchaseRepository::receipts_table() . ' WHERE id=%d', $receiptId
), ARRAY_A) : null;
if (!in_array($scenario, ['create', 'duplicate', 'over_limit'], true) || !is_array($receipt)) {
    throw new RuntimeException('Accepted receipt fixture for refund recording is missing.');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) ($admins[0] ?? 0);
wp_set_current_user($adminId);
if ($adminId < 1 || !current_user_can('manage_woocommerce')) {
    throw new RuntimeException('The integration administrator cannot manage WooCommerce.');
}

$refundId = 'integration-refund-' . $receiptId;
$refundAmount = $scenario === 'over_limit' ? (string) $receipt['amount'] : '5.00';
$_POST = [
    '_wpnonce' => wp_create_nonce('wfpms_record_refund'),
    'receipt_id' => (string) $receiptId,
    'refund_id' => $refundId,
    'amount' => $refundAmount,
    'currency' => (string) $receipt['currency'],
    'occurred_at' => '2026-10-01T12:00',
    'proof_reference' => 'merchant-portal-refund-' . $refundId,
    'reason' => 'Integration test refund evidence',
];
$_REQUEST = $_POST;

register_shutdown_function(static function () use ($wpdb, $scenario, $receiptId, $refundId, $refundAmount): void {
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT evidence,reason,actor_id FROM ' . PurchaseRepository::events_table()
        . ' WHERE receipt_id=%d AND kind=%s', $receiptId, 'refund_recorded'
    ), ARRAY_A);
    $events = is_array($rows) ? $rows : [];
    if ($scenario === 'create') {
        $evidence = isset($events[0]['evidence']) ? json_decode((string) $events[0]['evidence'], true) : null;
        $ok = count($events) === 1 && is_array($evidence)
            && (string) ($evidence['refund_id'] ?? '') === $refundId
            && (string) ($evidence['amount'] ?? '') === $refundAmount
            && (string) ($evidence['currency'] ?? '') !== ''
            && (string) ($evidence['proof_reference'] ?? '') !== ''
            && (string) ($events[0]['reason'] ?? '') === 'Integration test refund evidence'
            && (int) ($events[0]['actor_id'] ?? 0) > 0;
    } elseif ($scenario === 'duplicate') {
        $ok = count($events) === 1;
    } else {
        $ok = count($events) === 1;
    }
    if (!$ok) {
        fwrite(STDERR, 'FAIL: refund record case did not preserve its expected receipt-scoped evidence: ' . $scenario . "\n");
        exit(1);
    }
    fwrite(STDOUT, 'PASS: refund record case ' . $scenario . "\n");
});

PaymentOperations::record_refund();
