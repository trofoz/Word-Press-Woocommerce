<?php
/**
 * Plugin Name: ILoveSad WayForPay restricted pilot
 * Description: Temporary store policy for the two-product, one-account WayForPay pilot.
 * Version: 0.1.0
 */
declare(strict_types=1);

namespace ILoveSad\WfpmsPilot;

use WfpMultiSeller\OrderPurchaseService;
use WfpMultiSeller\LegacyMigration;
use WfpMultiSeller\PurchaseRepository;
use WfpMultiSeller\ProductRouting;

defined('ABSPATH') || exit;

final class Pilot
{
    public const OPTION = 'ilovesad_wfpms_pilot';
    private const METHODS = ['wfpms_card', 'wfpms_iban'];
    private const ERROR = 'Контрольна покупка недоступна для цього акаунта або складу замовлення.';

    public static function boot(): void
    {
        if (!class_exists(OrderPurchaseService::class) || !class_exists('WC_Payment_Gateway')) {
            return;
        }
        // Adapt store checkout only. Callback ownership, receipts and execution hooks stay intact.
        $hooks = [
            ['woocommerce_check_cart_items', 'validate_cart', 'validate_cart', 10, 0],
            ['woocommerce_add_to_cart_validation', 'validate_add_to_cart', 'add_to_cart', 10, 5],
            ['woocommerce_store_api_validate_add_to_cart', 'validate_store_api_add_to_cart', 'store_add_to_cart', 10, 2],
            ['woocommerce_checkout_order_created', 'finalize_checkout_order', 'finalize_order', 10, 1],
            ['woocommerce_store_api_checkout_order_processed', 'finalize_checkout_order', 'finalize_order', 10, 1],
            ['woocommerce_available_payment_gateways', 'filter_cart_gateways', 'cart_gateways', 100, 1],
            ['woocommerce_review_order_before_payment', 'render_checkout_seller', 'render_seller', 10, 0],
            ['woocommerce_pay_order_before_payment', 'render_checkout_seller', 'render_seller', 10, 0],
            ['woocommerce_gateway_title', 'filter_gateway_title', 'gateway_title', 10, 2],
        ];
        foreach ($hooks as [$hook, $original, $replacement, $priority, $args]) {
            remove_filter($hook, [OrderPurchaseService::class, $original], $priority);
            add_filter($hook, [self::class, $replacement], $priority, $args);
        }
        remove_filter('woocommerce_available_payment_gateways', [LegacyMigration::class, 'filter_gateways'], 1000);
        add_filter('woocommerce_available_payment_gateways', [self::class, 'legacy_gateways'], 1000);
        add_filter('woocommerce_payment_gateways', [self::class, 'wrap_gateways'], PHP_INT_MAX);
        add_filter('woocommerce_is_purchasable', [self::class, 'purchasable'], PHP_INT_MAX, 2);
        add_filter('woocommerce_variation_is_purchasable', [self::class, 'purchasable'], PHP_INT_MAX, 2);
        add_action('woocommerce_after_checkout_validation', [self::class, 'checkout_validation'], 10, 2);
        add_action('woocommerce_checkout_create_order', [self::class, 'creation_guard'], 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [self::class, 'store_order_guard'], 1, 2);
        add_action('woocommerce_before_order_object_save', [self::class, 'save_guard'], 1);
        add_filter('rest_request_before_callbacks', [self::class, 'rest_guard'], 10, 3);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_ilovesad_wfpms_save_pilot', [self::class, 'save_settings']);
        add_action('admin_post_ilovesad_wfpms_close_pilot', [self::class, 'close_pilot']);
    }

