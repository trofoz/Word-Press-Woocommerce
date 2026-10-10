<?php

use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\ProductRouting;

if (!function_exists('wc_load_cart')) {
    throw new RuntimeException('WooCommerce cart loader is unavailable.');
}
wc_load_cart();
$cart = WC()->cart;
if (!$cart instanceof WC_Cart) {
    throw new RuntimeException('WooCommerce cart did not initialize in the integration request.');
}
$cart->empty_cart();
$sameSellerProductId = (int) get_option('wfpms_integration_iban_product_id', 0);
$otherSellerProductId = (int) get_option('wfpms_integration_manual_product_id', 0);
if ($sameSellerProductId < 1 || $otherSellerProductId < 1) {
    throw new RuntimeException('Mixed-cart integration fixture is missing.');
}

$firstKey = $cart->add_to_cart($sameSellerProductId, 1);
if (!is_string($firstKey) || $firstKey === '') {
    throw new RuntimeException('Could not add the first seller product to the real WooCommerce cart.');
}
$directValidation = apply_filters('woocommerce_add_to_cart_validation', true, $otherSellerProductId, 1, 0, [], []);
if ($directValidation !== false) {
    $firstProductId = (int) ($cart->get_cart()[$firstKey]['product_id'] ?? 0);
    $firstResolution = ProductRouting::resolve($firstProductId);
    $otherResolution = ProductRouting::resolve($otherSellerProductId);
    throw new RuntimeException('Mixed-seller add-to-cart validation did not reject the candidate: '
        . wp_json_encode([
            'hook_priority' => has_filter('woocommerce_add_to_cart_validation', [OrderPurchaseService::class, 'validate_add_to_cart']),
            'first_seller' => $firstResolution['seller_id'] ?? null,
            'other_seller' => $otherResolution['seller_id'] ?? null,
            'cart_count' => $cart->get_cart_contents_count(),
        ]));
}
$storeApiRejected = false;
try {
    do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($otherSellerProductId), ['id' => $otherSellerProductId, 'quantity' => 1]);
} catch (Exception $error) {
    $storeApiRejected = str_contains($error->getMessage(), 'одного продавця');
}
if (!$storeApiRejected || $cart->get_cart_contents_count() !== 1) {
    throw new RuntimeException('WooCommerce Store API validation must reject a product assigned to another seller.');
}
do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($sameSellerProductId), ['id' => $sameSellerProductId, 'quantity' => 1]);
$sameSellerResult = $cart->add_to_cart($sameSellerProductId, 1);
if (!is_string($sameSellerResult) || $cart->get_cart_contents_count() !== 2) {
    throw new RuntimeException('WooCommerce cart must allow multiple products from the same seller.');
}

$gatewayMap = [
    'wfpms_card' => new stdClass(),
    'wfpms_iban' => new stdClass(),
    'bacs' => new stdClass(),
];
$available = OrderPurchaseService::filter_cart_gateways($gatewayMap);
if (array_keys($available) !== ['wfpms_iban']) {
    throw new RuntimeException('Checkout must show only the payment method enabled for this cart seller.');
}

$cart->empty_cart();
$cart->add_to_cart($sameSellerProductId, 1);
$items = $cart->get_cart();
$item = reset($items);
if (!is_array($item)) {
    throw new RuntimeException('Could not construct the mixed-cart checkout validation fixture.');
}
$item['product_id'] = $otherSellerProductId;
$item['variation_id'] = 0;
$item['data'] = wc_get_product($otherSellerProductId);
$cart->cart_contents['integration-forced-mixed-seller'] = $item;
wc_clear_notices();
OrderPurchaseService::validate_cart();
$errors = wc_get_notices('error');
if (!is_array($errors) || $errors === []) {
    throw new RuntimeException('Checkout validation must reject a mixed-seller cart that bypassed add-to-cart validation.');
}

$cart->empty_cart();
echo "PASS: classic and Store API validation block mixed sellers, allow same-seller products, and limit gateways\n";
