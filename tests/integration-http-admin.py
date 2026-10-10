#!/usr/bin/env python3
"""Check seller filtering and routing-error audit through authenticated admin HTTP pages."""

import http.cookiejar
import os
import re
import urllib.parse
import urllib.request


BASE_URL = os.environ.get("WFPMS_BASE_URL", "http://127.0.0.1:8099").rstrip("/")
ADMIN_USER = os.environ["WFPMS_ADMIN_USER"]
ADMIN_PASSWORD = os.environ["WFPMS_ADMIN_PASSWORD"]
HPOS = os.environ.get("WFPMS_EXPECT_HPOS") == "1"
SELLER_ONE = int(os.environ["WFPMS_SELLER_ONE_ID"])
SELLER_TWO = int(os.environ["WFPMS_SELLER_TWO_ID"])
ORDER_ONE = int(os.environ["WFPMS_ORDER_SELLER_ONE"])
ORDER_TWO = int(os.environ["WFPMS_ORDER_SELLER_TWO"])
UNASSIGNED_PRODUCT = int(os.environ["WFPMS_UNASSIGNED_PRODUCT"])
CONFLICTED_PRODUCT = int(os.environ["WFPMS_CONFLICTED_PRODUCT"])
ASSIGNED_PRODUCT = int(os.environ["WFPMS_PRODUCT_SELLER_ONE"])

client = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
)


def get(path):
    response = client.open(BASE_URL + path, timeout=20)
    body = response.read().decode("utf-8", "replace")
    if response.status != 200:
        raise RuntimeError(f"GET {path} returned HTTP {response.status}")
    return body


login_form = get("/wp-login.php")
if 'name="log"' not in login_form or 'name="pwd"' not in login_form:
    raise RuntimeError("WordPress login page did not expose the expected form.")
login_data = urllib.parse.urlencode(
    {
        "log": ADMIN_USER,
        "pwd": ADMIN_PASSWORD,
        "wp-submit": "Log In",
        "redirect_to": BASE_URL + "/wp-admin/",
        "testcookie": "1",
    }
).encode()
login_request = urllib.request.Request(
    BASE_URL + "/wp-login.php",
    data=login_data,
    headers={"Content-Type": "application/x-www-form-urlencoded"},
)
login_response = client.open(login_request, timeout=20)
if "wp-login.php" in login_response.geturl():
    raise RuntimeError("Could not authenticate the disposable WordPress admin user.")
get("/wp-admin/")

if HPOS:
    order_path = "/wp-admin/admin.php?" + urllib.parse.urlencode(
        {"page": "wc-orders", "wfpms_seller_filter": SELLER_ONE}
    )
else:
    order_path = "/wp-admin/edit.php?" + urllib.parse.urlencode(
        {"post_type": "shop_order", "wfpms_seller_filter": SELLER_ONE}
    )
orders_html = get(order_path)
row_ids = {
    int(order_id)
    for order_id in re.findall(r'<tr[^>]+id=["\'](?:order|post)-(\d+)', orders_html)
}
if ORDER_ONE not in row_ids or ORDER_TWO in row_ids:
    raise RuntimeError(
        f"Seller order filter returned rows {sorted(row_ids)}; expected seller-one order "
        f"{ORDER_ONE} and not seller-two order {ORDER_TWO}."
    )
if "Integration Seller 1" not in orders_html:
    raise RuntimeError("Filtered order table does not render the seller column label.")

audit_html = get(
    "/wp-admin/admin.php?" + urllib.parse.urlencode({"page": "wfpms-product-audit"})
)
for product_id, label in [
    (UNASSIGNED_PRODUCT, "unassigned product"),
    (CONFLICTED_PRODUCT, "conflicted product"),
]:
    if not re.search(rf'<tr[^>]*>.*?{product_id}.*?</tr>', audit_html, re.S):
        raise RuntimeError(f"Product audit page does not list the {label} #{product_id}.")
if re.search(rf'<tr[^>]*>.*?{ASSIGNED_PRODUCT}.*?</tr>', audit_html, re.S):
    raise RuntimeError(f"Product audit page incorrectly lists assigned product #{ASSIGNED_PRODUCT} as an error.")
if "Не визначено" not in audit_html or "Конфлікт" not in audit_html:
    raise RuntimeError("Product audit page does not explain both assignment errors.")

print("PASS: authenticated admin HTTP pages filter orders by seller and list only routing errors")
