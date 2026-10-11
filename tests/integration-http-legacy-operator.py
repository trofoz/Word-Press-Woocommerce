#!/usr/bin/env python3
"""Real admin forms and HTTP callbacks in the disposable WordPress runner only."""
import hashlib
import hmac
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('WFPMS_BASE_URL', 'http://127.0.0.1:8099').rstrip('/')
WP = os.environ['WP_PATH']
HELPER = str(Path(__file__).with_name('integration-legacy-operator-fixture.php'))
PAGE = '/wp-admin/admin.php?page=wfpms-legacy-migration'
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def inspect(mode='state', order_id=0):
    env = dict(os.environ, WFPMS_LEGACY_MODE=mode, WFPMS_LEGACY_ORDER_ID=str(order_id))
    out = subprocess.check_output(['wp', 'eval-file', HELPER, '--path=' + WP], env=env, text=True)
    return json.loads(out)

def request(path, data=None, opener=client):
    req = urllib.request.Request(BASE + path, data=data)
    if data is not None:
        req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    try:
        response = opener.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8', 'replace'), error.geturl()
    return response.status, response.read().decode('utf-8', 'replace'), response.geturl()

def form(action, order_id=None, page=PAGE):
    status, html, _ = request(page)
    assert status == 200, (status, page)
    for block in re.findall(r'<form\b.*?</form>', html, re.S):
        if f'value="{action}"' not in block:
            continue
        if order_id and f'name="order_id" value="{order_id}"' not in block:
            continue
        nonce = re.search(r'name="_wpnonce" value="([^"]+)"', block)
        assert nonce, 'Missing nonce: ' + action
        return {'action': action, '_wpnonce': nonce.group(1)}
    raise AssertionError('Supported admin form missing: ' + action)

def post(action, fields, notice, order_id=None, page=PAGE):
    data = form(action, order_id, page)
    data.update(fields)
    status, body, url = request('/wp-admin/admin-post.php', urllib.parse.urlencode(data).encode())
    assert status == 200 and notice in url, (action, status, url)
    return body

def payload(reference, merchant='synthetic-operator-history', secret='synthetic-operator-history-secret', transaction_status='Approved', auth_code='SYNTHETIC-AUTH'):
    values = [merchant, str(reference), '10.00', 'UAH', auth_code, '411111******1111', transaction_status, '1100']
    result = dict(zip(['merchantAccount', 'orderReference', 'amount', 'currency', 'authCode', 'cardPan', 'transactionStatus', 'reasonCode'], values))
    result['merchantSignature'] = hmac.new(secret.encode(), ';'.join(values).encode(), hashlib.md5).hexdigest()
    return result

def callback(data, route='WC_wayforpay'):
    req = urllib.request.Request(BASE + '/?wc-api=' + route, data=json.dumps(data).encode(), headers={'Content-Type': 'application/json'})
    try:
        response = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8', 'replace')
    return response.status, response.read().decode('utf-8', 'replace')

def plugin(action):
    subprocess.check_call(['wp', 'plugin', action, 'wayforpay-upstream', '--path=' + WP], stdout=subprocess.DEVNULL)

def assert_review(label, before=None):
    state = inspect()[label + '_state']
    assert len(state['receipts']) == 1 and state['receipts'][0]['classification'] == 'needs_review', state
    assert state['purchase']['state'] == 'review' and state['status'] == 'pending', state
    assert int(state['mapping']['config_id']) == int(inspect()['import']['config_id']), state
    if before is not None:
        assert state == before, 'Duplicate callback changed durable evidence'
    assert inspect('run', state['purchase']['order_id']) == {'payment_complete': 0, 'completed': 0}
    return state

fixture = inspect('seed')
request('/wp-login.php')
status, _, url = request('/wp-login.php', urllib.parse.urlencode({
    'log': os.environ['WFPMS_ADMIN_USER'], 'pwd': os.environ['WFPMS_ADMIN_PASSWORD'],
    'redirect_to': BASE + '/wp-admin/', 'testcookie': '1',
}).encode())
assert status == 200 and 'wp-login.php' not in url, 'Disposable admin login failed'
post('wfpms_enable_standalone_mode', {'confirm_standalone': 1}, 'standalone_enabled')
state = inspect()
assert state['standalone'] and not state['owner'] and state['upstream_registered']
issued_before_switch = inspect('new_payment')
assert issued_before_switch['result'] == 'success'
issued_evidence = inspect('order', issued_before_switch['order_id'])

