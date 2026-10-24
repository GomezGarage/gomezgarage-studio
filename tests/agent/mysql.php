<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/agent/bootstrap.php';

use Ggs\Agent\{Api, Config, ProductDraftRepository, Repository};

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
    foreach (['products', 'orders', 'product_views_daily'] as $table) {
        verify(preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $schema, $match) === 1, 'source_ddl_' . $table);
        $admin->exec($match[0]);
    }
    $draftMigration = file_get_contents(__DIR__ . '/../../database/migrations/20261024_agent_product_draft_requests.sql');
    $admin->exec($draftMigration);
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
    $admin->exec("INSERT INTO product_views_daily(product_id,view_date,view_count) VALUES
        (1,UTC_DATE(),8),(1,DATE_SUB(UTC_DATE(),INTERVAL 7 DAY),3)");
    $admin->exec("SET time_zone = '+00:00'");
    $password = bin2hex(random_bytes(32));
    $writerPassword = bin2hex(random_bytes(32));
    $admin->exec("CREATE USER 'ggs_fixture_reader'@'localhost' IDENTIFIED BY " . $admin->quote($password));
    $admin->exec("CREATE USER 'ggs_fixture_writer'@'localhost' IDENTIFIED BY " . $admin->quote($writerPassword));
    $productColumns = 'id,slug,status,name_ro,name_en,desc_short_ro,desc_short_en,desc_long_ro,desc_long_en,how_made_ro,how_made_en,material,category,price_eur,images,specs,stock,updated_at,created_at';
    $orderColumns = 'id,order_number,status,payment_method,created_at,updated_at,subtotal_eur,shipping_eur,total_eur,items,shipping_address,tracking_number,customer_email';
    $admin->exec("GRANT SELECT ($productColumns) ON ggs_agent_fixture.products TO 'ggs_fixture_reader'@'localhost'");
    $admin->exec("GRANT SELECT ($orderColumns) ON ggs_agent_fixture.orders TO 'ggs_fixture_reader'@'localhost'");
    $admin->exec("GRANT SELECT (product_id,view_date,view_count) ON ggs_agent_fixture.product_views_daily TO 'ggs_fixture_reader'@'localhost'");
    $draftReadColumns = 'id,slug,status,name_ro,name_en,desc_short_ro,desc_short_en,desc_long_ro,desc_long_en,how_made_ro,how_made_en,material,category,price_eur,images,specs,updated_at';
    $draftWriteColumns = 'slug,name_en,name_ro,desc_short_en,desc_short_ro,desc_long_en,desc_long_ro,how_made_en,how_made_ro,material,category,price_eur,images,specs';
    $admin->exec("GRANT SELECT ($draftReadColumns) ON ggs_agent_fixture.products TO 'ggs_fixture_writer'@'localhost'");
    $admin->exec("GRANT INSERT ($draftWriteColumns) ON ggs_agent_fixture.products TO 'ggs_fixture_writer'@'localhost'");
    $admin->exec("GRANT UPDATE ($draftWriteColumns) ON ggs_agent_fixture.products TO 'ggs_fixture_writer'@'localhost'");
    $admin->exec("GRANT SELECT (idempotency_hash,request_hash,response_json) ON ggs_agent_fixture.agent_product_draft_requests TO 'ggs_fixture_writer'@'localhost'");
    $admin->exec("GRANT INSERT (idempotency_hash,request_hash,response_json) ON ggs_agent_fixture.agent_product_draft_requests TO 'ggs_fixture_writer'@'localhost'");
    mkdir($private . '/rate', 0700);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $writerToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $config = ['cursor_key_hex' => bin2hex(random_bytes(32)), 'rate_limit_dir' => $private . '/rate',
        'timestamp_storage_verified' => true,
        'database' => ['engine' => $maria ? 'mariadb' : 'mysql', 'host' => 'localhost', 'name' => 'ggs_agent_fixture', 'user' => 'ggs_fixture_reader', 'password' => $password],
        'write_database' => ['engine' => $maria ? 'mariadb' : 'mysql', 'host' => 'localhost', 'name' => 'ggs_agent_fixture', 'user' => 'ggs_fixture_writer', 'password' => $writerPassword],
        'credentials' => [
            ['id' => 'synthetic-reader', 'active' => true, 'token_sha256' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'scopes' => ['agent.health.read','products.read','products.drafts.read','orders.read']],
            ['id' => 'synthetic-writer', 'active' => true, 'token_sha256' => hash('sha256', $writerToken),
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'scopes' => ['products.read','products.drafts.read','products.drafts.write']]
        ]];
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
    denied(static fn() => $reader->exec('UPDATE product_views_daily SET view_count=0'), 'no_view_dml');
    denied(static fn() => $reader->exec('CREATE TABLE forbidden (id INT)'), 'no_ddl');
    denied(static fn() => $reader->query('SELECT customer_phone FROM orders'), 'no_customer_phone');
    denied(static fn() => $reader->query('SELECT customer_name FROM orders'), 'no_customer_name');
    denied(static fn() => $reader->query('SELECT * FROM orders'), 'no_select_star');
    denied(static fn() => $reader->query('SELECT password_hash FROM admin_users'), 'no_admin');
    $writerRepository = ProductDraftRepository::connect($configuration());
    $writerProperty = new ReflectionProperty(ProductDraftRepository::class, 'db');
    if (PHP_VERSION_ID < 80100) { $writerProperty->setAccessible(true); }
    $writerDb = $writerProperty->getValue($writerRepository);
    verify($writerDb->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'writer_utc_session');
    denied(static fn() => $writerDb->query('SELECT stock FROM products'), 'writer_cannot_read_stock');
    denied(static fn() => $writerDb->exec("UPDATE products SET status='published' WHERE id=5"), 'writer_cannot_publish_column');
    denied(static fn() => $writerDb->exec('UPDATE products SET stock=999 WHERE id=5'), 'writer_cannot_update_stock');
    denied(static fn() => $writerDb->exec('UPDATE products SET featured=1 WHERE id=5'), 'writer_cannot_update_featured');
    denied(static fn() => $writerDb->exec('DELETE FROM products WHERE id=5'), 'writer_cannot_delete_product');
    denied(static fn() => $writerDb->exec("INSERT INTO orders (order_number) VALUES ('FORBIDDEN')"), 'writer_cannot_write_orders');
    denied(static fn() => $writerDb->exec('UPDATE agent_product_draft_requests SET request_hash=request_hash'), 'writer_cannot_change_idempotency_ledger');
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
    $snapshot = static fn() => serialize([$admin->query('SELECT * FROM products ORDER BY id')->fetchAll(), $admin->query('SELECT * FROM orders ORDER BY id')->fetchAll(), $admin->query('SELECT * FROM product_views_daily ORDER BY product_id,view_date')->fetchAll()]);
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
    verify($first['body']['data'][0]['view_stats']['current_7d'] === 8
        && $first['body']['data'][0]['view_stats']['previous_7d'] === 3
        && $first['body']['data'][0]['view_stats']['delta_7d'] === 5, 'mysql_utc_product_view_aggregate');
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
    $draftEvents = [];
    $draftApi = new Api($configuration, [Repository::class, 'connect'], static function (): void {},
        static function ($event) use (&$draftEvents): void { $draftEvents[] = $event; }, null,
        [ProductDraftRepository::class, 'connect']);
    $draftCall = static function (string $method, string $bearer, array $body, string $key) use ($draftApi, &$responses): array {
        $result = $draftApi->handle('product-drafts', $method, 'Bearer ' . $bearer, '', true,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $key, 'application/json');
        if (in_array($method, ['POST', 'PATCH'], true)) {
            $responses[] = ['route' => 'product-drafts', 'method' => strtolower($method), 'status' => $result['status'], 'body' => $result['body']];
        }
        return $result;
    };
    $draftContent = ['slug' => 'mysql-synthetic-draft', 'name_en' => 'MySQL synthetic draft', 'name_ro' => 'Draft MySQL sintetic',
        'category' => 'display', 'price_eur' => '19.95', 'images' => [], 'specs' => [['key' => 'Material', 'value' => 'PLA']]];
    $readTokenWrite = $draftCall('POST', $token, $draftContent, 'mysql-draft-read-token-001');
    verify($readTokenWrite['status'] === 403, 'read_token_cannot_write_draft');
    verify($draftCall('POST', $writerToken, $draftContent, 'mysql-draft-create-key-001')['status'] === 201, 'mariadb_mysql_draft_insert');
    $created = end($responses)['body']['data'];
    verify($created['status'] === 'draft' && preg_match('/^[a-f0-9]{64}$/D', $created['revision']) === 1,
        'writer_response_is_draft_with_revision');
    verify($draftCall('POST', $writerToken, $draftContent, 'mysql-draft-create-key-001')['status'] === 200
        && end($responses)['body']['data']['replayed'] === true, 'mariadb_mysql_idempotent_replay');
    $storedDraft = $admin->query("SELECT status,stock,featured FROM products WHERE id=" . (int) $created['product_id'])->fetch();
    verify($storedDraft['status'] === 'draft' && (int) $storedDraft['stock'] === 0 && (int) $storedDraft['featured'] === 0,
        'mariadb_mysql_draft_safe_defaults');
    $update = ['id' => $created['product_id'], 'expected_revision' => $created['revision'], 'name_en' => 'MySQL revised draft'];
    verify($draftCall('PATCH', $writerToken, $update, 'mysql-draft-update-key-001')['status'] === 200,
        'mariadb_mysql_draft_update');
    $updatedDraft = end($responses)['body']['data'];
    verify($updatedDraft['revision'] !== $created['revision'], 'mariadb_mysql_revision_changes');
    verify($draftCall('PATCH', $writerToken, $update, 'mysql-draft-stale-key-001')['status'] === 409,
        'mariadb_mysql_stale_revision_rejected');
    $published = $first['body']['data'][0];
    verify($published['status'] === 'published' && $draftCall('PATCH', $writerToken,
        ['id' => $published['id'], 'expected_revision' => $published['revision'], 'name_en' => 'Forbidden'],
        'mysql-draft-published-key-001')['status'] === 404, 'mariadb_mysql_published_row_not_editable');
    verify($draftCall('POST', $writerToken, $draftContent, 'mysql-draft-idempotency-conflict-001')['status'] === 409,
        'mariadb_mysql_duplicate_slug_conflict');
    verify($draftCall('GET', $writerToken, $draftContent, 'mysql-draft-method-key-001')['status'] === 405,
        'mariadb_mysql_draft_method_allowlist');
    verify($draftCall('GET', $writerToken, $draftContent, 'mysql-draft-method-key-001')['headers']['Allow'] === 'POST, PATCH',
        'mariadb_mysql_draft_allow_header');
    verify(!str_contains(json_encode($draftEvents), $writerToken) && !str_contains(json_encode($draftEvents), 'MySQL synthetic draft'),
        'mariadb_mysql_draft_audit_redaction');
    verify(!str_contains(json_encode($responses), 'MySQL synthetic draft'), 'mariadb_mysql_draft_response_does_not_echo_body');
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
        'tables' => 'products/orders/product_views DDL plus draft request ledger from isolated migrations',
        'explain_access' => $plan[0]['type'], 'explain_key' => $plan[0]['key'],
        'responses' => $responses], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Test assertion labels are safe; never print a PDO exception or configuration.
    echo json_encode(['status' => 'FAIL', 'passed_checks' => $checks,
        'check' => $error instanceof PDOException ? 'unexpected_database_error' :
            ($error instanceof RuntimeException ? $error->getMessage() : 'unexpected_test_error')]);
    exit(1);
}
