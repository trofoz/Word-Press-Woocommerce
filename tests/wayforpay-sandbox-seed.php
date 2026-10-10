No syntax errors detected in tests/wayforpay-sandbox-seed.php
<?php

use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;
use WfpMultiSeller\WayForPayGateway;

function wfpms_sandbox_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$secret = getenv('WFPMS_SANDBOX_SECRET');
$tunnelUrl = getenv('WFPMS_TUNNEL_URL');
$outputPath = getenv('WFPMS_SANDBOX_OUTPUT');
wfpms_sandbox_assert(is_string($secret) && $secret !== '', 'Published WayForPay sandbox credential is missing.');
wfpms_sandbox_assert(is_string($tunnelUrl) && filter_var($tunnelUrl, FILTER_VALIDATE_URL), 'Ephemeral callback URL is invalid.');
wfpms_sandbox_assert(is_string($outputPath) && $outputPath !== '', 'Sandbox output path is missing.');
wfpms_sandbox_assert(SecretStore::available(), 'Disposable merchant-secret encryption is unavailable.');

update_option('woocommerce_currency', 'UAH');
update_option('home', $tunnelUrl);
update_option('siteurl', $tunnelUrl);
$domain = (string) wp_parse_url($tunnelUrl, PHP_URL_HOST);
wfpms_sandbox_assert($domain !== '', 'Ephemeral callback host is invalid.');
// WP-CLI has no web request context; mirror the HTTPS tunnel so WooCommerce signs HTTPS callback URLs.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_SCHEME'] = 'https';
$_SERVER['HTTP_HOST'] = $domain;

$sellerId = SellerRepository::save(null, [
    'internal_label' => 'WayForPay sandbox seller',
    'legal_name' => 'WayForPay sandbox recipient',
    'tax_id' => 'SANDBOX-TEST',
], [
    'recipient_name' => 'WayForPay sandbox recipient',
    'iban' => 'UA893052990000026001234567890',
    'bank_name' => 'Sandbox bank',
    'purpose_template' => 'Sandbox order {order_number}',
    'merchant_account' => 'test_merch_n1',
    'merchant_domain' => $domain,
    'card_enabled' => 1,
    'iban_enabled' => 0,
    'secret_plaintext' => $secret,
    'operator_checked' => false,
], SecretStore::encrypt($secret), 1);

$product = new WC_Product_Simple();
$product->set_name('WayForPay sandbox item');
$product->set_status('publish');
$product->set_regular_price('10.00');
$product->set_virtual(true);
$productId = $product->save();
update_post_meta($productId, ProductRouting::META_SELLER_ID, $sellerId);

$order = wc_create_order();
$order->set_billing_first_name('Sandbox');
$order->set_billing_last_name('Buyer');
$order->set_billing_email('buyer@example.test');
$order->set_billing_phone('+380000000000');
$order->add_product(wc_get_product($productId), 1);
$order->set_payment_method('wfpms_card');
$order->calculate_totals();
$order->save();

$gateway = WC()->payment_gateways()->payment_gateways()['wfpms_card'] ?? null;
wfpms_sandbox_assert($gateway instanceof WayForPayGateway, 'WayForPay card gateway is unavailable.');
$result = $gateway->process_payment((int) $order->get_id());
wfpms_sandbox_assert(($result['result'] ?? '') === 'success' && !empty($result['redirect']), 'Could not create the sandbox payment attempt.');
$purchase = PurchaseRepository::for_order((int) $order->get_id());
wfpms_sandbox_assert($purchase !== null, 'Sandbox purchase record was not created.');
global $wpdb;
$storedPayload = $wpdb->get_var($wpdb->prepare(
    'SELECT payload FROM ' . PurchaseRepository::attempts_table() . ' WHERE purchase_id=%d ORDER BY id DESC LIMIT 1',
    (int) $purchase['id']
));
$paymentPayload = is_string($storedPayload) ? json_decode($storedPayload, true) : null;
wfpms_sandbox_assert(is_array($paymentPayload), 'Signed WayForPay request was not stored.');
foreach (['serviceUrl', 'returnUrl'] as $urlField) {
    $urlParts = isset($paymentPayload[$urlField]) ? wp_parse_url((string) $paymentPayload[$urlField]) : false;
    wfpms_sandbox_assert(is_array($urlParts)
        && ($urlParts['scheme'] ?? '') === 'https'
        && ($urlParts['host'] ?? '') === $domain, 'Signed WayForPay callback and return URLs must use the HTTPS tunnel.');
}

$manifest = wp_json_encode([
    'order_id' => (int) $order->get_id(),
    'order_key' => (string) $order->get_order_key(),
    'redirect_url' => (string) $result['redirect'],
], JSON_UNESCAPED_SLASHES);
wfpms_sandbox_assert(is_string($manifest) && file_put_contents($outputPath, $manifest, LOCK_EX) !== false, 'Could not persist the private sandbox browser handoff.');
@chmod($outputPath, 0600);
echo "PASS: created one disposable WooCommerce sandbox order and signed payment attempt.\n";
