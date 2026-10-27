#!/usr/bin/env python3
"""Refresh the static product catalog from Flowwow Seller API."""
import json
import os
import re
import sys
from datetime import datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo
from catalog_taxonomy import enrich

ROOT = Path(__file__).resolve().parents[1]
SHOP_ID = 257704
TOKEN = os.environ.get("FLOWWOW_API_KEY", "").strip()
API = "https://apis.flowwow.com"


def request(path, payload=None):
    body = None if payload is None else json.dumps(payload).encode()
    req = Request(API + path, data=body, method="POST", headers={
        "Authorization": "Bearer " + TOKEN,
        "Accept": "application/json",
        "Content-Type": "application/json",
    })
    with urlopen(req, timeout=45) as response:
        return json.load(response)


def pages():
    page, items, total = 0, [], None
    while True:
        result = request(f"/apiseller/products?shopId={SHOP_ID}", {
            "withArchive": True, "extended": True, "withStats": False,
            "page": page, "limit": 1000,
        })
        batch = result.get("items")
        if not isinstance(batch, list):
            raise RuntimeError("Flowwow returned an unexpected products response")
        total = result.get("total", total)
        items.extend(batch)
        if not batch or (total is not None and len(items) >= total):
            break
        page += 1
        if page > 100:
            raise RuntimeError("Flowwow pagination exceeded safety limit")
    if not items or (total is not None and len(items) < total):
        raise RuntimeError(f"Incomplete Flowwow catalog: received {len(items)} of {total}")
    return items


def category_names():
    result = request(f"/apiseller/categories/tree?shopId={SHOP_ID}", {})
    names = {}
    for group in result.get("data", []):
        for sub in group.get("subCategories", []):
            names[(group.get("categoryId"), sub.get("subCategoryId"))] = (
                group.get("name", "") + " " + sub.get("name", "")
            ).lower()
    return names


def kind(item, names):
    text = (names.get((item.get("categoryId"), item.get("subCategoryId")), "")
            + " " + item.get("name", "")).lower().replace("ё", "е")
    if "флорариум" in text or "террариум" in text:
        return "florarium"
    if "фикус" in text:
        return "ficus"
    if "суккулент" in text or "кактус" in text:
        return "succulent"
    if "композици" in text or "мини-сад" in text or "мини сад" in text:
        return "composition"
    return "plant"


def key(url):
    return urlparse(url or "").path.rstrip("/").lower()


