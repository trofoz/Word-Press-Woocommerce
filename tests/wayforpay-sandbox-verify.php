No syntax errors detected in tests/wayforpay-sandbox-verify.php
<?php

use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;

function wfpms_sandbox_verify_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$inputPath = getenv('WFPMS_SANDBOX_INPUT');
wfpms_sandbox_verify_assert(is_string($inputPath) && is_file($inputPath), 'Sandbox browser handoff file is missing.');
$manifest = json_decode((string) file_get_contents($inputPath), true);
wfpms_sandbox_verify_assert(is_array($manifest) && (int) ($manifest['order_id'] ?? 0) > 0, 'Sandbox handoff is invalid.');
$orderId = (int) $manifest['order_id'];
$purchase = PurchaseRepository::for_order($orderId);
wfpms_sandbox_verify_assert($purchase !== null, 'Sandbox purchase record is missing.');
global $wpdb;
$attempt = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . PurchaseRepository::attempts_table() . ' WHERE purchase_id=%d ORDER BY id DESC LIMIT 1',
    (int) $purchase['id']
), ARRAY_A);
wfpms_sandbox_verify_assert(is_array($attempt), 'Sandbox card attempt is missing.');
$config = SellerRepository::config((int) $attempt['config_id']);
$secret = $config === null ? null : SecretStore::decrypt((string) $config['secret_ciphertext']);
wfpms_sandbox_verify_assert(is_string($secret) && $secret !== '', 'Sandbox merchant credential could not be decrypted.');
$merchantAccount = (string) $attempt['merchant_account'];
$orderReference = (string) $attempt['external_reference'];
$statusRequest = [
    'transactionType' => 'CHECK_STATUS',
    'merchantAccount' => $merchantAccount,
    'orderReference' => $orderReference,
    'apiVersion' => 1,
];
$statusRequest['merchantSignature'] = hash_hmac('md5', $merchantAccount . ';' . $orderReference, $secret);
$providerStatus = null;
$lastProviderStatus = '';
$providerReasonCode = '';
$providerReason = '';
$lastStatusHttpCode = 0;
$lastStatusKeys = [];
$statusDeadline = time() + 120;
do {
    $statusResponse = wp_remote_post('https://api.wayforpay.com/api', [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($statusRequest),
    ]);
    if (!is_wp_error($statusResponse)) {
        $lastStatusHttpCode = wp_remote_retrieve_response_code($statusResponse);
    }
    if (!is_wp_error($statusResponse) && wp_remote_retrieve_response_code($statusResponse) === 200) {
        $statusData = json_decode((string) wp_remote_retrieve_body($statusResponse), true);
        if (is_array($statusData)) {
            $lastStatusKeys = array_values(array_filter(array_keys($statusData), 'is_string'));
            $providerReasonCode = (string) ($statusData['reasonCode'] ?? '');
            $providerReason = (string) ($statusData['reason'] ?? '');
        }
        if (is_array($statusData) && isset($statusData['transactionStatus'])) {
            wfpms_sandbox_verify_assert(
                hash_equals($merchantAccount, (string) ($statusData['merchantAccount'] ?? ''))
                    && hash_equals($orderReference, (string) ($statusData['orderReference'] ?? '')),
                'WayForPay status response does not match the sandbox attempt.'
            );
            $signatureFields = array_map(static fn(string $field): string => (string) ($statusData[$field] ?? ''), [
                'merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode',
            ]);
            $expectedSignature = hash_hmac('md5', implode(';', $signatureFields), $secret);
            wfpms_sandbox_verify_assert(
                hash_equals($expectedSignature, strtolower((string) ($statusData['merchantSignature'] ?? ''))),
                'WayForPay status response signature is invalid.'
            );
            $providerStatus = (string) $statusData['transactionStatus'];
            $lastProviderStatus = $providerStatus;
            if (in_array($providerStatus, ['Approved', 'Declined', 'Expired', 'Refunded', 'Voided'], true)) {
                break;
            }
        }
    }
    usleep(5_000_000);
} while (time() < $statusDeadline);

