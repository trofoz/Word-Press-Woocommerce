<?php

use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\PurchaseRepository;

function wfpms_sandbox_verify_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$inputPath = getenv('WFPMS_SANDBOX_INPUT');
wfpms_sandbox_verify_assert(is_string($inputPath) && is_file($inputPath), 'Sandbox browser handoff file is missing.');
$manifest = json_decode((string) file_get_contents($inputPath), true);
wfpms_sandbox_verify_assert(is_array($manifest) && (int) ($manifest['order_id'] ?? 0) > 0, 'Sandbox handoff is invalid.');
$orderId = (int) $manifest['order_id'];
$deadline = time() + 120;
$purchase = null;
do {
    $purchase = PurchaseRepository::for_order($orderId);
    if ($purchase !== null && (string) $purchase['state'] === 'paid') {
        break;
    }
    usleep(1_000_000);
} while (time() < $deadline);

wfpms_sandbox_verify_assert($purchase !== null && (string) $purchase['state'] === 'paid', 'WayForPay did not deliver an accepted payment callback within two minutes.');
wfpms_sandbox_verify_assert((int) $purchase['accepted_receipt_id'] > 0, 'Accepted callback receipt was not stored.');
global $wpdb;
$accepted = $wpdb->get_row($wpdb->prepare(
    'SELECT id,classification FROM ' . PurchaseRepository::receipts_table() . ' WHERE id=%d AND purchase_id=%d',
    (int) $purchase['accepted_receipt_id'],
    (int) $purchase['id']
), ARRAY_A);
wfpms_sandbox_verify_assert(is_array($accepted) && (string) $accepted['classification'] === 'accepted', 'Callback receipt was not classified as accepted.');
$approvedEventCount = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s',
    (int) $purchase['id'],
    'callback_Approved'
));
wfpms_sandbox_verify_assert($approvedEventCount === 1, 'Signed Approved callback evidence was not stored exactly once.');

PurchaseExecutionService::run((int) $purchase['id']);
$order = wc_get_order($orderId);
$purchase = PurchaseRepository::for_order($orderId);
wfpms_sandbox_verify_assert($order instanceof WC_Order && $order->is_paid(), 'WooCommerce order did not reach its paid state.');
wfpms_sandbox_verify_assert($purchase !== null && (string) $purchase['execution_state'] === 'done', 'Payment completion did not finish exactly once.');
echo "PASS: WayForPay signed callback, accepted receipt, and WooCommerce payment completion verified.\n";
