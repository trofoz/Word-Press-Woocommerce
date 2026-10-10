<?php
declare(strict_types=1);

namespace WfpMultiSeller;

final class ProductEditor
{
    public static function boot(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_field']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'save_field']);
    }

    public static function render_field(): void
    {
        global $post;
        if (!$post instanceof \WP_Post) {
            return;
        }
        wp_nonce_field('wfpms_save_product_seller', 'wfpms_product_nonce');
        $product = wc_get_product($post->ID);
        if (!$product || $product->is_type('variation')) {
            return;
        }

        $selected = (int) get_post_meta($post->ID, ProductRouting::META_SELLER_ID, true);
        $resolution = ProductRouting::resolve((int) $post->ID);
        $options = ['' => __('Автоматично за категорією', 'wayforpay-multi-seller')];
        foreach (SellerRepository::all(false) as $seller) {
            $options[(string) $seller['id']] = (string) $seller['internal_label'] . ' — ' . (string) $seller['legal_name'];
        }
        $archived = $selected > 0 ? SellerRepository::find($selected) : null;
        if ($archived !== null && (int) $archived['active'] !== 1) {
            $options[(string) $selected] = sprintf(
                __('АРХІВ — %s (купівля потребує нового призначення)', 'wayforpay-multi-seller'),
                (string) $archived['internal_label']
            );
        } elseif ($selected > 0 && $archived === null) {
            $options[(string) $selected] = sprintf(
                __('ВІДСУТНІЙ продавець #%d (призначення потрібно виправити)', 'wayforpay-multi-seller'),
                $selected
            );
        }

        woocommerce_wp_select([
            'id' => 'wfpms_seller_id',
            'label' => __('Продавець', 'wayforpay-multi-seller'),
            'description' => __('Ручний вибір має пріоритет над правилами категорій. Варіації успадковують продавця батьківського товару.', 'wayforpay-multi-seller'),
            'desc_tip' => true,
            'value' => $selected > 0 ? (string) $selected : '',
            'options' => $options,
        ]);

        $effective = $resolution['ok'] && is_array($resolution['seller'])
            ? (string) $resolution['seller']['internal_label'] . ' — ' . (string) $resolution['seller']['legal_name']
            : self::reason_label($resolution['reason']);
        $class = $resolution['ok'] ? 'notice notice-success inline' : 'notice notice-warning inline';
        echo '<p class="' . esc_attr($class) . '"><strong>'
            . esc_html__('Ефективне правило:', 'wayforpay-multi-seller') . '</strong> '
            . esc_html($effective) . '</p>';
    }

    public static function save_field(\WC_Product $product): void
    {
        if (!$product->is_type('simple') && !$product->is_type('variable') && !$product->is_type('external') && !$product->is_type('grouped')) {
            return;
        }
        if (!current_user_can('edit_post', $product->get_id())
            || !isset($_POST['wfpms_product_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wfpms_product_nonce'])), 'wfpms_save_product_seller')) {
            return;
        }

        $raw = isset($_POST['wfpms_seller_id']) ? sanitize_text_field(wp_unslash($_POST['wfpms_seller_id'])) : '';
        if ($raw === '') {
            $product->delete_meta_data(ProductRouting::META_SELLER_ID);
            return;
        }
        if (!ctype_digit($raw) || (int) $raw < 1) {
            return;
        }
        $seller = SellerRepository::find((int) $raw);
        if ($seller !== null && (int) $seller['active'] === 1) {
            $product->update_meta_data(ProductRouting::META_SELLER_ID, (string) (int) $raw);
        }
    }

    public static function reason_label(string $reason): string
    {
        $labels = [
            'seller_not_assigned' => __('Не визначено — додайте правило категорії або виберіть продавця вручну', 'wayforpay-multi-seller'),
            'category_seller_conflict' => __('Конфлікт — категорії визначають різних продавців', 'wayforpay-multi-seller'),
            'archived_manual_seller' => __('Архівований продавець — перепризначте товар', 'wayforpay-multi-seller'),
            'category_rule_archived_seller' => __('Архівований продавець у правилі категорії', 'wayforpay-multi-seller'),
            'unknown_manual_seller' => __('Вказаний продавець відсутній', 'wayforpay-multi-seller'),
            'category_rule_unknown_seller' => __('Правило категорії посилається на відсутнього продавця', 'wayforpay-multi-seller'),
            'missing_product' => __('Товар не знайдено', 'wayforpay-multi-seller'),
            'missing_parent' => __('Варіація не має доступного батьківського товару', 'wayforpay-multi-seller'),
            'category_lookup_failed' => __('Не вдалося прочитати категорії', 'wayforpay-multi-seller'),
        ];
        return $labels[$reason] ?? __('Потрібно перевірити налаштування товару', 'wayforpay-multi-seller');
    }
}
