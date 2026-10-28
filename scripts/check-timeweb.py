#!/usr/bin/env python3
"""Hosting diagnostics with an explicitly requested one-time mail setup check."""
import os
import tempfile
import posixpath
import shlex
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
        smoke=os.environ.get('SMOKE_ACTION','none')
        if smoke != 'none':
            if smoke not in ['inspect','start','check','cleanup']:raise ValueError('Invalid smoke action')
            public=posixpath.normpath(os.environ['TIMEWEB_PUBLIC_DIR'])
            if not public.startswith('/') or not public.endswith('/public_html'):raise ValueError('Invalid hosting path')
            target=posixpath.join(public,'api/.private/marketplace-smoke.php')
            with client.open_sftp() as sftp:
                sftp.put('hosting/private/marketplace-smoke.php',target+'.upload');sftp.chmod(target+'.upload',0o600);sftp.posix_rename(target+'.upload',target)
            command += '/opt/php83/bin/php ' + shlex.quote(target) + ' ' + smoke + '\n'
        if os.environ.get('MAIL_TEST') == 'true':
            public=posixpath.normpath(os.environ['TIMEWEB_PUBLIC_DIR'])
            if not public.startswith('/') or not public.endswith('/public_html'):
                raise ValueError('Invalid configured hosting directory')
            command += '/opt/php83/bin/php ' + shlex.quote(posixpath.join(public,'api/.private/console.php')) + ' notifications-check\n'
        _,stdout,stderr=client.exec_command(command,timeout=240)
        print(stdout.read().decode());print(stderr.read().decode())
        if stdout.channel.recv_exit_status()!=0:raise RuntimeError('Hosting diagnostic did not complete')
    finally:client.close()
