"""Loopback TLS/Apache test with copied agent files and temporary synthetic secrets.

Requires pre-existing Apache + mod_php binaries. No hosting/config.php access.
Does not certify cPanel FastCGI or the production virtual host.
"""
import argparse
import hashlib
import http.client
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import ssl
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--httpd-basedir', required=True, type=Path)
    parser.add_argument('--php-module', required=True, type=Path)
    parser.add_argument('--openssl', default='openssl')
    parser.add_argument('--library-path')
    args = parser.parse_args()
    base = args.httpd_basedir.resolve(strict=True)
    module = args.php_module.resolve(strict=True)
    root = Path(__file__).resolve().parents[2]
    env = dict(os.environ)
    if args.library_path:
        env['DYLD_LIBRARY_PATH'] = args.library_path
    with tempfile.TemporaryDirectory(prefix='ggs-apache-', dir='/tmp') as directory:
        private = Path(directory)
        private.chmod(0o700)
        web = private / 'web'
        web.mkdir()
        # Copy only API implementation, never the shop config or commercial routes.
        for name in ['api/agent', 'includes/agent']:
            shutil.copytree(root / name, web / name)
        rate = private / 'rate'
        rate.mkdir(mode=0o700)
        token = secrets.token_urlsafe(32)
        config = {'enabled': True, 'cursor_key_hex': secrets.token_hex(32), 'rate_limit_dir': str(rate),
                  'credentials': [{'id': 'apache-synthetic', 'token_sha256': hashlib.sha256(token.encode()).hexdigest(),
                  'active': True, 'expires_at': '2099-01-01T00:00:00Z', 'scopes': ['agent.health.read', 'products.read']}]}
        credential_file = private / 'agent.json'
        credential_file.write_text(json.dumps(config))
        credential_file.chmod(0o600)
        env.update(GGS_AGENT_ENABLED='1', GGS_AGENT_CONFIG_FILE=str(credential_file))
        cert, key = private / 'cert.pem', private / 'key.pem'
        subprocess.run([args.openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-keyout', str(key), '-out', str(cert), '-subj', '/CN=localhost',
                        '-addext', 'subjectAltName=IP:127.0.0.1'], env=env,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=30)
        key.chmod(0o600)
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        modules = base / 'lib/httpd/modules'
        conf = private / 'httpd.conf'
        lines = [f'ServerRoot "{base}"', f'Listen 127.0.0.1:{port}', 'ServerName localhost',
                 f'PidFile "{private}/httpd.pid"', f'ErrorLog "{private}/error.log"', 'LogLevel warn',
                 'ServerTokens Prod', 'ServerSignature Off']
        for name in ['mpm_prefork', 'authz_core', 'authz_host', 'unixd', 'mime', 'ssl']:
            lines.append(f'LoadModule {name}_module "{modules}/mod_{name}.so"')
        lines.extend([f'LoadModule php_module "{module}"', 'TypesConfig /dev/null',
            f'DocumentRoot "{web}"', f'<Directory "{web}">', 'Require all granted',
            'AllowOverride All', 'Options -Indexes', '</Directory>',
            '<FilesMatch "\\.php$">', 'SetHandler application/x-httpd-php', '</FilesMatch>',
            'SSLEngine On', f'SSLCertificateFile "{cert}"', f'SSLCertificateKeyFile "{key}"',
            'SSLSessionCache none', 'php_admin_flag display_errors Off',
            'php_admin_flag log_errors Off', 'php_admin_flag zend.exception_ignore_args On'])
        conf.write_text('\n'.join(lines) + '\n')
        with (private / 'process.log').open('w') as output:
            process = subprocess.Popen([str(base / 'bin/httpd'), '-f', str(conf), '-X'],
                                       env=env, stdout=output, stderr=output)
            try:
                for _ in range(100):
                    if process.poll() is not None:
                        raise RuntimeError('Isolated Apache startup failed; inspect local binary dependencies/configuration')
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.05)
                context = ssl.create_default_context(cafile=str(cert))
                def request(route='health', auth=True, method='GET', query='', body=None, idempotency_key=None):
                    connection = http.client.HTTPSConnection('127.0.0.1', port, timeout=5, context=context)
                    headers = {'Cookie': 'PHPSESSID=synthetic-admin'}
                    if auth:
                        headers['Authorization'] = 'Bearer ' + token
                    if body is not None:
                        headers['Content-Type'] = 'application/json'
                    if idempotency_key is not None:
                        headers['Idempotency-Key'] = idempotency_key
                    connection.request(method, '/api/agent/v1/' + route + '.php' + query, body=body, headers=headers)
                    result = connection.getresponse()
                    body = result.read()
                    status, returned_headers = result.status, dict(result.getheaders())
                    connection.close()
                    assert token.encode() not in body, 'credential redaction'
                    return status, json.loads(body), returned_headers
                status, body, headers = request()
                assert status == 200 and body['data']['check'] == 'liveness', 'Apache TLS health'
                assert headers['Cache-Control'] == 'no-store', 'no caching'
                assert request(auth=False)[0] == 401, 'no admin session fallback'
                assert request(method='POST')[0] == 405, 'read-only method'
                assert request('product-drafts', method='GET')[0] == 405, 'draft route method allowlist'
                config['credentials'][0]['scopes'].append('products.drafts.write')
                credential_file.write_text(json.dumps(config))
                assert request('product-drafts', method='POST', body='{}', idempotency_key='apache-draft-test-key-001') [0] == 422, 'draft validation before writer DB'
                draft = json.dumps({'slug':'apache-draft','name_en':'Synthetic draft','category':'desk','price_eur':'1.00'})
                assert request('product-drafts', method='POST', body=draft, idempotency_key='apache-draft-test-key-002')[0] == 503, 'writer DB is not inferred from reader'
                assert request(query='?limit=1')[0] == 422, 'validation'
                assert request('orders')[0] == 403, 'scope boundary'
                assert request('products')[0] == 503, 'DB activation gate'
                config['credentials'][0]['active'] = False
                credential_file.write_text(json.dumps(config))
                assert request()[0] == 401, 'credential revocation'
                print('PASS: Apache loopback HTTPS with verified temporary certificate; health, Bearer, scopes, validation, method, DB gate, revocation, no-store.')
            finally:
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)


if __name__ == '__main__':
    main()
