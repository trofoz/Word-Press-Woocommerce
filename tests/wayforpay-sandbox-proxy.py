#!/usr/bin/env python3
"""Expose only one WayForPay callback and one order return route to a temporary tunnel."""

import json
import os
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlsplit
from http.client import HTTPConnection, HTTPException


class CallbackOnlyProxy(BaseHTTPRequestHandler):
    def log_message(self, _format: str, *_args: object) -> None:
        # Request URLs contain a one-time order key; never log request paths or query strings.
        return

    def do_GET(self) -> None:
        if self.path == "/__health":
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"ok")
            return

        parsed = urlsplit(self.path)
        query = parse_qs(parsed.query, keep_blank_values=True)
        handoff = (
            parsed.path == "/wp-admin/admin-post.php"
            and query.get("action") == ["wfpms_pay"]
            and len(query.get("order_id", [""])) == 1
            and query["order_id"][0].isdigit()
            and len(query.get("reference", [""])) == 1
            and query["reference"][0].startswith("wfpms-")
            and len(query.get("token", [""])) == 1
            and len(query["token"][0]) == 64
        )
        if handoff:
            self._forward("GET")
            return
        if not self._is_test_order_return(parsed.path, query):
            self.send_error(404)
            return
        self._forward("GET")

    @staticmethod
    def _is_test_order_return(path: str, query: dict[str, list[str]]) -> bool:
        manifest_path = os.environ.get("WFPMS_SANDBOX_OUTPUT", "")
        try:
            with open(manifest_path, encoding="utf-8") as manifest_file:
                manifest = json.load(manifest_file)
        except (OSError, json.JSONDecodeError):
            return False

        order_id = str(manifest.get("order_id", ""))
        order_key = manifest.get("order_key", "")
        if not order_id.isdigit() or not isinstance(order_key, str) or not order_key:
            return False

        query_return = (
            path in ("/", "/index.php")
            and query.get("order-received") == [order_id]
            and query.get("key") == [order_key]
            and set(query) == {"order-received", "key"}
        )
        pretty_return = (
            path.rstrip("/") == f"/checkout/order-received/{order_id}"
            and query.get("key") == [order_key]
            and set(query) == {"key"}
        )
        return query_return or pretty_return

    def do_POST(self) -> None:
        parsed = urlsplit(self.path)
        query = parse_qs(parsed.query, keep_blank_values=True)
        if parsed.path != "/" or query != {"wc-api": ["wfpms_wayforpay"]}:
            self.send_error(404)
            return
        content_length = int(self.headers.get("Content-Length", "0"))
        if content_length < 1 or content_length > 65536:
            self.send_error(413)
            return
        self._record_callback_metric("requests", 1)
        self._forward("POST")

    @staticmethod
    def _record_callback_metric(name: str, value: int) -> None:
        metrics_path = os.environ.get("WFPMS_SANDBOX_METRICS", "")
        if not metrics_path:
            return
        try:
            with open(metrics_path, encoding="utf-8") as metrics_file:
                metrics = json.load(metrics_file)
        except (OSError, json.JSONDecodeError):
            metrics = {"requests": 0, "responses": []}
        if name == "requests":
            metrics["requests"] = int(metrics.get("requests", 0)) + value
        elif name == "responses":
            metrics.setdefault("responses", []).append(value)
        with open(metrics_path, "w", encoding="utf-8") as metrics_file:
            json.dump(metrics, metrics_file)

    def _forward(self, method: str) -> None:
        body = self.rfile.read(int(self.headers.get("Content-Length", "0"))) if method == "POST" else None
        headers = {
            "Host": self.headers.get("Host", ""),
            "X-Forwarded-Proto": "https",
            "X-Forwarded-For": self.client_address[0],
            "Accept": self.headers.get("Accept", "*/*"),
        }
        content_type = self.headers.get("Content-Type")
        if content_type:
            headers["Content-Type"] = content_type
        connection = HTTPConnection("127.0.0.1", 8099, timeout=30)
        try:
            connection.request(method, self.path, body=body, headers=headers)
            response = connection.getresponse()
            response_body = response.read()
            if method == "POST":
                self._record_callback_metric("responses", response.status)
            self.send_response(response.status)
            for header in ("Content-Type", "Cache-Control", "Location"):
                value = response.getheader(header)
                if value:
                    self.send_header(header, value)
            self.send_header("Content-Length", str(len(response_body)))
            self.end_headers()
            self.wfile.write(response_body)
        except (OSError, HTTPException):
            if method == "POST":
                self._record_callback_metric("responses", 502)
            self.send_error(502)
        finally:
            connection.close()


HTTPServer(("127.0.0.1", 8098), CallbackOnlyProxy).serve_forever()
