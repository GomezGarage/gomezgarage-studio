<?php
declare(strict_types=1);
// Entirely synthetic. Never includes config.php, vendor, mailer, payments or invoices.
require_once __DIR__ . '/../../includes/agent/bootstrap.php';

use Ggs\Agent\{Api, ApiError, Config, Dto, FileRateLimiter, Repository};
use function Ggs\Agent\{timestamp, utc};

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
}
function raises(callable $action, string $label, ?int $status = null): void
{
    try { $action(); } catch (Throwable $error) {
        check($status === null || ($error instanceof ApiError && $error->status === $status), $label);
        return;
    }
    check(false, $label);
}

// Tripwires are never loaded by the API; an accidental commercial include also fails the include allowlist below.
$externalCalls = 0;
function ggs_send_mail(...$args): void { global $externalCalls; $externalCalls++; throw new RuntimeException('Mail forbidden'); }
function ggs_revolut_create_order(...$args): void { global $externalCalls; $externalCalls++; throw new RuntimeException('Payment forbidden'); }

$now = timestamp('2026-10-21T12:00:00Z');
$dbNow = '2026-10-21 12:00:00';
$pdoClass = class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
$db = new $pdoClass('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$createFunction = method_exists($db, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
$db->$createFunction('UTC_TIMESTAMP', static function () use (&$dbNow): string { return $dbNow; }, 0);
$db->$createFunction('UTC_DATE', static function () use (&$dbNow): string { return substr($dbNow, 0, 10); }, 0);
$db->$createFunction('JSON_UNQUOTE', static fn($value) => $value, 1);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, slug TEXT, status TEXT, name_ro TEXT, name_en TEXT,
 desc_short_ro TEXT, desc_short_en TEXT, desc_long_ro TEXT, desc_long_en TEXT, how_made_ro TEXT, how_made_en TEXT,
 material TEXT, category TEXT, price_eur TEXT, images TEXT, specs TEXT, stock INTEGER, updated_at TEXT, created_at TEXT)');
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, order_number TEXT, status TEXT, payment_method TEXT,
 created_at TEXT, updated_at TEXT, subtotal_eur TEXT, shipping_eur TEXT, total_eur TEXT, items TEXT,
 shipping_address TEXT, tracking_number TEXT, customer_email TEXT, customer_name TEXT, customer_phone TEXT, customer_note TEXT)');
$db->exec('CREATE TABLE product_views_daily(product_id INTEGER NOT NULL,view_date TEXT NOT NULL,view_count INTEGER NOT NULL,
 PRIMARY KEY(product_id,view_date))');
