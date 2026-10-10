#!/usr/bin/env python3
"""Open real order-pay URLs with saved order keys; never submit payment."""

import json
import os
import urllib.parse
import urllib.request
import http.cookiejar


FIXTURE_PATH = os.environ["WFPMS_HTTP_ORDER_PAY_PATHS_PATH"]
with open(FIXTURE_PATH, encoding="utf-8") as fixture_file:
    fixtures = json.load(fixture_file)

base_url = os.environ.get("WFPMS_BASE_URL", "http://127.0.0.1:8099").rstrip("/")
client = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
)
login_page = client.open(base_url + "/wp-login.php", timeout=20).read().decode("utf-8", "replace")
if 'name="log"' not in login_page or 'name="pwd"' not in login_page:
    raise RuntimeError("WordPress login page did not expose the expected form.")
login_data = urllib.parse.urlencode(
    {
        "log": os.environ["WFPMS_ADMIN_USER"],
        "pwd": os.environ["WFPMS_ADMIN_PASSWORD"],
        "wp-submit": "Log In",
        "redirect_to": base_url + "/wp-admin/",
        "testcookie": "1",
    }
).encode()
login_request = urllib.request.Request(
    base_url + "/wp-login.php",
    data=login_data,
    headers={"Content-Type": "application/x-www-form-urlencoded"},
)
login_response = client.open(login_request, timeout=20)
if "wp-login.php" in login_response.geturl():
    raise RuntimeError("Could not authenticate the disposable WordPress admin user for order-pay.")

for checkout_kind in ("classic_order_id", "blocks_order_id"):
    fixture = fixtures.get(checkout_kind, {})
    order_id = int(fixture.get("order_id", 0))
    pay_url = fixture.get("url", "")
    if order_id < 1 or not pay_url:
        raise RuntimeError(f"Missing order-pay URL fixture for {checkout_kind}.")
    response = client.open(pay_url, timeout=20)
    body = response.read().decode("utf-8", "replace")
    if response.status != 200:
        raise RuntimeError(f"Order-pay GET for {order_id} returned HTTP {response.status}.")
    if "Integration Seller 1" not in body:
        markers = ["wfpms-checkout-seller", "payment_method_wfpms_iban", "order_review", "Bank transfer", "woocommerce-pay"]
        excerpts = {}
        for marker in markers:
            start = body.lower().find(marker.lower())
            excerpts[marker] = body[max(0, start - 200):start + 500] if start >= 0 else "<missing>"
        raise RuntimeError(
            f"Order-pay page for {order_id} does not show the frozen legal recipient; "
            f"final URL={response.geturl()}; markers={excerpts!r}"
        )
    if "payment_method_wfpms_iban" not in body:
        raise RuntimeError(f"Order-pay page for {order_id} does not show the saved IBAN method.")
    if "payment_method_wfpms_card" in body:
        raise RuntimeError(f"Order-pay page for {order_id} used the changed card-only current configuration.")

print("PASS: authenticated real Classic and Blocks order-pay URLs render frozen seller and IBAN method without submitting payment")
