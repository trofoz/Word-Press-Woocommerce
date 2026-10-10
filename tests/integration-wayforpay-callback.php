<?php

use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\ProductRouting;
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

$sendApproved = static function (array $attempt, string $amount, string $currency) use ($secret): array {
    $fields = [
        'merchantAccount' => (string) $attempt['merchant_account'],
        'orderReference' => (string) $attempt['external_reference'],
        'amount' => $amount,
        'currency' => $currency,
        'authCode' => 'AUTH-TEST',
        'cardPan' => '411111******1111',
        'transactionStatus' => 'Approved',
        'reasonCode' => '1100',
    ];
    $fields['merchantSignature'] = hash_hmac('md5', implode(';', array_values($fields)), $secret);
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
            hash_hmac('md5', $fields['orderReference'] . ';accept;' . (string) $ack['time'], $secret),
            (string) $ack['signature']
        ), 'Callback acknowledges the signed event using the WayForPay response signature.');

    return $fields;
};

global $wpdb;
[$paidOrder, $paidPurchase, $paidAttempt] = $createAttempt();
$paidFields = $sendApproved($paidAttempt, (string) $paidAttempt['expected_amount'], (string) $paidAttempt['currency']);
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

$sendApproved($paidAttempt, (string) $paidFields['amount'], (string) $paidFields['currency']);
$receiptCountAfterRetry = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
    (int) $paidPurchase['id']
));
$eventCountAfterRetry = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $paidPurchase['id'], 'callback_Approved'
));
wfpms_callback_integration_assert($receiptCountAfterRetry === 1 && $eventCountAfterRetry === 1, 'Repeated identical Approved callback is idempotent.');

sleep(2);
$executionArgs = [(int) $paidPurchase['id']];
$scheduledExecution = wp_next_scheduled('wfpms_process_execution', $executionArgs);
wfpms_callback_integration_assert($scheduledExecution !== false, 'Accepted callback schedules the purchase execution hook.');
\WP_CLI::runcommand('cron event run wfpms_process_execution');
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
$sendApproved($paidAttempt, (string) $paidFields['amount'], (string) $paidFields['currency']);
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

echo "PASS: signed Approved callback, duplicate callback, amount and currency mismatches, and WooCommerce payment completion.\n";
