#!/usr/bin/env python3
"""Exercise the packaged PHP API over HTTP, without contacting the bank."""
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get("PHP_BINARY") or shutil.which("php")
if not PHP:
    raise RuntimeError("PHP 8.3+ is required")

with tempfile.TemporaryDirectory(prefix="botanikart-http-") as temporary:
    base = Path(temporary)
    public = base / "public_html"
    private = base / "botanikart-private"
    private.mkdir(mode=0o700)
    subprocess.run([sys.executable, str(ROOT / "scripts/build-site.py"), "--output", str(public)], check=True)
    shutil.copy2(ROOT / "hosting/private/PaymentService.php", private / "PaymentService.php")
    (private / "config.php").write_text("<?php return ['site_url'=>'https://botanikart.ru',"
        "'terminal_key'=>'TESTDEMO','password'=>'fixture-only','order_secret'=>'" + "a" * 64
        + "','catalog_path'=>" + repr(str(public / "data/catalog.json"))
        + ",'data_dir'=>" + repr(str(private / "orders")) + "];\n")
    router = base / "router.php"
    router.write_text("<?php if(!is_file($_SERVER['DOCUMENT_ROOT'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)))"
                      "{http_response_code(404);return true;} return false;\n")
    with socket.socket() as socket_:
        socket_.bind(("127.0.0.1", 0))
        port = socket_.getsockname()[1]
    server = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", str(public), str(router)],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        def call(route, body=None, origin="https://botanikart.ru", raw=None):
            request = urllib.request.Request(f"http://127.0.0.1:{port}" + route,
                data=raw if raw is not None else (json.dumps(body).encode() if body is not None else None),
                headers={"Origin": origin, "Content-Type": "application/json"})
            try:
                with urllib.request.urlopen(request, timeout=5) as response:
                    return response.status, response.read()
            except urllib.error.HTTPError as error:
                return error.code, error.read()
        for _ in range(30):
            try:
                call("/index.html")
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        cases = [("/api/create-payment.php", None, 405), ("/api/create-payment.php", {}, 400),
                 ("/api/payment-status.php", {"reference": "forged"}, 400),
                 ("/botanikart-private/config.php", None, 404)]
        for route, body, expected in cases:
            status, response = call(route, body)
            assert status == expected, (route, status)
            assert b"fixture-only" not in response
        assert call("/api/create-payment.php", {}, origin="https://evil.example")[0] == 403
        assert call("/api/create-payment.php", raw=b"{")[0] == 400
        assert call("/api/create-payment.php", raw=b" " * 17000)[0] == 413
        catalog = json.loads((public / "data/catalog.json").read_text())
        product = next(item for item in catalog["products"] if item["available"])
        body = {"productId": product["id"], "requestId": "12345678-1234-1234-1234-123456789012",
                "expectedAmount": round(float(product["price"]) * 100) + 1, "email": "test@example.com", "consent": True}
        assert call("/api/create-payment.php", body)[0] == 409
        (private / "config.php").unlink()
        assert call("/api/create-payment.php", body)[0] == 503
        print("HTTP API checks passed: 9 scenarios, no real bank requests.")
    finally:
        server.terminate()
        server.wait(timeout=5)
