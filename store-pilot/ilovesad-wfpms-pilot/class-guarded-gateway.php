<?php
declare(strict_types=1);

namespace ILoveSad\WfpmsPilot;

use WfpMultiSeller\PurchaseRepository;

defined('ABSPATH') || exit;

/** Store policy in front of the unchanged, registered WooCommerce gateway. */
final class GuardedGateway extends \WC_Payment_Gateway
{
    private \WC_Payment_Gateway $inner;

    public function __construct(\WC_Payment_Gateway $inner)
    {
        $this->inner = $inner;
        foreach (get_object_vars($inner) as $key => $value) { $this->$key = $value; }
    }

    public function is_available(): bool
    {
        return Pilot::context_allowed() && $this->inner->is_available();
    }

    public function process_payment($orderId): array
    {
        $id = (int) $orderId;
        if ($id < 1 || !PurchaseRepository::order_lock($id)) { return ['result' => 'failure']; }
        try {
            $order = wc_get_order($id);
            if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->id) {
                throw new \RuntimeException('Контрольне замовлення або спосіб оплати не відповідає пілоту.');
            }
            Pilot::assert_order($order);
            return $this->inner->process_payment($id);
        } catch (\Throwable $error) {
            wc_add_notice(esc_html($error->getMessage()), 'error');
            return ['result' => 'failure'];
        } finally {
            PurchaseRepository::release_order_lock($id);
        }
    }

    public function get_option($key, $emptyValue = null)
    {
        return $this->inner->get_option($key, $emptyValue);
    }

    public function payment_fields(): void { $this->inner->payment_fields(); }
    public function admin_options(): void { $this->inner->admin_options(); }
    public function process_admin_options(): bool { return $this->inner->process_admin_options(); }
}
