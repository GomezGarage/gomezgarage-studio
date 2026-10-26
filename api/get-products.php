<?php
/**
 * GET /api/get-products.php
 * Public product feed for the frontend. Only ever returns published
 * products — draft status can never be requested by a client.
 *
 * Query params (all optional, combinable):
 *   id        int    — single product by id
 *   slug      string — single product by slug
 *   featured  0|1    — only featured products
 *   category  string — desk|display|automotive|other|home
 */

require_once __DIR__ . '/../includes/helpers.php';

ggs_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$where = ['status = ?'];
$params = ['published'];

if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
  $where[] = 'id = ?';
  $params[] = (int) $_GET['id'];
}

if (!empty($_GET['slug'])) {
  $where[] = 'slug = ?';
  $params[] = $_GET['slug'];
}

if (isset($_GET['featured']) && $_GET['featured'] === '1') {
  $where[] = 'featured = 1';
}

$allowedCategories = ['desk', 'display', 'automotive', 'other', 'home'];
if (!empty($_GET['category']) && in_array($_GET['category'], $allowedCategories, true)) {
  $where[] = 'category = ?';
  $params[] = $_GET['category'];
}

$sql = 'SELECT id, slug, name_en, name_ro, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro,
               how_made_en, how_made_ro, material, category, price_eur, stock, stock_threshold,
               images, specs, featured, created_at
        FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';

$stmt = ggs_db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$products = array_map(function ($row) {
  $row['price_eur'] = (float) $row['price_eur'];
  $row['stock'] = (int) $row['stock'];
  $row['stock_threshold'] = (int) $row['stock_threshold'];
  $row['featured'] = (bool) $row['featured'];
  $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
  $row['specs'] = json_decode($row['specs'] ?? '[]', true) ?: [];
  return $row;
}, $rows);

ggs_json_response(['success' => true, 'products' => $products]);
