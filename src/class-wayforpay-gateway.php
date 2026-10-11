<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Card payment gateway and WayForPay server callback endpoint. */
final class WayForPayGateway extends \WC_Payment_Gateway
{
    private const GATEWAY_ID = 'wfpms_card';
    private const CALLBACK_ACTION = 'wfpms_wayforpay';
    private const PAY_ACTION = 'wfpms_pay';
    private const PAY_URL = 'https://secure.wayforpay.com/pay';

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('Оплата карткою', 'wayforpay-multi-seller');
        $this->method_description = __('Карткова оплата через мерчанта, закріпленого за продавцем замовлення.', 'wayforpay-multi-seller');
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->title = __('Оплата карткою', 'wayforpay-multi-seller');
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', $this->title);
    }

    public static function boot(): void
    {
        add_action('woocommerce_api_' . self::CALLBACK_ACTION, [self::class, 'handle_callback']);
        add_action('admin_post_' . self::PAY_ACTION, [self::class, 'render_payment_form']);
        add_action('admin_post_nopriv_' . self::PAY_ACTION, [self::class, 'render_payment_form']);
    }

    public static function register(array $gateways): array
    {
        $gateways[] = self::class;
        $gateways[] = BankTransferGateway::class;
        return $gateways;
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Увімкнути/вимкнути', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Увімкнути оплату карткою WayForPay', 'wayforpay-multi-seller'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Назва способу оплати', 'wayforpay-multi-seller'),
                'type' => 'text',
                'default' => __('Оплата карткою', 'wayforpay-multi-seller'),
            ],
        ];
    }

    public function is_available(): bool
    {
        if (!parent::is_available() || !LegacyMigration::is_transition_enabled() || !function_exists('WC')) {
            return false;
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $orderId = absint(get_query_var('order-pay'));
            $order = $orderId > 0 ? wc_get_order($orderId) : false;
            $purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
            if (!$order instanceof \WC_Order || $purchase === null
                || !in_array($order->get_status(), ['pending', 'on-hold'], true)
                || !in_array((string) $purchase['state'], ['ready', 'awaiting_payment'], true)) {
                return false;
            }
            $activeChannel = PurchaseRepository::active_attempt_channel((int) $purchase['id']);
            if ($activeChannel !== null && $activeChannel !== 'card') {
                return false;
            }
            $config = SellerRepository::config((int) $purchase['config_id']);
            return $config !== null && (int) $config['card_enabled'] === 1
                && (string) $config['merchant_account'] !== ''
                && (string) $config['merchant_domain'] !== ''
                && SecretStore::decrypt((string) $config['secret_ciphertext']) !== null;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            return false;
        }
        $sellerIds = [];
        foreach (WC()->cart->get_cart() as $cartItem) {
            $productId = ProductRouting::cart_item_product_id($cartItem);
            $resolution = ProductRouting::resolve($productId);
            if (!$resolution['ok'] || !is_int($resolution['seller_id'])) {
                return false;
            }
            $sellerIds[$resolution['seller_id']] = true;
        }
        if (count($sellerIds) !== 1) {
            return false;
        }
        $config = SellerRepository::current_config((int) array_key_first($sellerIds));
        return $config !== null && (int) $config['card_enabled'] === 1
            && (string) $config['merchant_account'] !== ''
            && SecretStore::decrypt((string) $config['secret_ciphertext']) !== null;
    }

    public function process_payment($orderId): array
    {
        $orderId = (int) $orderId;
        try {
            if ($this->enabled !== 'yes') {
                throw new \RuntimeException('Card payment is disabled in WooCommerce settings.');
            }
            if (!LegacyMigration::is_transition_enabled()) {
                throw new \RuntimeException('New fork payments are paused until the WayForPay transition is enabled.');
            }
            $purchase = OrderPurchaseService::finalize($orderId);
            $attempt = self::begin_attempt($orderId, $purchase);
            $token = hash_hmac('sha256', $orderId . ':' . $attempt['external_reference'], wp_salt('auth'));
            $url = add_query_arg([
                'action' => self::PAY_ACTION,
                'order_id' => $orderId,
                'reference' => (string) $attempt['external_reference'],
                'token' => $token,
            ], admin_url('admin-post.php'));
            return ['result' => 'success', 'redirect' => $url];
        } catch (\Throwable $error) {
            wc_add_notice(esc_html($error->getMessage()), 'error');
            return ['result' => 'failure'];
        }
    }

    /** Creates or reuses one immutable attempt. */
    private static function begin_attempt(int $orderId, array $purchase): array
    {
        global $wpdb;
        if (!LegacyMigration::is_transition_enabled()) {
            throw new \RuntimeException('New fork payments are paused until the WayForPay transition is enabled.');
        }
        if (!PurchaseRepository::order_lock($orderId)) {
            throw new \RuntimeException('Could not lock this order. Please retry.');
        }
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('Could not start payment transaction.');
            }
            try {
                $purchase = PurchaseRepository::for_order($orderId, true);
                $order = wc_get_order($orderId);
                if ($purchase === null || !$order instanceof \WC_Order) {
                    throw new \RuntimeException('The saved purchase is unavailable.');
                }
                if (!in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                    throw new \RuntimeException('This order is not waiting for payment.');
                }
                $snapshot = OrderPurchaseService::current_snapshot($order, $purchase);
                $snapshotJson = wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($snapshotJson) || !hash_equals((string) $purchase['snapshot_hash'], hash('sha256', $snapshotJson))) {
                    throw new \RuntimeException('The order changed after finalization. Recalculate it before payment.');
                }
                if (preg_match('/\A0+(?:\.0+)?\z/', (string) $purchase['amount']) === 1) {
                    throw new \RuntimeException('Zero-total purchases do not use WayForPay.');
                }
                if (in_array((string) $purchase['state'], ['paid', 'review', 'paid_review', 'expired'], true)) {
                    throw new \RuntimeException('This purchase already has a confirmed payment or requires review.');
                }
                $now = current_time('mysql', true);
                $expiresAt = (string) ($purchase['expires_at'] ?? '');
                if ($expiresAt !== '' && $expiresAt <= $now) {
                    throw new \RuntimeException('The payment deadline has passed. Contact the store to resolve this order.');
                }
                if ($expiresAt === '') {
                    $expiresAt = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + PaymentOperations::timeout_seconds('card'));
                }
                $config = SellerRepository::config((int) $purchase['config_id']);
                if ($config === null || (int) $config['card_enabled'] !== 1
                    || empty($config['merchant_account']) || empty($config['merchant_domain'])) {
                    throw new \RuntimeException('Card payment is not available for this saved seller configuration.');
                }
                $secret = SecretStore::decrypt((string) $config['secret_ciphertext']);
                if ($secret === null || $secret === '') {
                    throw new \RuntimeException('The saved WayForPay credential cannot be decrypted.');
                }

                $existing = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . PurchaseRepository::attempts_table()
                    . ' WHERE purchase_id=%d AND status=%s ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    (int) $purchase['id'], 'issued'
                ), ARRAY_A);
                if (is_array($existing)) {
                    if ((string) $existing['channel'] !== 'card') {
                        throw new \RuntimeException('A different payment method already has an active attempt for this order.');
                    }
                    $payload = json_decode((string) $existing['payload'], true);
                    if (!is_array($payload)) {
                        throw new \RuntimeException('The saved payment form is invalid.');
                    }
                    $wpdb->query('COMMIT');
                    return array_merge($existing, ['payload_data' => $payload]);
                }

                $reference = 'wfpms-' . bin2hex(random_bytes(16));
                $amount = (string) $snapshot['amount'];
                $orderDate = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
                $productName = 'Замовлення №' . (string) $order->get_order_number();
                $payload = [
                    'merchantAccount' => (string) $config['merchant_account'],
                    'merchantDomainName' => (string) $config['merchant_domain'],
                    'merchantTransactionSecureType' => 'AUTO',
                    'orderReference' => $reference,
                    'orderDate' => $orderDate,
                    'amount' => $amount,
                    'currency' => (string) $purchase['currency'],
                    'productName' => [$productName],
                    'productCount' => [1],
                    'productPrice' => [$amount],
                    'serviceUrl' => WC()->api_request_url(self::CALLBACK_ACTION),
                    'returnUrl' => $order->get_checkout_order_received_url(),
                ];
                $payload['merchantSignature'] = self::purchase_signature($payload, $secret);
                $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new \RuntimeException('Could not create the WayForPay request.');
                }
                $parts = explode(':', (string) $config['secret_ciphertext'], 3);
                $attemptId = PurchaseRepository::insert_attempt([
                    'purchase_id' => (int) $purchase['id'],
                    'channel' => 'card',
                    'merchant_account' => (string) $config['merchant_account'],
                    'external_reference' => $reference,
                    'config_id' => (int) $config['id'],
                    'credential_key_id' => isset($parts[1]) ? substr($parts[1], 0, 32) : '',
                    'expected_amount' => $amount,
                    'amount_scale' => (int) $snapshot['amount_scale'],
                    'currency' => (string) $purchase['currency'],
                    'payload' => $encoded,
                    'status' => 'issued',
                    'issued_at' => current_time('mysql', true),
                    'expires_at' => $expiresAt,
                ]);
                if ($wpdb->update(PurchaseRepository::purchases_table(), [
                    'state' => 'awaiting_payment',
                    'locked_at' => current_time('mysql', true),
                    'expires_at' => $expiresAt,
                    'updated_at' => current_time('mysql', true),
                ], ['id' => (int) $purchase['id']]) === false) {
                    throw new \RuntimeException('Could not lock purchase terms for payment.');
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Could not commit the payment attempt.');
                }
                return [
                    'id' => $attemptId,
                    'external_reference' => $reference,
                    'payload_data' => $payload,
                ];
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
    }

    /** WayForPay Purchase request signature, UTF-8 and exact semicolon serialization. */
    private static function purchase_signature(array $data, string $secret): string
    {
        $fields = [
            (string) $data['merchantAccount'],
            (string) $data['merchantDomainName'],
            (string) $data['orderReference'],
            (string) $data['orderDate'],
            (string) $data['amount'],
            (string) $data['currency'],
        ];
        foreach (['productName', 'productCount', 'productPrice'] as $key) {
            foreach ($data[$key] as $value) {
                $fields[] = (string) $value;
            }
        }
        return hash_hmac('md5', implode(';', $fields), $secret);
    }

    public static function render_payment_form(): void
    {
        $orderRaw = $_GET['order_id'] ?? '';
        $referenceRaw = $_GET['reference'] ?? '';
        $tokenRaw = $_GET['token'] ?? '';
        if (!is_scalar($orderRaw) || !is_scalar($referenceRaw) || !is_scalar($tokenRaw)) {
            wp_die(esc_html__('Посилання на оплату недійсне.', 'wayforpay-multi-seller'), '', ['response' => 403]);
        }
        $orderId = absint($orderRaw);
        $reference = sanitize_text_field(wp_unslash((string) $referenceRaw));
        $token = sanitize_text_field(wp_unslash((string) $tokenRaw));
        $expected = hash_hmac('sha256', $orderId . ':' . $reference, wp_salt('auth'));
        if ($orderId < 1 || $reference === '' || !hash_equals($expected, $token)) {
            wp_die(esc_html__('Посилання на оплату недійсне.', 'wayforpay-multi-seller'), '', ['response' => 403]);
        }
        $attempt = PurchaseRepository::attempt_by_reference($reference);
        $payload = $attempt ? json_decode((string) $attempt['payload'], true) : null;
        if (!$attempt || (int) $attempt['order_id'] !== $orderId || !is_array($payload)
            || (string) $attempt['status'] !== 'issued'
            || (string) $attempt['purchase_state'] !== 'awaiting_payment') {
            wp_die(esc_html__('Платіжну спробу не знайдено.', 'wayforpay-multi-seller'), '', ['response' => 404]);
        }
        $now = current_time('mysql', true);
        if (empty($attempt['expires_at']) || (string) $attempt['expires_at'] <= $now
            || empty($attempt['purchase_expires_at']) || (string) $attempt['purchase_expires_at'] <= $now) {
            wp_die(esc_html__('Строк оплати минув. Створіть нову спробу оплати.', 'wayforpay-multi-seller'), '', ['response' => 410]);
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        echo '<!doctype html><html lang="uk"><meta charset="' . esc_attr(get_option('blog_charset')) . '">';
        echo '<title>' . esc_html__('Перехід до оплати', 'wayforpay-multi-seller') . '</title>';
        echo '<p>' . esc_html__('Зараз ви перейдете на захищену сторінку оплати WayForPay.', 'wayforpay-multi-seller') . '</p>';
        echo '<form id="wfpms-pay" method="post" action="' . esc_url(self::PAY_URL) . '">';
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr((string) $item) . '">';
                }
            } else {
                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">';
            }
        }
        echo '<button type="submit" form="wfpms-pay">' . esc_html__('Перейти до оплати', 'wayforpay-multi-seller') . '</button>';
        echo '</form><script>document.getElementById("wfpms-pay").submit();</script></html>';
        exit;
    }

    public static function handle_callback(): void
    {
        global $wpdb;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            status_header(405);
            exit;
        }
        $body = file_get_contents('php://input', false, null, 0, 65537);
        if (!is_string($body) || strlen($body) > 65536) {
            status_header(413);
            exit;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            status_header(400);
            exit;
        }
        foreach (['merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode', 'merchantSignature'] as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field])) {
                status_header(400);
                exit;
            }
        }
        $reference = (string) $data['orderReference'];
        $attempt = PurchaseRepository::attempt_by_reference($reference);
        $config = $attempt ? SellerRepository::config((int) $attempt['config_id']) : null;
        $secret = $config ? SecretStore::decrypt((string) $config['secret_ciphertext']) : null;
        if ($attempt === null || $config === null || $secret === null
            || !self::valid_callback_signature($data, $secret)) {
            status_header(400);
            exit;
        }
        $merchantMatches = hash_equals((string) $attempt['merchant_account'], (string) $data['merchantAccount'])
            && hash_equals((string) $config['merchant_account'], (string) $data['merchantAccount']);

        $orderId = (int) $attempt['order_id'];
        if (!PurchaseRepository::order_lock($orderId)) {
            status_header(503);
            exit;
        }
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                status_header(503);
                exit;
            }
            try {
                $attempt = PurchaseRepository::attempt_by_reference($reference);
                $purchase = $attempt ? PurchaseRepository::for_order($orderId, true) : null;
                if ($attempt === null || $purchase === null || (int) $attempt['purchase_id'] !== (int) $purchase['id']) {
                    throw new \RuntimeException('Callback attempt no longer maps to its purchase.');
                }
                $canonical = self::callback_fields($data);
                $eventKey = hash('sha256', implode(';', $canonical));
                $existingEvent = $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM ' . PurchaseRepository::events_table() . ' WHERE dedupe_key=%s', $eventKey
                ));
                if (!$existingEvent) {
                    $status = (string) $data['transactionStatus'];
                    $amount = wc_format_decimal((string) $data['amount'], (int) $attempt['amount_scale'], false);
                    $currency = strtoupper((string) $data['currency']);
                    $expectedAmount = wc_format_decimal((string) $attempt['expected_amount'], (int) $attempt['amount_scale'], false);
                    $amountMatch = is_string($amount) && is_string($expectedAmount) && hash_equals($expectedAmount, $amount);
                    $currencyMatch = hash_equals((string) $attempt['currency'], $currency);
                    $isApproved = $status === 'Approved';
                    $order = wc_get_order($orderId);
                    $closedOrder = !$order || !in_array($order->get_status(), ['pending', 'on-hold'], true);
                    $deadlineOpen = (string) $purchase['state'] === 'awaiting_payment'
                        && !empty($purchase['expires_at'])
                        && (string) $purchase['expires_at'] > current_time('mysql', true);
                    $snapshotMatches = false;
                    if ($order instanceof \WC_Order) {
                        try {
                            $currentSnapshot = OrderPurchaseService::current_snapshot($order, $purchase);
                            $currentJson = wp_json_encode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $snapshotMatches = is_string($currentJson)
                                && hash_equals((string) $purchase['snapshot_hash'], hash('sha256', $currentJson));
                        } catch (\Throwable $ignored) {
                            $snapshotMatches = false;
                        }
                    }
                    $classification = 'status_only';
                    $receiptId = 0;
                    if ($isApproved) {
                        $prior = (int) $wpdb->get_var($wpdb->prepare(
                            'SELECT COUNT(*) FROM ' . PurchaseRepository::receipts_table() . ' WHERE purchase_id=%d',
                            (int) $purchase['id']
                        ));
                        $classification = (!$merchantMatches || !$amountMatch || !$currencyMatch || $closedOrder || !$deadlineOpen || !$snapshotMatches || $prior > 0)
                            ? 'needs_review' : 'accepted';
                        $receiptId = PurchaseRepository::insert_receipt([
                            'purchase_id' => (int) $purchase['id'],
                            'attempt_id' => (int) $attempt['id'],
                            'source' => 'wayforpay',
                            'source_scope' => (string) $attempt['merchant_account'],
                            'external_id' => hash('sha256', serialize([
                                'wayforpay', (string) $attempt['merchant_account'], $reference, $eventKey,
                            ])),
                            'provider_reference' => $reference,
                            'amount' => preg_match('/\A\d{1,14}(?:\.\d{1,6})?\z/', (string) $amount) === 1 ? $amount : '0',
                            'amount_raw' => substr((string) $data['amount'], 0, 191),
                            'currency' => preg_match('/\A[A-Z]{3}\z/', $currency) === 1 ? $currency : 'XXX',
                            'transaction_status' => $status,
                            'classification' => $classification,
                            'first_event_id' => 0,
                            'recorded_at' => current_time('mysql', true),
                        ]);
                        $hasAcceptedReceipt = $prior > 0
                            && in_array((string) $purchase['state'], ['paid', 'paid_review'], true);
                        $newState = $classification === 'accepted' ? 'paid' : ($hasAcceptedReceipt ? 'paid_review' : 'review');
                        $purchaseUpdate = [
                            'state' => $newState,
                            'updated_at' => current_time('mysql', true),
                        ];
                        if ($classification === 'accepted') {
                            $purchaseUpdate['accepted_receipt_id'] = $receiptId;
                            $purchaseUpdate['execution_state'] = 'pending';
                            $purchaseUpdate['execution_updated_at'] = current_time('mysql', true);
                        }
                        if ($wpdb->update(PurchaseRepository::purchases_table(), $purchaseUpdate, ['id' => (int) $purchase['id']]) === false
                            || $wpdb->update(PurchaseRepository::attempts_table(), ['status' => 'approved'], ['id' => (int) $attempt['id']]) === false) {
                            throw new \RuntimeException('Could not persist the approved payment state.');
                        }
                    } elseif (in_array($status, ['Declined', 'Expired', 'Refunded', 'Voided'], true)) {
                        if ($wpdb->update(PurchaseRepository::attempts_table(), ['status' => strtolower($status)], ['id' => (int) $attempt['id']]) === false) {
                            throw new \RuntimeException('Could not update payment attempt state.');
                        }
                        if (in_array($status, ['Refunded', 'Voided'], true)) {
                            $classification = 'needs_review';
                            $reviewState = in_array((string) $purchase['state'], ['paid', 'paid_review'], true)
                                ? 'paid_review' : 'review';
                            if ($wpdb->update(PurchaseRepository::purchases_table(), [
                                'state' => $reviewState,
                                'updated_at' => current_time('mysql', true),
                            ], ['id' => (int) $purchase['id']]) === false) {
                                throw new \RuntimeException('Could not flag refund evidence for review.');
                            }
                        }
                    }
                    $evidence = wp_json_encode([
                        'transaction_status' => substr($status, 0, 64),
                        'amount' => is_string($amount) ? $amount : '',
                        'amount_received' => substr((string) $data['amount'], 0, 64),
                        'currency' => substr($currency, 0, 3),
                        'reason_code' => substr((string) $data['reasonCode'], 0, 64),
                        'merchant_account_received' => substr((string) $data['merchantAccount'], 0, 191),
                        'merchant_account_expected' => substr((string) $attempt['merchant_account'], 0, 191),
                        'merchant_account_matches' => $merchantMatches,
                        'classification' => $classification,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (!is_string($evidence) || $wpdb->insert(PurchaseRepository::events_table(), [
                        'purchase_id' => (int) $purchase['id'],
                        'attempt_id' => (int) $attempt['id'],
                        'receipt_id' => $receiptId,
                        'dedupe_key' => $eventKey,
                        'kind' => 'callback_' . preg_replace('/[^A-Za-z0-9_-]/', '', $status),
                        'evidence' => $evidence,
                        'received_at' => current_time('mysql', true),
                    ]) !== 1) {
                        throw new \RuntimeException('Could not persist callback event.');
                    }
                    if ($receiptId > 0 && $wpdb->update(
                        PurchaseRepository::receipts_table(),
                        ['first_event_id' => (int) $wpdb->insert_id],
                        ['id' => $receiptId, 'first_event_id' => 0],
                        ['%d'],
                        ['%d', '%d']
                    ) === false) {
                        throw new \RuntimeException('Could not link receipt to its first evidence event.');
                    }
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Could not commit callback event.');
                }
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                status_header(503);
                exit;
            }
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }

        $latestPurchase = PurchaseRepository::for_order($orderId);
        if ($latestPurchase !== null && (string) $latestPurchase['execution_state'] === 'pending'
            && in_array((string) $latestPurchase['state'], ['paid', 'free'], true)) {
            PurchaseExecutionService::schedule((int) $latestPurchase['id']);
        }

        $time = time();
        $ack = ['orderReference' => $reference, 'status' => 'accept', 'time' => $time];
        $ack['signature'] = hash_hmac('md5', $reference . ';accept;' . $time, $secret);
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($ack);
        exit;
    }

    private static function valid_callback_signature(array $data, string $secret): bool
    {
        $fields = self::callback_fields($data);
        $signature = hash_hmac('md5', implode(';', $fields), $secret);
        return hash_equals($signature, strtolower((string) $data['merchantSignature']));
    }

    /** @return list<string> */
    private static function callback_fields(array $data): array
    {
        return array_map('strval', [
            $data['merchantAccount'], $data['orderReference'], $data['amount'], $data['currency'],
            $data['authCode'], $data['cardPan'], $data['transactionStatus'], $data['reasonCode'],
        ]);
    }
}
