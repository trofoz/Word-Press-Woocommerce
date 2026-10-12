#!/usr/bin/env python3
"""Exercise the temporary pilot through native authenticated forms and checkout HTTP."""
import datetime
import html
import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('WFPMS_BASE_URL', 'http://127.0.0.1:8098').rstrip('/')
with open(os.environ['WFPMS_PILOT_FIXTURE_PATH']) as handle:
    FIXTURE = json.load(handle)
PHASE = os.environ['WFPMS_PILOT_HTTP_PHASE']
PAGE = '/?page_id=' + str(FIXTURE['classic_page'])
SETTINGS = '/wp-admin/admin.php?page=ilovesad-wfpms-pilot'
ADDRESS = {'first_name': 'Pilot', 'last_name': 'Buyer', 'company': '', 'address_1': '1 Test Street',
           'address_2': '', 'city': 'Kyiv', 'state': 'Kyiv', 'postcode': '01001', 'country': 'UA',
           'email': 'pilot-buyer@example.test', 'phone': '+380000000000'}


def client(login=None):
    class RedirectTrace(urllib.request.HTTPRedirectHandler):
        def __init__(self):
            super().__init__()
            self.history = []

        def redirect_request(self, request, response, code, message, headers, new_url):
            self.history.append({'status': code, 'from': request.full_url, 'to': new_url})
            return super().redirect_request(request, response, code, message, headers, new_url)

    trace = RedirectTrace()
    cookie_jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookie_jar), trace)
    opener._wfpms_redirect_trace = trace
    opener._wfpms_cookie_jar = cookie_jar
    if login:
        call(opener, '/wp-login.php')
        password_env = {
            'test-admin': 'WFPMS_PILOT_ADMIN_PASSWORD',
            'pilot-buyer': 'WFPMS_PILOT_BUYER_PASSWORD',
            'pilot-other': 'WFPMS_PILOT_OTHER_PASSWORD',
        }[login]
        password = os.environ[password_env]
        status, _, body, url = call(opener, '/wp-login.php', {'log': login, 'pwd': password,
            'redirect_to': BASE + '/', 'testcookie': '1', 'wp-submit': 'Log In'})
        assert status == 200 and 'wp-login.php' not in url, 'Disposable account login failed'
        if login == 'test-admin':
            # Consume WooCommerce's first-login onboarding redirect before opening native forms.
            call(opener, '/wp-admin/')
    return opener


