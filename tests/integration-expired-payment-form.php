<?php

use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\WayForPayGateway;

global $wpdb;
$orderId = (int) get_option('wfpms_integration_iban_order_id', 0);
$purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
if ($purchase === null) {
    throw new RuntimeException('Expired payment-form integration fixture is missing.');
}

$expiredAt = gmdate('Y-m-d H:i:s', time() - 60);
$wpdb->update(PurchaseRepository::purchases_table(), [
    'state' => 'awaiting_payment',
    'expires_at' => $expiredAt,
], ['id' => (int) $purchase['id']]);
$reference = 'wfpms-expired-integration-' . bin2hex(random_bytes(8));
$payload = wp_json_encode(['merchantAccount' => 'integration', 'amount' => (string) $purchase['amount']]);
if (!is_string($payload) || $wpdb->insert(PurchaseRepository::attempts_table(), [
    'purchase_id' => (int) $purchase['id'],
    'channel' => 'card',
    'merchant_account' => 'integration',
    'external_reference' => $reference,
    'config_id' => (int) $purchase['config_id'],
    'credential_key_id' => '',
    'expected_amount' => (string) $purchase['amount'],
    'amount_scale' => 2,
    'currency' => (string) $purchase['currency'],
    'payload' => $payload,
    'status' => 'issued',
    'issued_at' => current_time('mysql', true),
    'expires_at' => $expiredAt,
]) !== 1) {
    throw new RuntimeException('Could not create the expired payment-form fixture.');
}

$caught = null;
add_filter('wp_die_handler', static function ($handler) use (&$caught) {
    return static function ($message = '', $title = '', $args = []) use (&$caught): never {
        $caught = ['message' => (string) $message, 'args' => $args];
        throw new RuntimeException('Captured wp_die for integration assertion.');
    };
});
$token = hash_hmac('sha256', $orderId . ':' . $reference, wp_salt('auth'));
$_GET = ['order_id' => (string) $orderId, 'reference' => $reference, 'token' => $token];
ob_start();
try {
    WayForPayGateway::render_payment_form();
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Captured wp_die for integration assertion.') {
        throw $error;
    }
}
$body = (string) ob_get_clean();
if (!is_array($caught) || (int) ($caught['args']['response'] ?? 0) !== 410
    || !str_contains($caught['message'], 'Строк оплати минув')
    || str_contains($body, 'secure.wayforpay.com') || str_contains($body, 'id="wfpms-pay"')) {
    throw new RuntimeException('Expired payment links must return HTTP 410 without rendering the provider form.');
}
echo "PASS: expired WayForPay link rejected before the hosted payment form\n";
