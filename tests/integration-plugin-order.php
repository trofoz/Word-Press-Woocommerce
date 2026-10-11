<?php

use WfpMultiSeller\LegacyMigration;

$activePlugins = (array) get_option('active_plugins', []);
$upstreamActive = array_filter($activePlugins, static fn(string $plugin): bool => str_starts_with($plugin, 'wayforpay-upstream/'));
$forkActive = array_filter($activePlugins, static fn(string $plugin): bool => str_starts_with($plugin, 'wayforpay-multi-seller/'));
if ($upstreamActive === [] || $forkActive === []) {
    throw new RuntimeException('Both WayForPay plugins must be active after reverse-order reactivation.');
}

$gateways = WC()->payment_gateways()->payment_gateways();
$upstream = $gateways['wayforpay'] ?? null;
if (!$upstream instanceof WC_Payment_Gateway || get_class($upstream) !== 'WC_wayforpay') {
    throw new RuntimeException('The upstream gateway did not load after reverse-order reactivation.');
}
if (!isset($gateways['wfpms_card'], $gateways['wfpms_iban'])) {
    throw new RuntimeException('Fork gateways did not load after reverse-order reactivation.');
}
if (has_action('woocommerce_api_wc_wayforpay', [LegacyMigration::class, 'handle_callback']) !== 0
    || has_action('woocommerce_api_wc_wayforpay', [$upstream, 'check_wayforpay_response']) !== 10) {
    throw new RuntimeException('Reverse activation order changed callback ownership or priority.');
}

echo "PASS: upstream-first reactivation preserves both gateways and fork callback ownership\n";
