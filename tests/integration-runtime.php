<?php

use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\SellerRepository;

function wfpms_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

if (!class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce was not loaded in the integration runtime.');
}

global $wpdb;
$tables = [
    SellerRepository::sellers_table(),
    SellerRepository::configs_table(),
    SellerRepository::category_rules_table(),
    WfpMultiSeller\PurchaseRepository::purchases_table(),
    WfpMultiSeller\PurchaseRepository::attempts_table(),
    WfpMultiSeller\PurchaseRepository::receipts_table(),
    WfpMultiSeller\PurchaseRepository::events_table(),
    WfpMultiSeller\LegacyMigration::migrations_table(),
];
foreach ($tables as $table) {
    wfpms_integration_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'plugin schema table exists: ' . $table);
}
wfpms_integration_assert((int) get_option('wfpms_schema_version') === WfpMultiSeller\Installer::SCHEMA_VERSION, 'plugin schema version is current');

$expectedHpos = getenv('WFPMS_EXPECT_HPOS') === '1';
$hposEnabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
wfpms_integration_assert($hposEnabled === $expectedHpos, 'WooCommerce HPOS mode matches the matrix');

$gatewayIds = array_map(
    static fn($gateway): string => (string) $gateway->id,
    WC()->payment_gateways()->payment_gateways()
);
wfpms_integration_assert(in_array('wfpms_card', $gatewayIds, true), 'card gateway is registered in WooCommerce');
wfpms_integration_assert(in_array('wfpms_iban', $gatewayIds, true), 'IBAN gateway is registered in WooCommerce');

$blockRegistry = new class {
    /** @var list<object> */
    public array $registered = [];
    public function register(object $integration): void
    {
        $this->registered[] = $integration;
    }
};
WfpMultiSeller\BlocksIntegration::register_methods($blockRegistry);
$blockNames = array_map(static fn(object $integration): string => $integration->get_name(), $blockRegistry->registered);
wfpms_integration_assert(in_array('wfpms_card', $blockNames, true), 'Checkout Blocks registers the card payment integration');
wfpms_integration_assert(in_array('wfpms_iban', $blockNames, true), 'Checkout Blocks registers the IBAN payment integration');
$checkoutRegistry = new class {
    /** @var list<object> */
    public array $registered = [];
    public function register(object $integration): void
    {
        $this->registered[] = $integration;
    }
};
WfpMultiSeller\BlocksIntegration::register_checkout_label($checkoutRegistry);
wfpms_integration_assert(count($checkoutRegistry->registered) === 1, 'Checkout Blocks seller label integration registers against WooCommerce runtime');

$sellerIds = [];
for ($index = 1; $index <= 3; $index++) {
    $now = current_time('mysql', true);
    wfpms_integration_assert($wpdb->insert(SellerRepository::sellers_table(), [
        'seller_uuid' => wp_generate_uuid4(),
        'internal_label' => 'Seller ' . $index,
        'legal_name' => 'Integration Seller ' . $index,
        'tax_id' => 'TEST' . $index,
        'active' => 1,
        'current_config_id' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'seller fixture can be inserted');
    $sellerIds[] = (int) $wpdb->insert_id;
}
wfpms_integration_assert(count(SellerRepository::all(false)) === 3, 'seller registry supports three profiles');

$categories = [];
foreach (['Integration A', 'Integration B'] as $label) {
    $term = wp_insert_term($label, 'product_cat');
    wfpms_integration_assert(!is_wp_error($term), 'test category can be created');
    $categories[] = (int) $term['term_id'];
}
SellerRepository::replace_category_rules([
    $categories[0] => $sellerIds[0],
    $categories[1] => $sellerIds[1],
]);

$product = new WC_Product_Simple();
$product->set_name('Integration product');
$product->set_status('publish');
$product->set_regular_price('10.00');
$product->save();
wp_set_object_terms($product->get_id(), [$categories[0]], 'product_cat');
$resolved = ProductRouting::resolve($product->get_id());
wfpms_integration_assert($resolved['ok'] && $resolved['seller_id'] === $sellerIds[0] && $resolved['source'] === 'category', 'product routes to seller by category');

$variationParent = new WC_Product_Variable();
$variationParent->set_name('Integration variable product');
$variationParent->set_status('publish');
$variationParent->save();
wp_set_object_terms($variationParent->get_id(), [$categories[1]], 'product_cat');
$variation = new WC_Product_Variation();
$variation->set_parent_id($variationParent->get_id());
$variation->set_regular_price('12.00');
$variation->save();
$resolvedVariation = ProductRouting::resolve($variation->get_id());
wfpms_integration_assert($resolvedVariation['ok'] && $resolvedVariation['seller_id'] === $sellerIds[1], 'variation inherits seller routing from its parent');

$manualProduct = new WC_Product_Simple();
$manualProduct->set_name('Manual seller product');
$manualProduct->set_status('publish');
$manualProduct->set_regular_price('15.00');
$manualProduct->save();
update_post_meta($manualProduct->get_id(), ProductRouting::META_SELLER_ID, $sellerIds[2]);
$resolvedManual = ProductRouting::resolve($manualProduct->get_id());
wfpms_integration_assert($resolvedManual['ok'] && $resolvedManual['seller_id'] === $sellerIds[2] && $resolvedManual['source'] === 'manual', 'manual product assignment overrides category routing');

$order = wc_create_order();
$order->set_billing_email('integration@example.test');
$order->add_product(wc_get_product($product->get_id()), 1);
$order->set_payment_method('wfpms_iban');
$order->calculate_totals();
$order->save();
$reloadedOrder = wc_get_order($order->get_id());
wfpms_integration_assert($reloadedOrder instanceof WC_Order && $reloadedOrder->get_payment_method() === 'wfpms_iban', 'WooCommerce order persists and reloads via the active order store');

$checkoutId = (int) get_option('woocommerce_checkout_page_id');
$checkout = get_post($checkoutId);
$expectedCheckout = getenv('WFPMS_CHECKOUT') === 'blocks' ? 'woocommerce/checkout' : '[woocommerce_checkout]';
wfpms_integration_assert($checkout instanceof WP_Post && str_contains($checkout->post_content, $expectedCheckout), 'checkout fixture matches the matrix');

printf(
    "PASS: WooCommerce %s runtime; checkout=%s; HPOS=%s; schema, gateways, three sellers, product routing, and order storage\n",
    WC()->version,
    getenv('WFPMS_CHECKOUT') ?: 'unknown',
    $hposEnabled ? 'enabled' : 'disabled'
);
