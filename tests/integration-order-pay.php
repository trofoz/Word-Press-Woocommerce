<?php

use WfpMultiSeller\LegacyMigration;
use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SellerRepository;

$orderId = (int) get_option('wfpms_integration_legacy_order_id', 0);
$purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
$order = $orderId > 0 ? wc_get_order($orderId) : false;
if ($purchase === null || !$order instanceof WC_Order || $order->is_paid()) {
    throw new RuntimeException('Order-pay integration fixture is missing or already paid.');
}

if (function_exists('wc_load_cart')) {
    wc_load_cart();
}
$cart = WC()->cart;
if ($cart instanceof WC_Cart) {
    $cart->empty_cart();
}

$currentConfig = SellerRepository::config((int) $purchase['config_id']);
if ($currentConfig === null) {
    throw new RuntimeException('Frozen seller configuration is unavailable.');
}
$now = current_time('mysql', true);
if ($GLOBALS['wpdb']->insert(SellerRepository::configs_table(), [
    'seller_id' => (int) $purchase['seller_id'],
    'version' => (int) $currentConfig['version'] + 1,
    'recipient_name' => 'Changed Test Recipient',
    'tax_id' => (string) $currentConfig['tax_id'],
    'iban' => (string) $currentConfig['iban'],
    'bank_name' => (string) $currentConfig['bank_name'],
    'purpose_template' => (string) $currentConfig['purpose_template'],
    'merchant_account' => (string) $currentConfig['merchant_account'],
    'merchant_domain' => (string) $currentConfig['merchant_domain'],
    'card_enabled' => 0,
    'iban_enabled' => 1,
    'secret_ciphertext' => (string) $currentConfig['secret_ciphertext'],
    'checked_by' => 1,
    'checked_at' => $now,
    'created_at' => $now,
]) !== 1) {
    throw new RuntimeException('Could not create a changed seller configuration fixture.');
}
$newConfigId = (int) $GLOBALS['wpdb']->insert_id;
$GLOBALS['wpdb']->update(SellerRepository::sellers_table(), ['current_config_id' => $newConfigId], ['id' => (int) $purchase['seller_id']]);

global $wp, $wp_query;
if (!is_object($wp) || !is_object($wp_query)) {
    throw new RuntimeException('WordPress query context is unavailable.');
}
$oldWpVars = $wp->query_vars;
$oldQueryVars = $wp_query->query_vars;
$wp->query_vars['order-pay'] = (string) $orderId;
$wp_query->query_vars['order-pay'] = (string) $orderId;
if (!is_wc_endpoint_url('order-pay')) {
    throw new RuntimeException('WordPress did not recognize the simulated order-pay endpoint.');
}

$gatewayMap = WC()->payment_gateways()->payment_gateways();
$available = apply_filters('woocommerce_available_payment_gateways', $gatewayMap);
if (array_keys($available) !== ['wfpms_card']) {
    throw new RuntimeException('Order-pay must use only the card method frozen on the original purchase configuration.');
}
if (OrderPurchaseService::checkout_seller_name() !== 'Legacy Integration Seller') {
    throw new RuntimeException('Order-pay seller label must come from the order snapshot after seller details change.');
}
$title = OrderPurchaseService::filter_gateway_title('Card', 'wfpms_card');
if (!str_contains($title, 'Legacy Integration Seller')) {
    throw new RuntimeException('Order-pay payment title must show the frozen legal recipient.');
}

$wp->query_vars = $oldWpVars;
$wp_query->query_vars = $oldQueryVars;
update_option('wfpms_legacy_transition_enabled', 'yes', false);
$transitionGateways = LegacyMigration::filter_gateways($available);
if (isset($transitionGateways['wayforpay']) || !isset($transitionGateways['wfpms_card'])) {
    throw new RuntimeException('Order-pay gateway state must retain the fork method and hide the upstream method.');
}

echo "PASS: order-pay reuses frozen seller, recipient label, and gateway configuration\n";
