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
$standaloneFixtureGateways = ['wayforpay' => new stdClass(), 'wfpms_card' => new stdClass()];
delete_option('wfpms_legacy_transition_enabled');
update_option('wfpms_standalone_mode_enabled', 'yes', false);
wfpms_integration_assert(WfpMultiSeller\LegacyMigration::is_transition_enabled()
    && !WfpMultiSeller\LegacyMigration::is_legacy_transition_enabled()
    && array_keys(WfpMultiSeller\LegacyMigration::filter_gateways($standaloneFixtureGateways)) === ['wayforpay', 'wfpms_card'],
    'standalone mode enables fork payments without claiming the original callback or hiding the original gateway');
delete_option('wfpms_standalone_mode_enabled');

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
update_option('wfpms_integration_seller_one_id', $sellerIds[0], false);
update_option('wfpms_integration_seller_two_id', $sellerIds[1], false);

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

$now = current_time('mysql', true);
wfpms_integration_assert($wpdb->insert(SellerRepository::configs_table(), [
    'seller_id' => $sellerIds[0],
    'version' => 1,
    'recipient_name' => 'Integration Seller 1',
    'tax_id' => 'TEST1',
    'iban' => 'UA213223130000026007233566001',
    'bank_name' => 'Integration Bank',
    'purpose_template' => 'Order {order_number}',
    'merchant_account' => '',
    'merchant_domain' => '',
    'card_enabled' => 0,
    'iban_enabled' => 1,
    'secret_ciphertext' => '',
    'checked_by' => null,
    'checked_at' => null,
    'created_at' => $now,
]) === 1, 'IBAN seller configuration can be inserted');
$ibanConfigId = (int) $wpdb->insert_id;
wfpms_integration_assert($wpdb->update(SellerRepository::sellers_table(), [
    'current_config_id' => $ibanConfigId,
], ['id' => $sellerIds[0]]) !== false, 'IBAN seller configuration can be activated');

$product = new WC_Product_Simple();
$product->set_name('Integration product');
$product->set_status('publish');
$product->set_regular_price('10.00');
$product->set_virtual(true);
$product->save();
update_option('wfpms_integration_iban_product_id', $product->get_id(), false);
wp_set_object_terms($product->get_id(), [$categories[0]], 'product_cat');
$resolved = ProductRouting::resolve($product->get_id());
wfpms_integration_assert($resolved['ok'] && $resolved['seller_id'] === $sellerIds[0] && $resolved['source'] === 'category', 'product routes to seller by category');

$secondSameSellerProduct = new WC_Product_Simple();
$secondSameSellerProduct->set_name('Second Integration Seller 1 product');
$secondSameSellerProduct->set_status('publish');
$secondSameSellerProduct->set_regular_price('5.00');
$secondSameSellerProduct->set_virtual(true);
$secondSameSellerProduct->save();
wp_set_object_terms($secondSameSellerProduct->get_id(), [$categories[0]], 'product_cat');
update_option('wfpms_integration_same_seller_product_2_id', $secondSameSellerProduct->get_id(), false);

$unassignedProduct = new WC_Product_Simple();
$unassignedProduct->set_name('Unassigned Integration product');
$unassignedProduct->set_status('publish');
$unassignedProduct->set_regular_price('7.00');
$unassignedProduct->set_virtual(true);
$unassignedProduct->save();
update_option('wfpms_integration_unassigned_product_id', $unassignedProduct->get_id(), false);

$conflictedProduct = new WC_Product_Simple();
$conflictedProduct->set_name('Conflicted Integration product');
$conflictedProduct->set_status('publish');
$conflictedProduct->set_regular_price('8.00');
$conflictedProduct->set_virtual(true);
$conflictedProduct->save();
wp_set_object_terms($conflictedProduct->get_id(), $categories, 'product_cat');
update_option('wfpms_integration_conflicted_product_id', $conflictedProduct->get_id(), false);
$conflictedResolution = ProductRouting::resolve($conflictedProduct->get_id());
wfpms_integration_assert(!$conflictedResolution['ok'] && $conflictedResolution['reason'] === 'category_seller_conflict', 'conflicted product fixture resolves to an actionable audit error');

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
wp_set_object_terms($manualProduct->get_id(), [$categories[0]], 'product_cat');
update_post_meta($manualProduct->get_id(), ProductRouting::META_SELLER_ID, $sellerIds[2]);
update_option('wfpms_integration_manual_product_id', $manualProduct->get_id(), false);
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
$bankGateway = new WfpMultiSeller\BankTransferGateway();
$pausedBankResult = $bankGateway->process_payment($order->get_id());
wfpms_integration_assert(($pausedBankResult['result'] ?? '') === 'failure'
    && WfpMultiSeller\PurchaseRepository::for_order((int) $order->get_id()) === null,
    'Direct IBAN process_payment calls cannot create a purchase while the transition is paused');
