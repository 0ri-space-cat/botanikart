#!/usr/bin/env python3
"""Create a Timeweb ZIP with a protected PHP service directory."""
import argparse
import subprocess
import sys
import tempfile
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
PRIVATE_FILES = ("PaymentService.php", "CatalogStore.php", "configure-catalogue.php", "import-catalogue.php", "config.example.php", "configure.php", "stage-production.php", "console.php", ".htaccess")

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output", type=Path)
    destination = parser.parse_args().output.resolve()
    if destination.exists():
        raise FileExistsError("Choose a new archive filename")
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="botanikart-release-") as temporary:
        public = Path(temporary) / "public_html"
        subprocess.run([sys.executable, str(ROOT / "scripts/build-site.py"), "--output", str(public)], check=True)
        with ZipFile(destination, "w", ZIP_DEFLATED) as archive:
            for file in sorted(public.rglob("*")):
                if file.is_file():
                    archive.write(file, "public_html/" + file.relative_to(public).as_posix())
            for name in PRIVATE_FILES:
                info = ZipInfo("public_html/api/.private/" + name)
                info.external_attr = 0o100600 << 16
                info.compress_type = ZIP_DEFLATED
                archive.writestr(info, (ROOT / "hosting/private" / name).read_bytes())
            archive.write(ROOT / "hosting/SETUP.md", "SETUP.md")
    print(destination)

if __name__ == "__main__":
    main()
