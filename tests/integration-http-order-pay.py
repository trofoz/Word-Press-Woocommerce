#!/usr/bin/env python3
"""Open real order-pay URLs with saved order keys; never submit payment."""

import json
import os
import urllib.request


FIXTURE_PATH = os.environ["WFPMS_HTTP_ORDER_PAY_PATHS_PATH"]
with open(FIXTURE_PATH, encoding="utf-8") as fixture_file:
    fixtures = json.load(fixture_file)

for checkout_kind in ("classic_order_id", "blocks_order_id"):
    fixture = fixtures.get(checkout_kind, {})
    order_id = int(fixture.get("order_id", 0))
    pay_url = fixture.get("url", "")
    if order_id < 1 or not pay_url:
        raise RuntimeError(f"Missing order-pay URL fixture for {checkout_kind}.")
    response = urllib.request.urlopen(pay_url, timeout=20)
    body = response.read().decode("utf-8", "replace")
    if response.status != 200:
        raise RuntimeError(f"Order-pay GET for {order_id} returned HTTP {response.status}.")
    if "Integration Seller 1" not in body:
        marker = "Переказ на рахунок"
        start = body.find(marker)
        excerpt = body[max(0, start - 300):start + 900] if start >= 0 else body[:1200]
        raise RuntimeError(
            f"Order-pay page for {order_id} does not show the frozen legal recipient; "
            f"final URL={response.geturl()}; excerpt={excerpt!r}"
        )
    if "payment_method_wfpms_iban" not in body:
        raise RuntimeError(f"Order-pay page for {order_id} does not show the saved IBAN method.")
    if "payment_method_wfpms_card" in body:
        raise RuntimeError(f"Order-pay page for {order_id} used the changed card-only current configuration.")

print("PASS: real Classic and Blocks order-pay URLs render frozen seller and IBAN method without submitting payment")