update_option('wfpms_legacy_transition_enabled', 'yes', false);
$bankResult = $bankGateway->process_payment($order->get_id());
wfpms_integration_assert(($bankResult['result'] ?? '') === 'success', 'IBAN gateway finalizes the order and returns a successful checkout result');
$purchase = WfpMultiSeller\PurchaseRepository::for_order($order->get_id());
wfpms_integration_assert($purchase !== null && (string) $purchase['state'] === 'awaiting_payment', 'IBAN checkout creates an awaiting-payment purchase');
$ibanAttempt = WfpMultiSeller\PurchaseRepository::attempts_for_purchase((int) $purchase['id'])[0] ?? null;
$ibanInstructions = is_array($ibanAttempt) ? json_decode((string) $ibanAttempt['payload'], true) : null;
wfpms_integration_assert(is_array($ibanInstructions)
    && $ibanInstructions['recipient_name'] === 'Integration Seller 1'
    && $ibanInstructions['iban'] === 'UA213223130000026007233566001'
    && $ibanInstructions['bank_name'] === 'Integration Bank'
    && $ibanInstructions['amount'] === (string) $purchase['amount']
    && $ibanInstructions['currency'] === (string) $purchase['currency'], 'IBAN attempt stores the seller-specific payment instructions and amount');

$archivedSellerOrder = wc_create_order();
$archivedSellerOrder->add_product(wc_get_product($product->get_id()), 1);
$archivedSellerOrder->set_payment_method('wfpms_iban');
$archivedSellerOrder->calculate_totals();
$archivedSellerOrder->save();
$archivedPurchase = WfpMultiSeller\OrderPurchaseService::finalize((int) $archivedSellerOrder->get_id());
wfpms_integration_assert(SellerRepository::archive((int) $sellerIds[0]), 'seller can be archived for payment-attempt protection test');
$archivedSellerResult = $bankGateway->process_payment((int) $archivedSellerOrder->get_id());
wfpms_integration_assert(($archivedSellerResult['result'] ?? '') === 'failure'
    && WfpMultiSeller\PurchaseRepository::attempts_for_purchase((int) $archivedPurchase['id']) === [],
    'archiving a seller blocks new IBAN attempts for its existing purchases');
$wpdb->update(SellerRepository::sellers_table(), ['active' => 1], ['id' => $sellerIds[0]]);

wp_clear_scheduled_hook('wfpms_process_execution', [(int) $purchase['id']]);
update_option('wfpms_integration_iban_order_id', (int) $order->get_id(), false);
update_option('wfpms_integration_iban_expires_at', (string) $purchase['expires_at'], false);
$wpdb->update(WfpMultiSeller\PurchaseRepository::purchases_table(), [
    'state' => 'paid',
    'execution_state' => 'pending',
    'updated_at' => current_time('mysql', true),
], ['id' => (int) $purchase['id']]);
delete_option('wfpms_legacy_transition_enabled');
$rejectSchedule = static fn($pre, $event, $wpError) => false;
add_filter('pre_schedule_event', $rejectSchedule, 10, 3);
WfpMultiSeller\PurchaseExecutionService::schedule((int) $purchase['id']);
remove_filter('pre_schedule_event', $rejectSchedule, 10);
wfpms_integration_assert(!wp_next_scheduled('wfpms_process_execution', [(int) $purchase['id']]), 'failed one-off execution scheduling leaves no event');

WfpMultiSeller\PurchaseExecutionService::expire_due();
wfpms_integration_assert(wp_next_scheduled('wfpms_process_execution', [(int) $purchase['id']]),
    'hourly recovery schedules paid purchases whose execution remained pending');
wp_clear_scheduled_hook('wfpms_process_execution', [(int) $purchase['id']]);

$expiredBallastIds = [];
$expiredAt = gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 3600);
for ($index = 1; $index <= 100; $index++) {
    $inserted = $wpdb->insert(WfpMultiSeller\PurchaseRepository::purchases_table(), [
        'order_id' => 900000 + $index,
        'seller_id' => $sellerIds[0],
        'config_id' => (int) $purchase['config_id'],
        'financial_snapshot' => '{}',
        'snapshot_hash' => hash('sha256', '{}'),
        'amount' => '1.00',
        'currency' => 'UAH',
        'state' => 'expired',
        'expires_at' => $expiredAt,
        'created_at' => $expiredAt,
        'updated_at' => $expiredAt,
    ]);
    wfpms_integration_assert($inserted === 1, 'expired queue ballast fixture can be created');
    $expiredBallastIds[] = (int) $wpdb->insert_id;
}
$wpdb->update(WfpMultiSeller\PurchaseRepository::purchases_table(), [
    'state' => 'awaiting_payment',
    'expires_at' => $expiredAt,
    'updated_at' => $expiredAt,
], ['id' => (int) $purchase['id']]);
WfpMultiSeller\PurchaseExecutionService::expire_due();
wfpms_integration_assert((string) WfpMultiSeller\PurchaseRepository::for_order((int) $order->get_id())['state'] === 'expired',
    'old expired purchases do not starve newly due purchases from the expiry queue');
foreach ($expiredBallastIds as $expiredBallastId) {
    $wpdb->delete(WfpMultiSeller\PurchaseRepository::purchases_table(), ['id' => $expiredBallastId]);
}
wp_clear_scheduled_hook('wfpms_process_execution', [(int) $purchase['id']]);
$wpdb->update(WfpMultiSeller\PurchaseRepository::purchases_table(), [
    'state' => 'awaiting_payment',
    'expires_at' => (string) $purchase['expires_at'],
    'execution_state' => 'none',
    'updated_at' => current_time('mysql', true),
], ['id' => (int) $purchase['id']]);
$order->update_status('on-hold');

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
