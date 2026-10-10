<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Builds a trusted seller snapshot from a finalized WooCommerce order. */
final class OrderPurchaseService
{
    public static function boot(): void
    {
        add_action('woocommerce_check_cart_items', [self::class, 'validate_cart']);
        add_action('woocommerce_checkout_order_created', [self::class, 'finalize_checkout_order'], 10, 1);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'filter_cart_gateways'], 100);
    }

    public static function finalize_checkout_order($order): void
    {
        if ($order instanceof \WC_Order && preg_match(
            '/\A0+(?:\.0+)?\z/',
            wc_format_decimal($order->get_total(), wc_get_price_decimals(), false)
        ) !== 1) {
            self::finalize((int) $order->get_id());
        }
    }

    public static function validate_cart(): void
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        $resolution = self::seller_for_cart();
        if (!$resolution['ok']) {
            wc_add_notice(
                __('У кошику мають бути товари одного налаштованого продавця. Перевірте призначення товарів або розділіть покупки.', 'wayforpay-multi-seller'),
                'error'
            );
        }
    }

    public static function filter_cart_gateways(array $gateways): array
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return $gateways;
        }
        $resolution = self::seller_for_cart();
        if (!$resolution['ok']) {
            return [];
        }
        $config = SellerRepository::current_config((int) $resolution['seller_id']);
        if ($config === null || (int) $config['card_enabled'] !== 1
            || SecretStore::decrypt((string) $config['secret_ciphertext']) === null) {
            return [];
        }
        return isset($gateways['wfpms_card']) ? ['wfpms_card' => $gateways['wfpms_card']] : [];
    }

    /** @return array{ok:bool,seller_id:?int} */
    private static function seller_for_cart(): array
    {
        $sellerIds = [];
        foreach (WC()->cart->get_cart() as $cartItem) {
            $productId = (int) ($cartItem['variation_id'] ?? $cartItem['product_id'] ?? 0);
            $resolved = ProductRouting::resolve($productId);
            if (!$resolved['ok'] || !is_int($resolved['seller_id'])) {
                return ['ok' => false, 'seller_id' => null];
            }
            $sellerIds[$resolved['seller_id']] = true;
        }
        return count($sellerIds) === 1
            ? ['ok' => true, 'seller_id' => (int) array_key_first($sellerIds)]
            : ['ok' => false, 'seller_id' => null];
    }

    public static function finalize(int $orderId): array
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('WooCommerce order is unavailable.');
        }
        if (!PurchaseRepository::order_lock($orderId)) {
            throw new \RuntimeException('Could not lock this order. Please retry.');
        }
        try {
            $existing = PurchaseRepository::for_order($orderId);
            $sellerId = $existing !== null
                ? (int) $existing['seller_id']
                : self::seller_for_order($order);
            if ($existing !== null && empty($existing['locked_at'])) {
                self::validate_changed_products($order, $existing, $sellerId);
            }
            $seller = SellerRepository::find($sellerId);
            if ($seller === null || ($existing === null && (int) $seller['active'] !== 1)) {
                throw new \RuntimeException('The assigned seller is unavailable.');
            }
            if ($existing !== null) {
                $savedSnapshot = json_decode((string) $existing['financial_snapshot'], true);
                if (!is_array($savedSnapshot)) {
                    throw new \RuntimeException('The saved purchase snapshot is unavailable.');
                }
                $seller['internal_label'] = (string) ($savedSnapshot['seller_label'] ?? $seller['internal_label']);
            }
            $config = $existing === null
                ? SellerRepository::current_config($sellerId)
                : SellerRepository::config((int) $existing['config_id']);
            if ($config === null) {
                throw new \RuntimeException('The seller payment configuration is unavailable.');
            }

            $saved = $existing === null ? null : json_decode((string) $existing['financial_snapshot'], true);
            $scale = is_array($saved) ? (int) ($saved['amount_scale'] ?? wc_get_price_decimals()) : wc_get_price_decimals();
            $snapshot = self::snapshot($order, $seller, $config, $scale);
            $purchase = PurchaseRepository::finalize($orderId, $sellerId, (int) $config['id'], $snapshot);
            $order->update_meta_data('_wfpms_purchase_id', (string) $purchase['id']);
            $order->update_meta_data('_wfpms_seller_id', (string) $sellerId);
            $order->update_meta_data('_wfpms_config_id', (string) $config['id']);
            $order->update_meta_data('_wfpms_snapshot_hash', (string) $purchase['snapshot_hash']);
            $order->update_meta_data('_wfpms_seller_snapshot', [
                'seller_label' => (string) $seller['internal_label'],
                'legal_name' => (string) $config['recipient_name'],
                'tax_id' => (string) $config['tax_id'],
                'iban' => (string) $config['iban'],
                'bank_name' => (string) $config['bank_name'],
                'merchant_account' => (string) $config['merchant_account'],
                'config_id' => (int) $config['id'],
            ]);
            $order->save();
            return $purchase;
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
    }

    public static function seller_for_order(\WC_Order $order): int
    {
        $sellerIds = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product || $item->get_quantity() <= 0) {
                throw new \RuntimeException('The order contains an invalid product line.');
            }
            $productId = (int) ($item->get_variation_id() ?: $item->get_product_id());
            $resolved = ProductRouting::resolve($productId);
            if (!$resolved['ok'] || !is_int($resolved['seller_id'])) {
                throw new \RuntimeException('A product in this order has no valid seller assignment.');
            }
            $sellerIds[$resolved['seller_id']] = true;
        }
        if (count($sellerIds) !== 1) {
            throw new \RuntimeException('An order must contain products from exactly one seller.');
        }
        return (int) array_key_first($sellerIds);
    }

    /** Rebuilds the current trusted snapshot against a previously pinned seller/config. */
    public static function current_snapshot(\WC_Order $order, array $purchase): array
    {
        $savedSnapshot = json_decode((string) $purchase['financial_snapshot'], true);
        $seller = SellerRepository::find((int) $purchase['seller_id']);
        $config = SellerRepository::config((int) $purchase['config_id']);
        if ($seller === null || $config === null || !is_array($savedSnapshot)) {
            throw new \RuntimeException('The saved seller configuration is unavailable.');
        }
        $seller['internal_label'] = (string) ($savedSnapshot['seller_label'] ?? $seller['internal_label']);
        $scale = (int) ($savedSnapshot['amount_scale'] ?? wc_get_price_decimals());
        $snapshot = self::snapshot($order, $seller, $config, $scale);
        $snapshot['order_number'] = (string) ($savedSnapshot['order_number'] ?? $snapshot['order_number']);
        return $snapshot;
    }

    private static function validate_changed_products(\WC_Order $order, array $purchase, int $sellerId): void
    {
        $saved = json_decode((string) $purchase['financial_snapshot'], true);
        if (!is_array($saved) || !isset($saved['items']) || !is_array($saved['items'])) {
            throw new \RuntimeException('The saved purchase item list is unavailable.');
        }
        $savedIds = array_map(static fn(array $item): int => (int) ($item['product_id'] ?? 0), $saved['items']);
        $currentIds = [];
        foreach ($order->get_items('line_item') as $item) {
            $currentIds[] = (int) ($item->get_variation_id() ?: $item->get_product_id());
        }
        if ($savedIds === $currentIds) {
            return;
        }
        if (self::seller_for_order($order) !== $sellerId) {
            throw new \RuntimeException('Changed order items must still belong to the saved seller.');
        }
    }

    /** @param array<string,mixed> $seller @param array<string,mixed> $config @return array<string,mixed> */
    private static function snapshot(\WC_Order $order, array $seller, array $config, int $scale): array
    {
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $items[] = [
                'item_id' => (int) $item->get_id(),
                'product_id' => (int) ($item->get_variation_id() ?: $item->get_product_id()),
                'name' => (string) $item->get_name(),
                'quantity' => (string) $item->get_quantity(),
                'tax_class' => (string) $item->get_tax_class(),
                'subtotal' => wc_format_decimal($item->get_subtotal(), $scale, false),
                'total' => wc_format_decimal($item->get_total(), $scale, false),
                'tax' => wc_format_decimal($item->get_total_tax(), $scale, false),
            ];
        }
        $currency = strtoupper((string) $order->get_currency());
        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new \RuntimeException('The order currency is invalid.');
        }
        $amount = wc_format_decimal($order->get_total(), $scale, false);
        if (!is_string($amount) || preg_match('/\A\d{1,14}(?:\.\d{1,6})?\z/', $amount) !== 1) {
            throw new \RuntimeException('The order total has an unsupported amount or precision.');
        }
        return [
            'order_id' => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'seller_id' => (int) $seller['id'],
            'seller_label' => (string) $seller['internal_label'],
            'legal_name' => (string) $config['recipient_name'],
            'tax_id' => (string) $config['tax_id'],
            'iban' => (string) $config['iban'],
            'bank_name' => (string) $config['bank_name'],
            'merchant_account' => (string) $config['merchant_account'],
            'merchant_domain' => (string) $config['merchant_domain'],
            'config_id' => (int) $config['id'],
            'currency' => $currency,
            'amount_scale' => $scale,
            'amount' => $amount,
            'discount_total' => wc_format_decimal($order->get_discount_total(), $scale, false),
            'shipping_total' => wc_format_decimal($order->get_shipping_total(), $scale, false),
            'tax_total' => wc_format_decimal($order->get_total_tax(), $scale, false),
            'items' => $items,
            'coupons' => array_values(array_map('strval', $order->get_coupon_codes())),
            'shipping' => array_map(static function ($item): array {
                return [
                    'item_id' => (int) $item->get_id(),
                    'name' => (string) $item->get_name(),
                    'method_id' => (string) $item->get_method_id(),
                    'instance_id' => (int) $item->get_instance_id(),
                    'total' => (string) $item->get_total(),
                ];
            }, $order->get_items('shipping')),
            'fees' => array_map(static function ($item): array {
                return ['item_id' => (int) $item->get_id(), 'name' => (string) $item->get_name(), 'total' => (string) $item->get_total(), 'tax' => (string) $item->get_total_tax()];
            }, $order->get_items('fee')),
            'tax_lines' => array_map(static function ($item): array {
                return [
                    'item_id' => (int) $item->get_id(),
                    'rate_id' => (int) $item->get_rate_id(),
                    'tax' => (string) $item->get_tax_total(),
                    'shipping_tax' => (string) $item->get_shipping_tax_total(),
                ];
            }, $order->get_items('tax')),
        ];
    }
}
