<?php

use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;
use WfpMultiSeller\WayForPayGateway;

function wfpms_callback_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

if (!class_exists('WooCommerce') || !SecretStore::available()) {
    throw new RuntimeException('WooCommerce or integration-only merchant encryption is unavailable.');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) ($admins[0] ?? 0);
$productId = (int) get_option('wfpms_integration_manual_product_id', 0);
$product = $productId > 0 ? wc_get_product($productId) : false;
if ($adminId < 1 || !$product instanceof WC_Product) {
    throw new RuntimeException('The WayForPay callback integration fixture is missing.');
}

$secret = 'integration-only-' . wp_salt('auth');
$sellerId = SellerRepository::save(null, [
    'internal_label' => 'WayForPay callback integration seller',
    'legal_name' => 'WayForPay callback test recipient',
    'tax_id' => 'CALLBACK-TEST',
], [
    'recipient_name' => 'WayForPay callback test recipient',
    'iban' => 'UA213223130000026007233566001',
    'bank_name' => 'Integration Bank',
    'purpose_template' => 'Callback integration order {order_number}',
    'merchant_account' => 'integration-merchant',
    'merchant_domain' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
    'card_enabled' => 1,
    'iban_enabled' => 1,
    'secret_plaintext' => $secret,
    'operator_checked' => false,
], SecretStore::encrypt($secret), $adminId);
update_post_meta($productId, ProductRouting::META_SELLER_ID, $sellerId);
$physicalProduct = $product;
$product = new WC_Product_Simple();
$product->set_name('Educational virtual service callback fixture');
$product->set_regular_price('15.00');
$product->set_virtual(true);
$product->set_downloadable(false);
$product->save();
update_post_meta((int) $product->get_id(), ProductRouting::META_SELLER_ID, $sellerId);

$gateway = WC()->payment_gateways()->payment_gateways()['wfpms_card'] ?? null;
wfpms_callback_integration_assert($gateway instanceof WayForPayGateway, 'WayForPay card gateway is registered.');

$createAttempt = static function (array $purchaseProducts = []) use ($product, $gateway): array {
    global $wpdb;
    $order = wc_create_order();
    $order->set_billing_email('callback-integration@example.test');
    foreach ($purchaseProducts ?: [$product] as $purchaseProduct) {
        $order->add_product($purchaseProduct, 1);
    }
    $order->set_payment_method('wfpms_card');
    $order->calculate_totals();
    $order->save();

    $result = $gateway->process_payment((int) $order->get_id());
    wfpms_callback_integration_assert(($result['result'] ?? '') === 'success', 'Card checkout creates a signed payment attempt.');
    $purchase = PurchaseRepository::for_order((int) $order->get_id());
    wfpms_callback_integration_assert($purchase !== null, 'Card checkout stores a purchase.');
    $attempt = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . PurchaseRepository::attempts_table() . ' WHERE purchase_id=%d ORDER BY id DESC LIMIT 1',
        (int) $purchase['id']
    ), ARRAY_A);
    wfpms_callback_integration_assert(is_array($attempt), 'Card checkout stores its payment attempt.');

    return [$order, $purchase, $attempt];
};

$sendApproved = static function (array $attempt, string $amount, string $currency, string $authCode = 'AUTH-TEST', ?string $signingSecret = null, ?string $merchantAccount = null, string $transactionStatus = 'Approved'): array {
    $config = $signingSecret === null ? SellerRepository::config((int) $attempt['config_id']) : null;
    $callbackSecret = $signingSecret ?? ($config === null ? null : SecretStore::decrypt((string) $config['secret_ciphertext']));
    wfpms_callback_integration_assert(is_string($callbackSecret) && $callbackSecret !== '', 'Attempt retains its immutable signing credential version.');
    $fields = [
        'merchantAccount' => $merchantAccount ?? (string) $attempt['merchant_account'],
        'orderReference' => (string) $attempt['external_reference'],
        'amount' => $amount,
        'currency' => $currency,
        'authCode' => $authCode,
        'cardPan' => '411111******1111',
        'transactionStatus' => $transactionStatus,
        'reasonCode' => '1100',
    ];
    $fields['merchantSignature'] = hash_hmac('md5', implode(';', array_values($fields)), $callbackSecret);
    $payload = json_decode((string) $attempt['payload'], true);
    $callbackUrl = is_array($payload) ? (string) ($payload['serviceUrl'] ?? '') : '';
    wfpms_callback_integration_assert($callbackUrl !== '', 'Signed Purchase attempt contains a serviceUrl.');

    $response = wp_remote_post($callbackUrl, [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($fields),
    ]);
    wfpms_callback_integration_assert(!is_wp_error($response), 'WayForPay callback endpoint responds over HTTP.');
    wfpms_callback_integration_assert(wp_remote_retrieve_response_code($response) === 200, 'Valid signed callback receives HTTP 200.');
    $ack = json_decode((string) wp_remote_retrieve_body($response), true);
    wfpms_callback_integration_assert(is_array($ack)
        && ($ack['orderReference'] ?? '') === $fields['orderReference']
        && ($ack['status'] ?? '') === 'accept'
        && isset($ack['time'], $ack['signature'])
        && hash_equals(
            hash_hmac('md5', $fields['orderReference'] . ';accept;' . (string) $ack['time'], $callbackSecret),
            (string) $ack['signature']
        ), 'Callback acknowledges the signed event using the WayForPay response signature.');

    return $fields;
};