for ($id = 1; $id <= 6; $id++) {
    $stmt = $db->prepare('INSERT INTO products VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$id, 'synthetic-' . $id, $id === 6 ? 'draft' : 'published', 'Produs sintetic', 'Synthetic product',
        null, null, 'Descriere sintetică', null, null, null, 'PLA', 'desk', '12.30', '["/synthetic.svg"]',
        '[{"key":"Weight","value":"10g","private":"not exported"}]', 2, '2026-10-21 10:00:00', '2026-10-20 08:00:00']);
}
$db->exec("INSERT INTO product_views_daily VALUES(1,'2026-10-21',8),(1,'2026-10-14',3),(2,'2026-10-20',0)");
foreach (['pending', 'paid', 'shipped'] as $index => $status) {
    $stmt = $db->prepare('INSERT INTO orders VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $email = $index === 0 ? 'repeat@example.invalid'
        : ($index === 1 ? ' Repeat@Example.invalid ' : 'single@example.invalid');
    $stmt->execute([$index + 1, 'SYNTHETIC-' . ($index + 1), $status, $index === 0 ? 'cod' : 'card',
        '2026-10-20 08:00:00', '2026-10-21 10:00:00', '24.60', '5.00', '29.60',
        '[{"product_id":1,"name":"Synthetic product","qty":2,"price_eur":12.3,"private":"discard"}]',
        '{"street":"PRIVATE-STREET","country":"RO"}', null,
        $email, 'PRIVATE-NAME', 'PRIVATE-PHONE', 'PRIVATE-NOTE']);
}
$db->exec('PRAGMA query_only = ON');
$baseline = serialize([$db->query('SELECT * FROM products')->fetchAll(), $db->query('SELECT * FROM orders')->fetchAll(),
    $db->query('SELECT * FROM product_views_daily')->fetchAll()]);
$repository = new Repository($db);
$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$config = ['enabled' => true, 'cursor_key_hex' => bin2hex(random_bytes(32)), 'credentials' => [
    ['id' => 'synthetic-service', 'token_sha256' => hash('sha256', $token), 'active' => true,
        'expires_at' => '2026-10-22T00:00:00Z', 'scopes' => Config::SCOPES]
]];
$events = $responses = [];
$repoCalls = 0;
$api = new Api(static function () use (&$config): array { return $config; },
    static function () use (&$repoCalls, $repository): Repository { $repoCalls++; return $repository; },
    static function (): void {},
    static function ($event) use (&$events): void { $events[] = $event; },
    static function () use (&$now): int { return $now; });
function request(string $route = 'products', string $query = '', string $method = 'GET', ?string $auth = null, bool $https = true): array
{
    global $api, $token, $responses;
    $response = $api->handle($route, $method, $auth ?? 'Bearer ' . $token, $query, $https);
    $responses[] = ['route' => $route, 'status' => $response['status'], 'body' => $response['body']];
    return $response;
}

check(request('health')['status'] === 200 && $repoCalls === 0, 'health is liveness without database');
foreach (['', 'Basic abc', 'Bearer ' . str_repeat('x', 43)] as $auth) {
    check(request('orders', '', 'GET', $auth)['status'] === 401, 'missing/invalid token denied');
}
foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $method) {
    $response = request('orders', '', $method);
    check($response['status'] === 405 && $response['headers']['Allow'] === 'GET', 'mutation/method denied');
}
check(request('orders', '', 'GET', null, false)['status'] === 403, 'HTTP denied');
check($repoCalls === 0, 'rejections do not connect to database');
$config['credentials'][0]['scopes'] = ['agent.health.read', 'products.read'];
check(request('orders')['status'] === 403, 'orders requires scope');
check(request('products', 'status=draft')['status'] === 403, 'draft scope required');
check(request('products', 'status=all')['status'] === 403, 'all requires draft scope');
check(count(request()['body']['data']) === 5, 'default published only');
$syntheticProduct = request()['body']['data'][0];
check($syntheticProduct['view_stats'] === ['metric' => 'product_detail_view_events', 'current_7d' => 8,
    'previous_7d' => 3, 'delta_7d' => 5, 'window' => 'current_7_utc_days_vs_previous_7_utc_days'],
    'product_view_stats_are_aggregate_utc_week_comparison');
