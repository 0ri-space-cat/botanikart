#!/usr/bin/env python3
"""Upload a whitelisted release over SFTP, with pinned SSH host keys."""
import argparse
import os
import posixpath
import stat
import uuid
from pathlib import Path

import paramiko


def mkdirs(sftp, path, mode):
    try:
        info = sftp.stat(path)
        if not stat.S_ISDIR(info.st_mode):
            raise RuntimeError("Deployment path is not a directory")
    except FileNotFoundError:
        parent = posixpath.dirname(path)
        if parent == path:
            raise
        mkdirs(sftp, parent, mode)
        sftp.mkdir(path, mode)


def upload(sftp, source, destination, private=False):
    for file in sorted(source.rglob("*"), key=lambda file: (file.suffix == '.html', str(file))):
        if not file.is_file():
            continue
        target = posixpath.join(destination, file.relative_to(source).as_posix())
        if target.endswith("/config.php") or "/orders/" in target:
            raise RuntimeError("Credentials/order files must never be deployed")
        mkdirs(sftp, posixpath.dirname(target), 0o700 if private else 0o755)
        temporary = target + ".upload-" + uuid.uuid4().hex
        try:
            sftp.put(str(file), temporary)
            sftp.chmod(temporary, 0o600 if private else 0o644)
            # OpenSSH SFTP supports atomic replacement; do not delete the old file first.
            sftp.posix_rename(temporary, target)
        finally:
            try:
                sftp.remove(temporary)
            except FileNotFoundError:
                pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--public", type=Path, required=True)
    args = parser.parse_args()
    public = args.public.resolve()
    required = ("TIMEWEB_HOST", "TIMEWEB_USER", "TIMEWEB_PUBLIC_DIR", "TIMEWEB_KEY_FILE", "TIMEWEB_KNOWN_HOSTS_FILE")
    if not all(os.environ.get(name) for name in required):
        raise RuntimeError("Timeweb deployment credentials are not configured")
    target = posixpath.normpath(os.environ["TIMEWEB_PUBLIC_DIR"])
    if not target.startswith("/") or not target.endswith("/public_html") or len(target.split("/")) < 4:
        raise RuntimeError("Use the absolute site-specific public_html directory from the hosting panel")
    private = posixpath.join(target, "api/.private")
    if not (public / "index.html").is_file() or not (public / "api/create-payment.php").is_file():
        raise RuntimeError("Not a complete public release")
    client = paramiko.SSHClient()
    client.load_host_keys(os.environ["TIMEWEB_KNOWN_HOSTS_FILE"])
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    try:
        client.connect(os.environ["TIMEWEB_HOST"], port=int(os.environ.get("TIMEWEB_PORT", "22")),
                       username=os.environ["TIMEWEB_USER"], key_filename=os.environ["TIMEWEB_KEY_FILE"],
                       allow_agent=False, look_for_keys=False, timeout=15, auth_timeout=15, banner_timeout=15)
        with client.open_sftp() as sftp:
            mkdirs(sftp, private, 0o700)
            sftp.chmod(private, 0o700)
            # Upload backend first, then data/assets, and entry pages last.
            upload(sftp, Path(__file__).resolve().parents[1] / "hosting/private", private, private=True)
            upload(sftp, public, target)
        print("Timeweb release uploaded. Private configuration and orders were preserved.")
    finally:
        client.close()


if __name__ == "__main__":
    main()
