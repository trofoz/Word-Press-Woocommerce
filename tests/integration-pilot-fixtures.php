<?php

use WfpMultiSeller\ProductRouting;
use WfpMultiSeller\SecretStore;
use WfpMultiSeller\SellerRepository;

$buyerId = wp_insert_user(['user_login' => 'pilot-buyer', 'user_email' => 'pilot-buyer@example.test',
    'user_pass' => getenv('WFPMS_PILOT_BUYER_PASSWORD'), 'role' => 'customer']);
$otherId = wp_insert_user(['user_login' => 'pilot-other', 'user_email' => 'other@example.test',
    'user_pass' => getenv('WFPMS_PILOT_OTHER_PASSWORD'), 'role' => 'customer']);
if (is_wp_error($buyerId) || is_wp_error($otherId)) { throw new RuntimeException('Pilot users could not be seeded.'); }
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) $admins[0];
$products = [];
foreach (['book' => false, 'webinar' => true] as $name => $virtual) {
    $secret = 'pilot-runtime-only-' . $name;
    $sellerId = SellerRepository::save(null, ['internal_label' => 'Pilot ' . $name,
        'legal_name' => 'Synthetic pilot ' . $name, 'tax_id' => 'TEST-' . $name], [
        'recipient_name' => 'Synthetic pilot ' . $name, 'iban' => 'UA213223130000026007233566001',
        'bank_name' => 'Synthetic Bank', 'purpose_template' => 'Order {order_number}',
        'merchant_account' => 'pilot-' . $name, 'merchant_domain' => '127.0.0.1',
        'card_enabled' => 1, 'iban_enabled' => 1, 'secret_plaintext' => $secret, 'operator_checked' => false,
    ], SecretStore::encrypt($secret), $adminId);
    $product = new WC_Product_Simple();
    $product->set_name('Synthetic pilot ' . $name);
    $product->set_status('publish');
    $product->set_regular_price('100.00');
    $product->set_virtual($virtual);
    $product->save();
    update_post_meta((int) $product->get_id(), ProductRouting::META_SELLER_ID, $sellerId);
    $products[$name] = (int) $product->get_id();
}
$normal = new WC_Product_Simple();
$normal->set_name('Ordinary unmapped store product');
$normal->set_status('publish');
$normal->set_regular_price('100.00');
$normal->set_virtual(true);
$normal->save();
$products['normal'] = (int) $normal->get_id();
WC_Install::create_pages();
$classicPage = wp_insert_post(['post_title' => 'Pilot Classic checkout', 'post_type' => 'page',
    'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]']);
$blocksPage = wp_insert_post(['post_title' => 'Pilot Blocks checkout', 'post_type' => 'page',
    'post_status' => 'publish', 'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->']);
update_option('woocommerce_checkout_page_id', getenv('WFPMS_CHECKOUT') === 'blocks' ? $blocksPage : $classicPage);
update_option('woocommerce_currency', 'UAH');
update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Existing bank transfer']);
update_option('woocommerce_wayforpay_settings', ['enabled' => 'yes', 'title' => 'Original WayForPay',
    'showlogo' => 'no', 'returnUrl' => home_url('/'), 'description' => 'Original checkout',
    'merchant' => 'synthetic-original', 'secretkey' => 'synthetic-original-key']);
update_option('wfpms_integration_iban_product_id', $products['webinar']);
$data = ['buyer_id' => (int) $buyerId, 'other_id' => (int) $otherId, 'admin_id' => $adminId,
    'products' => $products, 'classic_page' => (int) $classicPage, 'blocks_page' => (int) $blocksPage,
    'checkout_page_id' => (int) wc_get_page_id('checkout'),
    'classic_page_content' => (string) get_post((int) $classicPage)->post_content,
    'guest_checkout_allowed' => !WC()->checkout()->is_registration_required()];
update_option('wfpms_pilot_fixture', $data, false);
file_put_contents(getenv('WFPMS_PILOT_FIXTURE_PATH'), wp_json_encode($data));
// Only this disposable runtime enqueues the native REST client nonce for HTTP cookie tests.
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents(WPMU_PLUGIN_DIR . '/pilot-runtime-nonce.php',
    <<<'PHP'
<?php
add_action('wp_enqueue_scripts', static function () { wp_enqueue_script('wp-api'); });
add_action('wp_footer', static function () {
    global $wp_query;
    $page = get_queried_object();
    $cart = function_exists('WC') ? WC()->cart : null;
    $state = [
        'page_id' => get_queried_object_id(),
        'checkout' => function_exists('is_checkout') ? is_checkout() : null,
        'cart_object' => $cart instanceof WC_Cart,
        'cart_count' => $cart instanceof WC_Cart ? $cart->get_cart_contents_count() : null,
        'checkout_shortcode_registered' => shortcode_exists('woocommerce_checkout'),
        'page_has_shortcode' => $page instanceof WP_Post && has_shortcode($page->post_content, 'woocommerce_checkout'),
        'query_is_page' => is_object($wp_query) && $wp_query->is_page,
        'theme' => wp_get_theme()->get_stylesheet(),
    ];
    echo '<!-- WFPMS-PILOT-HTTP-DIAG:' . esc_html(wp_json_encode($state)) . ' -->';
});
PHP
);
echo "PASS: isolated pilot fixtures contain two 100 UAH products, separate sellers, local users and an unmapped ordinary product.\n";
