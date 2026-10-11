<?php

use WfpMultiSeller\Installer;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SellerRepository;

function wfpms_schema_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

global $wpdb;
$orderId = (int) get_option('wfpms_integration_iban_order_id', 0);
$purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
wfpms_schema_assert($purchase !== null, 'schema upgrade fixture purchase exists');
$purchaseId = (int) $purchase['id'];
$before = [
    'order_id' => (int) $purchase['order_id'],
    'seller_id' => (int) $purchase['seller_id'],
    'config_id' => (int) $purchase['config_id'],
    'amount' => (string) $purchase['amount'],
    'currency' => (string) $purchase['currency'],
    'snapshot_hash' => (string) $purchase['snapshot_hash'],
    'receipt_count' => (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d', $purchaseId
    )),
    'event_count' => (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d', $purchaseId
    )),
];
$legacyTable = WfpMultiSeller\LegacyMigration::migrations_table();
wfpms_schema_assert($wpdb->query('DROP TABLE ' . $legacyTable) !== false, 'v3 fixture drops the v4-only legacy migration table');
update_option('wfpms_schema_version', 3, false);
Installer::maybe_upgrade();
wfpms_schema_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacyTable)) === $legacyTable,
    'v3 upgrade creates the legacy migration table');
wfpms_schema_assert((int) get_option('wfpms_schema_version') === Installer::SCHEMA_VERSION,
    'v3 upgrade advances to the current schema version');

$receiptsTable = PurchaseRepository::receipts_table();
$legacyReceipt = [
    'purchase_id' => $purchaseId,
    'attempt_id' => 0,
    'source' => 'wayforpay',
    'source_scope' => 'schema-upgrade-merchant',
    'external_id' => 'schema-upgrade-reference',
    'amount' => '1.00',
    'amount_raw' => '1.00',
    'currency' => 'UAH',
    'transaction_status' => 'operator_confirmed',
    'classification' => 'needs_review',
    'recorded_at' => current_time('mysql', true),
];
wfpms_schema_assert($wpdb->insert($receiptsTable, $legacyReceipt) === 1, 'v4 fixture stores a WayForPay receipt before removing the new provider reference column');
$legacyReceiptId = (int) $wpdb->insert_id;
$before['receipt_count']++;
wfpms_schema_assert($wpdb->query("ALTER TABLE {$receiptsTable} DROP COLUMN provider_reference") !== false,
    'v4 fixture removes the v5 provider reference column');
update_option('wfpms_schema_version', 4, false);

Installer::maybe_upgrade();
wfpms_schema_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacyTable)) === $legacyTable,
    'v4 to v5 upgrade preserves the legacy migration table');
wfpms_schema_assert((int) get_option('wfpms_schema_version') === Installer::SCHEMA_VERSION,
    'schema version advances only after the upgrade');
$migratedReceipt = $wpdb->get_row($wpdb->prepare(
    "SELECT provider_reference FROM {$receiptsTable} WHERE id=%d", $legacyReceiptId
), ARRAY_A);
wfpms_schema_assert(is_array($migratedReceipt)
    && (string) $migratedReceipt['provider_reference'] === 'schema-upgrade-reference',
    'schema upgrade backfills the provider reference from existing WayForPay receipts');

$after = PurchaseRepository::for_order($orderId);
wfpms_schema_assert($after !== null
    && (int) $after['order_id'] === $before['order_id']
    && (int) $after['seller_id'] === $before['seller_id']
    && (int) $after['config_id'] === $before['config_id']
    && (string) $after['amount'] === $before['amount']
    && (string) $after['currency'] === $before['currency']
    && (string) $after['snapshot_hash'] === $before['snapshot_hash'],
    'schema upgrade preserves the financial purchase snapshot');
wfpms_schema_assert((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d', $purchaseId
)) === $before['receipt_count'], 'schema upgrade preserves payment receipts');
wfpms_schema_assert((int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . PurchaseRepository::events_table() . ' WHERE purchase_id=%d', $purchaseId
)) === $before['event_count'], 'schema upgrade preserves payment events');
wfpms_schema_assert(wc_get_order($orderId) instanceof WC_Order, 'schema upgrade preserves the WooCommerce order');

Installer::maybe_upgrade();
wfpms_schema_assert((int) get_option('wfpms_schema_version') === Installer::SCHEMA_VERSION
    && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacyTable)) === $legacyTable,
    'repeated upgrade check is idempotent');

echo "PASS: v3 legacy-table and v4 provider-reference schema upgrades preserve financial data\n";
