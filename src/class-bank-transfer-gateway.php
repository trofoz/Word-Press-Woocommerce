<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Manual bank-transfer method with immutable order-specific instructions. */
final class BankTransferGateway extends \WC_Payment_Gateway
{
    private const GATEWAY_ID = 'wfpms_iban';
    private static array $renderedOrders = [];

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('Переказ на рахунок', 'wayforpay-multi-seller');
        $this->method_description = __('Оплата за реквізитами продавця, збереженими для замовлення.', 'wayforpay-multi-seller');
        $this->title = __('Переказ на рахунок', 'wayforpay-multi-seller');
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->enabled = 'yes';
    }

    public static function boot(): void
    {
        add_action('woocommerce_thankyou', [self::class, 'render_order_instructions']);
        add_action('woocommerce_order_details_after_order_table', [self::class, 'render_order_instructions']);
        add_action('woocommerce_email_after_order_table', [self::class, 'render_email_instructions'], 10, 4);
    }

    public function is_available(): bool
    {
        if (!parent::is_available() || !function_exists('WC')) {
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
            if ($activeChannel !== null && $activeChannel !== 'iban') {
                return false;
            }
            $config = SellerRepository::config((int) $purchase['config_id']);
            return $config !== null && (int) $config['iban_enabled'] === 1
                && trim((string) $config['iban']) !== '' && trim((string) $config['bank_name']) !== '';
        }
        if (!WC()->cart || WC()->cart->is_empty() || OrderPurchaseService::cart_is_zero_total_for_gateway()) {
            return false;
        }
        $sellerId = self::cart_seller_id();
        if ($sellerId < 1) {
            return false;
        }
        $config = SellerRepository::current_config($sellerId);
        return $config !== null && (int) $config['iban_enabled'] === 1
            && trim((string) $config['iban']) !== '' && trim((string) $config['bank_name']) !== '';
    }

    public function process_payment($orderId): array
    {
        $orderId = (int) $orderId;
        try {
            $purchase = OrderPurchaseService::finalize($orderId);
            $instructions = self::issue_instructions($orderId, (int) $purchase['id']);
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                throw new \RuntimeException('WooCommerce order is unavailable.');
            }
            if ($order->get_status() === 'pending') {
                $order->update_status('on-hold', __('Очікує підтвердження банківського надходження.', 'wayforpay-multi-seller'));
            }
            return ['result' => 'success', 'redirect' => $order->get_checkout_order_received_url()];
        } catch (\Throwable $error) {
            wc_add_notice(esc_html($error->getMessage()), 'error');
            return ['result' => 'failure'];
        }
    }

    public static function render_order_instructions($order): void
    {
        if (!($order instanceof \WC_Order) && is_scalar($order)) {
            $order = wc_get_order(absint($order));
        }
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== self::GATEWAY_ID) {
            return;
        }
        $orderId = (int) $order->get_id();
        if (isset(self::$renderedOrders[$orderId])) {
            return;
        }
        $purchase = PurchaseRepository::for_order((int) $order->get_id());
        if ($purchase === null || !in_array((string) $purchase['state'], ['awaiting_payment', 'expired'], true)) {
            return;
        }
        $attempts = PurchaseRepository::attempts_for_purchase((int) $purchase['id']);
        $attempt = null;
        foreach ($attempts as $candidate) {
            if ((string) $candidate['channel'] === 'iban' && (string) $candidate['status'] === 'issued') {
                $attempt = $candidate;
                break;
            }
        }
        $payload = $attempt ? json_decode((string) $attempt['payload'], true) : null;
        if (!is_array($payload)) {
            return;
        }
        self::$renderedOrders[$orderId] = true;
        self::render_instructions($payload, (string) ($purchase['expires_at'] ?? ''));
    }

    public static function render_email_instructions($order, bool $sentToAdmin, bool $plainText, $email = null): void
    {
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== self::GATEWAY_ID) {
            return;
        }
        $purchase = PurchaseRepository::for_order((int) $order->get_id());
        if ($purchase === null || (string) $purchase['state'] !== 'awaiting_payment') {
            return;
        }
        foreach (PurchaseRepository::attempts_for_purchase((int) $purchase['id']) as $attempt) {
            if ((string) $attempt['channel'] !== 'iban' || !in_array((string) $attempt['status'], ['issued', 'confirmed'], true)) {
                continue;
            }
            $payload = json_decode((string) $attempt['payload'], true);
            if (is_array($payload)) {
                self::render_instructions($payload, (string) ($purchase['expires_at'] ?? ''), $plainText);
                return;
            }
        }
    }

    private static function issue_instructions(int $orderId, int $purchaseId): array
    {
        global $wpdb;
        if (!PurchaseRepository::order_lock($orderId)) {
            throw new \RuntimeException('Could not lock this order. Please retry.');
        }
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('Could not start bank-transfer transaction.');
            }
            try {
                $purchase = PurchaseRepository::for_order($orderId, true);
                $order = wc_get_order($orderId);
                $snapshot = $purchase ? json_decode((string) $purchase['financial_snapshot'], true) : null;
                if ($purchase === null || (int) $purchase['id'] !== $purchaseId
                    || !$order instanceof \WC_Order || !is_array($snapshot)) {
                    throw new \RuntimeException('The saved purchase is unavailable.');
                }
                if (!in_array($order->get_status(), ['pending', 'on-hold'], true)
                    || in_array((string) $purchase['state'], ['paid', 'review', 'paid_review', 'expired', 'free'], true)) {
                    throw new \RuntimeException('This order cannot receive a new bank-transfer attempt.');
                }
                $now = current_time('mysql', true);
                $expiry = (string) ($purchase['expires_at'] ?? '');
                if ($expiry !== '' && $expiry <= $now) {
                    throw new \RuntimeException('The payment deadline has passed. Contact the store to resolve this order.');
                }
                $activeAttempt = $wpdb->get_row($wpdb->prepare(
                    'SELECT channel FROM ' . PurchaseRepository::attempts_table()
                    . ' WHERE purchase_id=%d AND status=%s ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    $purchaseId,
                    'issued'
                ), ARRAY_A);
                if (is_array($activeAttempt) && (string) $activeAttempt['channel'] !== 'iban') {
                    throw new \RuntimeException('A different payment method already has an active attempt for this order.');
                }
                foreach (PurchaseRepository::attempts_for_purchase($purchaseId) as $existing) {
                    if ((string) $existing['channel'] === 'iban' && (string) $existing['status'] === 'issued') {
                        $payload = json_decode((string) $existing['payload'], true);
                        if (!is_array($payload)) {
                            throw new \RuntimeException('The saved bank instructions are invalid.');
                        }
                        if ($wpdb->query('COMMIT') === false) {
                            throw new \RuntimeException('Could not commit bank-transfer attempt.');
                        }
                        return $payload;
                    }
                }
                $config = SellerRepository::config((int) $purchase['config_id']);
                if ($config === null || (int) $config['iban_enabled'] !== 1
                    || trim((string) $config['iban']) === '' || trim((string) $config['bank_name']) === '') {
                    throw new \RuntimeException('Bank transfer is unavailable for the saved seller configuration.');
                }
                $snapshotJson = wp_json_encode(OrderPurchaseService::current_snapshot($order, $purchase), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($snapshotJson) || !hash_equals((string) $purchase['snapshot_hash'], hash('sha256', $snapshotJson))) {
                    throw new \RuntimeException('The order changed after finalization. Recalculate it before payment.');
                }
                if ($expiry === '') {
                    $expiry = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + PaymentOperations::timeout_seconds('iban'));
                }
                $orderNumber = (string) ($snapshot['order_number'] ?? $order->get_order_number());
                $purpose = str_replace('{order_number}', $orderNumber, (string) ($snapshot['purpose_template'] ?? 'Оплата за замовлення №{order_number}'));
                $reference = 'wfpms-iban-' . bin2hex(random_bytes(16));
                $payload = [
                    'recipient_name' => (string) $snapshot['legal_name'],
                    'tax_id' => (string) $snapshot['tax_id'],
                    'iban' => (string) $snapshot['iban'],
                    'bank_name' => (string) $snapshot['bank_name'],
                    'amount' => (string) $purchase['amount'],
                    'currency' => (string) $purchase['currency'],
                    'purpose' => $purpose,
                    'order_number' => $orderNumber,
                ];
                $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded)) {
                    throw new \RuntimeException('Could not create bank-transfer instructions.');
                }
                PurchaseRepository::insert_attempt([
                    'purchase_id' => $purchaseId,
                    'channel' => 'iban',
                    'merchant_account' => '',
                    'external_reference' => $reference,
                    'config_id' => (int) $config['id'],
                    'credential_key_id' => '',
                    'expected_amount' => (string) $purchase['amount'],
                    'amount_scale' => (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()),
                    'currency' => (string) $purchase['currency'],
                    'payload' => $encoded,
                    'status' => 'issued',
                    'issued_at' => $now,
                    'expires_at' => $expiry,
                ]);
                if ($wpdb->update(PurchaseRepository::purchases_table(), [
                    'state' => 'awaiting_payment',
                    'locked_at' => $now,
                    'expires_at' => $expiry,
                    'updated_at' => $now,
                ], ['id' => $purchaseId]) === false || $wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Could not save bank-transfer instructions.');
                }
                return $payload;
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
    }

    private static function cart_seller_id(): int
    {
        $sellerIds = [];
        foreach (WC()->cart->get_cart() as $item) {
            $productId = (int) ($item['variation_id'] ?? $item['product_id'] ?? 0);
            $resolved = ProductRouting::resolve($productId);
            if (!$resolved['ok'] || !is_int($resolved['seller_id'])) {
                return 0;
            }
            $sellerIds[$resolved['seller_id']] = true;
        }
        return count($sellerIds) === 1 ? (int) array_key_first($sellerIds) : 0;
    }

    private static function render_instructions(array $payload, string $expiresAt, bool $plainText = false): void
    {
        $expiryLabel = $expiresAt === '' ? '' : wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime($expiresAt . ' UTC') ?: time()
        );
        if ($plainText) {
            echo "\n" . esc_html__('Переказ на рахунок', 'wayforpay-multi-seller') . "\n";
            foreach (['recipient_name' => 'Отримувач', 'tax_id' => 'Податковий код', 'iban' => 'IBAN', 'bank_name' => 'Банк', 'amount' => 'Сума', 'currency' => 'Валюта', 'purpose' => 'Призначення'] as $key => $label) {
                echo esc_html__($label, 'wayforpay-multi-seller') . ': ' . esc_html((string) ($payload[$key] ?? '')) . "\n";
            }
            if ($expiryLabel !== '') echo esc_html__('Сплатити до', 'wayforpay-multi-seller') . ': ' . esc_html($expiryLabel) . "\n";
            return;
        }
        echo '<section class="wfpms-bank-instructions"><h2>' . esc_html__('Переказ на рахунок', 'wayforpay-multi-seller') . '</h2>';
        echo '<p>' . esc_html__('Підтвердження замовлення відбудеться після перевірки фактичного надходження.', 'wayforpay-multi-seller') . '</p><dl>';
        foreach (['recipient_name' => 'Отримувач', 'tax_id' => 'Податковий код', 'iban' => 'IBAN', 'bank_name' => 'Банк', 'amount' => 'Сума', 'currency' => 'Валюта', 'purpose' => 'Призначення'] as $key => $label) {
            echo '<dt>' . esc_html__($label, 'wayforpay-multi-seller') . '</dt><dd>' . esc_html((string) ($payload[$key] ?? '')) . '</dd>';
        }
        if ($expiryLabel !== '') {
            echo '<dt>' . esc_html__('Сплатити до', 'wayforpay-multi-seller') . '</dt><dd>' . esc_html($expiryLabel) . '</dd>';
        }
        echo '</dl></section>';
    }
}
