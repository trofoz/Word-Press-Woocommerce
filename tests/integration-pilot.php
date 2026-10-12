<?php

use WfpMultiSeller\PurchaseRepository;

wp_set_current_user(0);
$product = wc_get_product((int) get_option('wfpms_integration_iban_product_id'));
$order = wc_create_order(['customer_id' => 0]);
$order->add_product($product, 1);
$order->set_payment_method('wayforpay');
$order->calculate_totals();
$order->save();
$gateway = WC()->payment_gateways()->payment_gateways()['wfpms_iban'];
$result = $gateway->process_payment((int) $order->get_id());
if (($result['result'] ?? '') !== 'failure'
    || PurchaseRepository::for_order((int) $order->get_id()) !== null) {
    throw new RuntimeException('FAIL: pilot default denies direct guest fork payment before creating a purchase or attempt.');
}
echo "PASS: pilot default denies direct guest fork payment before creating a purchase or attempt.\n";
