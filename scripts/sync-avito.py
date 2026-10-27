#!/usr/bin/env python3
"""Merge active Avito listings into the public catalogue snapshot.

Credentials are read only from AVITO_CLIENT_ID and AVITO_CLIENT_SECRET.
The script is deliberately fail-closed: malformed or incomplete API responses
leave the existing catalogue untouched.
"""
from __future__ import annotations

import difflib
import json
import os
import re
import sys
import unicodedata
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo
import time

from catalog_taxonomy import enrich

ROOT = Path(__file__).resolve().parents[1]
API = "https://api.avito.ru"
CLIENT_ID = os.environ.get("AVITO_CLIENT_ID", "").strip()
CLIENT_SECRET = os.environ.get("AVITO_CLIENT_SECRET", "").strip()
PAGE_SIZE = 100
MAX_PAGES = 100


class AvitoError(RuntimeError):
    pass


def call(path: str, *, token: str | None = None, form: dict[str, str] | None = None) -> dict:
    body = urlencode(form).encode() if form is not None else None
    headers = {"Accept": "application/json"}
    if body is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    if token:
        headers["Authorization"] = "Bearer " + token
    request = Request(API + path, data=body, headers=headers, method="POST" if body is not None else "GET")
    try:
        with urlopen(request, timeout=30) as response:
            result = json.load(response)
    except HTTPError as error:
        # Never print the response body: authentication errors can echo request data.
        raise AvitoError(f"Avito API returned HTTP {error.code}") from None
    except (URLError, TimeoutError, json.JSONDecodeError) as error:
        raise AvitoError(f"Avito API request failed ({type(error).__name__})") from None
    if not isinstance(result, dict):
        raise AvitoError("Avito API returned an unexpected response")
    return result


def access_token() -> str:
    result = call("/token/", form={
        "grant_type": "client_credentials",
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET,
    })
    token = result.get("access_token")
    if not isinstance(token, str) or not token:
        raise AvitoError("Avito did not return an access token")
    return token


def listings(token: str) -> list[dict]:
    all_items: list[dict] = []
    page = 1
    while page <= MAX_PAGES:
        query = urlencode({"per_page": PAGE_SIZE, "page": page, "status": "active"})
        result = call("/core/v1/items?" + query, token=token)
        batch = result.get("resources", result.get("items"))
        if batch is None and isinstance(result.get("data"), dict):
            batch = result["data"].get("resources", result["data"].get("items"))
        if not isinstance(batch, list):
            raise AvitoError("Avito returned an unrecognized listings format")
        if any(not isinstance(item, dict) for item in batch):
            raise AvitoError("Avito listings contain an invalid record")
        all_items.extend(batch)
        if len(batch) < PAGE_SIZE:
            break
        page += 1
    else:
        raise AvitoError("Avito pagination exceeded the safety limit")
    if not all_items:
        raise AvitoError("Avito returned no active listings; keeping the current snapshot")
    return all_items


def account_id(token: str):
    account = call("/core/v1/accounts/self", token=token)
    value = first(account, "id", "user_id", "userId", "account_id", "accountId")
    if value is None and isinstance(account.get("data"), dict):
        value = first(account["data"], "id", "user_id", "userId", "account_id", "accountId")
    if value is None:
        shape = response_shape(account)
        raise AvitoError(f"Cannot identify the Avito account; account field shape only: {json.dumps(shape, ensure_ascii=False)}")
    return value


def full_listing(token: str, user_id, summary: dict, index: int) -> dict:
    item_id = first(summary, "id", "item_id", "itemId")
    if item_id is None:
        raise AvitoError("An Avito listing has no identifier")
    # Pace requests conservatively while requesting every full card.
    if index:
        time.sleep(2.6)
    path = f"/core/v1/accounts/{quote(str(user_id), safe='')}/items/{quote(str(item_id), safe='')}/"
    detail = call(path, token=token)
    for key in ("item", "data", "result"):
        if isinstance(detail.get(key), dict):
            detail = detail[key]
            break
    return {**summary, **detail}


def first(item: dict, *keys: str):
    for key in keys:
        value = item.get(key)
        if value not in (None, "", [], {}):
            return value
    return None


def response_shape(value, depth: int = 2):
    """Describe field names/types only; never include listing values in CI logs."""
    if isinstance(value, dict):
        if depth <= 0:
            return "object"
        return {str(key): response_shape(child, depth - 1) for key, child in value.items()}
    if isinstance(value, list):
        return [response_shape(value[0], depth - 1)] if value and depth > 0 else "array"
    return type(value).__name__


def image_urls(value) -> list[str]:
    if not isinstance(value, list):
        return []
    result = []
    for image in value:
        url = image if isinstance(image, str) else first(image, "url", "image_url", "src", "preview") if isinstance(image, dict) else None
        if isinstance(url, str) and url.startswith("https://") and url not in result:
            result.append(url)
    return result


def normalize_title(value: str) -> str:
    value = unicodedata.normalize("NFKC", value).lower().replace("ё", "е")
    return " ".join(re.findall(r"[\w]+", value, flags=re.UNICODE))


def price_value(value):
    if isinstance(value, dict):
        value = first(value, "value", "amount", "price")
    try:
        price = round(float(str(value).replace("\u00a0", "").replace(" ", "").replace(",", ".")), 2)
    except (TypeError, ValueError):
        return None
    return price if price > 0 else None


