<?php

use WfpMultiSeller\LegacyMigration;
use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;

function wfpms_deactivated_legacy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function wfpms_legacy_callback_payload(string $merchant, string $secret, string $reference, string $amount, string $currency): array
{
    $payload = [
        'merchantAccount' => $merchant,
        'orderReference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'authCode' => 'AUTH-LEGACY-DEACTIVATED',
        'cardPan' => '411111******1111',
        'transactionStatus' => 'Approved',
        'reasonCode' => '1100',
    ];
    $payload['merchantSignature'] = hash_hmac('md5', implode(';', array_map('strval', [
        $payload['merchantAccount'], $payload['orderReference'], $payload['amount'], $payload['currency'],
        $payload['authCode'], $payload['cardPan'], $payload['transactionStatus'], $payload['reasonCode'],
    ])), $secret);
    return $payload;
}

function wfpms_send_legacy_callback(array $payload): array
{
    $response = wp_remote_post(home_url('/?wc-api=WC_wayforpay'), [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
    ]);
    return [
        'response' => $response,
        'status' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
        'body' => is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response),
    ];
}

global $wpdb;
$sellerId = (int) get_option('wfpms_integration_legacy_seller_id', 0);
$configId = (int) get_option('wfpms_integration_legacy_config_id', 0);
$productId = (int) get_option('wfpms_integration_manual_product_id', 0);
$config = SellerRepository::config($configId);
$product = wc_get_product($productId);
wfpms_deactivated_legacy_assert($sellerId > 0 && $config !== null && $product instanceof WC_Product,
    'previous migration integration setup left the seller, pinned config, and product fixtures available');
wfpms_deactivated_legacy_assert(!class_exists('WC_wayforpay'), 'upstream WayForPay plugin is deactivated in this PHP process');
wfpms_deactivated_legacy_assert(LegacyMigration::owns_callback_route(), 'fork owns the historical callback route');
update_option('wfpms_legacy_transition_enabled', 'no', false);
$pinnedSecret = SecretStore::decrypt((string) $config['secret_ciphertext']);

$createMappedOrder = static function () use ($product, $sellerId): WC_Order {
    $order = wc_create_order();
    $order->set_status('pending');
    $order->set_payment_method('wayforpay');
    $order->add_product($product, 1);
    $order->calculate_totals();
    $order->save();
    OrderPurchaseService::migrate_legacy_order((int) $order->get_id(), $sellerId, 1);
    return $order;
};

// A numeric reference and the provider's historical suffixed format both resolve only via the explicit mapping.
foreach (['numeric', 'suffixed'] as $format) {
    $order = $createMappedOrder();
    $reference = $format === 'numeric' ? (string) $order->get_id() : (string) $order->get_id() . '_woo_w4p_7';
    $result = wfpms_send_legacy_callback(wfpms_legacy_callback_payload(
        (string) $config['merchant_account'], $pinnedSecret,
        $reference, (string) $order->get_total(), (string) $order->get_currency()
    ));
    $purchase = PurchaseRepository::for_order((int) $order->get_id());
    $attempt = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . PurchaseRepository::attempts_table() . ' WHERE external_reference=%s', $reference
    ), ARRAY_A);
    $receipts = $purchase === null ? [] : PurchaseRepository::receipts_for_purchase((int) $purchase['id']);
    wfpms_deactivated_legacy_assert($result['status'] === 200 && $attempt !== null
        && (int) $attempt['config_id'] === (int) $config['id'] && (string) $attempt['merchant_account'] === (string) $config['merchant_account']
        && count($receipts) === 1 && (string) $receipts[0]['classification'] === 'needs_review',
        $format . ' historical callback is recorded against its mapped pinned config and receipt is held for review');
}

// Destroy the pinned credential material and change the legacy option to a different valid key.
$missingKeyOrder = $createMappedOrder();
$wpdb->update(SellerRepository::configs_table(), ['secret_ciphertext' => 'unreadable-test-ciphertext'], ['id' => $configId]);
update_option('woocommerce_wayforpay_settings', [
    'enabled' => 'yes',
    'merchant_account' => (string) $config['merchant_account'],
    'secret_key' => 'different-legacy-fallback-key',
], false);
$missingKeyResult = wfpms_send_legacy_callback(wfpms_legacy_callback_payload(
    (string) $config['merchant_account'], 'different-legacy-fallback-key', (string) $missingKeyOrder->get_id(),
    (string) $missingKeyOrder->get_total(), (string) $missingKeyOrder->get_currency()
));
$missingKeyPurchase = PurchaseRepository::for_order((int) $missingKeyOrder->get_id());
wfpms_deactivated_legacy_assert($missingKeyResult['status'] === 400
    && ($missingKeyPurchase === null || PurchaseRepository::receipts_for_purchase((int) $missingKeyPurchase['id']) === []),
    'unavailable mapped pinned key rejects a callback signed by legacy settings and creates no receipt');

// The missing mapping is an operational inventory/review case. A 503 signals transport retry only;
// this test makes no claim about provider retry policy or eventual delivery.
$unknownOrder = wc_create_order();
$unknownOrder->set_status('pending');
$unknownOrder->set_payment_method('wayforpay');
$unknownOrder->add_product($product, 1);
$unknownOrder->calculate_totals();
$unknownOrder->save();
$unknownResult = wfpms_send_legacy_callback(wfpms_legacy_callback_payload(
    (string) $config['merchant_account'], 'different-legacy-fallback-key', (string) $unknownOrder->get_id() . '_woo_w4p_9',
    (string) $unknownOrder->get_total(), (string) $unknownOrder->get_currency()
));
wfpms_deactivated_legacy_assert($unknownResult['status'] === 503
    && PurchaseRepository::for_order((int) $unknownOrder->get_id()) === null,
    'unmapped historical callback has no upstream handler and creates no purchase or receipt; 503 is transport retry only, so operator inventory/review remains necessary');

// Restore the disposable fixture so later integration cases can keep using it.
$wpdb->update(SellerRepository::configs_table(), ['secret_ciphertext' => SecretStore::encrypt('integration-only-legacy-secret')], ['id' => $configId]);
update_option('woocommerce_wayforpay_settings', [
    'enabled' => 'yes',
    'merchant_account' => (string) $config['merchant_account'],
    'secret_key' => 'integration-only-legacy-secret',
], false);
echo "PASS: mapped legacy callback references use pinned config while upstream plugin is deactivated; missing and unmapped references are rejected/left for operator inventory\n";