if os.environ.get('WFPMS_LEGACY_BASELINE') == '1':
    failures = []
    for action in ['wfpms_import_legacy_merchant', 'wfpms_claim_legacy_callback']:
        try:
            form(action)
        except AssertionError as error:
            failures.append(str(error))
    if not state['release_registered']:
        failures.append('Supported release callback action is not registered')
    print('BASELINE runtime gaps: ' + '; '.join(failures), flush=True)
    assert not failures, 'Legacy operator cycle is unavailable on accepted code'

post('wfpms_import_legacy_merchant', {'seller_id': fixture['seller_id'], 'merchant_domain': 'example.test', 'confirm_recipient': 1}, 'imported')
state = inspect()
assert state['import']['seller_id'] == fixture['seller_id'] and not state['owner']
post('wfpms_claim_legacy_callback', {'confirm_callback_owner': 1}, 'callback_owned')
assert inspect()['owner']
post('wfpms_enable_legacy_transition', {'confirm_transition': 1}, 'transition_orders_unmigrated')
assert not inspect()['transition']
for label in ['numeric', 'suffixed']:
    post('wfpms_migrate_legacy_order', {'order_id': fixture[label], 'seller_id': fixture['seller_id']}, 'order_migrated', fixture[label])

# Active upstream owns the unmapped order, even while fork owns the route for mapped orders.
code, _ = callback(payload(str(fixture['unmapped']) + '_woo_w4p_7'))
assert code == 200 and inspect()['unmapped_state']['status'] == 'completed'
assert inspect()['ready']
post('wfpms_enable_legacy_transition', {'confirm_transition': 1}, 'transition_enabled')
assert inspect()['transition'] and inspect()['standalone']
print('PASS: supported import, claim, order mapping, readiness gate and switch via authenticated HTTP forms', flush=True)
inspect('rotate_current')

plugin('deactivate')
try:
    assert not inspect()['upstream_registered']
    # Valid nonce cannot release ownership to an inactive original handler.
    post('wfpms_release_legacy_callback', {'confirm_release': 1}, 'callback_unavailable')
    assert inspect()['owner']
    inspect('missing_key')
    code, _ = callback(payload(fixture['numeric']))
    assert code == 400 and not inspect()['numeric_state']['receipts']
    inspect('restore_key')
    print('PASS: missing pinned historical key returns HTTP 400; configured current original key is not substituted', flush=True)
    for label in ['numeric', 'suffixed']:
        ref = str(fixture[label]) + ('_woo_w4p_7' if label == 'suffixed' else '')
        data = payload(ref)
        code, body = callback(data)
        ack = json.loads(body)
        assert code == 200 and ack['orderReference'] == ref and ack['status'] == 'accept', (code, body)
        expected = hmac.new(b'synthetic-operator-history-secret', f"{ref};accept;{ack['time']}".encode(), hashlib.md5).hexdigest()
        assert ack['signature'] == expected
        before = assert_review(label)
        assert callback(data)[0] == 200
        assert_review(label, before)
        print('PASS: original inactive; ' + label + ' pinned callback ACK verified; duplicate receipt/event/completion counts unchanged', flush=True)
    code, body = callback(payload(str(fixture['unmapped']) + '_woo_w4p_99'))
    assert code == 503 and inspect()['unmapped_state']['purchase'] is None
    assert body == '', ('Unknown reference was unexpectedly acknowledged', body)
    assert callback(payload('unsupported-synthetic-reference'))[0] == 400
    # Disposable runner uses WooCommerce's default file backend, also readable in Status > Logs.
    log_dir = subprocess.check_output(['wp', 'eval', 'echo WC_LOG_DIR;', '--path=' + WP], text=True)
    log_output = ''.join(path.read_text() for path in Path(log_dir).rglob('*wfpms-legacy*.log'))
    assert 'unmapped_reference_no_upstream' in log_output and 'historical_config_unavailable' in log_output, 'Operator diagnostic missing'
    assert 'synthetic-operator-history-secret' not in log_output and 'synthetic-operator-rotated-secret' not in log_output
    print('PASS: unknown reference HTTP 503 with empty body, unsupported format HTTP 400; no accepted payment; WooCommerce operator diagnostics present', flush=True)
finally:
    plugin('activate')

