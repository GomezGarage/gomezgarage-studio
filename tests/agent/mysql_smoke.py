"""Start a disposable MySQL with no TCP, run synthetic integration tests, stop it.

Requires an already available MySQL 8.4 or MariaDB 10.6.28 basedir; never installs a service, imports
shop config, or accepts an existing server/DSN. No credentials appear in argv.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mysql-basedir', required=True, type=Path)
    parser.add_argument('--engine', choices=['mysql', 'mariadb'], default='mysql')
    parser.add_argument('--php', default='php', help='PHP CLI executable for the target version')
    parser.add_argument('--library-path', help='Optional macOS bottle library directories')
    parser.add_argument('--responses', type=Path, help='Optional synthetic response export for schema validation')
    args = parser.parse_args()
    basedir = args.mysql_basedir.resolve(strict=True)
    binary = basedir / 'bin/mysqld'
    if not binary.is_file():
        raise SystemExit('Expected a MySQL basedir containing bin/mysqld')
    env = dict(os.environ)
    if args.library_path:
        env['DYLD_LIBRARY_PATH'] = args.library_path
    root = Path(__file__).resolve().parents[2]
    with tempfile.TemporaryDirectory(prefix='ggs-mysql-', dir='/tmp') as directory:
        private = Path(directory)
        private.chmod(0o700)
        data = private / 'data'
        socket = private / 'mysql.sock'
        log = private / 'mysql.log'
        if args.engine == 'mysql':
            init_command = [str(binary), '--no-defaults', '--initialize-insecure',
                            '--basedir=' + str(basedir), '--datadir=' + str(data),
                            '--log-error=' + str(log)]
        else:
            # Source the vendor bootstrap script in one shell. macOS strips DYLD_*
            # on entry to /bin/sh, so restore only the explicitly supplied test path.
            env['GGS_TEST_LIBRARY_PATH'] = args.library_path or ''
            installer = next((basedir / p for p in ['bin/mariadb-install-db', 'scripts/mariadb-install-db', 'bin/mysql_install_db'] if (basedir / p).is_file()), None)
            if installer is None:
                raise SystemExit('MariaDB bootstrap script not found')
            init_command = ['/bin/sh', '-c',
                'export DYLD_LIBRARY_PATH="$GGS_TEST_LIBRARY_PATH"; script="$1"; shift; . "$script"',
                'ggs-mariadb-bootstrap', str(installer), '--no-defaults',
                '--basedir=' + str(basedir), '--datadir=' + str(data),
                '--auth-root-authentication-method=normal', '--skip-test-db']
        init = subprocess.run(init_command, env=env, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, timeout=120)
        if init.returncode:
            raise SystemExit('Disposable MySQL initialization failed; no production connection attempted.')
        with (private / 'process.log').open('wb') as output:
            server = subprocess.Popen([str(binary), '--no-defaults', '--basedir=' + str(basedir),
                '--datadir=' + str(data), '--socket=' + str(socket), '--skip-networking',
                *(['--mysqlx=OFF'] if args.engine == 'mysql' else []),
                '--pid-file=' + str(private / 'mysql.pid'), '--log-error=' + str(log),
                '--general-log=OFF', '--slow-query-log=OFF', '--skip-log-bin',
                '--innodb-buffer-pool-size=67108864'], env=env, stdout=output, stderr=output)
            try:
                deadline = time.monotonic() + 60
                while not socket.exists():
                    if server.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('Disposable MySQL did not become ready.')
                    time.sleep(.1)
                request = {'socket': str(socket), 'datadir': str(data), 'private_dir': str(private), 'engine': args.engine}
                result = subprocess.run([args.php, '-n', '-d', 'pdo_mysql.default_socket=' + str(socket),
                    '-d', 'display_errors=0', '-d', 'zend.exception_ignore_args=1',
                    str(root / 'tests/agent/mysql.php')], input=json.dumps(request),
                    env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
                # Never echo uncaught driver exceptions, SQL, config or raw database logs.
                if result.returncode:
                    raise RuntimeError('MySQL integration test failed: ' + result.stdout[:300])
                report = json.loads(result.stdout)
                if args.responses:
                    args.responses.write_text(json.dumps(report.pop('responses'), indent=2))
                else:
                    report.pop('responses')
                print(json.dumps(report, indent=2))
            finally:
                if server.poll() is None:
                    server.terminate()
                    try:
                        server.wait(timeout=20)
                    except subprocess.TimeoutExpired:
                        server.kill()
                        server.wait(timeout=5)
        # TemporaryDirectory removes the synthetic DB, auth material and raw logs.


if __name__ == '__main__':
    main()