$config['credentials'][0]['scopes'] = Config::SCOPES;
check(count(request('products', 'status=draft')['body']['data']) === 1, 'draft explicit');
foreach (['limit=0', 'limit=101', 'limit=-1', 'limit=01', 'limit=1.0', 'limit[]=1', 'limit=1&limit=2',
    'status=anything', 'sort=id', 'sql=SELECT+1', 'token=anything', 'category=desk%27', 'updated_from=2026-02-30T00:00:00Z',
    'updated_from=2026-10-21T10:00:00%2B03:00', 'updated_from=2026-10-21T10:00:00.000Z',
    'updated_from=2026-10-22T00:00:00Z&updated_before=2026-10-21T00:00:00Z', 'cursor=bad', 'limit=', 'limit',
    'created_from=2026-10-20T00:00:00Z', 'limit=10;status=draft'] as $query) {
    check(request('products', $query)['status'] === 422, 'strict query validation');
}
check(request('health', 'extra=1')['status'] === 422, 'health rejects filters');
check(count(request('orders', 'created_from=2026-10-20T08:00:00Z&created_before=2026-10-20T08:00:01Z')['body']['data']) === 3, 'inclusive/exclusive interval');
check(request('orders', 'created_before=2026-10-20T08:00:00Z')['body']['data'] === [], 'exclusive end');
$first = request('products', 'limit=2&status=all');
check($first['status'] === 200 && count($first['body']['data']) === 2, 'first page');
$cursor = $first['body']['next_cursor'];
$ids = array_column($first['body']['data'], 'id');
for ($page = 0; $cursor !== null && $page < 10; $page++) {
    $response = request('products', 'status=all&limit=2&cursor=' . urlencode($cursor));
    check($response['status'] === 200, 'cursor page succeeds');
    $ids = array_merge($ids, array_column($response['body']['data'], 'id'));
    $cursor = $response['body']['next_cursor'];
}
check($ids === ['1', '2', '3', '4', '5', '6'] && $cursor === null, 'tie-break covers equal timestamps without duplicates');
$cursor = $first['body']['next_cursor'];
check(request('products', 'limit=2&status=all&cursor=' . urlencode($cursor . 'a'))['status'] === 422, 'tampering denied');
check(request('products', 'limit=3&status=all&cursor=' . urlencode($cursor))['status'] === 422, 'filter change denied');
check(request('orders', 'limit=2&status=all&cursor=' . urlencode($cursor))['status'] === 422, 'cross-route cursor denied');
$config['credentials'][0]['id'] = 'other-service';
check(request('products', 'limit=2&status=all&cursor=' . urlencode($cursor))['status'] === 422, 'cross-identity cursor denied');
$config['credentials'][0]['id'] = 'synthetic-service';
$now += 901;
check(request('products', 'limit=2&status=all&cursor=' . urlencode($cursor))['status'] === 422, 'expired cursor denied');
$now -= 901;
$config['credentials'][0]['active'] = false;
check(request('orders')['status'] === 401, 'revocation immediate');
$config['credentials'][0]['active'] = true;
$config['credentials'][0]['expires_at'] = utc($now);
check(request('orders')['status'] === 401, 'expiry immediate');
$config['credentials'][0]['expires_at'] = '2026-10-22T00:00:00Z';
$orderResponse = request('orders');
$order = $orderResponse['body']['data'][0];
check($order['payment_method'] === 'cod' && $order['payment_state'] === 'unknown' && $order['status'] === 'pending', 'COD not receipts');
check($orderResponse['body']['data'][1]['payment_state'] === 'unknown', 'manual paid not settlement proof');
check($order['customer_language'] === null && $order['country'] === 'RO', 'language not inferred');
check($order['currency'] === 'EUR' && $order['total'] === '29.60' && $order['items'][0]['price'] === '12.30', 'decimal amounts and currency');
check($order['created_at'] === '2026-10-20T08:00:00Z', 'UTC timestamp');
check(is_string($order['customer_ref']) && preg_match('/^studio_[a-f0-9]{64}$/D', $order['customer_ref']) === 1, 'stable pseudonymous customer reference');
check($order['customer_ref'] === $orderResponse['body']['data'][1]['customer_ref'], 'normalized email identifies repeat customer');
check($order['customer_ref'] !== $orderResponse['body']['data'][2]['customer_ref'], 'distinct customer reference');
check(!str_contains(json_encode($orderResponse), 'PRIVATE-') && !str_contains(json_encode($orderResponse), 'private@'), 'PII excluded');
check(!str_contains(json_encode($orderResponse), 'discard'), 'nested DTO projection');
check(serialize([$db->query('SELECT * FROM products')->fetchAll(), $db->query('SELECT * FROM orders')->fetchAll(),
    $db->query('SELECT * FROM product_views_daily')->fetchAll()]) === $baseline, 'reads preserve every fixture field');
raises(static fn() => $db->exec('UPDATE products SET stock=0'), 'synthetic database prohibits writes');

// Concurrent update: a row not reached yet moves past the fixed cutoff, then appears in the next overlapping pass.
$db->exec('PRAGMA query_only = OFF');
$db->exec("UPDATE products SET updated_at='2026-10-21 12:00:01', name_en='Updated synthetic product' WHERE id=3");
$db->exec('PRAGMA query_only = ON');
$second = request('products', 'limit=2&status=all&cursor=' . urlencode($first['body']['next_cursor']));
check(array_column($second['body']['data'], 'id') === ['4', '5'], 'moved row excluded from fixed window');
$now += 70;
$dbNow = '2026-10-21 12:01:10';
$incremental = request('products', 'status=all&updated_from=2026-10-21T11:58:55Z');
check(array_column($incremental['body']['data'], 'id') === ['3'], 'overlapping next pass catches concurrent update');
check($incremental['body']['data'][0]['name_en'] === 'Updated synthetic product', 'latest value upsert');
$db->exec('PRAGMA query_only = OFF');
$db->exec('UPDATE products SET updated_at=NULL WHERE id=2');
$db->exec('PRAGMA query_only = ON');
check(request('products', 'updated_from=2026-10-21T11:00:00Z')['status'] === 503, 'missing timestamp cannot disappear silently');
$db->exec('PRAGMA query_only = OFF');
$db->exec("UPDATE products SET updated_at='2026-10-21 10:00:00' WHERE id=2");
$db->exec('PRAGMA query_only = ON');
foreach ([null, 'NaN', '12.345', -1, '999999999999.99'] as $amount) {
    raises(static fn() => Dto::money($amount), 'invalid amount refused');
}
$db->exec('PRAGMA query_only = OFF');
$db->exec("UPDATE products SET specs='{}' WHERE id=1");
$db->exec('PRAGMA query_only = ON');
// Empty object must not masquerade as an empty array.
check(request()['status'] === 503, 'corrupt JSON list fails closed');