# Valid admin nonce is still insufficient to return unfinished mapped orders to unsafe upstream completion.
post('wfpms_release_legacy_callback', {'confirm_release': 1}, 'callback_release_blocked')
assert inspect()['owner'] and inspect()['transition']
print('PASS: ownership return is blocked while mapped financial review/execution is unfinished', flush=True)
for label in ['numeric', 'suffixed']:
    receipt_id = inspect()[label + '_state']['receipts'][0]['id']
    post('wfpms_review_receipt', {'receipt_id': receipt_id, 'reason': 'Synthetic legacy evidence reviewed', 'stock_reviewed': 1}, 'receipt_reviewed', page='/wp-admin/admin.php?page=wfpms-payments')
    assert inspect('run', fixture[label]) == {'payment_complete': 1, 'completed': 1}

before = inspect()
release = form('wfpms_release_legacy_callback')
bad = dict(release, confirm_release=1, _wpnonce='invalid-test-nonce')
assert request('/wp-admin/admin-post.php', urllib.parse.urlencode(bad).encode())[0] == 403
anonymous = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
assert request('/wp-admin/admin-post.php', urllib.parse.urlencode(dict(release, confirm_release=1)).encode(), anonymous)[0] != 200
assert inspect()['owner']
restricted = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
request('/wp-login.php', opener=restricted)
status, _, url = request('/wp-login.php', urllib.parse.urlencode({
    'log': 'legacy-no-capability', 'pwd': 'synthetic-subscriber-password',
    'redirect_to': BASE + '/wp-admin/', 'testcookie': '1',
}).encode(), restricted)
assert status == 200 and 'wp-login.php' not in url
assert request('/wp-admin/admin-post.php', urllib.parse.urlencode(dict(release, confirm_release=1)).encode(), restricted)[0] == 403
assert inspect()['owner']
post('wfpms_release_legacy_callback', {}, 'callback_release_blocked')
assert inspect()['owner']
post('wfpms_release_legacy_callback', {'confirm_release': 1}, 'callback_released')
after = inspect()
assert not after['owner'] and not after['transition'] and after['standalone'] and after['upstream_registered']
for label in ['numeric', 'suffixed']:
    assert after[label + '_state'] == before[label + '_state'], 'Return changed mapped history'
returned = inspect('original_payment')
assert callback(payload(str(returned['unmapped']) + '_woo_w4p_100'))[0] == 200
assert inspect()['unmapped_state']['status'] == 'completed' and inspect()['unmapped_state']['purchase'] is None
print('PASS: supported ownership return preserves mapped financial records and restores original handler; nonce and login required', flush=True)
assert inspect('order', issued_before_switch['order_id']) == issued_evidence, 'Return altered an already-issued new fork payment'
issued_data = payload(issued_before_switch['attempt']['external_reference'], 'synthetic-operator-new', 'synthetic-operator-new-secret')
assert callback(issued_data, 'wfpms_wayforpay')[0] == 200
assert inspect('run', issued_before_switch['order_id']) == {'payment_complete': 1, 'completed': 1}
print('PASS: new fork attempt issued before migration retains its evidence and completes after ownership return', flush=True)

new = inspect('new_payment')
assert new['result'] == 'success' and new['attempt']['merchant_account'] == 'synthetic-operator-new'
new_data = payload(new['attempt']['external_reference'], 'synthetic-operator-new', 'synthetic-operator-new-secret')
assert callback(new_data, 'wfpms_wayforpay')[0] == 200
assert inspect('run', new['order_id']) == {'payment_complete': 1, 'completed': 1}
assert callback(new_data, 'wfpms_wayforpay')[0] == 200
assert inspect('run', new['order_id']) == {'payment_complete': 0, 'completed': 0}
print('PASS: after ownership return, new standalone card payment uses its own merchant/route and completes once; duplicate completion zero', flush=True)

