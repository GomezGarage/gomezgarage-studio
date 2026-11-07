"""Real loopback multipart upload checks using a disposable web root, no DB/config."""
import argparse
import http.client
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
parser.add_argument('--sample', required=True, type=Path, help='A local synthetic H.264 MP4 fixture')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ggs-video-http-') as directory:
    web = Path(directory)
    (web / 'includes').mkdir()
    shutil.copy2(root / 'includes/product-content.php', web / 'includes/product-content.php')
    (web / 'upload.php').write_text('''<?php
require __DIR__ . '/includes/product-content.php';
header('Content-Type: application/json');
try { $path = ggs_upload_product_video($_FILES['video'] ?? []); http_response_code(201); echo json_encode(['path'=>$path]); }
catch (RuntimeException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); }
''')
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    with (web / 'server.log').open('w') as log:
        process = subprocess.Popen([args.php, '-d', 'upload_max_filesize=100M', '-d', 'post_max_size=110M', '-S', f'127.0.0.1:{port}', '-t', str(web)], stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            def upload(name, data):
                boundary='ggs-synthetic-boundary'
                payload=(f'--{boundary}\r\nContent-Disposition: form-data; name="video"; filename="{name}"\r\nContent-Type: video/mp4\r\n\r\n').encode()+data+f'\r\n--{boundary}--\r\n'.encode()
                con=http.client.HTTPConnection('127.0.0.1',port)
                con.request('POST','/upload.php',payload,{'Content-Type':f'multipart/form-data; boundary={boundary}'})
                response=con.getresponse(); status=response.status; body=json.loads(response.read()); con.close()
                return status,body
            sample=args.sample.read_bytes()
            status,body=upload('synthetic.mp4',sample)
            assert status==201,body
            path=web/body['path'].removeprefix('/studio/')
            assert path.read_bytes()==sample and len(path.stem)==32
            assert upload('executable.php',sample)[0]==422
            assert upload('fake.mp4',b'<?php echo 1; ?>')[0]==422
            assert upload('empty.mp4',b'')[0]==422
            print('PASS: actual multipart MP4 upload, random filename, exact bytes; executable, fake MP4 and empty uploads rejected.')
        finally:
            process.terminate(); process.wait(timeout=10)
