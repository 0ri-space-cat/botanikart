#!/usr/bin/env python3
"""Check session, CSRF, private data and live catalog behavior on an isolated local server."""
import http.cookiejar
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which("php")
with tempfile.TemporaryDirectory(prefix="botanikart-catalogue-http-") as temporary:
    base = Path(temporary)
    public = base / "public_html"
    subprocess.run(["python3", str(ROOT / "scripts/build-site.py"), "--output", str(public)], check=True)
    private = public / "api/.private"
    private.mkdir(mode=0o700)
    shutil.copy2(ROOT / "hosting/private/CatalogStore.php", private / "CatalogStore.php")
    for name in ['CatalogPhoto.php','PaymentService.php','MarketplaceService.php','PublicationRunner.php','FlowwowPublisher.php','AvitoFeed.php','avito-legacy.json','flowwow-fields.json']:
        shutil.copy2(ROOT / 'hosting/private' / name, private / name)
    password_hash = subprocess.check_output([PHP, "-r", "echo password_hash('fixture-admin-password',PASSWORD_DEFAULT);"]).decode()
    auth = private / "catalogue-auth.php"
    auth.write_text("<?php return ['username'=>'fixture','password_hash'=>'" + password_hash + "','revision'=>'first-test'];")
    router = base / "router.php"
    router.write_text("<?php if(str_contains($_SERVER['REQUEST_URI'],'/.private/')){http_response_code(403);return true;} return false;")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    origin = f"http://127.0.0.1:{port}"
    server = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", str(public), str(router)],
                              env={**os.environ, "CATALOGUE_LOCAL_TEST":"1"}, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    cookies = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))

    def call(action, body=None, csrf=None, custom_origin=None, path=None):
        headers = {"Origin": custom_origin or origin}
        if csrf: headers["X-CSRF-Token"] = csrf
        if body is not None: headers["Content-Type"] = "application/json"
        request = urllib.request.Request(origin + (path or "/catalogue/api.php?action="+action),
                                         data=json.dumps(body).encode() if body is not None else None, headers=headers)
        try:
            with client.open(request, timeout=5) as response:
                raw = response.read()
                return response.status, json.loads(raw) if response.headers.get_content_type()=="application/json" else raw
        except urllib.error.HTTPError as error:
            raw = error.read()
            try: raw=json.loads(raw)
            except json.JSONDecodeError: pass
            return error.code, raw
    try:
        for _ in range(30):
            try:
                status, session = call("session")
                break
            except urllib.error.URLError: time.sleep(.1)
        assert status == 200 and not session["authenticated"]
        assert call("products")[0] == 401
        assert call("orders")[0] == 401
        assert call("complete-order",{},session["csrf"])[0] == 401
        credentials = {"username":"fixture", "password":"fixture-admin-password"}
        assert call("login", credentials)[0] == 403
        assert call("login", credentials, session["csrf"], "https://evil.example")[0] == 403
        status, login = call("login", credentials, session["csrf"])
        assert status == 200 and login["authenticated"], login
        assert call("session")[1]["authenticated"]
        assert any(cookie.name=="BOTANIKART_CATALOGUE" and cookie.expires is not None for cookie in cookies)
        active_cookie = next(cookie for cookie in cookies if cookie.name == "BOTANIKART_CATALOGUE")
        assert active_cookie.expires > time.time() + 29*86400, "Login cookie is not persistent for 30 days"
        assert active_cookie.path == "/catalogue/" and active_cookie.has_nonstandard_attr("HttpOnly")
        # Simulate reopening the installed app after the browser has restarted:
        # reuse the persisted cookie, without resending a username or password.
        saved_jar = http.cookiejar.MozillaCookieJar(str(base / "catalogue-cookies.txt"))
        saved_jar.set_cookie(active_cookie)
        saved_jar.save()
        restored_jar = http.cookiejar.MozillaCookieJar(str(base / "catalogue-cookies.txt"))
        restored_jar.load()
        reopened = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(restored_jar))
        with reopened.open(origin + "/catalogue/api.php?action=session", timeout=5) as response:
            resumed = json.load(response)
        assert resumed["authenticated"] and resumed["csrf"] == login["csrf"], "Reopening lost the session"
        resumed_cookie = next(cookie for cookie in restored_jar if cookie.name == "BOTANIKART_CATALOGUE")
        assert resumed_cookie.expires > time.time() + 29*86400, "Returning visit did not renew the cookie"
        status, data = call("products")
        assert status == 200 and data["products"]
        assert call("connections")[0]==200
        (private/'config.php').write_text("<?php return ['data_dir'=>__DIR__.'/orders','terminal_key'=>'FIXTURELIVE'];")
        order={'orderId':'ba-'+24*'a','productId':next(p['id'] for p in data['products'] if p.get('catalogGroup')=='florarium'),'title':'Растение','amount':25000,'paid':True,'sold':True,'testMode':False,'createdAt':1,'status':'CONFIRMED','email':'buyer@example.com','phone':'','reference':'must-not-leak'}
        (private/'orders/live').mkdir(parents=True)
        (private/'orders/live/orders.php').write_text('<?php exit; ?>\n'+json.dumps({'orders':{order['orderId']:order},'reservations':{order['productId']:order['orderId']}}))
        status, result=call('orders')
        assert status==200 and len(result['orders'])==1 and 'reference' not in result['orders'][0]
        assert result['notificationReady'] is False
        assert call('complete-order',{'id':order['orderId'],'completed':True})[0]==403
        assert call('complete-order',{'id':order['orderId'],'completed':True},login['csrf'],'https://evil.example')[0]==403
        assert call('confirm-sale',{'id':order['orderId']})[0]==403
        assert call('complete-order',{'id':order['orderId'],'completed':True},login['csrf'])[0]==409
        assert call('confirm-sale',{'id':order['orderId']},login['csrf'])[0]==200
        assert call('withdraw-sale',{'id':order['orderId'],'channels':[]},login['csrf'])[0]==200
        assert call('complete-order',{'id':order['orderId'],'completed':True},login['csrf'])[1]['orders'][0]['fulfillment']=='completed'
        assert call('complete-order',{'id':order['orderId'],'completed':False},login['csrf'])[1]['orders'][0]['fulfillment']=='open'
        assert call('complete-order',{'id':order['orderId'],'completed':'yes'},login['csrf'])[0]==400
        reopened=next(p for p in call('products')[1]['products'] if p['id']==order['productId'])
        reopened['status']='ready'
        assert call('save',reopened,login['csrf'])[0]==200
        state=json.loads((private/'orders/live/orders.php').read_text().split('\n',1)[1])
        assert order['productId'] not in state['reservations'], 'Returning unique item to stock left it permanently unpurchasable'

        assert call("check-connections",{},login["csrf"])[0]==200
        assert call("renewal-date",{"date":"2026-02-30"},login["csrf"])[0]==400
        assert call("renewal-date",{"date":"2026-11-28"},login["csrf"])[0]==200
        product = call("products")[1]["products"][0]
        assert call("save", {"id":product["id"],"version":product["version"],"hidden":True}, login["csrf"])[0] == 200
        status, public_catalog = call("", path="/api/catalog.php")
        decoded=json.loads(public_catalog.decode().removeprefix("window.BOTANIK_CATALOG=").rstrip(";"))
        assert status == 200 and product["id"] not in [p["id"] for p in decoded["products"]]
        assert call("save", {"id":product["id"],"version":product["version"],"hidden":False}, login["csrf"])[0] == 409
        image = subprocess.check_output([PHP,"-r","$i=imagecreatetruecolor(2,2);imagepng($i);"])
        boundary = "fixture-image-boundary"
        multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="plant.png"\r\nContent-Type: image/png\r\n\r\n'.encode()
                     + image + f'\r\n--{boundary}--\r\n'.encode())
        upload = urllib.request.Request(origin+"/catalogue/api.php?action=upload",data=multipart,
             headers={"Origin":origin,"X-CSRF-Token":login["csrf"],"Content-Type":"multipart/form-data; boundary="+boundary})
        with client.open(upload,timeout=5) as response: uploaded=json.load(response)["path"]
        assert (public/uploaded).read_bytes().startswith(b'\xff\xd8'), "Upload was not safely re-encoded"
        manual = {"title":"Тестовое растение","price":490,"description":"Абзац один.\n\nАбзац два.","status":"ready",
                  "catalogGroup":"plant","source":"avito","url":"https://www.avito.ru/moskva/rasteniya/test_8303205392","images":[uploaded]}
        status, created = call("save",manual,login["csrf"])
        assert status==200, created
        assert call("save",manual,login["csrf"])[0]==409, "Same Avito listing was imported twice"
        local = next(p for p in call("products")[1]["products"] if p["id"]==created["id"])
        local["price"]=700;local["status"]="sold"
        assert call("save",local,login["csrf"])[0]==200
        public_data = call("",path="/api/catalog.php")[1]
        published = json.loads(public_data.decode().removeprefix("window.BOTANIK_CATALOG=").rstrip(";"))
        changed = next(p for p in published["products"] if p["id"]==created["id"])
        assert changed["price"]==700 and not changed["available"] and changed["description"]==manual["description"]
        local = next(p for p in call("products")[1]["products"] if p["id"]==created["id"])
        status, archived = call("archive",{"id":local["id"],"version":local["version"]},login["csrf"])
        assert status==200 and "avitoWithdrawal" in archived, "Archive bypassed immediate withdrawal reconciliation"
        local = next(p for p in call("products")[1]["products"] if p["id"]==created["id"])
        assert local["deleted"] and not local["channels"]["avito"]["enabled"], "Archive retained publication intent"
        assert call("archive",{"id":local["id"],"version":local["version"],"restore":True},login["csrf"])[0]==200
        assert call("", path="/api/.private/catalogue/catalogue.sqlite")[0] == 403
        assert call("logout",{},login["csrf"])[0]==200
        anonymous=call("session")[1]
        assert not anonymous["authenticated"]
        assert call("connections")[0]==401
        assert call("login",credentials,anonymous["csrf"])[0]==200
        auth.write_text(auth.read_text().replace("first-test","second-test"))
        assert call("products")[0] == 401
        assert call("orders")[0] == 401
        assert call("products")[0] == 401, "Password revision did not invalidate the session"
        print("Catalogue HTTP checks passed: persistent reopen and 30-day renewal, CSRF, origin, hidden product, conflicts, photo upload, local product creation/editing, Avito deduplication, private storage and session invalidation.")
    finally:
        server.terminate()
        server.wait(timeout=5)
