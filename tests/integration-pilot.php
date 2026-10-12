<?php

use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\PurchaseExecutionService;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;

function pilot_assert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
}

$phase = getenv('WFPMS_PILOT_PHASE') ?: 'default';
if ($phase === 'default') {

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
return;
}

$fixture = get_option('wfpms_pilot_fixture');
$buyerId = (int) $fixture['buyer_id'];
$otherId = (int) $fixture['other_id'];
$products = $fixture['products'];
$gateways = WC()->payment_gateways()->payment_gateways();
if (!WC()->cart) { wc_load_cart(); }
$createOrder = static function (int $productId, string $method = 'wfpms_card') use ($buyerId): WC_Order {
    wp_set_current_user($buyerId);
    $order = wc_create_order(['customer_id' => $buyerId]);
    $order->add_product(wc_get_product($productId), 1);
    $order->set_payment_method($method);
    $order->set_billing_email('pilot-buyer@example.test');
    $order->calculate_totals();
    $order->save();
    return $order;
};
$assertDenied = static function (WC_Order $order, int $userId, string $method) use ($gateways): void {
    wp_set_current_user($userId);
    $before = PurchaseRepository::for_order((int) $order->get_id());
    $beforeAttempts = $before === null ? [] : PurchaseRepository::attempts_for_purchase((int) $before['id']);
    $result = $gateways[$method]->process_payment((int) $order->get_id());
    $after = PurchaseRepository::for_order((int) $order->get_id());
    pilot_assert(($result['result'] ?? '') === 'failure' && $before === $after
        && ($after === null || $beforeAttempts === PurchaseRepository::attempts_for_purchase((int) $after['id'])),
        'Denied pilot caller cannot create or change a purchase or payment attempt.');
    wc_clear_notices();
};

if ($phase === 'empty') {
    // There is an authorized account but the allowlist is intentionally empty.
    wp_set_current_user($buyerId);
    $order = wc_create_order(['customer_id' => $buyerId]);
    $order->add_product(wc_get_product($products['webinar']), 1);
    $order->set_payment_method('wayforpay');
    $order->calculate_totals();
    $order->save();
    foreach (['wfpms_card', 'wfpms_iban'] as $method) { $assertDenied($order, $buyerId, $method); }
    $new = new WC_Order();
    $new->set_customer_id($buyerId);
    $new->add_product(wc_get_product($products['webinar']), 1);
    $new->set_payment_method('wfpms_card');
    $blocked = false;
    try { $blocked = (int) $new->save() === 0; } catch (Throwable $error) { $blocked = true; }
    pilot_assert($blocked && $new->get_id() === 0, 'Empty allowlist blocks new fork order persistence.');
    echo "PASS: empty pilot allowlist blocks authenticated fork orders and both direct gateways.\n";
    return;
}