$dir = sys_get_temp_dir() . '/ggs-agent-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    $limiter = new FileRateLimiter($dir);
    for ($i = 0; $i < 60; $i++) { $limiter('fixture', $now); }
    raises(static fn() => $limiter('fixture', $now), 'rate limit enforced', 429);
    $limiter('fixture', $now + 60);
    $limiter('second-fixture', $now);
    $config['rate_limit_dir'] = $dir;
    $path = $dir . '/config.json';
    file_put_contents($path, json_encode($config));
    chmod($path, 0600);
    check(Config::load($path, dirname(__DIR__, 2))['credentials'][0]['id'] === 'synthetic-service', 'private config loads');
    $webRoot = $dir . '/public_html';
    mkdir($webRoot, 0700);
    check(Config::defaultPath($webRoot) === dirname(realpath($webRoot)) . '/private/studio-agent/config.json', 'cPanel config is outside active document root');
    rmdir($webRoot);
    raises(static fn() => Config::load($path, $dir), 'config in web root rejected');
    chmod($path, 0644);
    raises(static fn() => Config::load($path, dirname(__DIR__, 2)), 'world readable config rejected');
    chmod($path, 0600);
    $config['credentials'][0]['active'] = false;
    file_put_contents($path, json_encode($config));
    check(Config::load($path, dirname(__DIR__, 2))['credentials'][0]['active'] === false, 'configuration reread for revocation');
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
check(!str_contains(json_encode($events), $token) && !str_contains(json_encode($events), 'PRIVATE-'), 'audit excludes token/PII');
check($externalCalls === 0, 'no commercial calls');
foreach (get_included_files() as $file) {
    check(str_starts_with($file, __DIR__ . '/') || str_starts_with($file, realpath(__DIR__ . '/../../includes/agent') . '/'), 'include isolation');
}

// Failure paths must never expose driver, configuration or audit error details.
$config['credentials'][0]['active'] = true;
$failureConfig = static function () use (&$config): array { return $config; };
$failureAudit = static function ($event) use (&$events): void { $events[] = $event; };
$api = new Api($failureConfig, static function () use ($token): void { throw new RuntimeException('PRIVATE-DB ' . $token); },
    static function (): void {}, $failureAudit, static function () use (&$now): int { return $now; });
$failure = request('orders');
check($failure['status'] === 503 && !str_contains(json_encode($failure), $token) && !str_contains(json_encode($failure), 'PRIVATE-DB'), 'driver errors sanitized');
$api = new Api($failureConfig, static fn() => $repository,
    static function (): void { throw new ApiError(429, 'rate_limited', 'Request limit reached. Retry later.'); },
    $failureAudit, static function () use (&$now): int { return $now; });
check(request('health')['status'] === 429, 'rate limit response contract');
$api = new Api($failureConfig, static fn() => $repository, static function (): void {},
    static function (): void { throw new RuntimeException('PRIVATE-AUDIT'); },
    static function () use (&$now): int { return $now; });
$failure = request('health');
check($failure['status'] === 503 && !str_contains(json_encode($failure), 'PRIVATE-AUDIT'), 'audit failure withholds data');

// An offline Romanian report proof, no delivery or consumer installation.
$totalCents = array_sum(array_map(static function (array $order): int {
    [$whole, $fraction] = explode('.', $order['total']);
    return (int) $whole * 100 + (int) $fraction;
}, $orderResponse['body']['data']));
$report = 'Sursă: studio. Ultima sincronizare: ' . $orderResponse['body']['generated_at']
    . '. 3 comenzi sintetice; valoarea comenzilor: ' . intdiv($totalCents, 100) . ',' . sprintf('%02d', $totalCents % 100)
    . ' EUR. Încasări: necunoscute. Limba clientului: neînregistrată.';
check(str_contains($report, '88,80 EUR'), 'Romanian report from API DTOs');
if (isset($argv[1])) {
    // Optional temporary export: synthetic DTOs and cursors, never credential/key values.
    file_put_contents($argv[1], json_encode($responses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
echo "PASS: $checks checks; SQLite synthetic queries (not MySQL integration).\n$report\n";