def call(opener, path, data=None, headers=None, as_json=False):
    payload = None
    if data is not None:
        payload = json.dumps(data).encode() if as_json else urllib.parse.urlencode(data).encode()
    headers = dict(headers or {})
    if payload is not None:
        headers['Content-Type'] = 'application/json' if as_json else 'application/x-www-form-urlencoded'
    request = urllib.request.Request(BASE + path, data=payload, headers=headers)
    trace = getattr(opener, '_wfpms_redirect_trace', None)
    if trace is not None:
        trace.history = []
    try:
        response = opener.open(request, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode('utf-8', 'replace'), response.geturl()


def nonce(opener, page, action):
    status, _, html, final_url = call(opener, page)
    assert status == 200, f'Admin page returned HTTP {status}'
    for form in re.findall(r'<form\b.*?</form>', html, re.S):
        if re.search(r'name=["\']action["\'][^>]*value=["\']' + re.escape(action) + r'["\']', form):
            found = re.search(r'name=["\']_wpnonce["\'][^>]*value=["\']([^"\']+)', form)
            if found:
                return found.group(1)
    actions = re.findall(r'name=["\']action["\'][^>]*value=["\']([^"\']+)', html)
    raise AssertionError('Native action form/nonce missing: ' + action + '; final_url=' + final_url + '; actions=' + repr(actions))


def post_action(opener, page, action, fields):
    fields = {**fields, 'action': action, '_wpnonce': nonce(opener, page, action)}
    status, _, body, _ = call(opener, '/wp-admin/admin-post.php', fields)
    assert status == 200, f'Native action {action} returned HTTP {status}: {body[:200]}'


def save_policy(opener, ids):
    post_action(opener, SETTINGS, 'ilovesad_wfpms_save_pilot',
                {'buyer_id': FIXTURE['buyer_id'], 'product_ids': ids, 'enabled': '1'})


def checkout(opener, product_id, method, api=False):
    if api:
        status, _, page, _ = call(opener, PAGE)
        found = re.search(r'var wpApiSettings\s*=\s*(\{[^;]+\});', page)
        assert status == 200 and found, 'Native WordPress REST nonce not rendered in disposable test'
        wp_nonce = json.loads(found.group(1))['nonce']
        route = '/?rest_route=/wc/store/v1/'
        status, headers, body, _ = call(opener, route + 'cart', headers={'X-WP-Nonce': wp_nonce})
        assert status == 200, 'Store API cart load failed'
        api_headers = {'X-WP-Nonce': wp_nonce, 'Nonce': headers.get('Nonce', '')}
        status, headers, body, _ = call(opener, route + 'cart/add-item', {'id': product_id, 'quantity': 1}, api_headers, True)
        assert status in [200, 201], f'Store API add failed: {body[:300]}'
        api_headers['Nonce'] = headers.get('Nonce', api_headers['Nonce'])
        status, _, body, _ = call(opener, route + 'checkout',
            {'billing_address': ADDRESS, 'shipping_address': {k: v for k, v in ADDRESS.items() if k not in ['email', 'phone']},
             'payment_method': method, 'payment_data': [], 'customer_note': '', 'create_account': False}, api_headers, True)
        result = json.loads(body)
        assert status == 200 and result.get('order_id'), f'Store API checkout failed: {body[:450]}'
        return int(result['order_id'])
    status, _, _, add_url = call(opener, '/?add-to-cart=' + str(product_id))
    add_redirects = list(getattr(getattr(opener, '_wfpms_redirect_trace', None), 'history', []))
    cookies = [cookie.name for cookie in getattr(opener, '_wfpms_cookie_jar', [])]
    assert status == 200
    status, _, page, final_url = call(opener, PAGE)
    found = re.search(r'name=["\']woocommerce-process-checkout-nonce["\'][^>]*value=["\']([^"\']+)', page)
    if status != 200 or not found:
        title = re.search(r'<title>(.*?)</title>', page, re.S)
        errors = re.findall(r'class=["\'][^"\']*woocommerce-error[^"\']*["\'][^>]*>(.*?)</', page, re.S)
        form_classes = re.findall(r'<form\b[^>]*class=["\']([^"\']+)', page, re.I)
        without_assets = re.sub(r'<(script|style)\b[^>]*>.*?</\1>', ' ', page, flags=re.I | re.S)
        visible_text = ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', without_assets)).split())
        api_settings = re.search(r'var wpApiSettings\s*=\s*(\{[^;]+\});', page)
        store_cart = None
        if api_settings:
            wp_nonce = json.loads(api_settings.group(1))['nonce']
            cart_status, _, cart_body, _ = call(opener, '/?rest_route=/wc/store/v1/cart',
                headers={'X-WP-Nonce': wp_nonce})
            if cart_status == 200:
                cart = json.loads(cart_body)
                store_cart = {'status': cart_status, 'items': [item.get('id') for item in cart.get('items', [])],
                    'item_count': cart.get('items_count')}
            else:
                store_cart = {'status': cart_status}
        summary = {
            'http_status': status,
            'final_url': final_url,
            'redirects': getattr(getattr(opener, '_wfpms_redirect_trace', None), 'history', []),
            'add_to_cart_final_url': add_url,
            'add_to_cart_redirects': add_redirects,
            'cookie_names': cookies,
            'store_api_cart': store_cart,
            'title': title.group(1) if title else 'no-title',
            'visible_text': visible_text[:500],
            'page_kind': {
                'checkout_form': any('checkout' in value.lower() for value in form_classes),
                'cart_form': any('cart' in value.lower() for value in form_classes),
                'empty_cart': 'cart-empty' in page.lower() or 'cart is currently empty' in page.lower(),
                'login_form': 'wp-login.php' in page or 'woocommerce-form-login' in page,
                'denial_marker': status in [401, 403] or 'access denied' in page.lower() or 'forbidden' in page.lower(),
                'pilot_error_marker': 'ilovesad_wfpms_pilot_denied' in page,
            },
            'form_classes': form_classes,
            'checkout_form_count': len(re.findall(r'<form\b[^>]*class=["\'][^"\']*checkout', page, re.I)),
            'checkout_nonce_present': found is not None,
            'guest_checkout_allowed_by_fixture': FIXTURE.get('guest_checkout_allowed'),
            'configured_checkout_page_id': FIXTURE.get('checkout_page_id'),
            'requested_classic_page_id': FIXTURE.get('classic_page'),
            'fixture_has_classic_shortcode': '[woocommerce_checkout]' in FIXTURE.get('classic_page_content', ''),
            'woocommerce_shortcode_wrapper_present': 'class="woocommerce"' in page,
            'normal_product_name_present': 'ordinary unmapped store product' in page.lower(),
            'woocommerce_error_count': len(errors),
            'woocommerce_error_text': re.sub('<[^>]+>', ' ', errors[0]).strip()[:180] if errors else '',
            'checkout_marker': 'woocommerce-checkout' in page,
        }
        raise AssertionError('Classic checkout response diagnostic: ' + json.dumps(summary, ensure_ascii=False))
    fields = {'billing_' + k: v for k, v in ADDRESS.items()}
    fields.update({'payment_method': method, 'woocommerce-process-checkout-nonce': found.group(1),
                   '_wp_http_referer': PAGE, 'terms': '1', 'terms-field': '1'})
    status, _, body, _ = call(opener, '/?wc-ajax=checkout', fields)
    result = json.loads(body)
    assert status == 200 and result.get('result') == 'success', f'Classic checkout failed: {body[:450]}'
    redirect = urllib.parse.urlparse(result['redirect'])
    found_id = re.search(r'/order-received/(\d+)', redirect.path)
    return int(found_id.group(1) if found_id else urllib.parse.parse_qs(redirect.query)['order-received'][0])


def assert_native_guest_login_requirement(opener, product_id, api=False):
    if api:
        status, _, page, _ = call(opener, PAGE)
        found = re.search(r'var wpApiSettings\s*=\s*(\{[^;]+\});', page)
        assert status == 200 and found, 'Native WordPress REST nonce not rendered for guest test'
        wp_nonce = json.loads(found.group(1))['nonce']
        route = '/?rest_route=/wc/store/v1/'
        status, headers, _, _ = call(opener, route + 'cart', headers={'X-WP-Nonce': wp_nonce})
        assert status == 200, 'Store API guest cart load failed'
        api_headers = {'X-WP-Nonce': wp_nonce, 'Nonce': headers.get('Nonce', '')}
        status, headers, body, _ = call(opener, route + 'cart/add-item',
            {'id': product_id, 'quantity': 1}, api_headers, True)
        assert status in [200, 201], f'Store API guest add-to-cart failed: {body[:180]}'
        api_headers['Nonce'] = headers.get('Nonce', api_headers['Nonce'])
        status, _, body, _ = call(opener, route + 'checkout',
            {'billing_address': ADDRESS, 'shipping_address': {k: v for k, v in ADDRESS.items() if k not in ['email', 'phone']},
             'payment_method': 'bacs', 'payment_data': [], 'customer_note': '', 'create_account': False}, api_headers, True)
        result = json.loads(body)
        assert status == 403 and result.get('code') == 'woocommerce_rest_guest_checkout_disabled', \
            f'Store API did not preserve WooCommerce guest-login policy: status={status} code={result.get("code")}'
        assert result.get('code') != 'ilovesad_wfpms_pilot_denied', 'Pilot module blocked an ordinary guest checkout.'
        return {'transport': 'store-api', 'status': status, 'code': result['code']}

    add_status, _, _, add_url = call(opener, '/?add-to-cart=' + str(product_id))
    assert add_status == 200
    status, _, page, final_url = call(opener, PAGE)
    visible = html.unescape(urllib.parse.unquote(re.sub(r'<[^>]+>', ' ', page))).lower()
    native_message = 'you must be logged in to checkout.' in visible
    assert status == 200 and native_message and 'ilovesad_wfpms_pilot_denied' not in page, \
        f'Classic checkout did not preserve WooCommerce guest-login policy: status={status} final_url={final_url}'
    assert 'woocommerce-process-checkout-nonce' not in page, 'Guest checkout form appeared despite login-required store policy.'
    return {'transport': 'classic', 'status': status, 'add_to_cart_url': add_url,
            'checkout_url': final_url, 'native_login_message': native_message}


if PHASE == 'setup-empty':
    admin = client('test-admin')
    post_action(admin, '/wp-admin/admin.php?page=wfpms-legacy-migration', 'wfpms_enable_standalone_mode', {'confirm_standalone': '1'})
    token = nonce(admin, SETTINGS, 'ilovesad_wfpms_save_pilot')
    fields = {'action': 'ilovesad_wfpms_save_pilot', 'buyer_id': FIXTURE['buyer_id'], 'product_ids': '', 'enabled': '1'}
    status, _, _, _ = call(admin, '/wp-admin/admin-post.php', {**fields, '_wpnonce': 'invalid'})
    assert status == 403, 'Invalid nonce was accepted'
    status, _, _, _ = call(client('pilot-other'), '/wp-admin/admin-post.php', {**fields, '_wpnonce': token})
    assert status == 403, 'Unauthorized settings write was accepted'
    save_policy(admin, '')
    print('PASS: native standalone activation and empty pilot settings; invalid nonce and unauthorized admin writes rejected.')
elif PHASE == 'configure':
    admin = client('test-admin')
    save_policy(admin, f"{FIXTURE['products']['book']},{FIXTURE['products']['webinar']}")
    print('PASS: native admin form enables the local buyer ID and exactly two local control product IDs.')
elif PHASE == 'checkout':
    results = {'ordinary': [], 'ordinary_guest_login': [], 'control': []}
    for login in [None, 'pilot-other', 'pilot-buyer']:
        for api in [False, True]:
            opener = client(login)
            if login is None and not FIXTURE.get('guest_checkout_allowed', False):
                results['ordinary_guest_login'].append(
                    assert_native_guest_login_requirement(opener, FIXTURE['products']['normal'], api))
            else:
                results['ordinary'].append(checkout(opener, FIXTURE['products']['normal'], 'bacs', api))
    for api in [False, True]:
        results['control'].append(checkout(client('pilot-buyer'), FIXTURE['products']['webinar'], 'wfpms_iban', api))
    for login in [None, 'pilot-other']:
        opener = client(login)
        status, _, page, _ = call(opener, PAGE)
        found = re.search(r'var wpApiSettings\s*=\s*(\{[^;]+\});', page)
        assert status == 200 and found
        token = json.loads(found.group(1))['nonce']
        status, _, body, _ = call(opener, '/?rest_route=/wc/store/v1/checkout',
            {'payment_method': 'wfpms_card', 'billing_address': ADDRESS, 'customer_id': FIXTURE['buyer_id']},
            {'X-WP-Nonce': token}, True)
        assert status == 403 and json.loads(body)['code'] == 'ilovesad_wfpms_pilot_denied', 'Forged Store API buyer accepted'
    with open(os.environ['WFPMS_PILOT_HTTP_ORDERS_PATH'], 'w') as handle:
        json.dump(results, handle)
    print('PASS: real Classic/Store API ordinary checkout for guest/other/pilot, authorized control checkout, forged Store API denial.')
elif PHASE == 'close':
    admin = client('test-admin')
    post_action(admin, SETTINGS, 'ilovesad_wfpms_close_pilot', {})
    with open(os.environ['WFPMS_PILOT_ISSUED_PATH']) as handle:
        issued = json.load(handle)
    for order in issued:
        if order['method'] != 'wfpms_iban':
            continue
        fields = {'order_id': order['order_id'], 'operation_id': 'pilot-bank-' + str(order['order_id']),
                  'recipient_account': 'UA213223130000026007233566001', 'amount': '100.00', 'currency': 'UAH',
                  'occurred_at': (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=1)).strftime('%Y-%m-%dT%H:%M')}
        for _ in range(2):
            post_action(admin, '/wp-admin/admin.php?page=wfpms-payments', 'wfpms_confirm_bank_receipt', fields)
    print('PASS: native pilot close preserves original callbacks; native IBAN confirmation and duplicate remain available after close.')
else:
    raise AssertionError('Unknown HTTP pilot phase')