    public static function settings(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    private static function ids(): array
    {
        $ids = self::settings()['product_ids'] ?? [];
        return is_array($ids) ? array_values(array_unique(array_filter(array_map('absint', $ids)))) : [];
    }

    private static function buyer_allowed(): bool
    {
        $settings = self::settings();
        $buyerId = (int) ($settings['buyer_id'] ?? 0);
        return ($settings['enabled'] ?? false) === true && count(self::ids()) === 2
            && $buyerId > 0 && is_user_logged_in() && get_current_user_id() === $buyerId;
    }

    private static function allowed_ids(array $ids): bool
    {
        return self::buyer_allowed() && $ids !== [] && array_diff($ids, self::ids()) === [];
    }

    private static function cart_ids(): array
    {
        if (!function_exists('WC') || !WC()->cart) { return []; }
        return array_map([ProductRouting::class, 'cart_item_product_id'], WC()->cart->get_cart());
    }

    private static function cart_error(array $ids): ?string
    {
        if (array_intersect($ids, self::ids()) === []) { return null; }
        return self::allowed_ids($ids) ? null : self::ERROR;
    }

    private static function managed_order_pay(): ?\WC_Order
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) { return null; }
        $id = absint(get_query_var('order-pay'));
        $order = $id > 0 ? wc_get_order($id) : false;
        return $order instanceof \WC_Order && PurchaseRepository::for_order($id) !== null ? $order : null;
    }

    public static function assert_order(\WC_Order $order): void
    {
        $ids = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product || $item->get_quantity() <= 0) {
                throw new \RuntimeException(self::ERROR);
            }
            $ids[] = (int) ($item->get_variation_id() ?: $item->get_product_id());
        }
        if (!self::allowed_ids($ids) || (int) $order->get_customer_id() !== get_current_user_id()) {
            throw new \RuntimeException(self::ERROR);
        }
    }

    public static function context_allowed(): bool
    {
        $order = self::managed_order_pay();
        if ($order !== null) {
            try { self::assert_order($order); return true; } catch (\RuntimeException $error) { return false; }
        }
        return self::allowed_ids(self::cart_ids());
    }

    public static function validate_cart(): void
    {
        $ids = self::cart_ids();
        $error = self::cart_error($ids);
        if ($error !== null) { wc_add_notice($error, 'error'); }
        elseif (self::allowed_ids($ids)) { OrderPurchaseService::validate_cart(); }
    }

    public static function add_to_cart(bool $passed, int $id, int $quantity, int $variationId = 0, array $variation = []): bool
    {
        if (!$passed) { return false; }
        $ids = array_merge(self::cart_ids(), [$variationId ?: $id]);
        if (self::cart_error($ids) !== null) { wc_add_notice(self::ERROR, 'error'); return false; }
        return self::allowed_ids($ids)
            ? OrderPurchaseService::validate_add_to_cart($passed, $id, $quantity, $variationId, $variation) : $passed;
    }

    public static function store_add_to_cart(\WC_Product $product, array $request): void
    {
        $ids = array_merge(self::cart_ids(), [(int) $product->get_id()]);
        if (self::cart_error($ids) !== null) { throw new \Exception(self::ERROR); }
        if (self::allowed_ids($ids)) { OrderPurchaseService::validate_store_api_add_to_cart($product, $request); }
    }

    public static function purchasable(bool $allowed, \WC_Product $product): bool
    {
        return $allowed && (!in_array((int) $product->get_id(), self::ids(), true) || self::buyer_allowed());
    }

    public static function cart_gateways(array $gateways): array
    {
        if (self::managed_order_pay() !== null) {
            return self::context_allowed() ? OrderPurchaseService::filter_cart_gateways($gateways) : [];
        }
        if (self::context_allowed()) { return OrderPurchaseService::filter_cart_gateways($gateways); }
        unset($gateways['wfpms_card'], $gateways['wfpms_iban']);
        return $gateways;
    }

    public static function legacy_gateways(array $gateways): array
    {
        return self::context_allowed() ? LegacyMigration::filter_gateways($gateways) : self::cart_gateways($gateways);
    }

    public static function render_seller(): void
    {
        if (self::context_allowed()) { OrderPurchaseService::render_checkout_seller(); }
    }

    public static function gateway_title(string $title, string $id): string
    {
        return self::context_allowed() ? OrderPurchaseService::filter_gateway_title($title, $id) : $title;
    }

    public static function checkout_validation(array $data, \WP_Error $errors): void
    {
        if ((in_array($data['payment_method'] ?? '', self::METHODS, true) && !self::allowed_ids(self::cart_ids()))
            || self::cart_error(self::cart_ids()) !== null) {
            $errors->add('ilovesad_wfpms_pilot_denied', self::ERROR);
        }
    }

    public static function creation_guard($order): void
    {
        if ($order instanceof \WC_Order && in_array($order->get_payment_method(), self::METHODS, true)) {
            self::assert_order($order);
        }
    }

    public static function store_order_guard(\WC_Order $order, \WP_REST_Request $request): void
    {
        if (in_array($request->get_param('payment_method'), self::METHODS, true)) { self::assert_order($order); }
        if (self::cart_error(self::cart_ids()) !== null) { throw new \RuntimeException(self::ERROR); }
    }

    public static function save_guard($order): void
    {
        if (!$order instanceof \WC_Order) { return; }
        $changes = $order->get_changes();
        // Do not gate callback/runner writes on current login or current pilot settings.
        if ((int) $order->get_id() === 0 || isset($changes['payment_method'])) { self::creation_guard($order); }
    }

    public static function rest_guard($result, $handler, \WP_REST_Request $request)
    {
        if ($result !== null || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) { return $result; }
        if (preg_match('#\A/wc/(?:store/v[0-9]+/checkout|v[0-9]+/orders(?:/[0-9]+)?)\z#', $request->get_route())
            && in_array($request->get_param('payment_method'), self::METHODS, true) && !self::buyer_allowed()) {
            return new \WP_Error('ilovesad_wfpms_pilot_denied', self::ERROR, ['status' => 403]);
        }
        return $result;
    }

    public static function finalize_order($order): void
    {
        if ($order instanceof \WC_Order && in_array($order->get_payment_method(), self::METHODS, true)) {
            self::assert_order($order);
            OrderPurchaseService::finalize_checkout_order($order);
        }
    }

    public static function wrap_gateways(array $gateways): array
    {
        foreach ($gateways as $key => $gateway) {
            if (in_array($gateway, ['WfpMultiSeller\\WayForPayGateway', 'WfpMultiSeller\\BankTransferGateway'], true)) {
                $gateways[$key] = new GuardedGateway(new $gateway());
            } elseif ($gateway instanceof \WC_Payment_Gateway && in_array($gateway->id, self::METHODS, true)
                && !$gateway instanceof GuardedGateway) {
                $gateways[$key] = new GuardedGateway($gateway);
            }
        }
        return $gateways;
    }

    public static function admin_menu(): void
    {
        add_submenu_page('woocommerce', 'Контрольний пілот WayForPay', 'Пілот WayForPay', 'manage_woocommerce',
            'ilovesad-wfpms-pilot', [self::class, 'admin_page']);
    }

    public static function admin_page(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden', '', ['response' => 403]); }
        $settings = self::settings();
        echo '<div class="wrap"><h1>Контрольний пілот WayForPay</h1>';
        echo '<p>Лише авторизований WordPress-акаунт trofym@ukr.net. Укажіть його локальний user ID і два локальні товарні ID. Порожній список або вимкнений пілот блокує нові покупки форку. Email у checkout не використовується.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ilovesad_wfpms_save_pilot">';
        wp_nonce_field('ilovesad_wfpms_save_pilot');
        echo '<p><label>WordPress user ID <input name="buyer_id" type="number" min="1" value="'
            . esc_attr((string) ($settings['buyer_id'] ?? 0)) . '"></label></p>';
        echo '<p><label>Два товарні ID через кому <input name="product_ids" value="' . esc_attr(implode(',', self::ids())) . '"></label></p>';
        echo '<p><label><input name="enabled" type="checkbox" value="1" ' . checked($settings['enabled'] ?? false, true, false)
            . '> Дозволити нові контрольні покупки</label></p>';
        echo '<p>Зняття прапорця зупиняє нові покупки й повторні платіжні спроби. Callback, підтвердження виданого IBAN та runner залишаються активними.</p>';
        submit_button('Зберегти');
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ilovesad_wfpms_close_pilot">';
        wp_nonce_field('ilovesad_wfpms_close_pilot');
        submit_button('Зупинити нові контрольні покупки', 'secondary');
        echo '</form></div>';
    }

    public static function close_pilot(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer('ilovesad_wfpms_close_pilot');
        $settings = self::settings();
        $settings['enabled'] = false;
        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(admin_url('admin.php?page=ilovesad-wfpms-pilot'));
        exit;
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer('ilovesad_wfpms_save_pilot');
        $buyerRaw = $_POST['buyer_id'] ?? '';
        $idsRaw = $_POST['product_ids'] ?? '';
        if (!is_scalar($buyerRaw) || !is_scalar($idsRaw)) { wp_die('Invalid settings', '', ['response' => 400]); }
        $buyerId = absint($buyerRaw);
        $user = get_user_by('id', $buyerId);
        $text = trim(wp_unslash((string) $idsRaw));
        $parts = $text === '' ? [] : preg_split('/\s*,\s*/', $text);
        $ids = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part) || (int) $part < 1) { wp_die('Invalid product ID', '', ['response' => 400]); }
            $ids[] = (int) $part;
        }
        $ids = array_values(array_unique($ids));
        if (!$user || strcasecmp($user->user_email, 'trofym@ukr.net') !== 0 || !in_array(count($ids), [0, 2], true)) {
            wp_die('Choose the local trofym@ukr.net user ID and zero or two product IDs.', '', ['response' => 400]);
        }
        foreach ($ids as $id) {
            if (!wc_get_product($id)) { wp_die('Product missing', '', ['response' => 400]); }
        }
        $enabled = isset($_POST['enabled']) && is_scalar($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        if ($enabled && (!LegacyMigration::is_standalone_enabled() || LegacyMigration::owns_callback_route()
            || LegacyMigration::is_legacy_transition_enabled())) {
            wp_die('Pilot requires standalone mode with the historical route owned by the original plugin.', '', ['response' => 400]);
        }
        update_option(self::OPTION, ['buyer_id' => $buyerId, 'product_ids' => $ids,
            'enabled' => $enabled], false);
        wp_safe_redirect(admin_url('admin.php?page=ilovesad-wfpms-pilot'));
        exit;
    }
}

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WC_Payment_Gateway')) { return; }
    require_once __DIR__ . '/class-guarded-gateway.php';
    Pilot::boot();
}, 30);
