#!/usr/bin/env python3
"""Build only the public Timeweb website, including its PHP API entry points."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
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
    "catalog/florarium-s-kryshkoy-tam-gde-c-1.jpg",
    "catalog/florarium-shepot-lesa-1.jpg",
    "aglaonema.jpg",
    "favicon.svg",
    "forest1.jpg",
    "forest2.jpg",
    "logo-mark-roots.png",
    "logo-mark.png",
    "reserve0.jpg",
    "reserve2.jpg",
    "studio-logo.jpg",
    "workshops.png",
    "materials.png",
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
    for source in sorted((ROOT / "api").glob("*.php")):
        copy_file(str(source.relative_to(ROOT)), output)
    for source in sorted((ROOT / "catalogue").rglob("*")):
        if source.is_file() and source.suffix != '.md':
            copy_file(str(source.relative_to(ROOT)), output)
    shutil.copy2(ROOT / "hosting/public.htaccess", output / ".htaccess")

    for relative in ("data/catalog.json", "data/catalog.js", "data/payment-config.js"):
        copy_file(relative, output)

    # The server merges Flowwow with local products and persistent visibility settings.
    for page in ("index.html", "catalog.html"):
        target = output / page
        html = target.read_text(encoding="utf-8")
        html, count = re.subn(r'src="data/catalog\.js(?:\?[^"<>]*)?"',
                              'src="api/catalog.php"', html)
        if count != 1:
            raise RuntimeError(f"Expected exactly one catalog script in {page}")
        target.write_text(html, encoding="utf-8")

    # Version the UI code so an earlier cached script cannot mislabel local stock.
    for page in ("index.html", "catalog.html"):
        target = output / page
        version = hashlib.sha256((output / 'script.js').read_bytes()).hexdigest()[:16]
        target.write_text(re.sub(r'src="script\.js(?:\?[^"<>]*)?"', f'src="script.js?v={version}"', target.read_text(encoding='utf-8')), encoding='utf-8')

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

    # Reviewed one-time imports contain public product photographs only, never raw account screenshots.
    for manifest_path in sorted((ROOT / 'imports').glob('*/catalog.json')):
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        for product in manifest.get('items', []):
            for relative in product.get('images', []):
                if not re.fullmatch(r'assets/uploads/catalogue/[a-f0-9]{32}\.jpg', relative):
                    raise ValueError('Invalid imported photograph path')
                source = manifest_path.parent / 'images' / Path(relative).name
                if not source.is_file():
                    raise FileNotFoundError('An imported photograph is missing')
                target = output / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)

    # Fail closed if implementation-only material ever appears in the publish tree.
    forbidden = {".git", "work", "private", ".env", "config.php", "config.example.php", "orders.json", "orders.php"}
    leaked = sorted(path.name for path in output.rglob("*") if path.name in forbidden)
    if leaked:
        raise RuntimeError(f"Unexpected non-public material in site build: {leaked}")
    print(f"Built {len(list(output.rglob('*')))} public files in {output}")


if __name__ == "__main__":
    main()
