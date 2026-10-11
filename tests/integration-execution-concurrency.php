<?php

use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\PurchaseRepository;

global $wpdb;
$mode = getenv('WFPMS_EXECUTION_CONCURRENCY_MODE') ?: '';
$fixture = get_option('wfpms_execution_concurrency_fixture', []);
$purchaseId = (int) ($fixture['purchase_id'] ?? 0);
$orderId = (int) ($fixture['order_id'] ?? 0);
if ($purchaseId < 1 || $orderId < 1 || !in_array($mode, ['worker', 'assert'], true)) {
    throw new RuntimeException('Concurrent execution fixture is missing.');
}
if ($mode === 'worker') {
    $increment = static function (string $option) use ($wpdb): void {
        // Atomic DB counters detect duplicate hooks even across independent PHP processes.
        if ($wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=CAST(option_value AS UNSIGNED)+1 WHERE option_name=%s", $option
        )) !== 1) {
            throw new RuntimeException('Could not increment concurrent hook counter.');
        }
    };
    add_action('woocommerce_order_status_completed', static function (int $id) use ($orderId, $increment): void {
        if ($id === $orderId) {
            $increment('wfpms_execution_concurrency_completed');
            usleep(300000); // Keep the order lock held while the competing worker arrives.
        }
    });
    add_action('woocommerce_payment_complete', static function (int $id) use ($orderId, $increment): void {
        if ($id === $orderId) {
            $increment('wfpms_execution_concurrency_payment');
        }
    });
    $increment('wfpms_execution_concurrency_ready');
    $deadline = microtime(true) + 10;
    do {
        $ready = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'wfpms_execution_concurrency_ready'
        ));
        if ($ready >= 2) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    if ($ready !== 2) {
        throw new RuntimeException('Concurrent execution workers did not reach the start barrier.');
    }
    do_action('wfpms_process_execution', $purchaseId);
    do_action('wfpms_process_execution', $purchaseId);
    echo "PASS: concurrent execution worker returned.\n";
    return;
}
$counts = $wpdb->get_results($wpdb->prepare(
    "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN (%s,%s)",
    'wfpms_execution_concurrency_completed', 'wfpms_execution_concurrency_payment'
), OBJECT_K);
$purchase = PurchaseRepository::for_order($orderId);
$done = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d AND kind=%s', $purchaseId, 'execution_done'
));
$receipts = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d', $purchaseId
));
if (wc_get_order($orderId)->get_status() !== 'completed' || (string) $purchase['execution_state'] !== 'done'
    || $done !== 1 || $receipts !== 2 || (int) $counts['wfpms_execution_concurrency_completed']->option_value !== 1
    || (int) $counts['wfpms_execution_concurrency_payment']->option_value !== 1) {
    throw new RuntimeException('FAIL: concurrent runner duplicated or lost completion.');
}
wp_clear_scheduled_hook('wfpms_process_execution', [$purchaseId]);
echo "PASS: two concurrent PHP workers and repeated runner calls: completed=1; payment_complete=1; execution_done=1; receipts=2.\n";
