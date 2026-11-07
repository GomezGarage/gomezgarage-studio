<?php
/** Additional fixture for the disposable tests/agent/mysql_smoke.py runner. */
require_once __DIR__ . '/../../includes/agent/bootstrap.php';
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$private = realpath($input['private_dir']);
$socket = realpath($input['socket']);
if (!$private || !preg_match('~/ggs-mysql-[a-zA-Z0-9_-]+$~D', $private)
    || $socket !== $private . '/mysql.sock' || realpath($input['datadir']) !== $private . '/data'
    || (fileperms($private) & 0077) !== 0) throw new RuntimeException('Not an isolated fixture.');
$db = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_STRINGIFY_FETCHES => true,
]);
if (realpath($db->query('SELECT @@datadir')->fetchColumn()) !== $private . '/data'
    || $db->query('SELECT @@skip_networking')->fetchColumn() !== '1') throw new RuntimeException('Not a disposable server.');
$root = dirname(__DIR__, 2);
$db->exec('CREATE DATABASE ggs_catalog_fixture CHARACTER SET utf8mb4');
$db->exec('USE ggs_catalog_fixture');
$db->exec(file_get_contents($root . '/database/schema.sql'));
$checks = 0;
function dbCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
dbCheck((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 6, 'fresh_schema_real_catalog');
dbCheck((int) $db->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn() === 0, 'fresh_schema_drafts_only');
// Simulate an existing installation with the old category enum and a paid order.
$db->exec('DELETE FROM products');
$db->exec("ALTER TABLE products MODIFY category ENUM('desk','display','automotive','other')");
$db->exec("INSERT INTO products (slug,name_en,category,price_eur,stock,status,featured) VALUES ('old-demo','Synthetic old listing','automotive',10,4,'published',1)");
$oldId = (int) $db->lastInsertId();
$order = $db->prepare("INSERT INTO orders (order_number,status,items,total_eur) VALUES ('SYNTHETIC-CATALOG','paid',?,10)");
$items = json_encode([['product_id' => $oldId, 'name' => 'Synthetic old listing', 'price_eur' => 10, 'qty' => 1]]);
$order->execute([$items]);
$run = static function () use ($db, $root): void {
    $db->exec(file_get_contents($root . '/database/migrations/20261026_home_category.sql'));
    $db->exec(file_get_contents($root . '/database/migrations/20261026_initial_catalog.sql'));
};
$run();
dbCheck((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 7, 'import_does_not_delete_identifiers');
dbCheck($db->query("SELECT status FROM products WHERE slug='old-demo'")->fetchColumn() === 'draft', 'old_public_catalog_archived');
dbCheck((int) $db->query("SELECT stock FROM products WHERE slug='old-demo'")->fetchColumn() === 4, 'old_stock_preserved');
dbCheck(json_decode($db->query("SELECT items FROM orders WHERE order_number='SYNTHETIC-CATALOG'")->fetchColumn(), true) === json_decode($items, true), 'paid_order_snapshot_preserved');
dbCheck((int) $db->query("SELECT COUNT(*) FROM products WHERE category='home'")->fetchColumn() === 2, 'two_home_listings');
dbCheck((int) $db->query("SELECT stock FROM products WHERE slug='tealight-holder-set-rainbow-one-of-a-kind'")->fetchColumn() === 1, 'rainbow_stock_one');
$source = json_decode(file_get_contents($root . '/database/catalog-products.json'), true);
foreach ($source as $expected) {
    $stmt = $db->prepare('SELECT *, 0 AS views_current_7d, 0 AS views_previous_7d FROM products WHERE slug=?');
    $stmt->execute([$expected['slug']]); $row = $stmt->fetch();
    dbCheck($row['desc_long_en'] === $expected['desc_long_en'] && $row['desc_long_ro'] === $expected['desc_long_ro'], 'copy_round_trip_' . $expected['slug']);
    dbCheck($row['material'] === $expected['material'] && $row['category'] === $expected['category'], 'material_category_' . $expected['slug']);
    \Ggs\Agent\Dto::product($row);
    dbCheck(true, 'service_reads_unpriced_draft_' . $expected['slug']);
}
$db->exec("UPDATE products SET status='published', price_eur=42.50, stock=9, images=JSON_ARRAY('/studio/assets/images/products/synthetic.webp') WHERE slug='media-dock-organizer'");
$db->exec("INSERT INTO products (slug,name_en,category,price_eur,stock,status) VALUES ('later-product','Synthetic later product','home',12,2,'published')");
$run();
$row = $db->query("SELECT * FROM products WHERE slug='media-dock-organizer'")->fetch();
dbCheck($row['status'] === 'published' && $row['price_eur'] === '42.50' && (int) $row['stock'] === 9 && str_contains($row['images'], 'synthetic.webp'), 'replay_preserves_admin_edits');
dbCheck($db->query("SELECT status FROM products WHERE slug='later-product'")->fetchColumn() === 'published', 'replay_preserves_future_catalog');
dbCheck((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 8, 'no_duplicates');
// Readable strings must also round-trip when backslash escaping is disabled.
$db->exec("SET SESSION sql_mode = CONCAT_WS(',', @@sql_mode, 'NO_BACKSLASH_ESCAPES')");
$db->exec('CREATE DATABASE ggs_catalog_no_backslash_fixture CHARACTER SET utf8mb4');
$db->exec('USE ggs_catalog_no_backslash_fixture');
$db->exec(file_get_contents($root . '/database/schema.sql'));
foreach ($source as $expected) {
    $stmt = $db->prepare('SELECT * FROM products WHERE slug=?');
    $stmt->execute([$expected['slug']]); $row = $stmt->fetch();
    dbCheck($row['desc_long_en'] === $expected['desc_long_en'] && $row['desc_long_ro'] === $expected['desc_long_ro']
        && json_decode($row['specs'], true) === $expected['specs'], 'readable_sql_no_backslash_' . $expected['slug']);
}
// Current owner-authorized reset: published guard, all seven drafts, metadata and replay.
require_once $root . '/includes/product-content.php';
$db->exec('USE ggs_catalog_fixture');
$reset = static function () use ($db, $root): void {
    $stmt = $db->query(file_get_contents($root . '/database/migrations/20261107_catalog_reset.sql'));
    do { $stmt->fetchAll(); } while ($stmt->nextRowset());
    $stmt->closeCursor();
};
$reset();
dbCheck((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 8, 'reset_refuses_published_catalog');
$db->exec("UPDATE products SET status='draft'");
$reset();
dbCheck((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 7, 'reset_seven_products');
dbCheck((int) $db->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn() === 0, 'reset_all_draft');
dbCheck((int) $db->query('SELECT MIN(id) FROM products')->fetchColumn() > $oldId, 'reset_does_not_reuse_historical_ids');
dbCheck($db->query("SELECT items FROM orders WHERE order_number='SYNTHETIC-CATALOG'")->fetchColumn() === $items, 'reset_keeps_order_snapshots');
$current = json_decode(file_get_contents($root . '/database/catalog-products-20261107.json'), true);
foreach ($current as $expected) {
    $q = $db->prepare('SELECT * FROM products WHERE slug=?'); $q->execute([$expected['slug']]);
    $p = ggs_with_product_content($db, [$q->fetch()])[0];
    dbCheck($p['desc_long_en'] === $expected['desc_long_en'] && $p['desc_long_ro'] === $expected['desc_long_ro'], 'new_bilingual_copy_' . $expected['slug']);
    dbCheck($p['sku'] === $expected['presentation']['sku'], 'new_sku_' . $expected['slug']);
    dbCheck((float) $p['price_eur'] === (float) $expected['price_eur'], 'new_price_' . $expected['slug']);
}
$id = (int) $db->query("SELECT id FROM products WHERE slug='media-dock-organizer'")->fetchColumn();
ggs_save_product_content($db, $id, array_merge(ggs_content_defaults(), ['sku'=>'ADMIN-EDIT','videos'=>[['src'=>'/studio/assets/videos/products/' . str_repeat('a',32) . '.mp4']]]));
$db->exec("UPDATE products SET price_eur=99, stock=9 WHERE id=$id");
$reset();
$p = ggs_with_product_content($db, [$db->query("SELECT * FROM products WHERE id=$id")->fetch()])[0];
dbCheck($p['sku'] === 'ADMIN-EDIT' && count($p['videos']) === 1 && (float)$p['price_eur'] === 99.0 && (int)$p['stock'] === 9, 'reset_replay_keeps_admin_edits_and_media');
require __DIR__ . '/admin-http.php';
testProductAdmin($db, $root, $private, $socket);
echo json_encode(['catalog_checks' => $checks, 'status' => 'PASS']) . "\n";