if ($phase === 'configured') {
    $saved = get_option('ilovesad_wfpms_pilot');
    pilot_assert($saved['buyer_id'] === $buyerId && $saved['product_ids'] === [$products['book'], $products['webinar']]
        && $saved['enabled'] === true, 'Authenticated admin HTTP saved the intended local ID policy.');
    foreach (['wfpms_card', 'wfpms_iban'] as $method) {
        $order = $createOrder($products['webinar'], $method);
        foreach ([0, $otherId, (int) $fixture['admin_id']] as $userId) { $assertDenied($order, $userId, $method); }
        $wrongOwner = $createOrder($products['webinar'], $method);
        $wrongOwner->set_customer_id($otherId);
        $wrongOwner->save();
        $assertDenied($wrongOwner, $buyerId, $method);
        $wrongItems = $createOrder($products['webinar'], $method);
        $wrongItems->add_product(wc_get_product($products['normal']), 1);
        $wrongItems->calculate_totals();
        $wrongItems->save();
        $assertDenied($wrongItems, $buyerId, $method);
        $bothSellers = $createOrder($products['webinar'], $method);
        $bothSellers->add_product(wc_get_product($products['book']), 1);
        $bothSellers->calculate_totals();
        $bothSellers->save();
        $assertDenied($bothSellers, $buyerId, $method);
    }
    foreach ([0, $otherId, (int) $fixture['admin_id']] as $userId) {
        wp_set_current_user($userId);
        $order = new WC_Order();
        $order->set_customer_id($buyerId);
        $order->set_billing_email('pilot-buyer@example.test');
        $order->add_product(wc_get_product($products['webinar']), 1);
        $order->set_payment_method('wfpms_iban');
        $blocked = false;
        try { $blocked = (int) $order->save() === 0; } catch (Throwable $error) { $blocked = true; }
        pilot_assert($blocked && $order->get_id() === 0, 'Forged email/customer ID cannot create a new fork order.');
    }
    wp_set_current_user($buyerId);
    $wrongMethod = new WC_Order();
    $wrongMethod->set_customer_id($buyerId);
    $wrongMethod->add_product(wc_get_product($products['webinar']), 1);
    $wrongMethod->set_payment_method('wayforpay');
    $blocked = false;
    try { $blocked = (int) $wrongMethod->save() === 0; } catch (Throwable $error) { $blocked = true; }
    pilot_assert($blocked && $wrongMethod->get_id() === 0, 'New control orders cannot bypass the pilot via an original gateway.');
    echo "PASS: pilot guest/other/admin identity spoofing, changed owner/items and mixed sellers create zero purchases or attempts.\n";

    wp_set_current_user($buyerId);
    WC()->cart->empty_cart();
    pilot_assert((bool) WC()->cart->add_to_cart($products['webinar']), 'Authorized pilot cart accepts its webinar.');
    pilot_assert(apply_filters('woocommerce_add_to_cart_validation', true, $products['normal'], 1) === false,
        'Classic control plus ordinary add-to-cart is rejected.');
    pilot_assert(apply_filters('woocommerce_add_to_cart_validation', true, $products['book'], 1) === false,
        'Classic control A plus B is rejected by distinct-seller policy.');
    foreach (['normal', 'book'] as $name) {
        $rejected = false;
        try { do_action('woocommerce_store_api_validate_add_to_cart', wc_get_product($products[$name]),
            ['id' => $products[$name], 'quantity' => 1]); } catch (Throwable $error) { $rejected = true; }
        pilot_assert($rejected, 'Store API rejects control/ordinary or cross-seller composition.');
    }
    wc_clear_notices();
    WC()->cart->empty_cart();
    foreach ([0, $otherId, $buyerId] as $userId) {
        wp_set_current_user($userId);
        pilot_assert((bool) WC()->cart->add_to_cart($products['normal']), 'Ordinary unmapped product stays purchasable.');
        wc_clear_notices();
        WC()->cart->calculate_totals();
        $available = WC()->payment_gateways()->get_available_payment_gateways();
        pilot_assert(isset($available['wayforpay'], $available['bacs'])
            && !isset($available['wfpms_card']) && !isset($available['wfpms_iban']),
            'Ordinary carts retain original gateways for guest, other and pilot buyer.');
        do_action('woocommerce_check_cart_items');
        pilot_assert(wc_notice_count('error') === 0, 'Original checkout has no fork seller-assignment error.');
        WC()->cart->empty_cart();
    }
    echo "PASS: ordinary unmapped carts keep original gateways and validation for guest, other and pilot buyer.\n";

    $issued = [];
    foreach (['book', 'webinar'] as $name) {
        foreach (['wfpms_card', 'wfpms_iban'] as $method) {
            $order = $createOrder($products[$name], $method);
            $result = $gateways[$method]->process_payment((int) $order->get_id());
            $purchase = PurchaseRepository::for_order((int) $order->get_id());
            pilot_assert(($result['result'] ?? '') === 'success' && $purchase !== null,
                'Authorized local account and exact control ID create a real fork attempt.');
            pilot_assert(wc_get_order((int) $order->get_id())->get_status() === ($method === 'wfpms_card' ? 'pending' : 'on-hold'),
                'Issued pilot card/IBAN preserves pending/on-hold contract.');
            $repeat = $gateways[$method]->process_payment((int) $order->get_id());
            pilot_assert(($repeat['result'] ?? '') === 'success'
                && count(PurchaseRepository::attempts_for_purchase((int) $purchase['id'])) === 1,
                'Authorized retry reuses its existing attempt.');
            $assertDenied($order, 0, $method);
            $assertDenied($order, $otherId, $method);
            $issued[] = ['order_id' => (int) $order->get_id(), 'purchase_id' => (int) $purchase['id'],
                'method' => $method, 'name' => $name];
            if ($method === 'wfpms_card') { $order->update_meta_data('_pilot_runtime_payment_url', $result['redirect']); $order->save(); }
        }
    }
    update_option('wfpms_pilot_issued', $issued, false);
    file_put_contents(getenv('WFPMS_PILOT_ISSUED_PATH'), wp_json_encode($issued));
    echo "PASS: exact local pilot IDs issue card and IBAN for both sellers; retries by guest/other are blocked without losing existing attempts.\n";
    return;
}

