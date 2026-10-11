<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** Builds a trusted seller snapshot from a finalized WooCommerce order. */
final class OrderPurchaseService
{
    private static ?int $validatedOrderPayId = null;

    public static function boot(): void
    {
        add_action('wp', [self::class, 'prepare_order_pay_checkout'], 1, 1);
        add_filter('pre_render_block', [self::class, 'render_order_pay_checkout_block'], 1, 3);
        add_action('woocommerce_check_cart_items', [self::class, 'validate_cart']);
        add_action('woocommerce_review_order_before_payment', [self::class, 'render_checkout_seller']);
        add_action('woocommerce_pay_order_before_payment', [self::class, 'render_checkout_seller']);
        add_action('woocommerce_checkout_order_created', [self::class, 'finalize_checkout_order'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'finalize_checkout_order'], 10, 1);
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_add_to_cart'], 10, 5);
        add_action('woocommerce_store_api_validate_add_to_cart', [self::class, 'validate_store_api_add_to_cart'], 10, 2);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'filter_cart_gateways'], 100);
        add_filter('woocommerce_gateway_title', [self::class, 'filter_gateway_title'], 10, 2);
    }

    public static function finalize_checkout_order($order): void
    {
        if ($order instanceof \WC_Order) {
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
            return;
        }
        if (self::cart_is_zero_total() && !PaymentOperations::zero_total_enabled()) {
            wc_add_notice(
                __('Безоплатні замовлення вимкнені адміністратором магазину.', 'wayforpay-multi-seller'),
                'error'
            );
        }
    }

    public static function validate_add_to_cart(bool $passed, int $productId, int $quantity, int $variationId = 0, array $variation = [], array $cartItemData = []): bool
    {
        if (!$passed) {
            return $passed;
        }
        $candidateId = $variationId > 0 ? $variationId : $productId;
        $error = self::add_to_cart_error($candidateId);
        if ($error !== null) {
            wc_add_notice($error, 'error');
            return false;
        }
        return true;
    }

    /** Prevent Store API (Cart/Checkout Blocks) from adding products from another seller. */
    public static function validate_store_api_add_to_cart(\WC_Product $product, array $request): void
    {
        $error = self::add_to_cart_error((int) $product->get_id());
        if ($error !== null) {
            throw new \Exception($error);
        }
    }

    private static function add_to_cart_error(int $candidateId): ?string
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return null;
        }
        $candidate = ProductRouting::resolve($candidateId);
        if (!$candidate['ok'] || !is_int($candidate['seller_id'])) {
            return __('Товар не має чинного призначення продавця.', 'wayforpay-multi-seller');
        }
        foreach (WC()->cart->get_cart() as $item) {
            $existing = ProductRouting::resolve(ProductRouting::cart_item_product_id($item));
            if (!$existing['ok'] || $existing['seller_id'] !== $candidate['seller_id']) {
                return __('У кошику можуть бути товари лише одного продавця. Оформіть поточний кошик перед додаванням товару іншого продавця.', 'wayforpay-multi-seller');
            }
        }
        return null;
    }

    public static function filter_cart_gateways(array $gateways): array
    {
        $external = self::allowed_external_gateways($gateways);
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $orderId = absint(get_query_var('order-pay'));
            $purchase = $orderId > 0 ? PurchaseRepository::for_order($orderId) : null;
            if ($purchase !== null) {
                $config = SellerRepository::config((int) $purchase['config_id']);
                if ($config === null) {
                    return [];
                }
                $allowed = [];
                if ((int) $config['card_enabled'] === 1 && isset($gateways['wfpms_card'])) {
                    $allowed['wfpms_card'] = $gateways['wfpms_card'];
                }
                if ((int) $config['iban_enabled'] === 1 && isset($gateways['wfpms_iban'])) {
                    $allowed['wfpms_iban'] = $gateways['wfpms_iban'];
                }
                return $allowed + $external;
            }
            return $gateways;
        }

        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return $gateways;
        }
        if (self::cart_is_zero_total()) {
            return [];
        }
        $resolution = self::seller_for_cart();
        if (!$resolution['ok']) {
            return [];
        }
        $config = SellerRepository::current_config((int) $resolution['seller_id']);
        if ($config === null) {
            return [];
        }
        $allowed = [];
        if ((int) $config['card_enabled'] === 1 && SecretStore::decrypt((string) $config['secret_ciphertext']) !== null
            && isset($gateways['wfpms_card'])) {
            $allowed['wfpms_card'] = $gateways['wfpms_card'];
        }
        if ((int) $config['iban_enabled'] === 1 && isset($gateways['wfpms_iban'])) {
            $allowed['wfpms_iban'] = $gateways['wfpms_iban'];
        }
        return $allowed + $external;
    }

    /** Include only methods explicitly approved by the store operator. */
    private static function allowed_external_gateways(array $gateways): array
    {
        return self::filter_external_gateways($gateways, PaymentOperations::allowed_external_gateway_ids());
    }

    /** @param array<string,mixed> $gateways @param list<string> $allowedIds @return array<string,mixed> */
    public static function filter_external_gateways(array $gateways, array $allowedIds): array
    {
        $filtered = [];
        foreach ($allowedIds as $gatewayId) {
            if (isset($gateways[$gatewayId])) {
                $filtered[$gatewayId] = $gateways[$gatewayId];
            }
        }
        return $filtered;
    }

    /** Full legal recipient shown alongside each available seller payment method. */
    public static function checkout_seller_name(): string
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $orderId = absint(get_query_var('order-pay'));
            $order = $orderId > 0 ? wc_get_order($orderId) : false;
            if ($order instanceof \WC_Order) {
                $snapshot = $order->get_meta('_wfpms_seller_snapshot', true);
                if (is_array($snapshot) && !empty($snapshot['legal_name'])) {
                    return (string) $snapshot['legal_name'];
                }
            }
        }
        if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            $resolution = self::seller_for_cart();
            if ($resolution['ok'] && is_int($resolution['seller_id'])) {
                $config = SellerRepository::current_config($resolution['seller_id']);
                return $config === null ? '' : (string) $config['recipient_name'];
            }
            return '';
        }
        return '';
    }

    public static function render_checkout_seller(): void
    {
        $sellerName = self::checkout_seller_name();
        if ($sellerName !== '') {
            echo '<p class="wfpms-checkout-seller"><strong>'
                . esc_html__('Продавець:', 'wayforpay-multi-seller') . '</strong> '
                . esc_html($sellerName) . '</p>';
        }
    }

    /** Route valid payment links through WooCommerce's order-pay shortcode on any checkout page. */
    public static function prepare_order_pay_checkout($wp = null): void
    {
        global $wp_query;
        if (!is_object($wp_query)
            || !isset($_GET['order-pay'], $_GET['pay_for_order'], $_GET['key'])
            || !is_scalar($_GET['order-pay']) || !is_scalar($_GET['pay_for_order']) || !is_scalar($_GET['key'])
            || (string) wp_unslash((string) $_GET['pay_for_order']) !== 'true') {
            return;
        }
        $orderId = absint(wp_unslash((string) $_GET['order-pay']));
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        $key = wc_clean(wp_unslash((string) $_GET['key']));
        if (!$order instanceof \WC_Order || !hash_equals($order->get_order_key(), $key)) {
            return;
        }
        self::$validatedOrderPayId = $orderId;
        if (is_object($wp)) {
            $wp->query_vars['order-pay'] = $orderId;
        }
        $wp_query->query_vars['order-pay'] = $orderId;
        foreach ($wp_query->posts as $post) {
            if ($post instanceof \WP_Post) {
                $post->post_content = '[woocommerce_checkout]';
            }
        }
    }

    /** Render the classic pay form inside block themes whose checkout block omits order-pay. */
    public static function render_order_pay_checkout_block($preRender, array $parsedBlock, $parentBlock = null)
    {
        if (self::$validatedOrderPayId === null || !function_exists('get_the_ID')
            || get_the_ID() !== wc_get_page_id('checkout')) {
            return $preRender;
        }
        if (!headers_sent()) {
            header('X-WFPMS-Render-Block: ' . rawurlencode(wp_json_encode([
                'block' => (string) ($parsedBlock['blockName'] ?? ''),
                'pre_render' => $preRender !== null,
            ])));
        }
        if ($preRender !== null || ($parsedBlock['blockName'] ?? '') !== 'core/post-content') {
            return $preRender;
        }
        $rendered = do_shortcode('[woocommerce_checkout]');
        return $rendered;
    }

    public static function filter_gateway_title(string $title, string $gatewayId): string
    {
        if (!in_array($gatewayId, ['wfpms_card', 'wfpms_iban'], true)) {
            return $title;
        }
        $sellerName = self::checkout_seller_name();
        return $sellerName === '' ? $title : $title . ' — ' . $sellerName;
    }

    /** @return array{ok:bool,seller_id:?int} */
    private static function seller_for_cart(): array
    {
        $sellerIds = [];
        foreach (WC()->cart->get_cart() as $cartItem) {
            $productId = ProductRouting::cart_item_product_id($cartItem);
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
            $isZero = self::is_zero_amount((string) $snapshot['amount']);
            if ($isZero && !PaymentOperations::zero_total_enabled()) {
                throw new \RuntimeException('Zero-total purchases are disabled by store policy.');
            }
            $purchase = PurchaseRepository::finalize($orderId, $sellerId, (int) $config['id'], $snapshot);
            if ($isZero) {
                $basis = wp_json_encode([
                    'coupon_codes' => $snapshot['coupons'],
                    'discount_total' => $snapshot['discount_total'],
                    'zero_price_items' => array_values(array_map(static fn(array $item): string => (string) $item['name'], $snapshot['items'])),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($basis) || !PurchaseRepository::mark_free((int) $purchase['id'], $basis)) {
                    throw new \RuntimeException('Could not persist the zero-total purchase basis.');
                }
                $purchase = PurchaseRepository::for_order($orderId) ?? $purchase;
            }
            self::persist_seller_snapshot_meta($order, $purchase, $seller, $config);
            if ($isZero && $order->is_paid()) {
                PurchaseExecutionService::observe_woo_payment_complete($orderId);
            }
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

    /** Creates an explicit seller mapping for an unpaid order from the original gateway. */
    public static function migrate_legacy_order(int $orderId, int $sellerId, int $actorId): void
    {
        global $wpdb;
        $order = wc_get_order($orderId);
        if (!LegacyMigration::owns_callback_route() || !$order instanceof \WC_Order || $order->get_payment_method() !== 'wayforpay'
            || !in_array($order->get_status(), ['pending', 'on-hold'], true) || $order->is_paid()) {
            throw new \RuntimeException('Only unpaid, open orders from the original WayForPay gateway can be migrated.');
        }
        if (!PurchaseRepository::order_lock($orderId)) {
            throw new \RuntimeException('Could not lock this order. Please retry.');
        }
        try {
            if (PurchaseRepository::for_order($orderId) !== null || LegacyMigration::for_order($orderId) !== null) {
                throw new \RuntimeException('This order already has a purchase or migration record.');
            }
            $seller = SellerRepository::find($sellerId);
            $config = $seller === null ? null : LegacyMigration::imported_config_for_seller($sellerId);
            $legacy = LegacyMigration::legacy_settings();
            if ($seller === null || (int) $seller['active'] !== 1 || $config === null
                || (int) $config['card_enabled'] !== 1
                || !is_array($legacy)
                || !hash_equals((string) ($legacy['merchant_account'] ?? ''), (string) $config['merchant_account'])) {
                throw new \RuntimeException('The seller must have the imported legacy merchant configuration.');
            }
            $snapshot = self::snapshot($order, $seller, $config, wc_get_price_decimals());
            if (preg_match('/\A0+(?:\.0+)?\z/', (string) $snapshot['amount']) === 1) {
                throw new \RuntimeException('Zero-total legacy orders cannot be migrated for card payment.');
            }
            $json = wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new \RuntimeException('Could not encode the legacy order snapshot.');
            }
            $now = current_time('mysql', true);
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('Could not start migration transaction.');
            }
            try {
                if ($wpdb->insert(PurchaseRepository::purchases_table(), [
                    'order_id' => $orderId,
                    'seller_id' => $sellerId,
                    'config_id' => (int) $config['id'],
                    'financial_snapshot' => $json,
                    'snapshot_hash' => hash('sha256', $json),
                    'amount' => $snapshot['amount'],
                    'currency' => $snapshot['currency'],
                    'state' => 'awaiting_payment',
                    'locked_at' => $now,
                    'expires_at' => null,
                    'execution_state' => 'none',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]) !== 1) {
                    throw new \RuntimeException('Could not create the legacy purchase record.');
                }
                $purchaseId = (int) $wpdb->insert_id;
                if ($wpdb->insert(LegacyMigration::migrations_table(), [
                    'order_id' => $orderId,
                    'purchase_id' => $purchaseId,
                    'seller_id' => $sellerId,
                    'config_id' => (int) $config['id'],
                    'migrated_by' => $actorId,
                    'migrated_at' => $now,
                ]) !== 1) {
                    throw new \RuntimeException('Could not persist the explicit legacy order mapping.');
                }
                if ($wpdb->query($wpdb->prepare(
                    'UPDATE ' . SellerRepository::sellers_table() . ' SET used_at=COALESCE(used_at,%s) WHERE id=%d',
                    $now,
                    $sellerId
                )) === false) {
                    throw new \RuntimeException('Could not mark the seller profile as used.');
                }
                self::persist_seller_snapshot_meta($order, [
                    'id' => $purchaseId,
                    'seller_id' => $sellerId,
                    'config_id' => (int) $config['id'],
                    'snapshot_hash' => hash('sha256', $json),
                ], $seller, $config);
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Could not commit the legacy migration.');
                }
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
        } finally {
            PurchaseRepository::release_order_lock($orderId);
        }
    }

    /** @param array<string,mixed> $purchase @param array<string,mixed> $seller @param array<string,mixed> $config */
    private static function persist_seller_snapshot_meta(\WC_Order $order, array $purchase, array $seller, array $config): void
    {
        $order->update_meta_data('_wfpms_purchase_id', (string) $purchase['id']);
        $order->update_meta_data('_wfpms_seller_id', (string) $purchase['seller_id']);
        $order->update_meta_data('_wfpms_config_id', (string) $purchase['config_id']);
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
            'purpose_template' => (string) $config['purpose_template'],
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

    public static function cart_is_zero_total_for_gateway(): bool
    {
        return self::cart_is_zero_total();
    }

    private static function cart_is_zero_total(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        return self::is_zero_amount(wc_format_decimal(WC()->cart->get_total('edit'), wc_get_price_decimals(), false));
    }

    private static function is_zero_amount(string $amount): bool
    {
        return preg_match('/\A0+(?:\.0+)?\z/', $amount) === 1;
    }
}
