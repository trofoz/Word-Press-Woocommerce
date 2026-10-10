<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Blocks supported WooCommerce CRUD edits that could change locked purchase terms. */
final class OrderMutationGuard
{
    public static function boot(): void
    {
        add_action('woocommerce_before_order_object_save', [self::class, 'guard_order'], 1, 2);
        add_action('woocommerce_before_order_item_object_save', [self::class, 'guard_item'], 1, 2);
        add_action('woocommerce_before_delete_order_item', [self::class, 'guard_delete_item'], 1, 1);
    }

    public static function guard_order($order, $dataStore = null): void
    {
        if (!$order instanceof \WC_Order || $order->get_id() < 1) {
            return;
        }
        $purchase = PurchaseRepository::for_order((int) $order->get_id());
        if ($purchase === null || empty($purchase['locked_at'])) {
            return;
        }
        $snapshot = json_decode((string) $purchase['financial_snapshot'], true);
        if (!is_array($snapshot)) {
            throw new \RuntimeException('This order has an unreadable locked purchase snapshot.');
        }
        $current = [
            'amount' => wc_format_decimal($order->get_total(), (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()), false),
            'currency' => strtoupper((string) $order->get_currency()),
            'discount_total' => wc_format_decimal($order->get_discount_total(), (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()), false),
            'shipping_total' => wc_format_decimal($order->get_shipping_total(), (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()), false),
            'tax_total' => wc_format_decimal($order->get_total_tax(), (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals()), false),
        ];
        foreach ($current as $key => $value) {
            if (!isset($snapshot[$key]) || !hash_equals((string) $snapshot[$key], (string) $value)) {
                throw new \RuntimeException('Financial terms are locked after payment begins. Create a replacement order to change them.');
            }
        }
    }

    public static function guard_item($item, $dataStore = null): void
    {
        if (!$item || !method_exists($item, 'get_order_id') || (int) $item->get_order_id() < 1) {
            return;
        }
        $purchase = PurchaseRepository::for_order((int) $item->get_order_id());
        if ($purchase === null || empty($purchase['locked_at'])) {
            return;
        }
        $snapshot = json_decode((string) $purchase['financial_snapshot'], true);
        if (!is_array($snapshot)) {
            throw new \RuntimeException('This order has an unreadable locked purchase snapshot.');
        }
        $type = method_exists($item, 'get_type') ? (string) $item->get_type() : '';
        $savedItems = match ($type) {
            'line_item' => $snapshot['items'] ?? [],
            'shipping' => $snapshot['shipping'] ?? [],
            'fee' => $snapshot['fees'] ?? [],
            'tax' => $snapshot['tax_lines'] ?? [],
            default => [],
        };
        $saved = null;
        foreach (is_array($savedItems) ? $savedItems : [] as $candidate) {
            if ((int) ($candidate['item_id'] ?? 0) === (int) $item->get_id() && (int) $item->get_id() > 0) {
                $saved = $candidate;
                break;
            }
        }
        if ($saved === null || !self::same_financial_item(
            $item,
            $type,
            $saved,
            (int) ($snapshot['amount_scale'] ?? wc_get_price_decimals())
        )) {
            throw new \RuntimeException('Order item financial fields are locked after payment begins. Create a replacement order to change them.');
        }
    }

    public static function guard_delete_item(int $itemId): void
    {
        $orderId = (int) wc_get_order_id_by_order_item_id($itemId);
        if ($orderId < 1) {
            return;
        }
        $purchase = PurchaseRepository::for_order($orderId);
        if ($purchase !== null && !empty($purchase['locked_at'])) {
            throw new \RuntimeException('Order items are locked after payment begins.');
        }
    }

    /** @param array<string,mixed> $saved */
    private static function same_financial_item($item, string $type, array $saved, int $scale): bool
    {
        if ($type === 'line_item') {
            return (int) ($saved['product_id'] ?? 0) === (int) ($item->get_variation_id() ?: $item->get_product_id())
                && (string) ($saved['quantity'] ?? '') === (string) $item->get_quantity()
                && (string) ($saved['tax_class'] ?? '') === (string) $item->get_tax_class()
                && (string) ($saved['subtotal'] ?? '') === wc_format_decimal($item->get_subtotal(), $scale, false)
                && (string) ($saved['total'] ?? '') === wc_format_decimal($item->get_total(), $scale, false)
                && (string) ($saved['tax'] ?? '') === wc_format_decimal($item->get_total_tax(), $scale, false);
        }
        if ($type === 'shipping') {
            return (string) ($saved['name'] ?? '') === (string) $item->get_name()
                && (string) ($saved['method_id'] ?? '') === (string) $item->get_method_id()
                && (int) ($saved['instance_id'] ?? 0) === (int) $item->get_instance_id()
                && (string) ($saved['total'] ?? '') === (string) $item->get_total();
        }
        if ($type === 'fee') {
            return (string) ($saved['name'] ?? '') === (string) $item->get_name()
                && (string) ($saved['total'] ?? '') === (string) $item->get_total()
                && (string) ($saved['tax'] ?? '') === (string) $item->get_total_tax();
        }
        if ($type === 'tax') {
            return (int) ($saved['rate_id'] ?? 0) === (int) $item->get_rate_id()
                && (string) ($saved['tax'] ?? '') === (string) $item->get_tax_total()
                && (string) ($saved['shipping_tax'] ?? '') === (string) $item->get_shipping_tax_total();
        }
        return false;
    }
}
