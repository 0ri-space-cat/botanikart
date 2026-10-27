#!/usr/bin/env python3
"""Read-only checks of the published Timeweb site; never initiate a bank payment."""
import json
import sys
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = "https://botanikart.ru"


def read(path):
    request = Request(BASE + path, headers={"User-Agent":"BotanikArt-release-check/1.0", "Cache-Control":"no-cache"})
    try:
        with urlopen(request, timeout=20) as response:
            return response.status, response.read(5_000_000).decode("utf-8")
    except HTTPError as error:
        return error.code, error.read(10000).decode("utf-8",errors="replace")


def main():
    checks = {}
    for path in ("/", "/catalog.html", "/catalogue/", "/catalogue/app.js?v=2"):
        status, body = read(path)
        checks[path] = status
        if status != 200:
            raise RuntimeError(f"Published page {path} returned {status}")
        if path == "/" and ('api/catalog.php' not in body or 'Сами' not in body and 'сами выращиваем' not in body):
            raise RuntimeError("Landing page is not the current release")
        if path == '/catalogue/' and 'id="category"' not in body:
            raise RuntimeError("Published catalogue is missing the category filter")
    status, body = read('/api/catalog.php')
    if status != 200 or not body.startswith('window.BOTANIK_CATALOG='):
        raise RuntimeError("Published catalogue API is unavailable")
    catalog = json.loads(body.removeprefix('window.BOTANIK_CATALOG=').rstrip(';\n'))
    checks['publicProducts'] = len(catalog.get('products',[]))
    if not checks['publicProducts']:
        raise RuntimeError("Published catalogue is empty")
    status, body = read('/api/payment-config.php')
    settings = json.loads(body)
    checks['paymentEndpointStatus'] = status
    checks['paymentsEnabled'] = settings.get('enabled',False)
    checks['paymentTestMode'] = settings.get('testMode')
    status, body = read('/catalogue/api.php?action=session')
    result = json.loads(body)
    if status == 503 and result.get('error') == 'setup_required':
        checks['catalogueLoginConfigured'] = False
    elif status == 200:
        checks['catalogueLoginConfigured'] = True
    else:
        raise RuntimeError(f"Catalogue session endpoint returned {status}")
    for path in ('/api/.private/config.php','/api/.private/catalogue-auth.php','/api/.private/catalogue/catalogue.sqlite'):
        status, _ = read(path)
        if status not in (403,404):
            raise RuntimeError("Private storage is publicly accessible")
    checks['privateFilesProtected'] = True
    checks['httpsCertificateVerified'] = True
    print(json.dumps(checks,ensure_ascii=False,indent=2))
    print('No payment was created, charged or refunded by these checks.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Do not log remote HTML, sessions, cookies or server response bodies.
        print(f'Live verification did not complete: {type(error).__name__}: {error}',file=sys.stderr)
        raise SystemExit(1)
