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
    'iban_enabled' => 0,
    'secret_plaintext' => $secret,
    'operator_checked' => false,
], SecretStore::encrypt($secret), $adminId);
update_post_meta($productId, ProductRouting::META_SELLER_ID, $sellerId);

$gateway = WC()->payment_gateways()->payment_gateways()['wfpms_card'] ?? null;
wfpms_callback_integration_assert($gateway instanceof WayForPayGateway, 'WayForPay card gateway is registered.');

$createAttempt = static function () use ($product, $gateway): array {
    global $wpdb;
    $order = wc_create_order();
    $order->set_billing_email('callback-integration@example.test');
    $order->add_product($product, 1);
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
    'iban_enabled' => 0,
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
$paidFields = $sendApproved($paidAttempt, (string) $paidAttempt['expected_amount'], (string) $paidAttempt['currency'], 'AUTH-TEST', $secret);
$paidPurchase = PurchaseRepository::for_order((int) $paidOrder->get_id());
wfpms_callback_integration_assert($paidPurchase !== null
    && (string) $paidPurchase['state'] === 'paid'
    && (int) $paidPurchase['accepted_receipt_id'] > 0, 'Matching Approved callback records one accepted payment.');
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
PurchaseExecutionService::run((int) $paidPurchase['id']);
wp_clear_scheduled_hook('wfpms_process_execution', $executionArgs);
$paidOrderId = (int) $paidOrder->get_id();
$paidOrder = wc_get_order($paidOrderId);
$paidPurchase = PurchaseRepository::for_order($paidOrderId);
$paidOrderStatus = $paidOrder instanceof WC_Order ? $paidOrder->get_status() : 'missing';
$paidExecutionState = (string) ($paidPurchase['execution_state'] ?? 'missing');
$paidExecutionError = (string) ($paidPurchase['execution_error_code'] ?? '');
wfpms_callback_integration_assert($paidOrder instanceof WC_Order && $paidOrder->is_paid()
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
$paidPurchase = PurchaseRepository::for_order((int) $paidOrder->get_id());
wfpms_callback_integration_assert($paidPurchase !== null && (string) $paidPurchase['execution_state'] === 'done'
    && (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
        (int) $paidPurchase['id'], 'execution_done'
    )) === 1, 'Repeated callback after fulfillment does not execute payment completion again.');

[$wrongAmountOrder, $wrongAmountPurchase, $wrongAmountAttempt] = $createAttempt();
$wrongAmount = number_format((float) $wrongAmountAttempt['expected_amount'] + 100, (int) $wrongAmountAttempt['amount_scale'], '.', '');
$sendApproved($wrongAmountAttempt, $wrongAmount, (string) $wrongAmountAttempt['currency']);
$wrongAmountPurchase = PurchaseRepository::for_order((int) $wrongAmountOrder->get_id());
wfpms_callback_integration_assert($wrongAmountPurchase !== null, 'Wrong-amount callback purchase remains available.');
$wrongAmountReceipt = $wpdb->get_row($wpdb->prepare(
    'SELECT classification,amount_raw FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $wrongAmountPurchase['id']
), ARRAY_A);
$wrongAmountOrder = wc_get_order((int) $wrongAmountOrder->get_id());
wfpms_callback_integration_assert($wrongAmountPurchase !== null
    && (string) $wrongAmountPurchase['state'] === 'review'
    && (int) $wrongAmountPurchase['accepted_receipt_id'] === 0
    && is_array($wrongAmountReceipt) && (string) $wrongAmountReceipt['classification'] === 'needs_review'
    && (string) $wrongAmountReceipt['amount_raw'] === $wrongAmount
    && $wrongAmountOrder instanceof WC_Order && !$wrongAmountOrder->is_paid(), 'Approved callback with the wrong amount is recorded for review and does not pay the order.');
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
wfpms_callback_integration_assert($executionWasScheduled || $executionWasAlreadyCompleted,
    'The accepted callback queued execution or the scheduled runner already completed it.');
wp_clear_scheduled_hook('wfpms_process_execution', $distinctAttemptsExecutionArgs);
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

echo "PASS: signed callback retries, invalid signatures, wrong-merchant review, same-attempt conflict exceptions, credential rotation, amount and currency mismatches, terminal-status protection, distinct-attempt review, and one-time WooCommerce payment completion.\n";
