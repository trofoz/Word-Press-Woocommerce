<?php

use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\ProductRouting;

global $wpdb;
$scenario = getenv('WFPMS_BANK_RECEIPT_CASE') ?: '';
$shape = str_starts_with($scenario, 'physical') ? 'physical' : (str_starts_with($scenario, 'mixed') ? 'mixed' : 'virtual');
$isDuplicate = in_array($scenario, ['duplicate', 'physical_duplicate', 'mixed_duplicate'], true);
$acceptedScenarios = ['accepted', 'physical', 'mixed'];
$orderId = (int) get_option($isDuplicate && $shape !== 'virtual'
    ? 'wfpms_integration_iban_' . $shape . '_order_id' : 'wfpms_integration_iban_order_id', 0);
$purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
if (!in_array($scenario, ['accepted', 'duplicate', 'conflict', 'review', 'physical', 'physical_duplicate', 'mixed', 'mixed_duplicate'], true) || $purchase === null) {
    throw new RuntimeException('IBAN receipt integration fixture is missing.');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) ($admins[0] ?? 0);
wp_set_current_user($adminId);
if ($adminId < 1 || !current_user_can('manage_woocommerce')) {
    throw new RuntimeException('The integration administrator cannot manage WooCommerce.');
}
if (in_array($scenario, ['review', 'physical', 'mixed'], true)) {
    $product = wc_get_product((int) get_option('wfpms_integration_iban_product_id', 0));
    if (!$product instanceof WC_Product) {
        throw new RuntimeException('IBAN product fixture is missing.');
    }
    if (in_array($scenario, ['physical', 'mixed'], true)) {
        $physicalProduct = new WC_Product_Simple();
        $physicalProduct->set_name('Physical IBAN receipt fixture');
        $physicalProduct->set_regular_price('10.00');
        $physicalProduct->save();
        update_post_meta((int) $physicalProduct->get_id(), ProductRouting::META_SELLER_ID,
            (int) $purchase['seller_id']);
        if ($scenario === 'physical') { $product = $physicalProduct; }
        if ($scenario === 'mixed') {
            $product = new WC_Product_Simple();
            $product->set_name('Virtual IBAN mixed receipt fixture');
            $product->set_regular_price('10.00');
            $product->set_virtual(true);
            $product->save();
            update_post_meta((int) $product->get_id(), ProductRouting::META_SELLER_ID, (int) $purchase['seller_id']);
        }
    }
    $order = wc_create_order();
    $order->add_product($product, 1);
    if ($scenario === 'mixed') { $order->add_product($physicalProduct, 1); }
    $order->set_payment_method('wfpms_iban');
    $order->calculate_totals();
    $order->save();
    $orderId = (int) $order->get_id();
    $gateway = WC()->payment_gateways()->payment_gateways()['wfpms_iban'];
    $result = $gateway->process_payment($orderId);
    $purchase = PurchaseRepository::for_order($orderId);
    if (($result['result'] ?? '') !== 'success' || $purchase === null) {
        throw new RuntimeException('New IBAN regression purchase could not be issued.');
    }
    if ($shape !== 'virtual') { update_option('wfpms_integration_iban_' . $shape . '_order_id', $orderId, false); }
}

$amount = (string) $purchase['amount'];
$operationId = 'integration-bank-' . $orderId;
$recipient = 'UA213223130000026007233566001';
$occurredAt = (string) get_option($shape === 'virtual' ? 'wfpms_integration_iban_occurred_at' : 'wfpms_integration_iban_' . $shape . '_occurred_at', '');
if (in_array($scenario, ['accepted', 'review', 'physical', 'mixed'], true)) {
    $occurredAt = (new DateTimeImmutable('-1 minute', wp_timezone()))->format('Y-m-d\TH:i');
    if (in_array($scenario, $acceptedScenarios, true)) {
        update_option($shape === 'virtual' ? 'wfpms_integration_iban_occurred_at' : 'wfpms_integration_iban_' . $shape . '_occurred_at', $occurredAt, false);
    }
}
$submittedAmount = in_array($scenario, ['conflict', 'review'], true) ? '9999.99' : $amount;
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

