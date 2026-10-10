<?php

use WfpMultiSeller\ProductRouting;

$sellerOneId = (int) get_option('wfpms_integration_seller_one_id', 0);
$sellerTwoId = (int) get_option('wfpms_integration_seller_two_id', 0);
$productOne = wc_get_product((int) get_option('wfpms_integration_iban_product_id', 0));
$productTwo = wc_get_product((int) get_option('wfpms_integration_manual_product_id', 0));
if ($sellerOneId < 1 || $sellerTwoId < 1 || !$productOne instanceof WC_Product || !$productTwo instanceof WC_Product) {
    throw new RuntimeException('Admin integration fixtures are missing their sellers or products.');
}

foreach ([[$sellerOneId, $productOne], [$sellerTwoId, $productTwo]] as $index => [$sellerId, $product]) {
    $order = wc_create_order();
    $order->set_status('pending');
    $order->set_payment_method('wfpms_iban');
    $order->set_billing_email('admin-filter-' . $index . '@example.test');
    $order->add_product($product, 1);
    $order->update_meta_data('_wfpms_seller_id', (string) $sellerId);
    $order->save();
    update_option('wfpms_integration_admin_order_seller_' . ($index + 1), (int) $order->get_id(), false);
}

$unassignedId = (int) get_option('wfpms_integration_unassigned_product_id', 0);
$conflictedId = (int) get_option('wfpms_integration_conflicted_product_id', 0);
$unassigned = $unassignedId > 0 ? ProductRouting::resolve($unassignedId) : null;
$conflicted = $conflictedId > 0 ? ProductRouting::resolve($conflictedId) : null;
if (!is_array($unassigned) || $unassigned['reason'] !== 'seller_not_assigned'
    || !is_array($conflicted) || $conflicted['reason'] !== 'category_seller_conflict') {
    throw new RuntimeException('Unassigned and conflicted product audit fixtures did not resolve as expected.');
}

echo "PASS: admin order and product-audit fixtures are ready\n";
