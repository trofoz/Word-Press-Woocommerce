<?php
declare(strict_types=1);

namespace WfpMultiSeller;

final class Admin
{
    private const CAPABILITY = 'manage_woocommerce';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_pages']);
        add_action('admin_post_wfpms_save_seller', [self::class, 'handle_save_seller']);
        add_action('admin_post_wfpms_archive_seller', [self::class, 'handle_archive_seller']);
        add_action('admin_post_wfpms_activate_seller', [self::class, 'handle_activate_seller']);
        add_action('admin_post_wfpms_save_category_rules', [self::class, 'handle_save_category_rules']);
    }

    public static function register_pages(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Продавці та маршрутизація', 'wayforpay-multi-seller'),
            __('Продавці', 'wayforpay-multi-seller'),
            self::CAPABILITY,
            'wfpms-sellers',
            [self::class, 'render_sellers']
        );
        add_submenu_page(
            'woocommerce',
            __('Аудит товарів', 'wayforpay-multi-seller'),
            __('Аудит продавців', 'wayforpay-multi-seller'),
            self::CAPABILITY,
            'wfpms-product-audit',
            [self::class, 'render_product_audit']
        );
    }

    public static function render_sellers(): void
    {
        self::authorize_page();
        $editingId = isset($_GET['seller_id']) ? absint($_GET['seller_id']) : 0;
        $seller = $editingId > 0 ? SellerRepository::find($editingId) : null;
        if ($editingId > 0 && $seller === null) {
            echo '<div class="wrap"><h1>' . esc_html__('Продавців не знайдено', 'wayforpay-multi-seller') . '</h1></div>';
            return;
        }
        $config = $seller === null ? null : SellerRepository::current_config((int) $seller['id']);
        $secretConfigured = $config !== null && SecretStore::decrypt((string) $config['secret_ciphertext']) !== null;
        $notice = isset($_GET['wfpms_notice']) ? sanitize_key(wp_unslash($_GET['wfpms_notice'])) : '';

        echo '<div class="wrap"><h1>' . esc_html__('Продавці', 'wayforpay-multi-seller') . '</h1>';
        self::render_notice($notice);
        if (!SecretStore::available()) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Не налаштований WFPMS_MASTER_KEY або недоступний OpenSSL. Карткові реквізити не можна зберегти.', 'wayforpay-multi-seller')
                . '</p></div>';
        }
        self::render_seller_form($seller, $config, $secretConfigured);
        self::render_seller_list();
        self::render_category_rules();
        echo '</div>';
    }

    private static function render_seller_form(?array $seller, ?array $config, bool $secretConfigured): void
    {
        $editing = $seller !== null;
        $active = $editing ? (int) $seller['active'] === 1 : true;
        $legalLocked = $editing && !empty($seller['used_at']);
        echo '<h2>' . esc_html($editing ? __('Редагувати продавця', 'wayforpay-multi-seller') : __('Додати продавця', 'wayforpay-multi-seller')) . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wfpms_save_seller">';
        echo '<input type="hidden" name="seller_id" value="' . esc_attr((string) ($seller['id'] ?? 0)) . '">';
        wp_nonce_field('wfpms_save_seller');
        echo '<table class="form-table"><tbody>';
        self::text_row('internal_label', __('Внутрішня назва', 'wayforpay-multi-seller'), (string) ($seller['internal_label'] ?? ''), true);
        self::text_row('legal_name', __('Юридичний отримувач', 'wayforpay-multi-seller'), (string) ($seller['legal_name'] ?? ''), true, $legalLocked);
        self::text_row('tax_id', __('Податковий ідентифікатор', 'wayforpay-multi-seller'), (string) ($seller['tax_id'] ?? ''), true, $legalLocked);
        self::text_row('recipient_name', __('Ім’я отримувача для платежу', 'wayforpay-multi-seller'), (string) ($config['recipient_name'] ?? ($seller['legal_name'] ?? '')), true);
        self::text_row('iban', __('IBAN', 'wayforpay-multi-seller'), (string) ($config['iban'] ?? ''));
        self::text_row('bank_name', __('Банк', 'wayforpay-multi-seller'), (string) ($config['bank_name'] ?? ''));
        self::text_row('purpose_template', __('Призначення платежу', 'wayforpay-multi-seller'), (string) ($config['purpose_template'] ?? 'Оплата за замовлення №{order_number}'), true);
        self::text_row('merchant_account', __('WayForPay merchantAccount', 'wayforpay-multi-seller'), (string) ($config['merchant_account'] ?? ''));
        self::text_row('merchant_domain', __('Домен мерчанта', 'wayforpay-multi-seller'), (string) ($config['merchant_domain'] ?? ''));
        echo '<tr><th scope="row"><label for="wfpms_secret">' . esc_html__('WayForPay secret key', 'wayforpay-multi-seller') . '</label></th><td>';
        echo '<input class="regular-text" type="password" autocomplete="new-password" id="wfpms_secret" name="secret_key" value="" spellcheck="false">';
        echo '<p class="description">' . esc_html($secretConfigured
            ? __('Ключ уже збережений зашифрованим. Порожнє поле залишає його без змін.', 'wayforpay-multi-seller')
            : __('Ключ буде зашифровано перед записом у базу.', 'wayforpay-multi-seller')) . '</p></td></tr>';
        self::checkbox_row('card_enabled', __('Дозволити оплату карткою', 'wayforpay-multi-seller'), (bool) ($config['card_enabled'] ?? false));
        self::checkbox_row('iban_enabled', __('Дозволити переказ на IBAN', 'wayforpay-multi-seller'), (bool) ($config['iban_enabled'] ?? false));
        self::checkbox_row('operator_checked', __('Я перевірив ці реквізити зараз', 'wayforpay-multi-seller'), false);
        echo '<tr><td></td><td><p class="description">' . esc_html__('Підтвердження записується для нової версії реквізитів. Якщо змінюєте реквізити, поставте прапорець лише після перевірки саме нових даних.', 'wayforpay-multi-seller') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button($editing ? __('Зберегти продавця', 'wayforpay-multi-seller') : __('Створити продавця', 'wayforpay-multi-seller'));
        if ($editing && $active) {
            echo '<p class="description">' . esc_html(sprintf(__('Поточна версія платіжних реквізитів: %d. Зміна реквізитів створює нову версію.', 'wayforpay-multi-seller'), (int) ($config['version'] ?? 0))) . '</p>';
        }
        echo '</form>';
    }

    private static function text_row(string $name, string $label, string $value, bool $required = false, bool $disabled = false): void
    {
        $id = 'wfpms_' . $name;
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"'
            . ($required ? ' required' : '') . ($disabled ? ' readonly' : '') . '>';
        echo '</td></tr>';
    }

    private static function checkbox_row(string $name, string $label, bool $checked): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"'
            . checked($checked, true, false) . '> ' . esc_html__('Увімкнено / підтверджено', 'wayforpay-multi-seller') . '</label></td></tr>';
    }

    private static function render_seller_list(): void
    {
        echo '<hr><h2>' . esc_html__('Налаштовані профілі', 'wayforpay-multi-seller') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Назва', 'wayforpay-multi-seller')
            . '</th><th>' . esc_html__('Отримувач', 'wayforpay-multi-seller') . '</th><th>' . esc_html__('Способи / версія', 'wayforpay-multi-seller')
            . '</th><th>' . esc_html__('Стан', 'wayforpay-multi-seller') . '</th><th>' . esc_html__('Дії', 'wayforpay-multi-seller')
            . '</th></tr></thead><tbody>';
        $sellers = SellerRepository::all();
        if ($sellers === []) {
            echo '<tr><td colspan="6">' . esc_html__('Продавців ще немає.', 'wayforpay-multi-seller') . '</td></tr>';
        }
        foreach ($sellers as $seller) {
            $config = SellerRepository::current_config((int) $seller['id']);
            $ways = [];
            if (!empty($config['card_enabled'])) $ways[] = 'WayForPay';
            if (!empty($config['iban_enabled'])) $ways[] = 'IBAN';
            $edit = add_query_arg(['page' => 'wfpms-sellers', 'seller_id' => (int) $seller['id']], admin_url('admin.php'));
            echo '<tr><td>' . esc_html((string) $seller['id']) . '</td><td>' . esc_html((string) $seller['internal_label'])
                . '</td><td>' . esc_html((string) $seller['legal_name']) . '<br><code>' . esc_html((string) $seller['tax_id']) . '</code>'
                . '</td><td>' . esc_html(implode(', ', $ways)) . ' / ' . esc_html((string) ($config['version'] ?? '—'))
                . '</td><td>' . esc_html((int) $seller['active'] === 1 ? __('Активний', 'wayforpay-multi-seller') : __('Архів', 'wayforpay-multi-seller'))
                . '</td><td><a class="button" href="' . esc_url($edit) . '">' . esc_html__('Редагувати', 'wayforpay-multi-seller') . '</a> ';
            if ((int) $seller['active'] === 1) {
                echo '<form class="inline-form" method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="wfpms_archive_seller"><input type="hidden" name="seller_id" value="' . esc_attr((string) $seller['id']) . '">';
                wp_nonce_field('wfpms_archive_seller_' . (int) $seller['id']);
                echo '<button class="button" type="submit">' . esc_html__('Архівувати', 'wayforpay-multi-seller') . '</button></form>';
            } else {
                echo '<form class="inline-form" method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '">'
                    . '<input type="hidden" name="action" value="wfpms_activate_seller"><input type="hidden" name="seller_id" value="' . esc_attr((string) $seller['id']) . '">';
                wp_nonce_field('wfpms_activate_seller_' . (int) $seller['id']);
                echo '<button class="button" type="submit">' . esc_html__('Відновити', 'wayforpay-multi-seller') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_category_rules(): void
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return;
        }
        $rules = SellerRepository::category_rules();
        $activeSellers = SellerRepository::all(false);
        echo '<hr><h2>' . esc_html__('Правила категорій', 'wayforpay-multi-seller') . '</h2>';
        echo '<p>' . esc_html__('Дочірня категорія успадковує найближче правило предка. Кілька категорій товару, що ведуть до різних продавців, утворюють конфлікт. Неналаштована категорія сама по собі не є конфліктом.', 'wayforpay-multi-seller') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wfpms_save_category_rules">';
        wp_nonce_field('wfpms_save_category_rules');
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Категорія', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Продавець', 'wayforpay-multi-seller') . '</th></tr></thead><tbody>';
        foreach ($terms as $term) {
            $term = get_term($term);
            if (!$term instanceof \WP_Term) continue;
            echo '<tr><td>' . esc_html(str_repeat('— ', count(get_ancestors($term->term_id, 'product_cat', 'taxonomy')))
                . $term->name) . '</td><td><select name="rules[' . esc_attr((string) $term->term_id) . ']">';
            echo '<option value="">' . esc_html__('Без правила', 'wayforpay-multi-seller') . '</option>';
            foreach ($activeSellers as $seller) {
                echo '<option value="' . esc_attr((string) $seller['id']) . '"' . selected((int) ($rules[$term->term_id] ?? 0), (int) $seller['id'], false) . '>'
                    . esc_html((string) $seller['internal_label']) . '</option>';
            }
            $currentSellerId = (int) ($rules[$term->term_id] ?? 0);
            $currentSeller = $currentSellerId > 0 ? SellerRepository::find($currentSellerId) : null;
            if ($currentSellerId > 0 && ($currentSeller === null || (int) $currentSeller['active'] !== 1)) {
                $archivedLabel = $currentSeller === null
                    ? sprintf(__('ВІДСУТНІЙ продавець #%d', 'wayforpay-multi-seller'), $currentSellerId)
                    : sprintf(__('АРХІВ — %s', 'wayforpay-multi-seller'), (string) $currentSeller['internal_label']);
                echo '<option value="' . esc_attr((string) $currentSellerId) . '" selected>' . esc_html($archivedLabel) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        submit_button(__('Зберегти правила категорій', 'wayforpay-multi-seller'));
        echo '</form>';
    }

    public static function render_product_audit(): void
    {
        self::authorize_page();
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $result = wc_get_products([
            'limit' => 100,
            'page' => $page,
            'paginate' => true,
            'status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'return' => 'objects',
        ]);
        $products = is_object($result) && isset($result->products) && is_array($result->products) ? $result->products : [];
        $totalPages = is_object($result) ? max(1, (int) ($result->max_num_pages ?? 1)) : 1;
        $errors = 0;
        echo '<div class="wrap"><h1>' . esc_html__('Аудит призначення продавців', 'wayforpay-multi-seller') . '</h1>';
        echo '<p>' . esc_html__('Цей список показує лише товари сторінки. Перевірте кожну сторінку перед увімкненням платежів.', 'wayforpay-multi-seller') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Товар', 'wayforpay-multi-seller') . '</th><th>'
            . esc_html__('Проблема', 'wayforpay-multi-seller') . '</th><th>' . esc_html__('Призначення', 'wayforpay-multi-seller') . '</th></tr></thead><tbody>';
        foreach ($products as $product) {
            if (!$product instanceof \WC_Product) continue;
            $resolution = ProductRouting::resolve($product->get_id());
            if ($resolution['ok']) continue;
            ++$errors;
            $edit = get_edit_post_link($product->get_id());
            $manualAssignment = (int) get_post_meta($product->get_id(), ProductRouting::META_SELLER_ID, true) > 0;
            echo '<tr><td>' . esc_html((string) $product->get_id()) . '</td><td>'
                . ($edit ? '<a href="' . esc_url($edit) . '">' . esc_html($product->get_name()) . '</a>' : esc_html($product->get_name()))
                . '</td><td>' . esc_html(ProductEditor::reason_label($resolution['reason'])) . '</td><td>'
                . esc_html($manualAssignment
                    ? __('Ручне призначення', 'wayforpay-multi-seller')
                    : __('Категорії', 'wayforpay-multi-seller')) . '</td></tr>';
        }
        if ($errors === 0) {
            echo '<tr><td colspan="4">' . esc_html__('На цій сторінці немає товарів без визначеного продавця або з конфліктом.', 'wayforpay-multi-seller') . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($totalPages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                'base' => add_query_arg(['page' => 'wfpms-product-audit', 'paged' => '%#%'], admin_url('admin.php')),
                'format' => '', 'current' => $page, 'total' => $totalPages,
            ])) . '</div></div>';
        }
        echo '</div>';
    }

    public static function handle_save_seller(): void
    {
        self::authorize_post('wfpms_save_seller');
        $sellerId = isset($_POST['seller_id']) ? absint($_POST['seller_id']) : 0;
        $sellerId = $sellerId > 0 ? $sellerId : null;
        $existing = $sellerId === null ? null : SellerRepository::find($sellerId);
        if ($sellerId !== null && $existing === null) {
            self::redirect('seller_missing');
        }

        $identity = [
            'internal_label' => self::posted_text('internal_label', 191),
            'legal_name' => self::posted_text('legal_name', 255),
            'tax_id' => self::posted_text('tax_id', 64),
        ];
        $configuration = [
            'recipient_name' => self::posted_text('recipient_name', 255),
            'iban' => self::normalize_iban(self::posted_text('iban', 64)),
            'bank_name' => self::posted_text('bank_name', 191),
            'purpose_template' => self::posted_text('purpose_template', 255),
            'merchant_account' => self::posted_text('merchant_account', 191),
            'merchant_domain' => self::normalize_domain(self::posted_text('merchant_domain', 255)),
            'card_enabled' => isset($_POST['card_enabled']) ? 1 : 0,
            'iban_enabled' => isset($_POST['iban_enabled']) ? 1 : 0,
            'operator_checked' => isset($_POST['operator_checked']),
        ];
        if (in_array('', $identity, true) || $configuration['recipient_name'] === '' || $configuration['purpose_template'] === '') {
            self::redirect('required_fields');
        }
        if (!$configuration['card_enabled'] && !$configuration['iban_enabled']) {
            self::redirect('payment_method_required');
        }
        if (!str_contains($configuration['purpose_template'], '{order_number}')
            || preg_match('/\{(?!order_number\})[^}]*\}/', $configuration['purpose_template'])) {
            self::redirect('invalid_purpose');
        }
        if ($configuration['iban_enabled'] && !self::valid_iban($configuration['iban'])) {
            self::redirect('invalid_iban');
        }
        if ($configuration['iban_enabled'] && $configuration['bank_name'] === '') {
            self::redirect('missing_bank');
        }
        if ($configuration['card_enabled'] && ($configuration['merchant_account'] === '' || $configuration['merchant_domain'] === '')) {
            self::redirect('incomplete_card');
        }

        $postedSecret = isset($_POST['secret_key']) && is_string($_POST['secret_key'])
            ? (string) wp_unslash($_POST['secret_key'])
            : '';
        $existingConfig = $existing === null ? null : SellerRepository::current_config($sellerId);
        $secretPlaintext = $postedSecret;
        if ($secretPlaintext === '' && $existingConfig !== null) {
            $storedCiphertext = (string) $existingConfig['secret_ciphertext'];
            if ($storedCiphertext !== '') {
                $decrypted = SecretStore::decrypt($storedCiphertext);
                if (!is_string($decrypted)) {
                    self::redirect('secret_unavailable');
                }
                $secretPlaintext = $decrypted;
            }
        }
        if ($configuration['card_enabled'] && ($secretPlaintext === '' || !SecretStore::available())) {
            self::redirect('secret_required');
        }
        if ($secretPlaintext !== '' && !SecretStore::available()) {
            self::redirect('secret_unavailable');
        }

        try {
            $configuration['secret_plaintext'] = $secretPlaintext;
            $ciphertext = $secretPlaintext === ''
                ? (string) ($existingConfig['secret_ciphertext'] ?? '')
                : SecretStore::encrypt($secretPlaintext);
            $savedId = SellerRepository::save($sellerId, $identity, $configuration, $ciphertext, get_current_user_id());
            self::redirect('saved', $savedId);
        } catch (\Throwable $error) {
            error_log('WFPMS seller save failed: ' . sanitize_text_field($error->getMessage()));
            self::redirect('save_failed');
        }
    }

    public static function handle_archive_seller(): void
    {
        $sellerId = isset($_POST['seller_id']) ? absint($_POST['seller_id']) : 0;
        self::authorize_post('wfpms_archive_seller_' . $sellerId);
        if ($sellerId < 1 || !SellerRepository::archive($sellerId)) {
            self::redirect('archive_failed');
        }
        self::redirect('archived', $sellerId);
    }

    public static function handle_activate_seller(): void
    {
        $sellerId = isset($_POST['seller_id']) ? absint($_POST['seller_id']) : 0;
        self::authorize_post('wfpms_activate_seller_' . $sellerId);
        if ($sellerId < 1 || !SellerRepository::activate($sellerId)) {
            self::redirect('activate_failed');
        }
        self::redirect('activated', $sellerId);
    }

    public static function handle_save_category_rules(): void
    {
        self::authorize_post('wfpms_save_category_rules');
        $rawRules = isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : [];
        $rules = [];
        $existingRules = SellerRepository::category_rules();
        foreach ($rawRules as $categoryRaw => $sellerRaw) {
            if (!ctype_digit((string) $categoryRaw)) self::redirect('invalid_category');
            $categoryId = (int) $categoryRaw;
            if ($sellerRaw === '') continue;
            if (!is_string($sellerRaw) || !ctype_digit($sellerRaw)) self::redirect('invalid_seller');
            $sellerId = (int) $sellerRaw;
            $term = get_term($categoryId, 'product_cat');
            $seller = SellerRepository::find($sellerId);
            $unchangedArchivedRule = (int) ($existingRules[$categoryId] ?? 0) === $sellerId;
            if (!$term instanceof \WP_Term
                || (!$unchangedArchivedRule && ($seller === null || (int) $seller['active'] !== 1))) {
                self::redirect('invalid_rule');
            }
            $rules[$categoryId] = $sellerId;
        }
        try {
            SellerRepository::replace_category_rules($rules);
            self::redirect('rules_saved');
        } catch (\Throwable $error) {
            error_log('WFPMS category rule save failed: ' . sanitize_text_field($error->getMessage()));
            self::redirect('rules_failed');
        }
    }

    private static function valid_iban(string $iban): bool
    {
        if (preg_match('/\A[A-Z0-9]{15,34}\z/', $iban) !== 1) return false;
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }

    private static function normalize_iban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private static function normalize_domain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || str_contains($domain, '://') || str_contains($domain, '/') || str_contains($domain, ':')) {
            return '';
        }
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $domain : '';
    }

    private static function posted_text(string $key, int $maxLength): string
    {
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        return function_exists('mb_substr') ? mb_substr(trim($value), 0, $maxLength) : substr(trim($value), 0, $maxLength);
    }

    private static function authorize_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage seller profiles.', 'wayforpay-multi-seller'), 403);
        }
    }

    private static function authorize_post(string $nonceAction): void
    {
        self::authorize_page();
        check_admin_referer($nonceAction);
    }

    private static function redirect(string $notice, int $sellerId = 0): never
    {
        $url = add_query_arg(['page' => 'wfpms-sellers', 'wfpms_notice' => $notice], admin_url('admin.php'));
        if ($sellerId > 0) $url = add_query_arg('seller_id', $sellerId, $url);
        wp_safe_redirect($url);
        exit;
    }

    private static function render_notice(string $notice): void
    {
        $messages = [
            'saved' => __('Профіль збережено.', 'wayforpay-multi-seller'),
            'archived' => __('Профіль архівовано. Історичні дані залишено.', 'wayforpay-multi-seller'),
            'activated' => __('Профіль відновлено.', 'wayforpay-multi-seller'),
            'rules_saved' => __('Правила категорій збережено.', 'wayforpay-multi-seller'),
            'required_fields' => __('Заповніть назву, юридичного отримувача, податковий код і назву отримувача.', 'wayforpay-multi-seller'),
            'payment_method_required' => __('Увімкніть карткову оплату або переказ на IBAN.', 'wayforpay-multi-seller'),
            'invalid_purpose' => __('Шаблон призначення має містити лише підтриману підстановку {order_number}.', 'wayforpay-multi-seller'),
            'invalid_iban' => __('IBAN не пройшов перевірку формату й контрольної суми.', 'wayforpay-multi-seller'),
            'missing_bank' => __('Для IBAN вкажіть банк.', 'wayforpay-multi-seller'),
            'incomplete_card' => __('Для картки потрібні merchantAccount і домен мерчанта.', 'wayforpay-multi-seller'),
            'secret_required' => __('Карткова оплата потребує secret key і захищеного WFPMS_MASTER_KEY.', 'wayforpay-multi-seller'),
            'secret_unavailable' => __('Не вдалося розшифрувати наявний ключ або захищене сховище не налаштовано.', 'wayforpay-multi-seller'),
            'save_failed' => __('Не вдалося зберегти профіль. Перевірте обов’язкові поля та журнали сервера.', 'wayforpay-multi-seller'),
            'archive_failed' => __('Не вдалося архівувати продавця.', 'wayforpay-multi-seller'),
            'activate_failed' => __('Не вдалося відновити продавця.', 'wayforpay-multi-seller'),
            'seller_missing' => __('Продавця не знайдено.', 'wayforpay-multi-seller'),
            'invalid_category' => __('Отримано некоректний ідентифікатор категорії.', 'wayforpay-multi-seller'),
            'invalid_seller' => __('Отримано некоректний ідентифікатор продавця.', 'wayforpay-multi-seller'),
            'invalid_rule' => __('Правило посилається на невідому або архівовану категорію/продавця.', 'wayforpay-multi-seller'),
            'rules_failed' => __('Не вдалося зберегти правила категорій.', 'wayforpay-multi-seller'),
        ];
        if (isset($messages[$notice])) {
            $class = in_array($notice, ['saved', 'archived', 'activated', 'rules_saved'], true) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
    }
}
