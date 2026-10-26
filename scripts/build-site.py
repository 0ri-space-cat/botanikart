#!/usr/bin/env python3
"""Create a clean, public-only static site directory for Yandex Object Storage."""
from __future__ import annotations

import argparse
import json
import shutil
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PAGES = (
    "index.html",
    "catalog.html",
    "offer.html",
    "privacy.html",
    "order-success.html",
    "order-error.html",
    "style.css",
    "script.js",
    "payment-result.js",
)
PUBLIC_ASSETS = (
    "aglaonema.jpg",
    "favicon.svg",
    "forest1.jpg",
    "forest2.jpg",
    "logo-mark-roots.png",
    "logo-mark.png",
    "reserve0.jpg",
    "reserve2.jpg",
    "studio-logo.jpg",
)


def copy_file(relative: str, destination: Path) -> None:
    source = ROOT / relative
    if not source.is_file():
        raise FileNotFoundError(f"Required site file is missing: {relative}")
    target = destination / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, target)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path, help="Destination for the publishable site")
    args = parser.parse_args()
    output = args.output.resolve()
    if output == ROOT or ROOT in output.parents:
        raise ValueError("Choose an output directory outside the repository")
    if output.exists():
        raise FileExistsError(f"Output directory already exists: {output}")
    output.mkdir(parents=True)

    for relative in PAGES:
        copy_file(relative, output)

    for relative in ("data/catalog.json", "data/catalog.js", "data/payment-config.js"):
        copy_file(relative, output)

    for relative in PUBLIC_ASSETS:
        copy_file(f"assets/{relative}", output)

    catalog = json.loads((ROOT / "data/catalog.json").read_text(encoding="utf-8"))
    image_paths = {
        image
        for product in catalog.get("products", [])
        for image in product.get("images", [])
        if isinstance(image, str) and image.startswith("assets/catalog/")
    }
    for relative in sorted(image_paths):
        copy_file(relative, output)

    # Fail closed if implementation-only material ever appears in the publish tree.
    forbidden = {".git", "work", "payments-worker", "payments-yandex", "private", ".env"}
    leaked = sorted(path.name for path in output.rglob("*") if path.name in forbidden)
    if leaked:
        raise RuntimeError(f"Unexpected non-public material in site build: {leaked}")
    print(f"Built {len(list(output.rglob('*')))} public files in {output}")


if __name__ == "__main__":
    main()