$selectionProof = '';
if (in_array($scenario, $acceptedScenarios, true)) {
    if (wc_get_order($orderId)->get_status() !== 'on-hold'
        || (int) $purchase['accepted_receipt_id'] !== 0 || $purchase['execution_state'] !== 'none'
        || count(PurchaseRepository::receipts_for_purchase((int) $purchase['id'])) !== 0) {
        throw new RuntimeException('Selecting IBAN alone must keep on-hold with no accepted receipt or execution.');
    }
    $selectionProof = 'PASS: IBAN ' . $shape . ' selection stays on-hold until authorized receipt confirmation.';
}
$completedTransitions = 0;
$processingTransitions = 0;
$paymentCompletions = 0;
add_action('woocommerce_order_status_processing', static function (int $id) use ($orderId, &$processingTransitions): void {
    if ($id === $orderId) { $processingTransitions++; }
});
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
register_shutdown_function(static function () use ($wpdb, $scenario, $purchase, $submittedAmount, $storedOccurredAt, $orderId, $acceptedScenarios, $isDuplicate, $selectionProof, &$processingTransitions, &$completedTransitions, &$paymentCompletions): void {
    // Confirmation queues execution; exercise the actual runner, including a duplicate delivery.
    PurchaseExecutionService::run((int) $purchase['id']);
    PurchaseExecutionService::run((int) $purchase['id']);
    wp_clear_scheduled_hook('wfpms_process_execution', [(int) $purchase['id']]);
    $fresh = PurchaseRepository::for_order($orderId);
    $order = wc_get_order($orderId);
    $expectedCalls = in_array($scenario, $acceptedScenarios, true) ? 1 : 0;
    $expectedCompleted = $expectedCalls;
    $expectedStatus = match ($scenario) {
        'review' => 'on-hold',
        default => 'completed',
    };
    $ok = $order instanceof WC_Order && $order->get_status() === $expectedStatus
        && $fresh !== null && (string) $fresh['execution_state'] === ($scenario === 'review' ? 'none' : 'done')
        && $completedTransitions === $expectedCompleted && $paymentCompletions === $expectedCalls && $processingTransitions === 0
        && (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
            (int) $purchase['id'], 'execution_done'
        )) === ($scenario === 'review' ? 0 : 1);
    if (in_array($scenario, $acceptedScenarios, true)) {
        $ok = $ok && (string) $fresh['state'] === 'paid'
            && (int) $fresh['accepted_receipt_id'] > 0
            && (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table()
                . ' WHERE id=%d AND classification=%s', (int) $fresh['accepted_receipt_id'], 'accepted'
            )) === 1;
    } elseif ($scenario === 'review') {
        $ok = $ok && (string) $fresh['state'] === 'review'
            && (int) $fresh['accepted_receipt_id'] === 0
            && (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d AND classification=%s',
                (int) $purchase['id'], 'needs_review'
            )) === 1;
    } elseif ($isDuplicate) {
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
            'payment_completions' => $paymentCompletions, 'processing_transitions' => $processingTransitions,
            'expected_occurred_at' => $storedOccurredAt,
            'event_evidence' => $evidence ?? null,
        ];
        fwrite(STDERR, 'FAIL: IBAN receipt case did not produce its expected durable state: '
            . $scenario . ' ' . wp_json_encode($diagnostic, JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    if ($selectionProof !== '') { fwrite(STDOUT, $selectionProof . "\n"); }
    fwrite(STDOUT, 'PASS: IBAN receipt case ' . $scenario . '; order=' . $expectedStatus . '; completed transitions='
        . $completedTransitions . '; payment completions=' . $paymentCompletions . "\n");
});

PaymentOperations::confirm_bank_receipt();
