<?php

use WfpMultiSeller\BankTransferGateway;
use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SellerRepository;

function wfpms_untrusted_completion_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$sellerId = (int) get_option('wfpms_integration_seller_one_id', 0);
$config = $sellerId > 0 ? SellerRepository::current_config($sellerId) : null;
if ($sellerId < 1 || $config === null || (int) $config['iban_enabled'] !== 1) {
    throw new RuntimeException('Untrusted payment-completion fixture has no IBAN-enabled seller.');
}

$product = new WC_Product_Simple();
$product->set_name('Untrusted payment-completion stock fixture');
$product->set_status('publish');
$product->set_regular_price('10.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->save();
update_post_meta($product->get_id(), ProductRouting::META_SELLER_ID, $sellerId);

$order = wc_create_order();
$order->set_status('pending');
$order->set_payment_method('wfpms_iban');
$order->add_product($product, 1);
$order->calculate_totals();
$order->save();
update_option('wfpms_legacy_transition_enabled', 'yes', false);
$gateway = new BankTransferGateway();
$result = $gateway->process_payment((int) $order->get_id());
wfpms_untrusted_completion_assert(($result['result'] ?? '') === 'success', 'IBAN fixture starts an unpaid payment attempt.');

$orderId = (int) $order->get_id();
$purchase = PurchaseRepository::for_order($orderId);
$stockBefore = (int) wc_get_product($product->get_id())->get_stock_quantity();
wfpms_untrusted_completion_assert($purchase !== null && (string) $purchase['state'] === 'awaiting_payment'
    && $stockBefore === 10, 'fixture remains unpaid and inventory is untouched before the external completion call');

$order->payment_complete('untrusted-integration-completion');
$freshOrder = wc_get_order($orderId);
$freshProduct = wc_get_product($product->get_id());
$purchase = PurchaseRepository::for_order($orderId);
$receiptCount = $purchase === null ? -1 : count(PurchaseRepository::receipts_for_purchase((int) $purchase['id']));
wfpms_untrusted_completion_assert($freshOrder instanceof WC_Order && !$freshOrder->is_paid()
    && $freshOrder->get_date_paid() === null && $freshOrder->get_status() === 'on-hold'
    && $freshProduct instanceof WC_Product && (int) $freshProduct->get_stock_quantity() === 10
    && $purchase !== null && (string) $purchase['state'] === 'awaiting_payment'
    && (string) $purchase['execution_state'] === 'none' && $receiptCount === 0,
    'an unrelated integration cannot complete an unpaid seller order, set paid date, or reduce stock');

$freeProduct = new WC_Product_Simple();
$freeProduct->set_name('Allowed zero-total completion fixture');
$freeProduct->set_status('publish');
$freeProduct->set_regular_price('0.00');
$freeProduct->set_virtual(true);
$freeProduct->save();
update_post_meta($freeProduct->get_id(), ProductRouting::META_SELLER_ID, $sellerId);
$freeOrder = wc_create_order();
$freeOrder->set_status('pending');
$freeOrder->add_product($freeProduct, 1);
$freeOrder->calculate_totals();
$freeOrder->save();
update_option('wfpms_allow_zero_total', 'yes', false);
$freePurchase = OrderPurchaseService::finalize((int) $freeOrder->get_id());
$freeOrder->payment_complete();
$freePurchase = PurchaseRepository::for_order((int) $freeOrder->get_id());
$freeOrder = wc_get_order((int) $freeOrder->get_id());
wfpms_untrusted_completion_assert($freePurchase !== null && (string) $freePurchase['state'] === 'free'
    && (string) $freePurchase['execution_state'] === 'done'
    && $freeOrder instanceof WC_Order && $freeOrder->is_paid(),
    'an explicitly allowed zero-total purchase can still use WooCommerce native payment completion');

delete_option('wfpms_legacy_transition_enabled');
delete_option('wfpms_allow_zero_total');
echo "PASS: payment_complete without an accepted receipt is blocked before order payment state and inventory change\n";
