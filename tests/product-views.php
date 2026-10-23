<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/product-views.php';

$checks = 0;
function verify(bool $condition, string $name): void
{
  global $checks;
  $checks++;
  if (!$condition) {
    throw new RuntimeException('FAILED: ' . $name);
  }
}

function rejects(callable $action, int $status, string $name): void
{
  try {
    $action();
  } catch (GgsProductViewRequestError $error) {
    verify($error->httpStatus === $status, $name);
    return;
  }
  verify(false, $name);
}

$origin = 'https://gomezgarage.eu';
$server = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'application/json'];
verify(ggs_parse_product_view_request($server, '{"product_id":1}', $origin) === 1, 'strict_first_party_request');
rejects(static fn() => ggs_parse_product_view_request(['REQUEST_METHOD' => 'GET'], '', $origin), 405, 'post_only');
rejects(static fn() => ggs_parse_product_view_request(array_replace($server, ['HTTP_ORIGIN' => 'https://evil.invalid']), '{"product_id":1}', $origin), 403, 'foreign_origin_rejected');
rejects(static fn() => ggs_parse_product_view_request(array_replace($server, ['CONTENT_TYPE' => 'text/plain']), '{"product_id":1}', $origin), 415, 'content_type_required');
rejects(static fn() => ggs_parse_product_view_request($server, '{"product_id":1,"ip":"must-not-be-accepted"}', $origin), 400, 'extra_fields_rejected');
rejects(static fn() => ggs_parse_product_view_request($server, '{"product_id":"1"}', $origin), 400, 'id_must_be_integer');
rejects(static fn() => ggs_parse_product_view_request($server, str_repeat('x', 257), $origin), 400, 'body_size_bounded');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,status TEXT NOT NULL);
  INSERT INTO products VALUES (1,'published'),(2,'draft');
  CREATE TABLE product_views_daily(product_id INTEGER NOT NULL,view_date TEXT NOT NULL,view_count INTEGER NOT NULL,
    PRIMARY KEY(product_id,view_date));");
verify(ggs_record_product_view($db, 1, '2026-10-24'), 'published_product_counted');
verify(ggs_record_product_view($db, 1, '2026-10-24'), 'repeated_page_load_counted');
verify(!ggs_record_product_view($db, 2, '2026-10-24'), 'draft_product_not_counted');
verify(!ggs_record_product_view($db, 3, '2026-10-24'), 'unknown_product_not_counted');
verify($db->query('SELECT view_count FROM product_views_daily WHERE product_id=1 AND view_date="2026-10-24"')->fetchColumn() === 2,
  'daily_aggregate_only');
rejects(static fn() => ggs_parse_product_view_request($server, '[]', $origin), 400, 'json_array_rejected');
try {
  ggs_record_product_view($db, 1, '2026-02-30');
  verify(false, 'invalid_date_rejected');
} catch (InvalidArgumentException) {
  verify(true, 'invalid_date_rejected');
}

echo json_encode(['status' => 'PASS', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
