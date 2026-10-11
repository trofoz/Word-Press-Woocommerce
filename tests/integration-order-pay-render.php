<?php

$fixturePath = getenv('WFPMS_HTTP_ORDER_PAY_PATHS_PATH');
$fixtures = is_string($fixturePath) && is_file($fixturePath)
    ? json_decode((string) file_get_contents($fixturePath), true)
    : null;
if (!is_array($fixtures)) {
    throw new RuntimeException('Order-pay rendering fixture is unavailable.');
}

$wpUser = get_user_by('login', 'test-admin');
if (!$wpUser instanceof WP_User) {
    throw new RuntimeException('Disposable test user is unavailable for direct order-pay rendering.');
}
wp_set_current_user((int) $wpUser->ID);
if (function_exists('wc_load_cart')) {
    wc_load_cart();
}

global $wp, $wp_query;
if (!is_object($wp) || !is_object($wp_query)) {
    throw new RuntimeException('WordPress query context is unavailable for direct rendering.');
}
$savedWpVars = $wp->query_vars;
$savedQueryVars = $wp_query->query_vars;
$savedGet = $_GET;
$rendered = [];
try {
    foreach (['classic_order_id', 'blocks_order_id'] as $fixtureName) {
        $fixture = $fixtures[$fixtureName] ?? [];
        $orderId = (int) ($fixture['order_id'] ?? 0);
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!$order instanceof WC_Order) {
            throw new RuntimeException("Order-pay fixture {$fixtureName} is unavailable.");
        }

        $wp->query_vars['order-pay'] = (string) $orderId;
        $wp_query->query_vars['order-pay'] = (string) $orderId;
        $_GET['order-pay'] = (string) $orderId;
        $_GET['pay_for_order'] = 'true';
        $_GET['key'] = $order->get_order_key();

        $rendered[$fixtureName] = do_shortcode('[woocommerce_checkout]');
        if (!str_contains($rendered[$fixtureName], 'Integration Seller 1')
            || !str_contains($rendered[$fixtureName], 'payment_method_wfpms_iban')
            || str_contains($rendered[$fixtureName], 'payment_method_wfpms_card')) {
            throw new RuntimeException(
                "Direct order-pay shortcode output is incorrect for {$fixtureName}; "
                . 'bytes=' . strlen($rendered[$fixtureName])
                . '; seller=' . (str_contains($rendered[$fixtureName], 'Integration Seller 1') ? 'yes' : 'no')
                . '; iban=' . (str_contains($rendered[$fixtureName], 'payment_method_wfpms_iban') ? 'yes' : 'no')
                . '; card=' . (str_contains($rendered[$fixtureName], 'payment_method_wfpms_card') ? 'yes' : 'no')
            );
        }
    }
} finally {
    $wp->query_vars = $savedWpVars;
    $wp_query->query_vars = $savedQueryVars;
    $_GET = $savedGet;
}

echo "PASS: direct WooCommerce checkout shortcode renders saved order-pay methods for Classic and Blocks orders\n";
