#!/usr/bin/env python3
"""Build the Cloud Functions upload package without credentials or dependencies."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import sys
root=Path(__file__).resolve().parents[1]
destination=Path(sys.argv[1]) if len(sys.argv)>1 else root/"botanikart-payments-yandex.zip"
with ZipFile(destination,"w",ZIP_DEFLATED) as archive:
    archive.writestr("index.js",(root/"payments-yandex/index.js").read_text().replace("../payments-worker/src/index.js","./payment-handler.js"))
    archive.write(root/"payments-worker/src/index.js","payment-handler.js")
    archive.write(root/"payments-yandex/package.json","package.json")
print(destination)
