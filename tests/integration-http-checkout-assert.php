<?php

use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;

$inputPath = getenv('WFPMS_HTTP_ORDER_IDS_PATH');
$outputPath = getenv('WFPMS_HTTP_ORDER_PAY_PATHS_PATH');
if (!is_string($inputPath) || !is_file($inputPath) || !is_string($outputPath) || $outputPath === '') {
    throw new RuntimeException('HTTP checkout result paths are missing.');
}
$ids = json_decode((string) file_get_contents($inputPath), true);
if (!is_array($ids)) {
    throw new RuntimeException('HTTP checkout did not produce order IDs.');
}

$orderPayPaths = [];
foreach (['classic_order_id', 'blocks_order_id'] as $key) {
    $orderId = (int) ($ids[$key] ?? 0);
    $order = $orderId > 0 ? wc_get_order($orderId) : false;
    $purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
    $snapshot = $purchase === null ? null : json_decode((string) $purchase['financial_snapshot'], true);
    if (!$order instanceof WC_Order || $order->is_paid() || $order->get_status() !== 'on-hold'
        || $order->get_payment_method() !== 'wfpms_iban' || $purchase === null
        || (string) $purchase['state'] !== 'awaiting_payment'
        || !is_array($snapshot) || ($snapshot['legal_name'] ?? '') !== 'Integration Seller 1') {
        throw new RuntimeException("HTTP {$key} did not create an unpaid order with the expected seller snapshot.");
    }
    $orderPayPaths[$key] = [
        'order_id' => $orderId,
        'url' => $order->get_checkout_payment_url(false),
    ];
}

$sellerId = (int) get_option('wfpms_integration_seller_one_id', 0);
$currentConfig = SellerRepository::current_config($sellerId);
if ($sellerId < 1 || $currentConfig === null) {
    throw new RuntimeException('Current seller configuration is unavailable for the order-pay mutation test.');
}
$now = current_time('mysql', true);
$wpdb = $GLOBALS['wpdb'];
if ($wpdb->insert(SellerRepository::configs_table(), [
    'seller_id' => $sellerId,
    'version' => (int) $currentConfig['version'] + 1,
    'recipient_name' => 'Changed Current Recipient',
    'tax_id' => (string) $currentConfig['tax_id'],
    'iban' => (string) $currentConfig['iban'],
    'bank_name' => (string) $currentConfig['bank_name'],
    'purpose_template' => (string) $currentConfig['purpose_template'],
    'merchant_account' => 'integration-only-card-merchant',
    'merchant_domain' => 'example.test',
    'card_enabled' => 1,
    'iban_enabled' => 0,
    'secret_ciphertext' => SecretStore::encrypt('integration-only-card-secret'),
    'checked_by' => 1,
    'checked_at' => $now,
    'created_at' => $now,
]) !== 1) {
    throw new RuntimeException('Could not write the changed-current-config fixture.');
}
$newConfigId = (int) $wpdb->insert_id;
$wpdb->update(SellerRepository::sellers_table(), ['current_config_id' => $newConfigId], ['id' => $sellerId]);
foreach ($orderPayPaths as &$orderData) {
    $orderData['url'] = wc_get_order((int) $orderData['order_id'])->get_checkout_payment_url(false);
}
unset($orderData);

if (file_put_contents($outputPath, wp_json_encode($orderPayPaths, JSON_UNESCAPED_SLASHES)) === false) {
    throw new RuntimeException('Could not write the order-pay URL fixtures.');
}
echo "PASS: HTTP-created Classic and Blocks orders preserve unpaid purchase snapshots after current seller config changes\n";