$sendRejected = static function (array $attempt, string $secret, string $mutation): void {
    $payload = json_decode((string) $attempt['payload'], true);
    $callbackUrl = is_array($payload) ? (string) ($payload['serviceUrl'] ?? '') : '';
    wfpms_callback_integration_assert($callbackUrl !== '', 'Rejected callback fixture contains a serviceUrl.');
    $fields = [
        'merchantAccount' => (string) $attempt['merchant_account'],
        'orderReference' => (string) $attempt['external_reference'],
        'amount' => (string) $attempt['expected_amount'],
        'currency' => (string) $attempt['currency'],
        'authCode' => 'AUTH-REJECTED',
        'cardPan' => '411111******1111',
        'transactionStatus' => 'Approved',
        'reasonCode' => '1100',
    ];
    if ($mutation === 'invalid_signature') {
        $fields['merchantSignature'] = str_repeat('0', 32);
    } else {
        throw new InvalidArgumentException('Unknown rejected callback mutation.');
    }
    $response = wp_remote_post($callbackUrl, [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($fields),
    ]);
    wfpms_callback_integration_assert(!is_wp_error($response), 'Rejected callback endpoint responds over HTTP.');
    wfpms_callback_integration_assert(wp_remote_retrieve_response_code($response) === 400,
        'Callback with ' . $mutation . ' is rejected with HTTP 400.');
};

global $wpdb;
update_option('wfpms_legacy_transition_enabled', 'no', false);
$pausedOrder = wc_create_order();
$pausedOrder->add_product($product, 1);
$pausedOrder->set_payment_method('wfpms_card');
$pausedOrder->calculate_totals();
$pausedOrder->save();
$pausedResult = $gateway->process_payment((int) $pausedOrder->get_id());
wfpms_callback_integration_assert(($pausedResult['result'] ?? '') === 'failure'
    && PurchaseRepository::for_order((int) $pausedOrder->get_id()) === null,
    'Direct card process_payment calls are rejected before creating a purchase while the transition is paused.');
update_option('wfpms_legacy_transition_enabled', 'yes', false);
[$paidOrder, $paidPurchase, $paidAttempt] = $createAttempt();
wfpms_callback_integration_assert($paidOrder->get_status() === 'pending',
    'Selecting card for a virtual purchase leaves it pending before payment confirmation.');
$sendRejected($paidAttempt, $secret, 'invalid_signature');
$untrustedCallbackReceiptCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $paidPurchase['id']
));
$untrustedCallbackEventCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d',
    (int) $paidPurchase['id']
));
wfpms_callback_integration_assert($untrustedCallbackReceiptCount === 0 && $untrustedCallbackEventCount === 0
    && !$paidOrder->is_paid(), 'Invalid signature callbacks create no financial evidence and do not pay the order.');

[$mismatchOrder, $mismatchPurchase, $mismatchAttempt] = $createAttempt();
$sendApproved($mismatchAttempt, (string) $mismatchAttempt['expected_amount'], (string) $mismatchAttempt['currency'],
    'AUTH-WRONG-MERCHANT', $secret, 'unrecognized-integration-merchant');
$mismatchPurchase = PurchaseRepository::for_order((int) $mismatchOrder->get_id());
$mismatchReceipt = $mismatchPurchase === null ? null : $wpdb->get_row($wpdb->prepare(
    'SELECT classification FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $mismatchPurchase['id']
), ARRAY_A);
$mismatchEvent = $mismatchPurchase === null ? null : $wpdb->get_row($wpdb->prepare(
    'SELECT evidence FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d ORDER BY id DESC LIMIT 1',
    (int) $mismatchPurchase['id']
), ARRAY_A);
$mismatchEvidence = is_array($mismatchEvent) ? json_decode((string) $mismatchEvent['evidence'], true) : null;
$mismatchOrder = wc_get_order((int) $mismatchOrder->get_id());
wfpms_callback_integration_assert($mismatchPurchase !== null && (string) $mismatchPurchase['state'] === 'review'
    && is_array($mismatchReceipt) && (string) $mismatchReceipt['classification'] === 'needs_review'
    && is_array($mismatchEvidence) && ($mismatchEvidence['merchant_account_received'] ?? '') === 'unrecognized-integration-merchant'
    && ($mismatchEvidence['merchant_account_matches'] ?? true) === false
    && $mismatchOrder instanceof WC_Order && !$mismatchOrder->is_paid(),
    'A correctly signed Approved callback naming a different merchant is durably recorded for review and does not pay the order.');
wp_set_current_user($adminId);
ob_start();
PaymentOperations::render_page();
$reviewQueueHtml = (string) ob_get_clean();
wfpms_callback_integration_assert(str_contains($reviewQueueHtml, 'unrecognized-integration-merchant')
    && str_contains($reviewQueueHtml, 'integration-merchant'),
    'The operator review queue displays the received and expected merchant accounts for a mismatch.');
$sendApproved($mismatchAttempt, (string) $mismatchAttempt['expected_amount'], (string) $mismatchAttempt['currency'],
    'AUTH-WRONG-MERCHANT', $secret, 'unrecognized-integration-merchant');
