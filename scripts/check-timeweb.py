#!/usr/bin/env python3
"""Read-only hosting diagnostics; never reads keys, orders or customer data remotely."""
import os
import tempfile
from pathlib import Path
import paramiko

with tempfile.TemporaryDirectory() as temporary:
    key=Path(temporary)/'key';known=Path(temporary)/'known'
    key.write_text(os.environ['TIMEWEB_SSH_KEY']+'\n');key.chmod(0o600)
    known.write_text(os.environ['TIMEWEB_KNOWN_HOSTS']+'\n')
    client=paramiko.SSHClient();client.load_host_keys(str(known));client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(os.environ['TIMEWEB_HOST'],username=os.environ['TIMEWEB_USER'],key_filename=str(key),allow_agent=False,look_for_keys=False,timeout=15,auth_timeout=15,banner_timeout=15)
    try:
        command="""id
/opt/php83/bin/php -r 'echo "sendmail_path: ".ini_get("sendmail_path").PHP_EOL;echo "mail available: ".(function_exists("mail")?"yes":"no").PHP_EOL;'
for url in https://botanikart.ru/catalog.html https://botanikart.ru/catalogue/ https://botanikart.ru/api/payment-config.php https://botanikart.ru/catalogue/api.php?action=orders https://botanikart.ru/api/.private/flowwow-source.json; do curl --connect-timeout 4 --max-time 10 -sS -o /dev/null -w "$url HTTP %{http_code}; TLS %{ssl_verify_result}\\n" "$url"; done
"""
        _,stdout,stderr=client.exec_command(command,timeout=90)
        print(stdout.read().decode());print(stderr.read().decode())
        if stdout.channel.recv_exit_status()!=0:raise RuntimeError('Hosting diagnostic did not complete')
    finally:client.close()
