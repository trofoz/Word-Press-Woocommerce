<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\Integrations {
    interface IntegrationInterface
    {
        public function get_name(): string;
        public function initialize(): void;
        public function get_script_handles(): array;
        public function get_editor_script_handles(): array;
        public function get_script_data(): array;
    }
}

namespace WfpMultiSeller {
    final class ProductRouting
    {
        public static function cart_item_product_id(array $item): int
        {
            return (int) ($item['product_id'] ?? 0);
        }

        public static function resolve(int $productId): array
        {
            return ['ok' => true, 'seller_id' => 7];
        }
    }

    final class SellerRepository
    {
        public static function current_config(int $sellerId): ?array
        {
            return ['recipient_name' => 'ТОВ «Сад»'];
        }
    }

    require_once __DIR__ . '/../src/class-order-purchase-service.php';
}

namespace {
    define('WFPMS_URL', 'https://example.test/plugin/');
    define('WFPMS_VERSION', 'test');

    function add_action(string $hook, $callback): void {}

    function WC()
    {
        return $GLOBALS['wc'];
    }

    final class TestCart
    {
        public function is_empty(): bool { return false; }
        public function get_cart(): array { return [['product_id' => 42]]; }
    }

    final class TestWoo
    {
        public TestCart $cart;
        public function __construct() { $this->cart = new TestCart(); }
    }

    require_once __DIR__ . '/../src/class-blocks-integration.php';
    \WfpMultiSeller\BlocksIntegration::boot();

    $registry = new class {
        public array $items = [];
        public function register($item): void { $this->items[] = $item; }
    };
    \WfpMultiSeller\BlocksIntegration::register_checkout_label($registry);
    if (count($registry->items) !== 1
        || !$registry->items[0] instanceof \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface) {
        throw new \RuntimeException('Checkout Blocks integration was not registered.');
    }

    $GLOBALS['wc'] = new TestWoo();
    if (\WfpMultiSeller\OrderPurchaseService::checkout_seller_name() !== 'ТОВ «Сад»') {
        throw new \RuntimeException('Checkout seller name did not resolve from the current seller configuration.');
    }

    echo "PASS: Checkout Blocks integration registration and seller name contract\n";
}