wfpms_callback_integration_assert((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $mismatchPurchase['id']
)) === 1, 'Retry of a signed wrong-merchant callback does not duplicate its review receipt.');
$rotatedSecret = 'integration-rotated-only-' . wp_salt('auth');
SellerRepository::save($sellerId, [
    'internal_label' => 'WayForPay callback integration seller',
    'legal_name' => 'WayForPay callback test recipient',
    'tax_id' => 'CALLBACK-TEST',
], [
    'recipient_name' => 'WayForPay callback test recipient',
    'iban' => 'UA213223130000026007233566001',
    'bank_name' => 'Integration Bank',
    'purpose_template' => 'Callback integration order {order_number}',
    'merchant_account' => 'integration-merchant',
    'merchant_domain' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
    'card_enabled' => 1,
    'iban_enabled' => 1,
    'secret_plaintext' => $rotatedSecret,
    'operator_checked' => false,
], SecretStore::encrypt($rotatedSecret), $adminId);
$currentConfig = SellerRepository::current_config($sellerId);
$persistedAttemptConfigId = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT config_id FROM ' . PurchaseRepository::attempts_table() . ' WHERE id=%d',
    (int) $paidAttempt['id']
));
wfpms_callback_integration_assert($currentConfig !== null
    && (int) $currentConfig['id'] !== (int) $paidAttempt['config_id']
    && $persistedAttemptConfigId === (int) $paidAttempt['config_id'], 'Merchant key rotation appends a new seller configuration while keeping the issued attempt pinned.');
update_option('wfpms_sales_paused', 'yes', false);
$paidFields = $sendApproved($paidAttempt, (string) $paidAttempt['expected_amount'], (string) $paidAttempt['currency'], 'AUTH-TEST', $secret);
$paidPurchase = PurchaseRepository::for_order((int) $paidOrder->get_id());
wfpms_callback_integration_assert($paidPurchase !== null
    && (string) $paidPurchase['state'] === 'paid'
    && (int) $paidPurchase['accepted_receipt_id'] > 0, 'Matching Approved callback for an already-issued payment is processed while new sales are paused.');
delete_option('wfpms_sales_paused');
$receiptCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $paidPurchase['id']
));
$eventCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $paidPurchase['id'], 'callback_Approved'
));
wfpms_callback_integration_assert($receiptCount === 1 && $eventCount === 1, 'Accepted callback stores one receipt and one event.');

$sendApproved($paidAttempt, (string) $paidFields['amount'], (string) $paidFields['currency'], 'AUTH-TEST', $secret);
$receiptCountAfterRetry = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $paidPurchase['id']
));
$eventCountAfterRetry = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $paidPurchase['id'], 'callback_Approved'
));
wfpms_callback_integration_assert($receiptCountAfterRetry === 1 && $eventCountAfterRetry === 1, 'Repeated identical Approved callback is idempotent.');

$executionArgs = [(int) $paidPurchase['id']];
$paidOrderId = (int) $paidOrder->get_id();
$completedTransitions = 0;
$paymentCompletions = 0;
$virtualProcessingTransitions = 0;
add_action('woocommerce_order_status_processing', static function (int $id) use ($paidOrderId, &$virtualProcessingTransitions): void {
    if ($id === $paidOrderId) { $virtualProcessingTransitions++; }
});
add_action('woocommerce_order_status_completed', static function (int $orderId) use ($paidOrderId, &$completedTransitions): void {
    if ($orderId === $paidOrderId) {
        $completedTransitions++;
    }
});
add_action('woocommerce_payment_complete', static function (int $orderId) use ($paidOrderId, &$paymentCompletions): void {
    if ($orderId === $paidOrderId) {
        $paymentCompletions++;
    }
});
wfpms_callback_integration_assert($paidOrder->needs_processing(),
    'Before authorized execution, a virtual non-downloadable service follows native needs_processing, including its cache.');
PurchaseExecutionService::run((int) $paidPurchase['id']);
wp_clear_scheduled_hook('wfpms_process_execution', $executionArgs);
$paidOrder = wc_get_order($paidOrderId);
$paidPurchase = PurchaseRepository::for_order($paidOrderId);
$paidOrderStatus = $paidOrder instanceof WC_Order ? $paidOrder->get_status() : 'missing';
$paidExecutionState = (string) ($paidPurchase['execution_state'] ?? 'missing');
$paidExecutionError = (string) ($paidPurchase['execution_error_code'] ?? '');
wfpms_callback_integration_assert($paidOrder instanceof WC_Order && $paidOrder->get_status() === 'completed'
    && $completedTransitions === 1 && $paymentCompletions === 1 && $virtualProcessingTransitions === 0
    && $paidPurchase !== null && $paidExecutionState === 'done', 'Accepted payment completion state: order=' . $paidOrderStatus
    . ', execution=' . $paidExecutionState . ', error=' . $paidExecutionError . '.');
$executionCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $paidPurchase['id'], 'execution_done'
));
wfpms_callback_integration_assert($executionCount === 1, 'WooCommerce payment completion is recorded once.');
$firstAcceptedReceiptId = (int) $paidPurchase['accepted_receipt_id'];
$sendApproved($paidAttempt, (string) $paidFields['amount'], (string) $paidFields['currency'], 'AUTH-SECOND', $secret);
$paidPurchase = PurchaseRepository::for_order($paidOrderId);
$sameReferenceReceipts = $wpdb->get_results($wpdb->prepare(
    'SELECT id,external_id,provider_reference,classification FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d ORDER BY id ASC',
    (int) $paidPurchase['id']
), ARRAY_A);
$distinctEventCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $paidPurchase['id'], 'callback_Approved'
));
wfpms_callback_integration_assert($paidPurchase !== null
    && (string) $paidPurchase['state'] === 'paid_review'
    && (string) $paidPurchase['execution_state'] === 'done'
    && (int) $paidPurchase['accepted_receipt_id'] === $firstAcceptedReceiptId
    && is_array($sameReferenceReceipts) && count($sameReferenceReceipts) === 1
    && (string) $sameReferenceReceipts[0]['provider_reference'] === (string) $paidAttempt['external_reference']
    && (string) $sameReferenceReceipts[0]['classification'] === 'accepted'
    && $distinctEventCount === 2, 'Distinct signed Approved evidence on the same reference is retained for review without changing accepted payment or replaying fulfillment.');