$safeReason = preg_replace('/https?:\/\/\S+|\d{6,}/i', '<redacted>', $providerReason);
echo 'SAFE_STATUS_DIAGNOSTIC http=' . $lastStatusHttpCode
    . ' status=' . preg_replace('/[^A-Za-z_-]/', '', $lastProviderStatus)
    . ' reasonCode=' . preg_replace('/[^0-9]/', '', $providerReasonCode)
    . ' reason=' . substr((string) $safeReason, 0, 120)
    . ' responseKeys=' . implode(',', $lastStatusKeys) . "\n";
if (in_array($providerStatus, ['Declined', 'InProcessing'], true)) {
    $purchase = PurchaseRepository::for_order($orderId);
    $order = wc_get_order($orderId);
    echo 'SAFE_ORDER_DIAGNOSTIC paid=' . (int) ($order instanceof WC_Order && $order->is_paid())
        . ' purchaseState=' . preg_replace('/[^A-Za-z_-]/', '', (string) ($purchase['state'] ?? 'missing'))
        . ' executionState=' . preg_replace('/[^A-Za-z_-]/', '', (string) ($purchase['execution_state'] ?? 'missing'))
        . ' acceptedReceipt=' . (int) ($purchase['accepted_receipt_id'] ?? 0) . "\n";
    wfpms_sandbox_verify_assert($order instanceof WC_Order && !$order->is_paid(), 'Unconfirmed sandbox payment must not mark the WooCommerce order paid.');
    $executionState = (string) ($purchase['execution_state'] ?? 'missing');
    wfpms_sandbox_verify_assert($purchase !== null
        && (string) $purchase['state'] !== 'paid'
        && (int) $purchase['accepted_receipt_id'] === 0
        && !in_array($executionState, ['running', 'done'], true), 'Unconfirmed sandbox payment must not issue or fulfill a paid receipt.');
    echo 'PASS: WayForPay status=' . $providerStatus . ' (reasonCode=' . $providerReasonCode
        . '); the order stayed unpaid and unfulfilled.' . "\n";
    exit;
}
wfpms_sandbox_verify_assert($providerStatus === 'Approved', 'WayForPay did not return a signed terminal sandbox payment status.');

$deadline = time() + 120;
do {
    $purchase = PurchaseRepository::for_order($orderId);
    if ($purchase !== null && (string) $purchase['state'] === 'paid') {
        break;
    }
    usleep(1_000_000);
} while (time() < $deadline);

wfpms_sandbox_verify_assert($purchase !== null && (string) $purchase['state'] === 'paid', 'WayForPay did not deliver an accepted payment callback within two minutes.');
wfpms_sandbox_verify_assert((int) $purchase['accepted_receipt_id'] > 0, 'Accepted callback receipt was not stored.');
$accepted = $wpdb->get_row($wpdb->prepare(
    'SELECT id,classification FROM ' . PurchaseRepository::receipts_table() . ' WHERE id=%d AND purchase_id=%d',
    (int) $purchase['accepted_receipt_id'],
    (int) $purchase['id']
), ARRAY_A);
wfpms_sandbox_verify_assert(is_array($accepted) && (string) $accepted['classification'] === 'accepted', 'Callback receipt was not classified as accepted.');
$approvedEventCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $purchase['id'],
    'callback_Approved'
));
wfpms_sandbox_verify_assert($approvedEventCount === 1, 'Signed Approved callback evidence was not stored exactly once.');

PurchaseExecutionService::run((int) $purchase['id']);
$order = wc_get_order($orderId);
$purchase = PurchaseRepository::for_order($orderId);
wfpms_sandbox_verify_assert($order instanceof WC_Order && $order->is_paid(), 'WooCommerce order did not reach its paid state.');
wfpms_sandbox_verify_assert($purchase !== null && (string) $purchase['execution_state'] === 'done', 'Payment completion did not finish exactly once.');
echo "PASS: WayForPay signed callback, accepted receipt, and WooCommerce payment completion verified.\n";
