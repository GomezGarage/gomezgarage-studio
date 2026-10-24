"""Loopback-only smoke tests through real PHP entry points; never loads shop config."""
import argparse
import hashlib
import http.client
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--library-path', help='Optional macOS bottle library directories')
args = parser.parse_args()

root = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='ggs-http-') as directory:
    temp = Path(directory)
    web = temp / 'web'
    web.mkdir()
    private = temp / 'private'
    private.mkdir(mode=0o700)
    rate = private / 'rate'
    rate.mkdir(mode=0o700)
    token = secrets.token_urlsafe(32)
    config = {'enabled':True, 'cursor_key_hex':secrets.token_hex(32), 'rate_limit_dir':str(rate),
              'credentials':[{'id':'http-fixture','token_sha256':hashlib.sha256(token.encode()).hexdigest(),
                              'active':True,'expires_at':'2099-01-01T00:00:00Z',
                              'scopes':['agent.health.read','products.read','orders.read']}]}
    config_file = private / 'config.json'
    config_file.write_text(json.dumps(config)); config_file.chmod(0o600)
    # Test-only transport fixture: maps TLS termination locally. Never part of the deploy artifact.
    router = web / 'router.php'
    php_root = str(root).replace('\\', '\\\\').replace("'", "\\'")
    router.write_text("<?php\n"
        "$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
        "if (!preg_match('~^/(secure|insecure)/(health|products|orders|product-drafts)\\.php$~D', $path, $parts)) { http_response_code(404); exit; }\n"
        "if ($parts[1] === 'secure') { $_SERVER['HTTPS'] = 'on'; }\n"
        f"require '{php_root}/api/agent/v1/' . $parts[2] . '.php';\n")
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    env = dict(os.environ, GGS_AGENT_CONFIG_FILE=str(config_file))
    if args.library_path:
        env['DYLD_LIBRARY_PATH'] = args.library_path
    log_file = temp / 'server.log'
    with log_file.open('w+') as log:
        process = subprocess.Popen([args.php,'-n','-d','display_errors=0','-d','zend.exception_ignore_args=1',
                                    '-S', f'127.0.0.1:{port}', '-t', str(web), str(router)],
                                   env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError('PHP test server failed to start; check executable/dependencies')
                try:
                    with socket.create_connection(('127.0.0.1',port), timeout=.1): break
                except OSError: time.sleep(.03)
            def request(path='health', auth=True, method='GET', secure=True, query='', body=None, idempotency_key=None):
                connection = http.client.HTTPConnection('127.0.0.1',port,timeout=3)
                headers={'X-Forwarded-Proto':'https','Cookie':'PHPSESSID=synthetic-admin'}
                if auth: headers['Authorization']='Bearer '+token
                if body is not None:
                    headers['Content-Type']='application/json'
                if idempotency_key is not None:
                    headers['Idempotency-Key']=idempotency_key
                connection.request(method, f"/{'secure' if secure else 'insecure'}/{path}.php{query}",body=body,headers=headers)
                result=connection.getresponse()
                body=result.read()
                status=result.status
                response_headers=dict(result.getheaders())
                connection.close()
                assert token.encode() not in body, 'Credential leak in HTTP response'
                return status, json.loads(body), response_headers
            config['enabled']=False
            config_file.write_text(json.dumps(config))
            assert request()[0]==503, 'Private config keeps API disabled by default'
            config['enabled']=True
            config_file.write_text(json.dumps(config))
            status, body, headers = request()
            assert status == 200 and body['data']['check']=='liveness', 'Health routing'
            assert headers['Cache-Control']=='no-store' and headers['X-Request-ID']==body['request_id'], 'HTTP headers'
            assert request('orders',auth=False)[0]==401, 'No session fallback'
            assert request('orders',method='POST')[0]==405, 'Method rejection'
            assert request('product-drafts',method='GET')[0]==405, 'Draft route rejects reads'
            config['credentials'][0]['scopes'].append('products.drafts.write')
            config_file.write_text(json.dumps(config))
            assert request('product-drafts',method='POST',body='{}',idempotency_key='draft-http-test-key-0001')[0]==422, 'Draft body validation before DB'
            draft_body=json.dumps({'slug':'http-draft','name_en':'Synthetic HTTP draft','category':'desk','price_eur':'1.00'})
            assert request('product-drafts',method='POST',body=draft_body,idempotency_key='draft-http-test-key-0002')[0]==503, 'No writer DB fallback'
            assert request(secure=False)[0]==403, 'Forwarded HTTPS not trusted'
            assert request(query='?limit=1')[0]==422, 'Health query validation'
            assert request('products')[0]==503, 'Database gate before connection'
            config['credentials'][0]['active']=False
            config_file.write_text(json.dumps(config))
            assert request()[0]==401, 'Revocation visible on next request'
            config['credentials'][0]['active']=True
            config['credentials'][0]['scopes']=[]
            config_file.write_text(json.dumps(config))
            assert request()[0]==403, 'Scope rejection'
            config['credentials'][0]['scopes']=['agent.health.read']
            config_file.write_text(json.dumps(config))
            last = None
            for _ in range(121):
                last=request()
                if last[0]==429: break
            assert last[0]==429 and 'Retry-After' in last[2], 'HTTP rate limit'
            config_file.write_text('{invalid')
            assert request()[0]==503, 'Malformed private config sanitized'
        finally:
            process.terminate()
            process.wait(timeout=5)
        log.flush()
    logs=log_file.read_text()
    assert token not in logs and config['cursor_key_hex'] not in logs, 'Secret leak in logs'
    events=[json.loads(line[line.index('{'):]) for line in logs.splitlines() if '"event":"agent_api_access"' in line]
    assert events and {200,401,403,405,422,429,503}.issubset({e['status'] for e in events}), 'Audit statuses'
print('PASS: loopback HTTP entry points, headers, auth, revocation, scopes, throttling and sanitized audit.')
