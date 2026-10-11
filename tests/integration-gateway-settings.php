<?php

use WfpMultiSeller\BankTransferGateway;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SellerRepository;
use WfpMultiSeller\WayForPayGateway;

function wfpms_gateway_settings_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$productId = (int) get_option('wfpms_integration_iban_product_id', 0);
$product = $productId > 0 ? wc_get_product($productId) : false;
if (!$product instanceof WC_Product) {
    throw new RuntimeException('Gateway-settings integration product is missing.');
}

update_option('woocommerce_wfpms_card_settings', ['enabled' => 'no'], false);
update_option('woocommerce_wfpms_iban_settings', ['enabled' => 'no'], false);
$disabledCard = new WayForPayGateway();
$disabledIban = new BankTransferGateway();
wfpms_gateway_settings_assert($disabledCard->enabled === 'no' && $disabledIban->enabled === 'no',
    'both gateways load the standard WooCommerce enabled setting');

update_option('woocommerce_wfpms_card_settings', ['enabled' => 'yes'], false);
update_option('woocommerce_wfpms_iban_settings', ['enabled' => 'yes'], false);
$enabledCard = new WayForPayGateway();
$enabledIban = new BankTransferGateway();
wfpms_gateway_settings_assert($enabledCard->enabled === 'yes' && $enabledIban->enabled === 'yes',
    'both gateways respect the explicitly enabled WooCommerce setting');

$previousPost = $_POST;
foreach ([[$enabledCard, 'wfpms_card', 'Card save test'], [$enabledIban, 'wfpms_iban', 'IBAN save test']] as [$gateway, $gatewayId, $title]) {
    $saveAction = 'woocommerce_update_options_payment_gateways_' . $gatewayId;
    wfpms_gateway_settings_assert(has_action($saveAction, [$gateway, 'process_admin_options']) !== false,
        $gatewayId . ' registers WooCommerce standard settings save handler');
    $_POST = ['woocommerce_' . $gatewayId . '_title' => $title];
    do_action($saveAction);
    $savedSettings = get_option('woocommerce_' . $gatewayId . '_settings', []);
    wfpms_gateway_settings_assert(is_array($savedSettings) && ($savedSettings['title'] ?? '') === $title,
        $gatewayId . ' persists its title through the standard WooCommerce save event');
}
$_POST = $previousPost;

update_option('wfpms_legacy_transition_enabled', 'yes', false);
foreach ([[$disabledCard, 'wfpms_card'], [$disabledIban, 'wfpms_iban']] as [$gateway, $gatewayId]) {
    $order = wc_create_order();
    $order->set_status('pending');
    $order->set_payment_method($gatewayId);
    $order->add_product($product, 1);
    $order->calculate_totals();
    $order->save();
    $result = $gateway->process_payment((int) $order->get_id());
    wfpms_gateway_settings_assert(($result['result'] ?? '') === 'failure'
        && PurchaseRepository::for_order((int) $order->get_id()) === null,
        $gatewayId . ' rejects direct process_payment calls when WooCommerce setting is disabled');
}

delete_option('woocommerce_wfpms_card_settings');
delete_option('woocommerce_wfpms_iban_settings');
delete_option('wfpms_legacy_transition_enabled');
echo "PASS: standard global gateway settings disable both methods, including direct process_payment calls\n";
