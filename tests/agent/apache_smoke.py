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
import sqlite3
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--httpd-basedir', required=True, type=Path)
    parser.add_argument('--php-module', required=True, type=Path)
    parser.add_argument('--openssl', default='openssl')
    parser.add_argument('--library-path')
    parser.add_argument('--catalog-checks', action='store_true', help='Also check storefront rewrites using a synthetic SQLite catalog')
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
        if args.catalog_checks:
            studio = web / 'studio'
            (studio / 'includes').mkdir(parents=True)
            for name in ['.htaccess', 'product.php', 'products.php', 'sitemap.php', 'product.html', 'products.html', 'sitemap.xml']:
                shutil.copy2(root / name, studio / name)
            for name in ['catalog.php', 'catalog-view.php']:
                shutil.copy2(root / 'includes' / name, studio / 'includes' / name)
            fixture = private / 'catalog.sqlite'
            rows = json.loads((root / 'database/catalog-products.json').read_text())
            with sqlite3.connect(fixture) as db:
                db.execute('CREATE TABLE products (id INTEGER PRIMARY KEY, ' + ','.join('"' + k + '" TEXT' for k in rows[0]) + ', created_at TEXT)')
                for i, row in enumerate(rows):
                    row.update(status='published', price_eur='49.00', created_at='2026-10-26 00:00:00')
                    row['images'] = json.dumps(['/studio/assets/images/products/synthetic.webp'])
                    row['specs'] = json.dumps(row['specs'])
                    db.execute('INSERT INTO products (' + ','.join(row) + ') VALUES (' + ','.join('?' for _ in row) + ')', list(row.values()))
                db.execute("INSERT INTO products (slug,name_en,status) VALUES ('synthetic-draft','Synthetic draft','draft')")
            # This is a fixture-only replacement, never the site's config or database helper.
            php_fixture = str(fixture).replace("'", "\\'")
            (studio / 'includes' / 'db.php').write_text("<?php function ggs_db(): PDO { return new PDO('sqlite:" + php_fixture + "', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }")
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
        module_names = ['mpm_prefork', 'authz_core', 'authz_host', 'unixd', 'mime', 'ssl']
        if args.catalog_checks:
            module_names.append('rewrite')
        for name in module_names:
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
                assert request('product-drafts', method='POST', body=draft, idempotency_key='apache-draft-test-key-002')[0] == 503, 'draft DB config absent is unavailable'
                assert request(query='?limit=1')[0] == 422, 'validation'
                assert request('orders')[0] == 403, 'scope boundary'
                assert request('products')[0] == 503, 'DB activation gate'
                assert request('products', query='?category=home')[0] == 503, 'home read filter passes validation before DB gate'
                config['credentials'][0]['active'] = False
                credential_file.write_text(json.dumps(config))
                assert request()[0] == 401, 'credential revocation'
                print('PASS: Apache loopback HTTPS with verified temporary certificate; health, Bearer, scopes, validation, method, DB gate, revocation, no-store.')
                if args.catalog_checks:
                    def page(path):
                        conn = http.client.HTTPSConnection('127.0.0.1', port, timeout=5, context=context)
                        conn.request('GET', '/studio/' + path)
                        result = conn.getresponse()
                        text = result.read().decode()
                        status = result.status
                        conn.close()
                        return status, text
                    status, text = page('product.html?slug=magsafe-apple-watch-dock')
                    assert status == 200 and '<h1' in text and 'Give your everyday devices' in text, 'server-rendered product copy'
                    assert 'id="product-schema"' in text and 'og:image' in text, 'server-rendered schema and sharing metadata'
                    assert 'href="https://gomezgarage.eu/studio/product.html?slug=magsafe-apple-watch-dock"' in text, 'canonical slug URL'
                    assert page('product.html?id=1')[0] == 200, 'legacy id URL'
                    for query in ['slug=synthetic-draft', 'slug=does-not-exist', 'id=bad', 'id[]=1', 'slug[]=bad', '']:
                        status, text = page('product.html?' + query)
                        assert status == 404 and 'noindex' in text and 'application/ld+json' not in text, 'missing/draft/invalid products are 404'
                    status, text = page('products.html')
                    assert status == 200 and text.count('class="product-card"') == 6 and 'synthetic-draft' not in text, 'published catalog links in initial HTML'
                    status, text = page('sitemap.xml')
                    assert status == 200 and text.count('<loc>') == 10 and 'synthetic-draft' not in text and 'product.html?id=' not in text, 'live published sitemap'
                    assert page('includes/catalog.php')[0] == 403, 'includes blocked by root htaccess'
                    fixture.unlink()
                    assert page('product.html?slug=magsafe-apple-watch-dock')[0] == 503, 'DB failure is not a false 404'
                    assert page('sitemap.xml')[0] == 503, 'sitemap DB failure signals retry'
                    print('PASS: storefront Apache rewrites, initial HTML copy/links, metadata/schema, legacy IDs, draft/missing 404, live sitemap, internal access denial and 503 failures. Synthetic catalog only.')
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
