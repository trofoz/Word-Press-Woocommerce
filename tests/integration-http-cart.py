#!/usr/bin/env python3
"""Exercise classic add-to-cart and WooCommerce Store API over HTTP."""

import http.cookiejar
import json
import os
import urllib.error
import urllib.parse
import urllib.request


BASE_URL = os.environ.get("WFPMS_BASE_URL", "http://127.0.0.1:8099").rstrip("/")
STORE_API_ROOT = "/?" + urllib.parse.urlencode({"rest_route": "/wc/store/v1"})
SAME_SELLER_IDS = [
    int(os.environ["WFPMS_PRODUCT_SELLER_ONE"]),
    int(os.environ["WFPMS_PRODUCT_SELLER_ONE_SECOND"]),
]
OTHER_SELLER_ID = int(os.environ["WFPMS_PRODUCT_SELLER_TWO"])


def opener():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
    )


def request(client, path, method="GET", headers=None, body=None):
    encoded_body = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(
        BASE_URL + path,
        data=encoded_body,
        method=method,
        headers=headers or {},
    )
    try:
        response = client.open(req, timeout=20)
        return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def cart(client):
    status, headers, body = request(client, STORE_API_ROOT + "/cart")
    if status != 200:
        raise RuntimeError(f"Store API cart GET returned HTTP {status}: {body[:500]!r}")
    try:
        return json.loads(body), headers
    except json.JSONDecodeError as error:
        raise RuntimeError(
            f"Store API cart GET did not return JSON (content-type={headers.get('Content-Type')}): {body[:500]!r}"
        ) from error


def assert_cart(cart_data, expected_ids, label):
    actual_ids = [int(item["id"]) for item in cart_data.get("items", [])]
    quantities = [int(item["quantity"]) for item in cart_data.get("items", [])]
    if sorted(actual_ids) != sorted(expected_ids) or sum(quantities) != len(expected_ids):
        raise RuntimeError(
            f"{label}: expected item IDs {expected_ids}, got IDs {actual_ids} and quantities {quantities}"
        )


def classic_add(client, product_id):
    query = urllib.parse.urlencode({"add-to-cart": product_id})
    status, _, body = request(client, "/?" + query)
    if status not in (200, 302):
        raise RuntimeError(f"Classic add-to-cart returned HTTP {status}: {body[:500]!r}")


classic = opener()
classic_add(classic, SAME_SELLER_IDS[0])
classic_add(classic, OTHER_SELLER_ID)
classic_cart, _ = cart(classic)
assert_cart(classic_cart, [SAME_SELLER_IDS[0]], "classic mixed-seller request")
classic_add(classic, SAME_SELLER_IDS[1])
classic_cart, _ = cart(classic)
assert_cart(classic_cart, SAME_SELLER_IDS, "classic same-seller requests")

store_api = opener()
empty_cart, headers = cart(store_api)
nonce = headers.get("Nonce")
if not nonce:
    raise RuntimeError("Store API cart GET did not return the nonce needed for add-item requests.")

def store_api_add(product_id):
    global nonce
    query = urllib.parse.urlencode({"id": product_id, "quantity": 1})
    status, response_headers, body = request(
        store_api,
        STORE_API_ROOT + "/cart/add-item&" + query,
        method="POST",
        headers={"Nonce": nonce, "Content-Type": "application/json"},
        body={},
    )
    updated_nonce = response_headers.get("Nonce")
    if updated_nonce:
        nonce = updated_nonce
    return status, body


for product_id in SAME_SELLER_IDS:
    status, body = store_api_add(product_id)
    if status != 200:
        raise RuntimeError(f"Store API rejected same-seller product {product_id}: HTTP {status}: {body[:500]!r}")

status, body = store_api_add(OTHER_SELLER_ID)
if status < 400:
    raise RuntimeError(f"Store API accepted mixed-seller product {OTHER_SELLER_ID}.")
store_cart, _ = cart(store_api)
assert_cart(store_cart, SAME_SELLER_IDS, "Store API mixed-seller request")

print("PASS: real HTTP classic and Store API carts reject mixed sellers and accept same-seller products")
