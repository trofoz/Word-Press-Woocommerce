#!/usr/bin/env python3
"""Create Classic and Store API orders through HTTP without initiating a card payment."""

import http.cookiejar
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request


BASE_URL = os.environ.get("WFPMS_BASE_URL", "http://127.0.0.1:8099").rstrip("/")
PRODUCT_ID = int(os.environ["WFPMS_PRODUCT_ID"])
CLASSIC_PATH = os.environ.get("WFPMS_CLASSIC_CHECKOUT_PATH", "/classic-checkout-test/")
OUTPUT_PATH = os.environ["WFPMS_HTTP_ORDER_IDS_PATH"]
ADDRESS = {
    "first_name": "Integration",
    "last_name": "Buyer",
    "company": "",
    "address_1": "1 Test Street",
    "address_2": "",
    "city": "Kyiv",
    "state": "Kyiv",
    "postcode": "01001",
    "country": "UA",
    "email": "integration-checkout@example.test",
    "phone": "+380000000000",
}


def new_client():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )


def call(client, path, method="GET", headers=None, data=None):
    if isinstance(data, dict):
        data = urllib.parse.urlencode(data).encode()
        default_headers = {"Content-Type": "application/x-www-form-urlencoded"}
    elif data is not None:
        data = json.dumps(data).encode()
        default_headers = {"Content-Type": "application/json"}
    else:
        default_headers = {}
    req = urllib.request.Request(
        BASE_URL + path,
        data=data,
        method=method,
        headers={**default_headers, **(headers or {})},
    )
    try:
        response = client.open(req, timeout=30)
        return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def store_api_path(route, query=None):
    params = {"rest_route": "/wc/store/v1/" + route.lstrip("/")}
    if query:
        params.update(query)
    return "/?" + urllib.parse.urlencode(params)


def add_classic_item(client):
    query = urllib.parse.urlencode({"add-to-cart": PRODUCT_ID})
    status, _, body = call(client, "/?" + query)
    if status != 200:
        raise RuntimeError(f"Classic checkout fixture product was not added: HTTP {status}: {body[:500]!r}")


classic = new_client()
add_classic_item(classic)
status, _, page = call(classic, CLASSIC_PATH)
page = page.decode("utf-8", "replace")
if status != 200:
    raise RuntimeError(f"Classic checkout page returned HTTP {status}.")
nonce_match = re.search(
    r'name=["\']woocommerce-process-checkout-nonce["\'][^>]*value=["\']([^"\']+)', page
)
if not nonce_match:
    nonce_match = re.search(
        r'name=["\']woocommerce-process-checkout-nonce["\'][^>]*value=["\']([^"\']+)',
        page.replace("\n", " "),
    )
if not nonce_match:
    raise RuntimeError("Classic checkout page did not render the checkout nonce.")
classic_fields = {
    "billing_first_name": ADDRESS["first_name"],
    "billing_last_name": ADDRESS["last_name"],
    "billing_company": "",
    "billing_address_1": ADDRESS["address_1"],
    "billing_address_2": "",
    "billing_city": ADDRESS["city"],
    "billing_state": ADDRESS["state"],
    "billing_postcode": ADDRESS["postcode"],
    "billing_country": ADDRESS["country"],
    "billing_email": ADDRESS["email"],
    "billing_phone": ADDRESS["phone"],
    "payment_method": "wfpms_iban",
    "woocommerce-process-checkout-nonce": nonce_match.group(1),
    "_wp_http_referer": CLASSIC_PATH,
    "terms": "1",
    "terms-field": "1",
}
classic_status, _, classic_response = call(
    classic,
    "/?wc-ajax=checkout",
    method="POST",
    data=classic_fields,
)
if classic_status != 200:
    raise RuntimeError(f"Classic checkout returned HTTP {classic_status}: {classic_response[:1000]!r}")
try:
    classic_result = json.loads(classic_response)
except json.JSONDecodeError as error:
    raise RuntimeError(f"Classic checkout did not return JSON: {classic_response[:1000]!r}") from error
if classic_result.get("result") != "success":
    raise RuntimeError(f"Classic checkout failed: {classic_response[:1500]!r}")
classic_redirect = urllib.parse.urlparse(classic_result.get("redirect", ""))
classic_id_match = re.search(r"/order-received/(\d+)", classic_redirect.path)
if classic_id_match:
    classic_order_id = int(classic_id_match.group(1))
else:
    received_id = urllib.parse.parse_qs(classic_redirect.query).get("order-received", [""])[0]
    if not re.fullmatch(r"\d+", received_id):
        raise RuntimeError(f"Classic checkout redirect did not contain an order ID: {classic_result.get('redirect')!r}")
    classic_order_id = int(received_id)

blocks = new_client()
cart_status, cart_headers, cart_body = call(blocks, store_api_path("cart"))
if cart_status != 200:
    raise RuntimeError(f"Store API cart initialization failed: HTTP {cart_status}: {cart_body[:700]!r}")
nonce = cart_headers.get("Nonce")
if not nonce:
    raise RuntimeError("Store API did not return the cart nonce.")
add_status, add_headers, add_body = call(
    blocks,
    store_api_path("cart/add-item", {"id": PRODUCT_ID, "quantity": 1}),
    method="POST",
    headers={"Nonce": nonce},
    data={},
)
if add_status not in (200, 201):
    raise RuntimeError(f"Store API product add failed: HTTP {add_status}: {add_body[:1000]!r}")
nonce = add_headers.get("Nonce", nonce)
blocks_status, blocks_headers, blocks_body = call(
    blocks,
    store_api_path("checkout"),
    method="POST",
    headers={"Nonce": nonce},
    data={
        "billing_address": ADDRESS,
        "shipping_address": {key: value for key, value in ADDRESS.items() if key != "email" and key != "phone"},
        "payment_method": "wfpms_iban",
        "customer_note": "",
        "create_account": False,
    },
)
if blocks_status != 200:
    raise RuntimeError(f"Store API checkout returned HTTP {blocks_status}: {blocks_body[:1500]!r}")
try:
    blocks_result = json.loads(blocks_body)
except json.JSONDecodeError as error:
    raise RuntimeError(f"Store API checkout did not return JSON: {blocks_body[:1000]!r}") from error
blocks_order_id = int(blocks_result.get("order_id", 0))
if blocks_order_id < 1:
    raise RuntimeError(f"Store API checkout response omitted order_id: {blocks_body[:1000]!r}")

with open(OUTPUT_PATH, "w", encoding="utf-8") as output:
    json.dump(
        {
            "classic_order_id": classic_order_id,
            "blocks_order_id": blocks_order_id,
        },
        output,
    )
print("PASS: Classic and Store API HTTP checkout created IBAN orders without card payment")