$conflictEvent = $wpdb->get_row($wpdb->prepare(
    'SELECT receipt_id,evidence FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND dedupe_key<>%s ORDER BY id DESC LIMIT 1',
    (int) $paidPurchase['id'], hash('sha256', implode(';', array_map('strval', [
        'integration-merchant', $paidAttempt['external_reference'], $paidFields['amount'], $paidFields['currency'],
        'AUTH-TEST', $paidFields['cardPan'], 'Approved', '1100',
    ])))
), ARRAY_A);
wfpms_callback_integration_assert(is_array($conflictEvent) && (int) $conflictEvent['receipt_id'] === 0
    && str_contains((string) $conflictEvent['evidence'], 'same_attempt_conflict'),
    'Changed signed callback for the same attempt is stored as an exception without inventing a second receipt.');
$sendApproved($paidAttempt, (string) $paidFields['amount'], (string) $paidFields['currency'], 'AUTH-SECOND', $secret);
wfpms_callback_integration_assert((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $paidPurchase['id']
)) === 1, 'Retry of the conflicting signed callback does not create a receipt.');
$sendApproved($paidAttempt, (string) $paidFields['amount'], (string) $paidFields['currency'], 'AUTH-TEST', $secret);
PurchaseExecutionService::run((int) $paidPurchase['id']);
$paidPurchase = PurchaseRepository::for_order((int) $paidOrder->get_id());
wfpms_callback_integration_assert($paidPurchase !== null && (string) $paidPurchase['execution_state'] === 'done'
    && wc_get_order($paidOrderId)->get_status() === 'completed'
    && $completedTransitions === 1 && $paymentCompletions === 1 && $virtualProcessingTransitions === 0
    && (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
        (int) $paidPurchase['id'], 'execution_done'
    )) === 1, 'Repeated callback after fulfillment does not execute payment completion again.');

echo "PASS: accepted card virtual service becomes completed once; duplicate callback does not repeat completion.\n";
[$physicalOrder, $physicalPurchase, $physicalAttempt] = $createAttempt([$physicalProduct]);
$physicalOrderId = (int) $physicalOrder->get_id();
$physicalCompletions = 0;
$physicalPaymentCompletions = 0;
$physicalProcessingTransitions = 0;
add_action('woocommerce_order_status_completed', static function (int $id) use ($physicalOrderId, &$physicalCompletions): void {
    if ($id === $physicalOrderId) {
        $physicalCompletions++;
    }
});
add_action('woocommerce_payment_complete', static function (int $id) use ($physicalOrderId, &$physicalPaymentCompletions): void {
    if ($id === $physicalOrderId) { $physicalPaymentCompletions++; }
});
add_action('woocommerce_order_status_processing', static function (int $id) use ($physicalOrderId, &$physicalProcessingTransitions): void {
    if ($id === $physicalOrderId) { $physicalProcessingTransitions++; }
});
wfpms_callback_integration_assert(wc_get_order($physicalOrderId)->get_status() === 'pending',
    'Selecting card for a physical purchase leaves it pending before payment confirmation.');
$sendApproved($physicalAttempt, (string) $physicalAttempt['expected_amount'], (string) $physicalAttempt['currency']);
PurchaseExecutionService::run((int) $physicalPurchase['id']);
PurchaseExecutionService::run((int) $physicalPurchase['id']);
$sendApproved($physicalAttempt, (string) $physicalAttempt['expected_amount'], (string) $physicalAttempt['currency']);
PurchaseExecutionService::run((int) $physicalPurchase['id']);
wp_clear_scheduled_hook('wfpms_process_execution', [(int) $physicalPurchase['id']]);
wfpms_callback_integration_assert(wc_get_order($physicalOrderId)->get_status() === 'completed'
    && $physicalCompletions === 1 && $physicalPaymentCompletions === 1 && $physicalProcessingTransitions === 0,
    'Confirmed physical card purchase reaches completed once; duplicate callback and runner do not repeat completion.');
echo "PASS: confirmed physical card purchase completed once; duplicate callback and runner are harmless.\n";
[$mixedOrder, $mixedPurchase, $mixedAttempt] = $createAttempt([$product, $physicalProduct]);
$mixedOrderId = (int) $mixedOrder->get_id();
$mixedCompletions = 0;
$mixedPaymentCompletions = 0;
$mixedProcessingTransitions = 0;
add_action('woocommerce_order_status_completed', static function (int $id) use ($mixedOrderId, &$mixedCompletions): void {
    if ($id === $mixedOrderId) { $mixedCompletions++; }
});
add_action('woocommerce_payment_complete', static function (int $id) use ($mixedOrderId, &$mixedPaymentCompletions): void {
    if ($id === $mixedOrderId) { $mixedPaymentCompletions++; }
});
add_action('woocommerce_order_status_processing', static function (int $id) use ($mixedOrderId, &$mixedProcessingTransitions): void {
    if ($id === $mixedOrderId) { $mixedProcessingTransitions++; }
});
wfpms_callback_integration_assert(wc_get_order($mixedOrderId)->get_status() === 'pending',
    'Selecting card for a mixed purchase leaves it pending before payment confirmation.');
