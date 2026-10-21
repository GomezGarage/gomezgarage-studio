<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/agent/bootstrap.php';

use Ggs\Agent\{Api, Config, Repository};

$checks = 0;
function verify(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function denied(callable $call, string $label): void
{
    try { $call(); } catch (PDOException $e) {
        verify(in_array($e->errorInfo[1] ?? 0, [1142, 1143, 1792], true), $label);
        return;
    }
    verify(false, $label);
}

try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $socket = realpath($input['socket']);
    $private = realpath($input['private_dir']);
    $data = realpath($input['datadir']);
    verify($private !== false && preg_match('~/ggs-mysql-[a-zA-Z0-9_-]+$~D', $private) === 1
        && $socket === $private . '/mysql.sock' && $data === $private . '/data'
        && (fileperms($private) & 0077) === 0, 'isolated_paths');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
    $admin = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', $options);
    verify(realpath($admin->query('SELECT @@datadir')->fetchColumn()) === $data, 'isolated_datadir');
    verify($admin->query('SELECT @@skip_networking')->fetchColumn() === '1', 'no_tcp');
    $version = $admin->query('SELECT VERSION()')->fetchColumn();
    $maria = ($input['engine'] ?? 'mysql') === 'mariadb';
    verify($maria ? str_starts_with($version, '10.6.28-MariaDB') : str_starts_with($version, '8.4.'), 'verified_database_version');
    verify($admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='ggs_agent_fixture'")->fetchColumn() === '0', 'fresh_database');
    $admin->exec('CREATE DATABASE ggs_agent_fixture CHARACTER SET utf8mb4');
    $admin->exec('USE ggs_agent_fixture');
    $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
    foreach (['products', 'orders'] as $table) {
        verify(preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $schema, $match) === 1, 'source_ddl_' . $table);
        $admin->exec($match[0]);
    }
    $admin->exec('CREATE TABLE admin_users (id INT PRIMARY KEY, password_hash TEXT)');
    $admin->exec("SET time_zone = '+03:00'");
    $product = $admin->prepare('INSERT INTO products (id,slug,name_en,name_ro,category,price_eur,stock,images,specs,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    for ($id = 1; $id <= 5; $id++) {
        $product->execute([$id, 'synthetic-' . $id, 'Synthetic', 'Sintetic', 'desk', '12.30', 4,
            '["/synthetic.svg"]', '[{"key":"Material","value":"PLA"}]', $id === 5 ? 'draft' : 'published',
            '2026-10-20 11:00:00', '2026-10-20 11:00:00']);
    }
    $order = $admin->prepare('INSERT INTO orders (id,order_number,payment_method,status,items,shipping_address,subtotal_eur,shipping_eur,total_eur,created_at,updated_at,customer_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    for ($id = 1; $id <= 3; $id++) {
        $order->execute([$id, 'SYNTHETIC-' . $id, $id === 1 ? 'cod' : 'card', $id === 1 ? 'pending' : 'paid',
            '[{"product_id":1,"name":"Synthetic","qty":2,"price_eur":12.3}]',
            '{"country":"RO","street":"PRIVATE-STREET"}', '24.60', '5.00', '29.60',
            '2026-10-20 11:00:00', '2026-10-20 11:00:00', 'private@example.invalid']);
    }
    $admin->exec("SET time_zone = '+00:00'");
    $password = bin2hex(random_bytes(32));
    $admin->exec("CREATE USER 'ggs_fixture_reader'@'localhost' IDENTIFIED BY " . $admin->quote($password));
    $productColumns = 'id,slug,status,name_ro,name_en,desc_short_ro,desc_short_en,desc_long_ro,desc_long_en,how_made_ro,how_made_en,material,category,price_eur,images,specs,stock,updated_at,created_at';
    $orderColumns = 'id,order_number,status,payment_method,created_at,updated_at,subtotal_eur,shipping_eur,total_eur,items,shipping_address,tracking_number';
    $admin->exec("GRANT SELECT ($productColumns) ON ggs_agent_fixture.products TO 'ggs_fixture_reader'@'localhost'");
    $admin->exec("GRANT SELECT ($orderColumns) ON ggs_agent_fixture.orders TO 'ggs_fixture_reader'@'localhost'");
    mkdir($private . '/rate', 0700);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $config = ['cursor_key_hex' => bin2hex(random_bytes(32)), 'rate_limit_dir' => $private . '/rate',
        'timestamp_storage_verified' => true,
        'database' => ['engine' => $maria ? 'mariadb' : 'mysql', 'host' => 'localhost', 'name' => 'ggs_agent_fixture', 'user' => 'ggs_fixture_reader', 'password' => $password],
        'credentials' => [['id' => 'synthetic-mysql', 'active' => true, 'token_sha256' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'scopes' => Config::SCOPES]]];
    $configPath = $private . '/config.json';
    file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));
    chmod($configPath, 0600);
    $configuration = static fn() => Config::load($configPath, dirname(__DIR__, 2));
    $repository = Repository::connect($configuration());
    $property = new ReflectionProperty(Repository::class, 'db');
    if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
    $reader = $property->getValue($repository);
    verify($reader->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'utc_session');
    verify($reader->query($maria ? 'SELECT @@session.tx_read_only' : 'SELECT @@session.transaction_read_only')->fetchColumn() === '1', 'read_only_session');
    verify($maria ? (float) $reader->query('SELECT @@session.max_statement_time')->fetchColumn() === 3.0
        : $reader->query('SELECT @@session.max_execution_time')->fetchColumn() === '3000', 'query_timeout_setting');
    denied(static fn() => $reader->exec('UPDATE products SET stock=0'), 'no_dml');
    denied(static fn() => $reader->exec('CREATE TABLE forbidden (id INT)'), 'no_ddl');
    denied(static fn() => $reader->query('SELECT customer_email FROM orders'), 'no_pii_column');
    denied(static fn() => $reader->query('SELECT * FROM orders'), 'no_select_star');
    denied(static fn() => $reader->query('SELECT password_hash FROM admin_users'), 'no_admin');
    try {
        $joins = implode(' CROSS JOIN ', array_map(static fn($i) => 'products p' . $i, range(1, 10)));
        if ($maria) { $reader->exec('SET SESSION max_statement_time = 0.01'); }
        $reader->query('SELECT /*+ MAX_EXECUTION_TIME(10) */ SUM(p1.stock + p10.stock) FROM ' . $joins)->fetchAll();
        verify(false, 'query_timeout_execution');
    } catch (PDOException $e) {
        verify(($e->errorInfo[1] ?? null) === ($maria ? 1969 : 3024), 'query_timeout_execution');
    } finally {
        if ($maria) { $reader->exec('SET SESSION max_statement_time = 3'); }
    }
    $snapshot = static fn() => serialize([$admin->query('SELECT * FROM products ORDER BY id')->fetchAll(), $admin->query('SELECT * FROM orders ORDER BY id')->fetchAll()]);
    $before = $snapshot();
    $events = $responses = [];
    $api = new Api($configuration, [Repository::class, 'connect'], static function (): void {},
        static function ($event) use (&$events): void { $events[] = $event; });
    $call = static function (string $route, string $query = '') use ($api, $token, &$responses): array {
        $result = $api->handle($route, 'GET', 'Bearer ' . $token, $query, true);
        $responses[] = ['route' => $route, 'status' => $result['status'], 'body' => $result['body']];
        return $result;
    };
    $first = $call('products', 'limit=2&status=all');
    verify($first['status'] === 200, 'products_real_connection');
    verify($first['body']['data'][0]['updated_at'] === '2026-10-20T08:00:00Z', 'timestamp_offset_normalization');
    verify($first['body']['data'][0]['price']['value'] === '12.30', 'mysql_decimal_string');
    $ids = array_column($first['body']['data'], 'id');
    $cursor = $first['body']['next_cursor'];
    for ($page = 0; $cursor !== null && $page < 10; $page++) {
        $result = $call('products', 'limit=2&status=all&cursor=' . urlencode($cursor));
        verify($result['status'] === 200, 'native_prepared_cursor');
        $ids = array_merge($ids, array_column($result['body']['data'], 'id'));
        $cursor = $result['body']['next_cursor'];
    }
    verify($ids === ['1', '2', '3', '4', '5'] && $cursor === null, 'keyset_ties');
    $orders = $call('orders', 'created_from=2026-10-20T08:00:00Z&created_before=2026-10-20T08:00:01Z');
    verify($orders['status'] === 200 && count($orders['body']['data']) === 3, 'orders_real_connection');
    verify($orders['body']['data'][0]['country'] === 'RO', 'mysql_json_projection');
    verify($orders['body']['data'][0]['payment_method'] === 'cod' && $orders['body']['data'][0]['payment_state'] === 'unknown', 'cod_not_receipts');
    verify($orders['body']['data'][1]['payment_state'] === 'unknown', 'paid_not_receipts');
    verify($orders['body']['data'][0]['total'] === '29.60' && $orders['body']['data'][0]['items'][0]['price'] === '12.30', 'order_decimal');
    verify(!str_contains(json_encode($orders), 'PRIVATE-') && !str_contains(json_encode($orders), 'private@'), 'no_pii_export');
    verify($before === $snapshot(), 'no_commercial_mutation');
    // EXPLAIN records the baseline limitation, not a made-up performance guarantee.
    $plan = $reader->query('EXPLAIN SELECT id,updated_at FROM products WHERE updated_at < UTC_TIMESTAMP() ORDER BY updated_at,id LIMIT 51')->fetchAll();
    verify(count($plan) === 1, 'explain_available');
    $admin->exec("UPDATE products SET name_en='Updated', updated_at='2026-10-20 08:00:01' WHERE id=1");
    $updated = $call('products', 'updated_from=2026-10-20T08:00:01Z');
    verify($updated['status'] === 200 && array_column($updated['body']['data'], 'id') === ['1'], 'incremental_after_update');
    $admin->exec('ALTER TABLE products MODIFY updated_at DATETIME');
    verify($call('products')['status'] === 503, 'datetime_schema_rejected');
    $admin->exec('ALTER TABLE products MODIFY updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    $admin->exec('ALTER TABLE orders DROP COLUMN payment_method');
    verify($call('orders')['status'] === 503, 'old_schema_rejected');
    $config['credentials'][0]['active'] = false;
    file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));
    verify($call('products')['status'] === 401, 'revocation_before_db');
    verify(!str_contains(json_encode($events), $password) && !str_contains(json_encode($events), $token), 'sanitized_audit');
    echo json_encode(['status' => 'PASS', 'checks' => $checks, 'database_version' => $version, 'php_version' => PHP_VERSION,
        'transport' => 'private Unix socket; no TCP listeners',
        'tables' => 'products/orders DDL extracted without seeds from local schema.sql',
        'explain_access' => $plan[0]['type'], 'explain_key' => $plan[0]['key'],
        'responses' => $responses], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Test assertion labels are safe; never print a PDO exception or configuration.
    echo json_encode(['status' => 'FAIL', 'passed_checks' => $checks,
        'check' => $error instanceof PDOException ? 'unexpected_database_error' :
            ($error instanceof RuntimeException ? $error->getMessage() : 'unexpected_test_error')]);
    exit(1);
}
