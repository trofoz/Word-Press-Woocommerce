<?php
/** Disposable CI fixtures/inspection only; never an operator migration command. */
use WfpMultiSeller\LegacyMigration;
use WfpMultiSeller\SellerRepository;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\PurchaseExecutionService;

global $wpdb;
wp_set_current_user((int) get_user_by('login', 'test-admin')->ID);
$mode = getenv('WFPMS_LEGACY_MODE') ?: 'state';
$fixture = get_option('wfpms_legacy_operator_fixture', []);
if ($mode === 'seed') {
    if (LegacyMigration::owns_callback_route() || LegacyMigration::is_transition_enabled()) {
        throw new RuntimeException('Operator test must start before legacy fixture flag writes.');
    }
    $ids = [];
    foreach (['history', 'new'] as $label) {
        $secret = 'synthetic-operator-' . $label . '-secret';
        $ids[$label] = SellerRepository::save(null, [
            'internal_label' => 'Operator ' . $label, 'legal_name' => 'Synthetic Operator ' . $label,
            'tax_id' => 'SYNTHETIC-' . $label,
        ], [
            'recipient_name' => 'Synthetic Operator ' . $label, 'iban' => 'UA213223130000026007233566001',
            'bank_name' => 'Synthetic Bank', 'purpose_template' => 'Order {order_number}',
            'merchant_account' => 'synthetic-operator-' . $label, 'merchant_domain' => 'example.test',
            'card_enabled' => 1, 'iban_enabled' => 1, 'operator_checked' => true, 'secret_plaintext' => $secret,
        ], SecretStore::encrypt($secret), get_current_user_id());
    }
    // Original gateway settings are synthetic fixture input, never a way to switch ownership.
    update_option('woocommerce_wayforpay_settings', [
        'enabled' => 'yes', 'merchant_account' => 'synthetic-operator-history',
        'secret_key' => 'synthetic-operator-history-secret', 'title' => 'Synthetic original gateway',
        'description' => '', 'showlogo' => 'no', 'returnUrl_m' => '',
    ], false);
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic legacy operator virtual item');
    $product->set_regular_price('10.00');
    $product->set_virtual(true);
    $product->save();
    update_post_meta($product->get_id(), ProductRouting::META_SELLER_ID, $ids['new']);
    $fixture = ['seller_id' => $ids['history'], 'new_seller_id' => $ids['new'], 'product_id' => $product->get_id()];
    foreach (['numeric', 'suffixed', 'unmapped'] as $label) {
        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_payment_method('wayforpay');
        $order->calculate_totals();
        $order->save();
        $fixture[$label] = $order->get_id();
    }
    update_option('wfpms_legacy_operator_fixture', $fixture, false);
} elseif ($mode === 'missing_key' || $mode === 'restore_key') {
    // Fault injection affects only the new synthetic imported configuration in this disposable DB.
    $configId = (int) get_option('wfpms_legacy_import')['config_id'];
    if ($mode === 'missing_key') {
        update_option('wfpms_legacy_operator_saved_ciphertext', SellerRepository::config($configId)['secret_ciphertext'], false);
        $ciphertext = 'synthetic-unreadable-key';
    } else {
        $ciphertext = get_option('wfpms_legacy_operator_saved_ciphertext');
    }
    $wpdb->update(SellerRepository::configs_table(), ['secret_ciphertext' => $ciphertext], ['id' => $configId]);
} elseif ($mode === 'new_payment') {
    $order = wc_create_order();
    $order->add_product(wc_get_product((int) $fixture['product_id']), 1);
    $order->set_payment_method('wfpms_card');
    $order->calculate_totals();
    $order->save();
    $result = WC()->payment_gateways()->payment_gateways()['wfpms_card']->process_payment($order->get_id());
    $purchase = PurchaseRepository::for_order($order->get_id());
    $attempts = $purchase ? PurchaseRepository::attempts_for_purchase((int) $purchase['id']) : [];
    echo wp_json_encode(['result' => $result['result'] ?? '', 'order_id' => $order->get_id(), 'attempt' => $attempts[0] ?? null]);
    return;
} elseif ($mode === 'run') {
    $orderId = (int) getenv('WFPMS_LEGACY_ORDER_ID');
    $purchase = PurchaseRepository::for_order($orderId);
    $completionCount = $statusCount = 0;
    add_action('woocommerce_payment_complete', static function ($id) use ($orderId, &$completionCount): void {
        if ((int) $id === $orderId) $completionCount++;
    });
    add_action('woocommerce_order_status_completed', static function ($id) use ($orderId, &$statusCount): void {
        if ((int) $id === $orderId) $statusCount++;
    });
    PurchaseExecutionService::run((int) $purchase['id']);
    PurchaseExecutionService::run((int) $purchase['id']);
    echo wp_json_encode(['payment_complete' => $completionCount, 'completed' => $statusCount]);
    return;
}
$gateway = WC()->payment_gateways()->payment_gateways()['wayforpay'] ?? null;
$fixture['owner'] = LegacyMigration::owns_callback_route();
$fixture['standalone'] = LegacyMigration::is_standalone_enabled();
$fixture['transition'] = LegacyMigration::is_legacy_transition_enabled();
$fixture['release_registered'] = has_action('admin_post_wfpms_release_legacy_callback');
$fixture['upstream_registered'] = $gateway !== null && has_action('woocommerce_api_wc_wayforpay', [$gateway, 'check_wayforpay_response']) !== false;
$fixture['ready'] = LegacyMigration::transition_is_ready();
$fixture['import'] = get_option('wfpms_legacy_import', []);
foreach (['numeric', 'suffixed', 'unmapped'] as $label) {
    $orderId = (int) $fixture[$label];
    $purchase = PurchaseRepository::for_order($orderId);
    $fixture[$label . '_state'] = [
        'status' => wc_get_order($orderId)->get_status(),
        'mapping' => LegacyMigration::for_order($orderId),
        'purchase' => $purchase,
        'receipts' => $purchase ? PurchaseRepository::receipts_for_purchase((int) $purchase['id']) : [],
        'events' => $purchase ? PurchaseRepository::events_for_purchase((int) $purchase['id']) : [],
    ];
}
echo wp_json_encode($fixture);