$sendApproved($mixedAttempt, (string) $mixedAttempt['expected_amount'], (string) $mixedAttempt['currency']);
PurchaseExecutionService::run((int) $mixedPurchase['id']);
$sendApproved($mixedAttempt, (string) $mixedAttempt['expected_amount'], (string) $mixedAttempt['currency']);
PurchaseExecutionService::run((int) $mixedPurchase['id']);
wp_clear_scheduled_hook('wfpms_process_execution', [(int) $mixedPurchase['id']]);
wfpms_callback_integration_assert(wc_get_order($mixedOrderId)->get_status() === 'completed'
    && $mixedCompletions === 1 && $mixedPaymentCompletions === 1 && $mixedProcessingTransitions === 0,
    'Confirmed mixed card purchase completes once without processing or repeated completion.');
echo "PASS: confirmed mixed card purchase completed once without processing; duplicate callback and runner are harmless.\n";
$unmanagedOrder = wc_create_order();
$unmanagedOrder->add_product($product, 1);
$unmanagedOrder->calculate_totals();
$unmanagedOrder->save();
$unmanagedOrder->payment_complete();
wfpms_callback_integration_assert(wc_get_order((int) $unmanagedOrder->get_id())->get_status() === 'processing',
    'Unmanaged virtual services retain native WooCommerce completion policy.');
echo "PASS: unmanaged virtual purchases retain native WooCommerce processing.\n";

[$wrongAmountOrder, $wrongAmountPurchase, $wrongAmountAttempt] = $createAttempt();
$wrongAmountOrderId = (int) $wrongAmountOrder->get_id();
$exceptionCompletions = 0;
add_action('woocommerce_order_status_completed', static function (int $id) use ($wrongAmountOrderId, &$exceptionCompletions): void {
    if ($id === $wrongAmountOrderId) {
        $exceptionCompletions++;
    }
});
$wrongAmount = number_format((float) $wrongAmountAttempt['expected_amount'] + 100, (int) $wrongAmountAttempt['amount_scale'], '.', '');
$sendApproved($wrongAmountAttempt, $wrongAmount, (string) $wrongAmountAttempt['currency']);
$wrongAmountPurchase = PurchaseRepository::for_order((int) $wrongAmountOrder->get_id());
wfpms_callback_integration_assert($wrongAmountPurchase !== null, 'Wrong-amount callback purchase remains available.');
$wrongAmountReceipt = $wpdb->get_row($wpdb->prepare(
    'SELECT classification,amount_raw FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $wrongAmountPurchase['id']
), ARRAY_A);
$wrongAmountOrder = wc_get_order((int) $wrongAmountOrder->get_id());
PurchaseExecutionService::run((int) $wrongAmountPurchase['id']);
wfpms_callback_integration_assert($wrongAmountPurchase !== null
    && (string) $wrongAmountPurchase['state'] === 'review'
    && (int) $wrongAmountPurchase['accepted_receipt_id'] === 0
    && is_array($wrongAmountReceipt) && (string) $wrongAmountReceipt['classification'] === 'needs_review'
    && (string) $wrongAmountReceipt['amount_raw'] === $wrongAmount
    && $wrongAmountOrder instanceof WC_Order && !$wrongAmountOrder->is_paid()
    && wc_get_order($wrongAmountOrderId)->get_status() !== 'completed'
    && $exceptionCompletions === 0, 'Financial exception does not complete the educational purchase or emit completed.');
echo "PASS: financial callback exception does not complete an educational purchase.\n";
$sendApproved($wrongAmountAttempt, $wrongAmount, (string) $wrongAmountAttempt['currency']);
wfpms_callback_integration_assert((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $wrongAmountPurchase['id']
)) === 1 && (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d',
    (int) $wrongAmountPurchase['id']
)) === 1, 'Retry of a signed wrong-amount callback preserves one review receipt and does not duplicate the event.');

[$wrongCurrencyOrder, $wrongCurrencyPurchase, $wrongCurrencyAttempt] = $createAttempt();
$wrongCurrency = (string) $wrongCurrencyAttempt['currency'] === 'UAH' ? 'USD' : 'UAH';
$sendApproved($wrongCurrencyAttempt, (string) $wrongCurrencyAttempt['expected_amount'], $wrongCurrency);
$wrongCurrencyPurchase = PurchaseRepository::for_order((int) $wrongCurrencyOrder->get_id());
wfpms_callback_integration_assert($wrongCurrencyPurchase !== null, 'Wrong-currency callback purchase remains available.');
$wrongCurrencyReceipt = $wpdb->get_row($wpdb->prepare(
    'SELECT classification,currency FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $wrongCurrencyPurchase['id']
), ARRAY_A);
$wrongCurrencyOrder = wc_get_order((int) $wrongCurrencyOrder->get_id());
wfpms_callback_integration_assert($wrongCurrencyPurchase !== null
    && (string) $wrongCurrencyPurchase['state'] === 'review'
    && (int) $wrongCurrencyPurchase['accepted_receipt_id'] === 0
    && is_array($wrongCurrencyReceipt) && (string) $wrongCurrencyReceipt['classification'] === 'needs_review'
    && (string) $wrongCurrencyReceipt['currency'] === $wrongCurrency
    && $wrongCurrencyOrder instanceof WC_Order && !$wrongCurrencyOrder->is_paid(), 'Approved callback with the wrong currency is recorded for review and does not pay the order.');

