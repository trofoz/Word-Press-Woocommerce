<?php
declare(strict_types=1);

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $key));
    }
}

require_once __DIR__ . '/../src/class-product-routing.php';
require_once __DIR__ . '/../src/class-payment-operations.php';
require_once __DIR__ . '/../src/class-order-purchase-service.php';

use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\PaymentOperations;
use WfpMultiSeller\ProductRouting;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_same(42, ProductRouting::cart_item_product_id(['product_id' => 42, 'variation_id' => 0]), 'simple product fallback');
assert_same(84, ProductRouting::cart_item_product_id(['product_id' => 42, 'variation_id' => 84]), 'variation product selection');
assert_same(0, ProductRouting::cart_item_product_id([]), 'missing product ID');

assert_same(
    ['bacs', 'safe_gateway'],
    PaymentOperations::sanitize_external_gateway_ids(
        ['bacs', 'safe_gateway', 'wayforpay', ['invalid']],
        ['bacs', 'safe_gateway']
    ),
    'external gateway allowlist sanitation'
);

$decimalUnits = new ReflectionMethod(PaymentOperations::class, 'decimal_units');
assert_same('1000', $decimalUnits->invoke(null, '10.000000', 2), 'stored trailing zero decimals normalize to currency scale');
assert_same(null, $decimalUnits->invoke(null, '10.001000', 2), 'non-zero excess decimals remain invalid');
assert_same('10', $decimalUnits->invoke(null, '10.00', 0), 'zero scale accepts only trailing zero decimals');

$gateways = [
    'wfpms_card' => new stdClass(),
    'bacs' => new stdClass(),
    'unsafe_gateway' => new stdClass(),
];
assert_same(
    ['bacs' => $gateways['bacs']],
    OrderPurchaseService::filter_external_gateways($gateways, ['bacs', 'missing_gateway']),
    'external gateway allowlist filtering'
);

echo "PASS: cart product resolution and external gateway allowlist\n";
