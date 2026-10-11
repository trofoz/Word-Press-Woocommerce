<?php
/** Addressed staging operator adapter. No SQL writes, no queue, no early expiry. */
use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\SellerRepository;
use WfpMultiSeller\SecretStore;

if (!defined('WP_CLI') || !WP_CLI || home_url() !== 'https://staging.ilovesad.com'
    || !current_user_can('manage_woocommerce') || !method_exists(PurchaseExecutionService::class, 'expire_unpaid_purchase')) {
    throw new RuntimeException('Staging WP-CLI with a WooCommerce administrator is required.');
}
$expected = [13 => [47521, 'card'], 14 => [47522, 'card'], 15 => [47523, 'card'], 28 => [47536, 'card'],
    16 => [47524, 'iban'], 17 => [47525, 'iban'], 29 => [47537, 'iban'], 30 => [47538, 'iban']];
$selected = getenv('WFPMS_SETTLEMENT_IDS') ?: implode(',', array_keys($expected));
if (!preg_match('/\A\d+(?:,\d+)*\z/', $selected)) { throw new RuntimeException('Invalid purchase ID list.'); }
$ids = array_values(array_unique(array_map('intval', explode(',', $selected))));
$mode = getenv('WFPMS_SETTLEMENT_MODE') ?: 'preview';
if (!in_array($mode, ['preview', 'apply'], true)) { throw new RuntimeException('Unknown mode.'); }
$approved = [];
if ($mode === 'apply') {
    $path = getenv('WFPMS_SETTLEMENT_PREVIEW_FILE') ?: '';
    $document = $path !== '' && is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if (!is_array($document) || ($document['site'] ?? '') !== home_url()
        || ($document['ids'] ?? []) !== $ids || ($document['mode'] ?? '') !== 'preview'
        || !is_array($document['purchases'] ?? null)) {
        throw new RuntimeException('Matching reviewed preview file is required.');
    }
    foreach ($document['purchases'] as $row) { $approved[(int) $row['purchase_id']] = $row; }
}
$previews = [];
foreach ($ids as $id) {
    if (!isset($expected[$id])) { throw new RuntimeException('Purchase outside the eight approved IDs.'); }
    [$orderId, $channel] = $expected[$id];
    $purchase = PurchaseRepository::for_order($orderId);
    $attempts = PurchaseRepository::attempts_for_purchase($id);
    if ($purchase === null || (int) $purchase['id'] !== $id || $attempts === []) {
        throw new RuntimeException('Staging purchase/order mapping or attempt missing.');
    }
    foreach ($attempts as $attempt) {
        if ($attempt['channel'] !== $channel) { throw new RuntimeException('Unexpected payment channel.'); }
        if ($channel === 'card') {
            $config = SellerRepository::config((int) $attempt['config_id']);
            if ($attempt['external_reference'] === '' || $config === null
                || SecretStore::decrypt((string) $config['secret_ciphertext']) === null) {
                throw new RuntimeException('Pinned card reference or historical key unavailable.');
            }
        }
    }
    $preview = PurchaseExecutionService::expire_unpaid_purchase($id);
    if ($mode === 'apply' && (empty($preview['eligible']) || empty($approved[$id]['eligible'])
        || !hash_equals($preview['preview_hash'] ?? '', $approved[$id]['preview_hash'] ?? ''))) {
        throw new RuntimeException('Apply stopped before changes: ineligible or stale preview for purchase ' . $id);
    }
    $previews[] = $preview;
}
$results = $previews;
if ($mode === 'apply') {
    $results = [];
    foreach ($ids as $id) {
        $result = PurchaseExecutionService::expire_unpaid_purchase($id, false, $approved[$id]['preview_hash']);
        $results[] = $result;
        if (!in_array($result['result'], ['expired', 'already_expired'], true)) { break; }
    }
}
echo wp_json_encode(['site' => home_url(), 'mode' => $mode, 'ids' => $ids,
    'at' => current_time('mysql', true), 'purchases' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($mode === 'apply' && (count($results) !== count($ids)
    || array_filter($results, static fn(array $row): bool => !in_array($row['result'], ['expired', 'already_expired'], true)))) {
    throw new RuntimeException('Settlement interrupted; inspect partial result, never force a state or retry a stale preview.');
}