foreach (['Refunded', 'Voided'] as $terminalStatus) {
    [$terminalOrder, $terminalPurchase, $terminalAttempt] = $createAttempt();
    $sendApproved($terminalAttempt, (string) $terminalAttempt['expected_amount'], (string) $terminalAttempt['currency'],
        'AUTH-' . strtoupper($terminalStatus), null, null, $terminalStatus);
    $sendApproved($terminalAttempt, (string) $terminalAttempt['expected_amount'], (string) $terminalAttempt['currency'],
        'AUTH-LATE-APPROVAL');
    $terminalPurchase = PurchaseRepository::for_order((int) $terminalOrder->get_id());
    $terminalOrder = wc_get_order((int) $terminalOrder->get_id());
    wfpms_callback_integration_assert($terminalPurchase !== null
        && (string) $terminalPurchase['state'] === 'review'
        && (int) $terminalPurchase['accepted_receipt_id'] === 0
        && !in_array((string) $terminalPurchase['execution_state'], ['running', 'done'], true)
        && $terminalOrder instanceof WC_Order && !$terminalOrder->is_paid(),
        'A signed ' . $terminalStatus . ' followed by Approved on the same unexpired attempt remains review-only.');
}

[$reversedAcceptedOrder, $reversedAcceptedPurchase, $reversedAcceptedAttempt] = $createAttempt();
$sendApproved($reversedAcceptedAttempt, (string) $reversedAcceptedAttempt['expected_amount'], (string) $reversedAcceptedAttempt['currency'], 'AUTH-ACCEPTED-THEN-REFUNDED');
$sendApproved($reversedAcceptedAttempt, (string) $reversedAcceptedAttempt['expected_amount'], (string) $reversedAcceptedAttempt['currency'], 'AUTH-REFUND', null, null, 'Refunded');
$reversedAcceptedPurchase = PurchaseRepository::for_order((int) $reversedAcceptedOrder->get_id());
wfpms_callback_integration_assert($reversedAcceptedPurchase !== null
    && (string) $reversedAcceptedPurchase['state'] === 'paid_review'
    && !PurchaseExecutionService::is_financially_accepted($reversedAcceptedPurchase),
    'A refund after accepted payment blocks further fulfillment.');
$sendApproved($reversedAcceptedAttempt, (string) $reversedAcceptedAttempt['expected_amount'], (string) $reversedAcceptedAttempt['currency'], 'AUTH-LATE-AFTER-REFUND');
$reversedAcceptedPurchase = PurchaseRepository::for_order((int) $reversedAcceptedOrder->get_id());
wfpms_callback_integration_assert($reversedAcceptedPurchase !== null
    && !PurchaseExecutionService::is_financially_accepted($reversedAcceptedPurchase),
    'A late Approved callback cannot revive fulfillment after a prior refund.');

[$distinctAttemptsOrder, $distinctAttemptsPurchase, $firstDistinctAttempt] = $createAttempt();
$wpdb->update(PurchaseRepository::attempts_table(), ['status' => 'expired'], ['id' => (int) $firstDistinctAttempt['id']]);
$secondAttemptResult = $gateway->process_payment((int) $distinctAttemptsOrder->get_id());
wfpms_callback_integration_assert(($secondAttemptResult['result'] ?? '') === 'success',
    'A second card attempt can be issued for the same pending purchase after the first attempt is no longer active.');
$secondDistinctAttempt = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . PurchaseRepository::attempts_table() . ' WHERE purchase_id=%d ORDER BY id DESC LIMIT 1',
    (int) $distinctAttemptsPurchase['id']
), ARRAY_A);
wfpms_callback_integration_assert(is_array($secondDistinctAttempt)
    && (string) $secondDistinctAttempt['external_reference'] !== (string) $firstDistinctAttempt['external_reference'],
    'The replacement attempt has its own external reference.');
$sendApproved($firstDistinctAttempt, (string) $firstDistinctAttempt['expected_amount'], (string) $firstDistinctAttempt['currency'], 'AUTH-FIRST');
$sendApproved($secondDistinctAttempt, (string) $secondDistinctAttempt['expected_amount'], (string) $secondDistinctAttempt['currency'], 'AUTH-SECOND-REF');
$distinctAttemptsPurchase = PurchaseRepository::for_order((int) $distinctAttemptsOrder->get_id());
$distinctAttemptReceipts = $wpdb->get_results($wpdb->prepare(
    'SELECT id,provider_reference,classification FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d ORDER BY id ASC',
    (int) $distinctAttemptsPurchase['id']
), ARRAY_A);
$distinctAttemptsExecutionArgs = [(int) $distinctAttemptsPurchase['id']];
$executionWasScheduled = (bool) wp_next_scheduled('wfpms_process_execution', $distinctAttemptsExecutionArgs);
$distinctAttemptsPurchase = PurchaseRepository::for_order((int) $distinctAttemptsOrder->get_id());
$executionWasAlreadyCompleted = $distinctAttemptsPurchase !== null
    && (string) $distinctAttemptsPurchase['execution_state'] === 'done';
if (!$executionWasScheduled && !$executionWasAlreadyCompleted
    && $distinctAttemptsPurchase !== null
    && PurchaseExecutionService::is_financially_accepted($distinctAttemptsPurchase)) {
    // Isolate the scheduler from background WP-Cron while still exercising its paid_review path.
    PurchaseExecutionService::schedule((int) $distinctAttemptsPurchase['id']);
    $executionWasScheduled = (bool) wp_next_scheduled('wfpms_process_execution', $distinctAttemptsExecutionArgs);
}
$executionDiagnostic = $distinctAttemptsPurchase === null ? 'purchase=missing' : sprintf(
    'state=%s execution=%s error=%s accepted_receipt=%d eligible=%s scheduled=%s',
    (string) $distinctAttemptsPurchase['state'],
    (string) $distinctAttemptsPurchase['execution_state'],
    (string) $distinctAttemptsPurchase['execution_error_code'],
    (int) $distinctAttemptsPurchase['accepted_receipt_id'],
    PurchaseExecutionService::is_financially_accepted($distinctAttemptsPurchase) ? 'yes' : 'no',
    $executionWasScheduled ? 'yes' : 'no'
);
wfpms_callback_integration_assert($executionWasScheduled || $executionWasAlreadyCompleted,
    'The accepted receipt remains eligible for the scheduled runner in paid_review (' . $executionDiagnostic . ').');
