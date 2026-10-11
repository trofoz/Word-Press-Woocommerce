<?php

use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\PurchaseExecutionService;

global $wpdb;
$scenario = getenv('WFPMS_BANK_RECEIPT_CASE') ?: '';
$orderId = (int) get_option('wfpms_integration_iban_order_id', 0);
$purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
if (!in_array($scenario, ['accepted', 'duplicate', 'conflict'], true) || $purchase === null) {
    throw new RuntimeException('IBAN receipt integration fixture is missing.');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) ($admins[0] ?? 0);
wp_set_current_user($adminId);
if ($adminId < 1 || !current_user_can('manage_woocommerce')) {
    throw new RuntimeException('The integration administrator cannot manage WooCommerce.');
}

$amount = (string) $purchase['amount'];
$operationId = 'integration-bank-' . $orderId;
$recipient = 'UA213223130000026007233566001';
$occurredAt = (string) get_option('wfpms_integration_iban_occurred_at', '');
if ($scenario === 'accepted') {
    $occurredAt = (new DateTimeImmutable('-1 minute', wp_timezone()))->format('Y-m-d\TH:i');
    update_option('wfpms_integration_iban_occurred_at', $occurredAt, false);
}
$submittedAmount = $scenario === 'conflict' ? '9999.99' : $amount;
$storedOccurredAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $occurredAt, wp_timezone())
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$nonce = wp_create_nonce('wfpms_confirm_bank_receipt');
$_POST = [
    '_wpnonce' => $nonce,
    'order_id' => (string) $orderId,
    'operation_id' => $operationId,
    'recipient_account' => $recipient,
    'amount' => $submittedAmount,
    'currency' => (string) $purchase['currency'],
    'occurred_at' => $occurredAt,
];
$_REQUEST = $_POST;

$completedTransitions = 0;
$paymentCompletions = 0;
add_action('woocommerce_order_status_completed', static function (int $id) use ($orderId, &$completedTransitions): void {
    if ($id === $orderId) {
        $completedTransitions++;
    }
});
add_action('woocommerce_payment_complete', static function (int $id) use ($orderId, &$paymentCompletions): void {
    if ($id === $orderId) {
        $paymentCompletions++;
    }
});
register_shutdown_function(static function () use ($wpdb, $scenario, $purchase, $submittedAmount, $storedOccurredAt, $orderId, &$completedTransitions, &$paymentCompletions): void {
    // Confirmation queues execution; exercise the actual runner, including a duplicate delivery.
    PurchaseExecutionService::run((int) $purchase['id']);
    PurchaseExecutionService::run((int) $purchase['id']);
    wp_clear_scheduled_hook('wfpms_process_execution', [(int) $purchase['id']]);
    $fresh = PurchaseRepository::for_order($orderId);
    $order = wc_get_order($orderId);
    $expectedCalls = $scenario === 'accepted' ? 1 : 0;
    $ok = $order instanceof WC_Order && $order->get_status() === 'processing'
        && $fresh !== null && (string) $fresh['execution_state'] === 'done'
        && $completedTransitions === 0 && $paymentCompletions === $expectedCalls
        && (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
            (int) $purchase['id'], 'execution_done'
        )) === 1;
    if ($scenario === 'accepted') {
        $ok = $ok && (string) $fresh['state'] === 'paid'
            && (int) $fresh['accepted_receipt_id'] > 0
            && (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table()
                . ' WHERE id=%d AND classification=%s', (int) $fresh['accepted_receipt_id'], 'accepted'
            )) === 1;
    } elseif ($scenario === 'duplicate') {
        $ok = $ok && (string) $fresh['state'] === 'paid'
            && (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d', (int) $purchase['id']
            )) === 1;
    } else {
        $event = $wpdb->get_row($wpdb->prepare(
            'SELECT evidence FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s ORDER BY id DESC LIMIT 1',
            (int) $purchase['id'], 'bank_identity_conflict'
        ), ARRAY_A);
        $evidence = is_array($event) ? json_decode((string) $event['evidence'], true) : null;
        $ok = $ok && (string) $fresh['state'] === 'paid_review'
            && is_array($evidence)
            && (string) ($evidence['submitted_amount'] ?? '') === $submittedAmount
            && (string) ($evidence['normalized_amount'] ?? '') === '9999.99'
            && (string) ($evidence['currency'] ?? '') === (string) $purchase['currency']
            && (string) ($evidence['occurred_at'] ?? '') === $storedOccurredAt;
    }
    if (!$ok) {
        $diagnostic = [
            'state' => $fresh['state'] ?? null,
            'order_status' => $order instanceof WC_Order ? $order->get_status() : 'missing',
            'completed_transitions' => $completedTransitions,
            'payment_completions' => $paymentCompletions,
            'expected_occurred_at' => $storedOccurredAt,
            'event_evidence' => $evidence ?? null,
        ];
        fwrite(STDERR, 'FAIL: IBAN receipt case did not produce its expected durable state: '
            . $scenario . ' ' . wp_json_encode($diagnostic, JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    fwrite(STDOUT, 'BASELINE: IBAN virtual non-downloadable service remains processing; completed transitions=0; case ' . $scenario . "\n");
});

PaymentOperations::confirm_bank_receipt();
