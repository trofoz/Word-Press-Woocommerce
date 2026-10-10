<?php
/**
 * Plugin Name: WayForPay Multi-Seller for WooCommerce
 * Description: Configurable seller profiles and product routing for WayForPay WooCommerce payments.
 * Version: 0.1.0-dev
 * Author: WayForPay and contributors
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wayforpay-multi-seller
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 */

declare(strict_types=1);

namespace WfpMultiSeller;

if (!defined('ABSPATH')) {
    exit;
}

define('WFPMS_VERSION', '0.1.0-dev');
define('WFPMS_FILE', __FILE__);
define('WFPMS_DIR', plugin_dir_path(__FILE__));
define('WFPMS_URL', plugin_dir_url(__FILE__));

require_once WFPMS_DIR . 'src/class-secret-store.php';
require_once WFPMS_DIR . 'src/class-seller-repository.php';
require_once WFPMS_DIR . 'src/class-product-routing.php';
require_once WFPMS_DIR . 'src/class-product-editor.php';
require_once WFPMS_DIR . 'src/class-admin.php';
require_once WFPMS_DIR . 'src/class-installer.php';
require_once WFPMS_DIR . 'src/class-purchase-repository.php';
require_once WFPMS_DIR . 'src/class-order-purchase-service.php';
require_once WFPMS_DIR . 'src/class-order-mutation-guard.php';
require_once WFPMS_DIR . 'src/class-purchase-execution-service.php';
require_once WFPMS_DIR . 'src/class-payment-operations.php';
require_once WFPMS_DIR . 'src/class-legacy-migration.php';
add_action('before_woocommerce_init', static function (): void {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks', WFPMS_FILE, true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', WFPMS_FILE, true
        );
    }
});

register_activation_hook(WFPMS_FILE, [Installer::class, 'activate']);
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__('WayForPay Multi-Seller requires WooCommerce.', 'wayforpay-multi-seller')
                    . '</p></div>';
            }
        });
        return;
    }

    Installer::maybe_upgrade();
    ProductEditor::boot();
    Admin::boot();
    LegacyMigration::boot();
    OrderMutationGuard::boot();
    OrderPurchaseService::boot();
    require_once WFPMS_DIR . 'src/class-wayforpay-gateway.php';
    require_once WFPMS_DIR . 'src/class-bank-transfer-gateway.php';
    require_once WFPMS_DIR . 'src/class-blocks-integration.php';
    WayForPayGateway::boot();
    BankTransferGateway::boot();
    PaymentOperations::boot();
    PurchaseExecutionService::boot();
    BlocksIntegration::boot();
    add_filter('woocommerce_payment_gateways', [WayForPayGateway::class, 'register']);
}, 20);