def normalize_description(value):
    """Keep source paragraphs and repair section/list boundaries in flattened exports."""
    text = str(value or "").replace("\r\n", "\n").replace("\r", "\n").replace("\xa0", " ")
    text = re.sub(r"[\t ]+", " ", text).strip()
    # These two illustrations introduce the following paragraph in the catalogue copy.
    text = re.sub(r"\s*([🦕🌿])\s*\n\s*\n\s*", r"\n\n\1 ", text)
    text = re.sub(r"([.!?]) +(?=🦕)", r"\1\n\n", text)
    heading = r"((?:🌿\s*)?Что внутри[^:\n]{0,65}:|(?:🎁\s*)?Кому подойд[её]т[^:\n]{0,35}:)"
    text = re.sub(r"\s*" + heading + r"\s*", r"\n\n\1\n", text, flags=re.IGNORECASE)
    # Split dashes only inside an explicit list section; prose dashes stay intact.
    match = re.search(r"(?:🌿\s*)?Что внутри[^:\n]*:", text, re.IGNORECASE)
    if match:
        tail = text[match.end():]
        end = re.search(r"\n\n(?=🎁|Кому подойд|Напишите нам)", tail, re.IGNORECASE)
        length = end.start() if end else len(tail)
        listing = re.sub(r"\s+[—•]\s+(?=[А-ЯЁA-Z])", "\n— ", tail[:length])
        text = text[:match.end()] + listing + tail[length:]
    text = re.sub(r"(?<=[.!?]) +(?=Напишите нам\s*[—-])", "\n\n", text)
    text = re.sub(r" *\n *", "\n", text)
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def main():
    if not TOKEN:
        print("FLOWWOW_API_KEY is not set; keeping the existing snapshot.")
        return

    products = pages()
    names = category_names()
    data_path = ROOT / "data/catalog.json"
    catalog = json.loads(data_path.read_text(encoding="utf-8"))
    existing = {key(p.get("url")): p for p in catalog["products"] if p.get("url") and p.get("source", "flowwow") == "flowwow"}
    existing_ids = {str(p["flowwowProductId"]): p for p in existing.values() if p.get("flowwowProductId") is not None}
    overrides = json.loads((ROOT / "data/catalog-groups.json").read_text(encoding="utf-8"))
    seen = set()
    refreshed = []

    for item in products:
        if item.get("type") == 3:
            continue
        url = item.get("url", "")
        if not url:
            raise RuntimeError("Flowwow item is missing its product URL")
        product_key = key(url)
        prior = existing_ids.get(str(item.get("productId"))) or existing.get(product_key)
        archived = bool(item.get("isArchived"))
        active = bool(item.get("isActive")) and not archived
        # Keep only selected archived products from the manually curated snapshot.
        if archived and not prior:
            continue
        if not active and not prior:
            continue
        seen.add(product_key)
        if prior:
            seen.add(key(prior.get("url")))
        current_price = float(item.get("price") or 0)
        discount = float(item.get("discount") or 0)
        if discount:
            current_price = round(current_price * (100 - discount) / 100, 2)
        stock = int(item.get("stock") or 0)
        is_available = active and stock > 0
        entry = dict(prior or {})
        entry.update({
            "id": (prior or {}).get("id") or f"flowwow-{item['productId']}",
            "title": item.get("name") or entry.get("title") or "Товар БотаникАрт",
            "url": url or entry.get("url"),
            "price": current_price,
            "available": is_available,
            "status": "ready" if is_available else "preorder",
            "category": (prior or {}).get("category") or kind(item, names),
            "sourceCategory": (prior or {}).get("sourceCategory") or names.get((item.get("categoryId"), item.get("subCategoryId")), "Растения").strip().title(),
            "description": normalize_description(item.get("description") or entry.get("description") or "Композиция ручной работы от студии БотаникАрт."),
            "checkedAt": datetime.now(ZoneInfo("Europe/Moscow")).date().isoformat(),
            "flowwowProductId": item.get("productId"),
            "source": "flowwow",
        })
        api_images = item.get("images") or []
        if api_images:
            entry["images"] = api_images
        elif not entry.get("images"):
            entry["images"] = []
        refreshed.append(entry)

    # Preserve selected made-to-order examples that are no longer returned by Flowwow.
    for old in catalog["products"]:
        if old.get("source", "flowwow") != "flowwow" or (old.get("status") in ("order", "preorder") and key(old.get("url")) not in seen):
            refreshed.append(old)
    if not refreshed:
        raise RuntimeError("Refusing to replace the catalog with an empty result")

    # Keep the manually selected homepage feature order, then fill missing slots from current florariums.
    ids = {p["id"] for p in refreshed}
    featured = [pid for pid in catalog.get("featured", []) if pid in ids]
    for product in refreshed:
        if len(featured) >= 4:
            break
        if product.get("category") == "florarium" and product.get("available") and product["id"] not in featured:
            featured.append(product["id"])
    catalog["products"] = [enrich(product, overrides) for product in refreshed]
    catalog["checkedAt"] = datetime.now(ZoneInfo("Europe/Moscow")).date().isoformat()
    catalog["featured"] = featured[:4]
    catalog["source"] = "Flowwow Seller API"
    catalog["mode"] = "synchronized"
    catalog["liveStoreCount"] = len(products)
    data_path.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    js_path = ROOT / "data/catalog.js"
    js_path.write_text("window.BOTANIK_CATALOG=" + json.dumps(catalog, ensure_ascii=False, separators=(",", ":")) + ";\n", encoding="utf-8")
    print(f"Catalog updated: {len(refreshed)} products from {len(products)} Flowwow items; {sum(p['available'] for p in refreshed)} available.")


if __name__ == "__main__":
    try:
        main()
    except (HTTPError, URLError, TimeoutError, ValueError, RuntimeError) as exc:
        print(f"Flowwow sync failed; existing catalog retained: {type(exc).__name__}: {exc}", file=sys.stderr)
        raise SystemExit(1)