# Reproduce terminal legacy events on actual mapped attempts, without SQL-seeded receipts/events.
post('wfpms_claim_legacy_callback', {'confirm_callback_owner': 1}, 'callback_owned')
legacy_terminal_failures = []
for terminal_status in ['Refunded', 'Voided']:
    for scope in ['accepted', 'extra']:
        new_fixture = inspect('original_payment')
        order_id = new_fixture['unmapped']
        post('wfpms_migrate_legacy_order', {'order_id': order_id, 'seller_id': fixture['seller_id']}, 'order_migrated', order_id)
        accepted_ref = str(order_id) + '_woo_w4p_11'
        assert callback(payload(accepted_ref))[0] == 200
        before = inspect('order', order_id)
        assert len(before['receipts']) == 1 and before['receipts'][0]['classification'] == 'needs_review'
        accepted_receipt_id = before['receipts'][0]['id']
        post('wfpms_review_receipt', {'receipt_id': accepted_receipt_id, 'reason': 'Synthetic terminal-event regression', 'stock_reviewed': 1}, 'receipt_reviewed', page='/wp-admin/admin.php?page=wfpms-payments')
        accepted = inspect('order', order_id)
        assert accepted['financially_accepted'] and accepted['purchase']['execution_state'] == 'pending'
        assert accepted['status'] == 'pending'
        terminal_ref = accepted_ref
        if scope == 'extra':
            terminal_ref = str(order_id) + '_woo_w4p_12'
            assert callback(payload(terminal_ref, auth_code='SYNTHETIC-EXTRA-AUTH'))[0] == 200
        plugin('deactivate')
        try:
            assert callback(payload(terminal_ref, transaction_status=terminal_status))[0] == 200
            terminal = inspect('order', order_id)
            assert any(event['kind'] == 'legacy_' + terminal_status for event in terminal['events'])
            # Change a signed field so this Approved is new evidence rather than an exact replay.
            delayed = payload(terminal_ref, auth_code='SYNTHETIC-DELAYED-AUTH')
            assert callback(delayed)[0] == 200
            delayed_state = inspect('order', order_id)
            expected_receipts = 1 if scope == 'accepted' else 2
            assert len(delayed_state['receipts']) == expected_receipts
            assert int(delayed_state['purchase']['accepted_receipt_id']) == int(accepted_receipt_id)
            assert delayed_state['purchase']['state'] == 'paid_review'
            assert delayed_state['purchase']['execution_state'] == 'pending'
            terminal_attempt = next(attempt for attempt in delayed_state['attempts'] if attempt['external_reference'] == terminal_ref)
            assert terminal_attempt['status'] == 'approved', 'Regression did not reproduce reapproval of the same historical attempt'
            assert any(event['kind'] == 'legacy_reference_conflict' for event in delayed_state['events'])
        finally:
            plugin('activate')
        # Upstream must be present: otherwise ownership return would be blocked only by inactivity.
        post('wfpms_release_legacy_callback', {'confirm_release': 1}, 'callback_release_blocked')
        assert inspect()['owner'] and inspect()['upstream_registered']
        counts = inspect('run', order_id)
        after_run = inspect('order', order_id)
        expected_counts = {'payment_complete': 0, 'completed': 0} if scope == 'accepted' else {'payment_complete': 1, 'completed': 1}
        expected_status = 'pending' if scope == 'accepted' else 'completed'
        print(f'LEGACY TERMINAL runtime: status={terminal_status}; scope={scope}; financially_accepted={delayed_state["financially_accepted"]}; payment_complete={counts["payment_complete"]}; completed={counts["completed"]}; order={after_run["status"]}; ownership_return=blocked', flush=True)
        if counts != expected_counts or after_run['status'] != expected_status or delayed_state['financially_accepted'] != (scope == 'extra'):
            legacy_terminal_failures.append(f'{terminal_status}/{scope}: expected {expected_counts}/{expected_status}, got {counts}/{after_run["status"]}')
        # Replays of both terminal and delayed evidence must preserve financial rows and not complete again.
        events_before_repeat = after_run['events']
        receipts_before_repeat = after_run['receipts']
        assert callback(payload(terminal_ref, transaction_status=terminal_status))[0] == 200
        assert callback(delayed)[0] == 200
        repeated = inspect('order', order_id)
        assert repeated['events'] == events_before_repeat and repeated['receipts'] == receipts_before_repeat
        assert inspect('run', order_id) == {'payment_complete': 0, 'completed': 0}
        post('wfpms_release_legacy_callback', {'confirm_release': 1}, 'callback_release_blocked')
        assert inspect()['owner']
assert not legacy_terminal_failures, '\n'.join(legacy_terminal_failures)
print('PASS: legacy Refunded/Voided forbid reapproved accepted attempt execution; extra-attempt terminal events preserve valid acceptance; duplicate financial rows and completions zero; ownership return blocked', flush=True)