wfpms_callback_integration_assert(is_array($distinctAttemptReceipts) && count($distinctAttemptReceipts) === 2
    && (string) $distinctAttemptReceipts[0]['provider_reference'] === (string) $firstDistinctAttempt['external_reference']
    && (string) $distinctAttemptReceipts[1]['provider_reference'] === (string) $secondDistinctAttempt['external_reference']
    && (string) $distinctAttemptReceipts[0]['classification'] === 'accepted'
    && (string) $distinctAttemptReceipts[1]['classification'] === 'needs_review'
    && $distinctAttemptsPurchase !== null && (int) $distinctAttemptsPurchase['accepted_receipt_id'] === (int) $distinctAttemptReceipts[0]['id'],
    'Two distinct signed Approved attempts retain both receipts, accepting only the first.');
// The scheduled runner may have fired already; duplicate delivery must stay harmless either way.
do_action('wfpms_process_execution', (int) $distinctAttemptsPurchase['id']);
do_action('wfpms_process_execution', (int) $distinctAttemptsPurchase['id']);
wp_clear_scheduled_hook('wfpms_process_execution', $distinctAttemptsExecutionArgs);
$distinctAttemptsPurchase = PurchaseRepository::for_order((int) $distinctAttemptsOrder->get_id());
$distinctAttemptsOrder = wc_get_order((int) $distinctAttemptsOrder->get_id());
wfpms_callback_integration_assert($distinctAttemptsPurchase !== null
    && (string) $distinctAttemptsPurchase['execution_state'] === 'done'
    && $distinctAttemptsOrder instanceof WC_Order && $distinctAttemptsOrder->is_paid()
    && (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
        (int) $distinctAttemptsPurchase['id'], 'execution_done'
    )) === 1,
    'The scheduled execution pays once and a duplicate execution action is ignored.');

// Only disposable fixtures change their clock; operators must wait for the original deadline.
$expiryCalls = 0;
$expiryCompleted = 0;
$expiryOrderIds = [];
add_action('woocommerce_payment_complete', static function (int $id) use (&$expiryCalls, &$expiryOrderIds): void {
    if (in_array($id, $expiryOrderIds, true)) { $expiryCalls++; }
});
add_action('woocommerce_order_status_completed', static function (int $id) use (&$expiryCompleted, &$expiryOrderIds): void {
    if (in_array($id, $expiryOrderIds, true)) { $expiryCompleted++; }
});
[$untouchedOrder, $untouchedPurchase] = $createAttempt();
$past = gmdate('Y-m-d H:i:s', time() - 60);
$wpdb->update(PurchaseRepository::purchases_table(), ['expires_at' => $past], ['id' => (int) $untouchedPurchase['id']]);
foreach (['card', 'card_declined', 'card_expired', 'iban'] as $channel) {
    if (str_starts_with($channel, 'card')) {
        [$expiryOrder, $expiryPurchase, $expiryAttempt] = $createAttempt();
        if ($channel !== 'card') {
            $sendApproved($expiryAttempt, (string) $expiryAttempt['expected_amount'], (string) $expiryAttempt['currency'],
                'BEFORE-EXPIRY', null, null, $channel === 'card_declined' ? 'Declined' : 'Expired');
        }
    } else {
        $expiryOrder = wc_create_order();
        $expiryOrder->add_product($product, 1);
        $expiryOrder->set_payment_method('wfpms_iban');
        $expiryOrder->calculate_totals();
        $expiryOrder->save();
        $ibanResult = WC()->payment_gateways()->payment_gateways()['wfpms_iban']->process_payment((int) $expiryOrder->get_id());
        wfpms_callback_integration_assert(($ibanResult['result'] ?? '') === 'success', 'Expiry IBAN fixture issues a real attempt.');
        $expiryPurchase = PurchaseRepository::for_order((int) $expiryOrder->get_id());
        $expiryOrder->update_status('cancelled'); // The actual staging IBAN condition.
    }
    $expiryId = (int) $expiryPurchase['id'];
    $expiryOrderIds[] = (int) $expiryOrder->get_id();
    $notDue = PurchaseExecutionService::expire_unpaid_purchase($expiryId);
    wfpms_callback_integration_assert($notDue['reason'] === 'not_due', 'Targeted expiry cannot close before the pinned deadline.');
    $wpdb->update(PurchaseRepository::purchases_table(), ['expires_at' => $past], ['id' => $expiryId]);
    $before = PurchaseRepository::for_order((int) $expiryOrder->get_id());
    $attemptsBefore = PurchaseRepository::attempts_for_purchase($expiryId);
    $configsBefore = array_map(static fn(array $a) => SellerRepository::config((int) $a['config_id']), $attemptsBefore);
    $preview = PurchaseExecutionService::expire_unpaid_purchase($expiryId);
    wfpms_callback_integration_assert($preview['eligible'] && $preview['result'] === 'preview'
        && PurchaseRepository::for_order((int) $expiryOrder->get_id()) === $before
        && PurchaseRepository::attempts_for_purchase($expiryId) === $attemptsBefore,
        'Dry-run returns an eligible locked preview without modifying purchase or attempts.');
    $applied = PurchaseExecutionService::expire_unpaid_purchase($expiryId, false, $preview['preview_hash']);
    wfpms_callback_integration_assert($applied['result'] === 'expired'
        && PurchaseRepository::for_order((int) $expiryOrder->get_id())['state'] === 'expired'
        && wc_get_order((int) $expiryOrder->get_id())->get_status() === 'cancelled',
        'Addressed ' . $channel . ' expiry closes only the unpaid due purchase.');
    foreach (PurchaseRepository::attempts_for_purchase($expiryId) as $index => $a) {
        $original = $attemptsBefore[$index];
        $original['status'] = $original['status'] === 'issued' ? 'expired' : $original['status'];
        wfpms_callback_integration_assert($a === $original
            && SellerRepository::config((int) $a['config_id']) === $configsBefore[$index],
            'Expiry preserves every reference, pinned credential, payload and historical config.');
    }
    $repeat = PurchaseExecutionService::expire_unpaid_purchase($expiryId);
    wfpms_callback_integration_assert(PurchaseExecutionService::expire_unpaid_purchase($expiryId, false, $repeat['preview_hash'])['result'] === 'already_expired'
        && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . PurchaseRepository::events_table()
            . ' WHERE purchase_id=%d AND kind=%s', $expiryId, 'payment_deadline_expired')) === 1,
        'Repeated addressed expiry preserves one deadline event.');
    if (str_starts_with($channel, 'card')) {
        $sendApproved($expiryAttempt, (string) $expiryAttempt['expected_amount'], (string) $expiryAttempt['currency'], 'LATE-AFTER-ADDRESSSED-EXPIRY');
        $sendApproved($expiryAttempt, (string) $expiryAttempt['expected_amount'], (string) $expiryAttempt['currency'], 'LATE-AFTER-ADDRESSSED-EXPIRY');
        $fresh = PurchaseRepository::for_order((int) $expiryOrder->get_id());
        wfpms_callback_integration_assert($fresh['state'] === 'review' && (int) $fresh['accepted_receipt_id'] === 0
            && $fresh['execution_state'] === 'none'
            && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table()
                . ' WHERE purchase_id=%d AND classification=%s', $expiryId, 'needs_review')) === 1,
            'Late authentic Approved is retained once for manual review after targeted closure.');
    }
    PurchaseExecutionService::run($expiryId);
    PurchaseExecutionService::run($expiryId);
}
wfpms_callback_integration_assert($expiryCalls === 0 && $expiryCompleted === 0
    && PurchaseRepository::for_order((int) $untouchedOrder->get_id())['state'] === 'awaiting_payment',
    'Targeted expiry and late callbacks cause zero completion and leave unrelated due purchases alone.');