if ($phase === 'http-orders') {
    $data = json_decode(file_get_contents(getenv('WFPMS_PILOT_HTTP_ORDERS_PATH')), true);
    pilot_assert(count($data['ordinary']) === 6 && count($data['control']) === 2, 'All HTTP checkout paths ran.');
    foreach ($data['ordinary'] as $id) {
        $order = wc_get_order($id);
        pilot_assert($order instanceof WC_Order && $order->get_payment_method() === 'bacs'
            && $order->get_status() === 'on-hold' && PurchaseRepository::for_order($id) === null,
            'Ordinary HTTP checkout has native payment method and no fork financial snapshot.');
    }
    foreach ($data['control'] as $id) {
        $order = wc_get_order($id);
        $purchase = PurchaseRepository::for_order($id);
        pilot_assert($order instanceof WC_Order && $order->get_payment_method() === 'wfpms_iban'
            && $order->get_status() === 'on-hold' && (int) $order->get_customer_id() === $buyerId
            && $purchase !== null && (string) $purchase['amount'] === '100.00'
            && count(PurchaseRepository::attempts_for_purchase((int) $purchase['id'])) === 1,
            'Control HTTP checkout persists exact authenticated owner and one IBAN attempt.');
    }
    echo "PASS: six native ordinary HTTP orders have no fork snapshot; Classic/Store API control orders have exact authenticated owner and one 100 UAH IBAN attempt.\n";
    return;
}

if ($phase === 'closed') {
    global $wpdb;
    pilot_assert(get_option('ilovesad_wfpms_pilot')['enabled'] === false, 'Native close action stopped the pilot.');
    $completed = [];
    $payments = [];
    add_action('woocommerce_order_status_completed', static function (int $id) use (&$completed): void {
        $completed[$id] = ($completed[$id] ?? 0) + 1;
    });
    add_action('woocommerce_payment_complete', static function (int $id) use (&$payments): void {
        $payments[$id] = ($payments[$id] ?? 0) + 1;
    });
    foreach (get_option('wfpms_pilot_issued') as $issued) {
        $order = wc_get_order($issued['order_id']);
        $assertDenied($order, $buyerId, $issued['method']);
        wp_set_current_user(0);
        if ($issued['method'] === 'wfpms_card') {
            $form = wp_remote_get((string) $order->get_meta('_pilot_runtime_payment_url'));
            pilot_assert(!is_wp_error($form) && wp_remote_retrieve_response_code($form) === 200
                && str_contains(wp_remote_retrieve_body($form), 'https://secure.wayforpay.com/pay'),
                'Previously issued hosted form stays accessible after closing the pilot; no external payment submitted.');
            $attempt = PurchaseRepository::attempts_for_purchase($issued['purchase_id'])[0];
            $config = SellerRepository::config((int) $attempt['config_id']);
            $secret = SecretStore::decrypt($config['secret_ciphertext']);
            $fields = ['merchantAccount' => $attempt['merchant_account'], 'orderReference' => $attempt['external_reference'],
                'amount' => $attempt['expected_amount'], 'currency' => $attempt['currency'], 'authCode' => 'PILOT-APPROVED',
                'cardPan' => '411111******1111', 'transactionStatus' => 'Approved', 'reasonCode' => '1100'];
            $fields['merchantSignature'] = hash_hmac('md5', implode(';', array_values($fields)), $secret);
            $payload = json_decode($attempt['payload'], true);
            for ($i = 0; $i < 2; $i++) {
                $response = wp_remote_post($payload['serviceUrl'], ['headers' => ['Content-Type' => 'application/json'],
                    'body' => wp_json_encode($fields)]);
                $ack = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
                pilot_assert(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200
                    && ($ack['status'] ?? '') === 'accept'
                    && hash_equals(hash_hmac('md5', $attempt['external_reference'] . ';accept;' . $ack['time'], $secret), $ack['signature']),
                    'Issued signed card callback is accepted with pilot closed and no logged-in user.');
            }
        }
        PurchaseExecutionService::run($issued['purchase_id']);
        PurchaseExecutionService::run($issued['purchase_id']);
        $fresh = PurchaseRepository::for_order($issued['order_id']);
        pilot_assert(wc_get_order($issued['order_id'])->get_status() === 'completed'
            && $fresh['execution_state'] === 'done' && count(PurchaseRepository::receipts_for_purchase($issued['purchase_id'])) === 1
            && ($completed[$issued['order_id']] ?? 0) === 1 && ($payments[$issued['order_id']] ?? 0) === 1,
            'Closed pilot retains one accepted receipt and one completed/payment_complete for its issued card/IBAN.');
    }
    $original = $gateways['wayforpay'];
    pilot_assert(!WfpMultiSeller\LegacyMigration::owns_callback_route()
        && has_action('woocommerce_api_wc_wayforpay', [$original, 'check_wayforpay_response']) !== false,
        'Original historical callback handler remains registered and owned by the original.');
    echo "PASS: closed pilot blocks new/retry attempts but guest signed callbacks, native IBAN confirmation and runner complete all four issued payments exactly once; original callback ownership unchanged.\n";
    return;
}
throw new RuntimeException('Unknown pilot integration phase.');
