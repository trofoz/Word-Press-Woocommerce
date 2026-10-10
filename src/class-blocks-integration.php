<?php
declare(strict_types=1);

namespace WfpMultiSeller;

/** WooCommerce Checkout Blocks payment method registration. */
final class BlocksIntegration
{
    public static function boot(): void
    {
        add_action('woocommerce_blocks_payment_method_type_registration', [self::class, 'register_methods']);
        add_action('woocommerce_blocks_checkout_block_registration', [self::class, 'register_checkout_label']);
    }

    public static function register_methods($registry): void
    {
        $base = 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType';
        if (!class_exists($base) || !is_object($registry) || !method_exists($registry, 'register')) {
            return;
        }
        $registry->register(new CardBlocksMethod());
        $registry->register(new IbanBlocksMethod());
    }

    public static function register_checkout_label($registry): void
    {
        $interface = 'Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface';
        if (interface_exists($interface) && is_object($registry) && method_exists($registry, 'register')) {
            $registry->register(new CheckoutSellerLabelIntegration());
        }
    }
}

if (interface_exists('Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface')) {
    final class CheckoutSellerLabelIntegration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface
    {
        public function get_name(): string
        {
            return 'wfpms-checkout-seller';
        }

        public function initialize(): void
        {
            wp_register_script(
                'wfpms-checkout-seller-label',
                WFPMS_URL . 'assets/js/checkout-seller-label.js',
                ['wc-blocks-checkout', 'wp-plugins', 'wp-element', 'wp-i18n'],
                WFPMS_VERSION,
                true
            );
        }

        public function get_script_handles(): array
        {
            return ['wfpms-checkout-seller-label'];
        }

        public function get_editor_script_handles(): array
        {
            return [];
        }

        public function get_script_data(): array
        {
            return ['sellerName' => OrderPurchaseService::checkout_seller_name()];
        }
    }
}

if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    abstract class SellerPaymentBlocksMethod extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
        protected $name = '';
        protected $gatewayId = '';
        protected $methodTitle = '';

        public function initialize(): void
        {
            $this->settings = get_option('woocommerce_' . $this->gatewayId . '_settings', []);
        }

        public function is_active(): bool
        {
            if (!function_exists('WC') || !WC()->payment_gateways()) {
                return false;
            }
            $gateway = WC()->payment_gateways()->payment_gateways()[$this->gatewayId] ?? null;
            return $gateway instanceof \WC_Payment_Gateway && $gateway->is_available();
        }

        public function get_payment_method_script_handles(): array
        {
            wp_register_script(
                'wfpms-checkout-blocks',
                WFPMS_URL . 'assets/js/payment-methods.js',
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
                WFPMS_VERSION,
                true
            );
            return ['wfpms-checkout-blocks'];
        }

        public function get_payment_method_data(): array
        {
            $description = $this->gatewayId === 'wfpms_iban'
                ? __('Реквізити рахунку продавця з’являться після оформлення замовлення.', 'wayforpay-multi-seller')
                : __('Безпечна оплата карткою через WayForPay.', 'wayforpay-multi-seller');
            $sellerName = OrderPurchaseService::checkout_seller_name();
            if ($sellerName !== '') {
                $description .= ' ' . sprintf(__('Продавець: %s.', 'wayforpay-multi-seller'), $sellerName);
            }
            return [
                'title' => $this->methodTitle,
                'description' => $description,
                'supports' => ['products'],
            ];
        }
    }

    final class CardBlocksMethod extends SellerPaymentBlocksMethod
    {
        protected $name = 'wfpms_card';
        protected $gatewayId = 'wfpms_card';
        protected $methodTitle = 'Оплата карткою';
    }

    final class IbanBlocksMethod extends SellerPaymentBlocksMethod
    {
        protected $name = 'wfpms_iban';
        protected $gatewayId = 'wfpms_iban';
        protected $methodTitle = 'Переказ на рахунок';
    }
}