def normalize_listing(item: dict) -> dict | None:
    remote_id = first(item, "id", "item_id", "itemId")
    title = first(item, "title", "name")
    url = first(item, "url", "item_url", "itemUrl")
    price = price_value(first(item, "price", "price_value", "priceValue"))
    photos = image_urls(first(item, "images", "image_urls", "imageUrls", "image"))
    if not remote_id or not isinstance(title, str) or not title.strip() or not isinstance(url, str) or not url.startswith("https://") or price is None or not photos:
        return None
    raw_category = first(item, "category", "category_name", "categoryName")
    if isinstance(raw_category, dict):
        raw_category = first(raw_category, "name", "title", "id")
    description = first(item, "description", "body", "text")
    return {
        "id": f"avito-{remote_id}",
        "avitoItemId": str(remote_id),
        "title": title.strip(),
        "url": url,
        "price": price,
        "available": True,
        "status": "ready",
        "category": "plant",
        "sourceCategory": str(raw_category or "Растения Авито"),
        "description": str(description or "").strip(),
        "images": photos,
        "source": "avito",
        "checkedAt": datetime.now(ZoneInfo("Europe/Moscow")).date().isoformat(),
    }


def duplicate_of(candidate: dict, products: list[dict]) -> tuple[str | None, bool]:
    title = normalize_title(candidate["title"])
    for old in products:
        if round(float(old.get("price") or 0), 2) != candidate["price"]:
            continue
        other = normalize_title(old.get("title", ""))
        if not title or not other:
            continue
        if title == other:
            return str(old.get("id")), False
        if difflib.SequenceMatcher(None, title, other).ratio() >= 0.88:
            return str(old.get("id")), True
    return None, False


def main() -> int:
    if not CLIENT_ID or not CLIENT_SECRET:
        print("Avito credentials are not configured; leaving the current catalogue unchanged.")
        return 0

    token = access_token()
    user_id = account_id(token)
    summaries = listings(token)
    remote_items = [full_listing(token, user_id, item, index) for index, item in enumerate(summaries)]
    catalog_path = ROOT / "data/catalog.json"
    catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
    if not isinstance(catalog, dict) or not isinstance(catalog.get("products"), list):
        raise AvitoError("The current catalogue has an unexpected format")

    products = catalog["products"]
    existing = {str(p.get("avitoItemId")): p for p in products if p.get("source") == "avito" and p.get("avitoItemId")}
    flowwow = [p for p in products if p.get("source", "flowwow") == "flowwow"]
    overrides = json.loads((ROOT / "data/catalog-groups.json").read_text(encoding="utf-8"))
    imported: list[dict] = []
    review: list[dict] = []
    suppressed_ids: set[str] = set()
    skipped = 0

    for raw in remote_items:
        candidate = normalize_listing(raw)
        if candidate is None:
            skipped += 1
            continue
        prior = existing.get(candidate["avitoItemId"])
        match, fuzzy = duplicate_of(candidate, flowwow)
        if match and not fuzzy:
            # Exact title and price: keep Flowwow as the canonical storefront card.
            suppressed_ids.add(candidate["avitoItemId"])
            continue
        if match and fuzzy:
            # Do not publish uncertain cross-market duplicates until reviewed.
            review.append({"avitoItemId": candidate["avitoItemId"], "title": candidate["title"], "price": candidate["price"], "possibleDuplicateOf": match, "url": candidate["url"]})
            suppressed_ids.add(candidate["avitoItemId"])
            continue
        if prior:
            candidate = {**prior, **candidate}
        imported.append(enrich(candidate, overrides))

    if skipped == len(remote_items):
        shape = response_shape(remote_items[0]) if remote_items else "empty"
        raise AvitoError(f"No Avito listings had all required fields and photos; first-record field shape only: {json.dumps(shape, ensure_ascii=False)}")

    # Keep previously imported ads not returned in this complete active-listings snapshot,
    # but label them as made to order rather than deleting their catalogue cards.
    seen_ids = {p["avitoItemId"] for p in imported}
    for old in products:
        if old.get("source") == "avito" and str(old.get("avitoItemId")) not in seen_ids and str(old.get("avitoItemId")) not in suppressed_ids:
            old = dict(old)
            old.update({"available": False, "status": "preorder", "checkedAt": datetime.now(ZoneInfo("Europe/Moscow")).date().isoformat()})
            imported.append(enrich(old, overrides))

    catalog["products"] = [p for p in products if p.get("source") != "avito"] + imported
    catalog["avitoCheckedAt"] = datetime.now(ZoneInfo("Europe/Moscow")).date().isoformat()
    catalog_path.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    js_path = ROOT / "data/catalog.js"
    js_path.write_text("window.BOTANIK_CATALOG=" + json.dumps(catalog, ensure_ascii=False, separators=(",", ":")) + ";\n", encoding="utf-8")
    review_path = ROOT / "work/avito-review.json"
    review_path.parent.mkdir(parents=True, exist_ok=True)
    review_path.write_text(json.dumps(review, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Avito sync: {len(imported)} listings merged; {skipped} incomplete listings skipped; {len(review)} possible duplicates held for review.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except AvitoError as error:
        print(f"Avito sync failed; current catalogue retained: {error}", file=sys.stderr)
        raise SystemExit(1)
    except (HTTPError, URLError, TimeoutError, ValueError, OSError) as error:
        print(f"Avito sync failed; current catalogue retained: {type(error).__name__}.", file=sys.stderr)
        raise SystemExit(1)
