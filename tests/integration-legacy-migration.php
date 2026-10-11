<?php

use WfpMultiSeller\LegacyMigration;
use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;

function wfpms_legacy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

global $wpdb;
$sellerId = 0;
$now = current_time('mysql', true);
wfpms_legacy_assert($wpdb->insert(SellerRepository::sellers_table(), [
    'seller_uuid' => wp_generate_uuid4(),
    'internal_label' => 'Legacy migration test seller',
    'legal_name' => 'Legacy Integration Seller',
    'tax_id' => 'LEGACY-TEST',
    'active' => 1,
    'current_config_id' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]) === 1, 'legacy migration seller fixture can be created');
$sellerId = (int) $wpdb->insert_id;
$secret = 'integration-only-legacy-secret';
$merchant = 'integration-legacy-merchant';
wfpms_legacy_assert($wpdb->insert(SellerRepository::configs_table(), [
    'seller_id' => $sellerId,
    'version' => 1,
    'recipient_name' => 'Legacy Integration Seller',
    'tax_id' => 'LEGACY-TEST',
    'iban' => 'UA213223130000026007233566001',
    'bank_name' => 'Integration Bank',
    'purpose_template' => 'Order {order_number}',
    'merchant_account' => $merchant,
    'merchant_domain' => 'example.test',
    'card_enabled' => 1,
    'iban_enabled' => 0,
    'secret_ciphertext' => SecretStore::encrypt($secret),
    'checked_by' => 1,
    'checked_at' => $now,
    'created_at' => $now,
]) === 1, 'imported legacy seller configuration can be created');
$configId = (int) $wpdb->insert_id;
$wpdb->update(SellerRepository::sellers_table(), ['current_config_id' => $configId], ['id' => $sellerId]);
update_option('woocommerce_wayforpay_settings', [
    'merchant_account' => $merchant,
    'secret_key' => $secret,
], false);
update_option('wfpms_legacy_import', [
    'seller_id' => $sellerId,
    'config_id' => $configId,
], false);
update_option('wfpms_legacy_callback_owner', 'yes', false);
delete_option('wfpms_legacy_transition_enabled');
wfpms_legacy_assert(has_action('woocommerce_api_wc_wayforpay', [LegacyMigration::class, 'handle_callback']) === 0,
    'fork registers the legacy callback handler ahead of the upstream handler');
$registeredGateways = WC()->payment_gateways()->payment_gateways();
$upstreamGateway = $registeredGateways['wayforpay'] ?? null;
wfpms_legacy_assert($upstreamGateway instanceof WC_Payment_Gateway && get_class($upstreamGateway) === 'WC_wayforpay',
    'the original WayForPay plugin loads beside the fork');
wfpms_legacy_assert(has_action('woocommerce_api_wc_wayforpay', [$upstreamGateway, 'check_wayforpay_response']) === 10,
    'the fork callback is ordered before the original gateway callback');

$productId = (int) get_option('wfpms_integration_manual_product_id', 0);
$product = $productId > 0 ? wc_get_product($productId) : null;
wfpms_legacy_assert($product instanceof WC_Product, 'legacy migration product fixture exists');

$legacyOrder = wc_create_order();
$legacyOrder->set_status('pending');
$legacyOrder->set_payment_method('wayforpay');
$legacyOrder->add_product($product, 1);
$legacyOrder->calculate_totals();
$legacyOrder->save();
OrderPurchaseService::migrate_legacy_order((int) $legacyOrder->get_id(), $sellerId, 1);
$purchase = PurchaseRepository::for_order((int) $legacyOrder->get_id());
$mapping = LegacyMigration::for_order((int) $legacyOrder->get_id());
update_option('wfpms_integration_legacy_order_id', (int) $legacyOrder->get_id(), false);
$snapshot = is_array($purchase) ? json_decode((string) $purchase['financial_snapshot'], true) : null;
wfpms_legacy_assert($purchase !== null && (string) $purchase['state'] === 'awaiting_payment', 'legacy migration creates an awaiting-payment purchase');
wfpms_legacy_assert($mapping !== null && (int) $mapping['seller_id'] === $sellerId && (int) $mapping['config_id'] === $configId,
    'legacy migration persists the explicit seller and configuration mapping');
wfpms_legacy_assert(is_array($snapshot) && (string) $snapshot['merchant_account'] === $merchant
    && (int) $snapshot['config_id'] === $configId, 'migrated purchase freezes the imported merchant configuration');
wfpms_legacy_assert(PurchaseRepository::attempts_for_purchase((int) $purchase['id']) === [],
    'migration itself does not create or charge a payment attempt');

$rejected = static function (int $orderId) use ($sellerId): bool {
    try {
        OrderPurchaseService::migrate_legacy_order($orderId, $sellerId, 1);
    } catch (RuntimeException $error) {
        return true;
    }
    return false;
};
wfpms_legacy_assert($rejected((int) $legacyOrder->get_id()), 'a legacy order cannot be migrated twice');

$closedOrder = wc_create_order();
$closedOrder->set_status('cancelled');
$closedOrder->set_payment_method('wayforpay');
$closedOrder->add_product($product, 1);
$closedOrder->calculate_totals();
$closedOrder->save();
wfpms_legacy_assert($rejected((int) $closedOrder->get_id()), 'a closed legacy order is rejected');

$paidOrder = wc_create_order();
$paidOrder->set_status('processing');
$paidOrder->set_payment_method('wayforpay');
$paidOrder->add_product($product, 1);
$paidOrder->calculate_totals();
$paidOrder->save();
wfpms_legacy_assert($rejected((int) $paidOrder->get_id()), 'a paid legacy order is rejected');

$otherGatewayOrder = wc_create_order();
$otherGatewayOrder->set_status('pending');
$otherGatewayOrder->set_payment_method('cod');
$otherGatewayOrder->add_product($product, 1);
$otherGatewayOrder->calculate_totals();
$otherGatewayOrder->save();
wfpms_legacy_assert($rejected((int) $otherGatewayOrder->get_id()), 'an order from another gateway is rejected');

$gateways = ['wayforpay' => new stdClass(), 'wfpms_card' => new stdClass(), 'wfpms_iban' => new stdClass()];
$pausedGateways = LegacyMigration::filter_gateways($gateways);
wfpms_legacy_assert($pausedGateways === [], 'claiming the old callback route keeps all checkout gateways paused');
update_option('wfpms_legacy_transition_enabled', 'yes', false);
$activeGateways = LegacyMigration::filter_gateways($gateways);
wfpms_legacy_assert(!isset($activeGateways['wayforpay']) && isset($activeGateways['wfpms_card']) && isset($activeGateways['wfpms_iban']),
    'enabling the transition hides upstream WayForPay and exposes fork gateways');

echo "PASS: legacy order migration, invalid-order rejection, and callback/gateway ownership transition\n";