echo "PASS: addressed card/IBAN expiry, read-only locked preview, immutable history, repeat idempotence, late HTTP Approved review and zero completion.\n";

[$raceOrder, $racePurchase, $raceAttempt] = $createAttempt();
$racePreview = PurchaseExecutionService::expire_unpaid_purchase((int) $racePurchase['id']);
$sendApproved($raceAttempt, (string) $raceAttempt['expected_amount'], (string) $raceAttempt['currency'], 'PAID-BETWEEN-PREVIEW-APPLY');
$raceApply = PurchaseExecutionService::expire_unpaid_purchase((int) $racePurchase['id'], false, $racePreview['preview_hash']);
wfpms_callback_integration_assert(!$raceApply['eligible'] && $raceApply['reason'] === 'preview_changed'
    && PurchaseRepository::for_order((int) $raceOrder->get_id())['state'] === 'paid',
    'A payment between preview and apply blocks stale closure under the order lock.');
// Explicitly exercise each financial guard on synthetic inconsistent rows.
foreach (['receipt', 'accepted', 'execution', 'started'] as $guard) {
    [$guardOrder, $guardPurchase, $guardAttempt] = $createAttempt();
    $guardId = (int) $guardPurchase['id'];
    $wpdb->update(PurchaseRepository::purchases_table(), ['expires_at' => $past], ['id' => $guardId]);
    if ($guard === 'receipt') {
        $sendApproved($guardAttempt, '9999.99', (string) $guardAttempt['currency'], 'GUARD-REVIEW');
        $wpdb->update(PurchaseRepository::purchases_table(), ['state' => 'awaiting_payment'], ['id' => $guardId]);
    } else {
        $change = match ($guard) {
            'accepted' => ['accepted_receipt_id' => 999999],
            'execution' => ['execution_state' => 'pending'],
            'started' => ['execution_started_at' => $past],
        };
        $wpdb->update(PurchaseRepository::purchases_table(), $change, ['id' => $guardId]);
    }
    $guardPreview = PurchaseExecutionService::expire_unpaid_purchase($guardId);
    $guardBefore = PurchaseRepository::for_order((int) $guardOrder->get_id());
    $guardApply = PurchaseExecutionService::expire_unpaid_purchase($guardId, false, $guardPreview['preview_hash']);
    wfpms_callback_integration_assert(!$guardApply['eligible'] && $guardApply['reason'] === 'financial_or_execution_evidence'
        && PurchaseRepository::for_order((int) $guardOrder->get_id()) === $guardBefore,
        'Addressed expiry refuses ' . $guard . ' evidence and performs no financial mutation.');
}
echo "PASS: expiry rejects stale previews, receipts, accepted receipt IDs and pending/previous execution under the order lock.\n";
echo "PASS: signed callback retries, invalid signatures, wrong-merchant review, same-attempt conflict exceptions, credential rotation, amount and currency mismatches, terminal-status protection, distinct-attempt review, and one-time WooCommerce payment completion.\n";
