<?php
/** Server-side Eurosender options. Product prices and measurements come from DB. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/product-content.php';
require_once __DIR__ . '/../includes/eurosender.php';

ggs_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!ggs_eurosender_checkout_enabled()) {
  ggs_json_response(['success' => false, 'error' => 'Eurosender checkout is not available in this environment'], 503);
}

$body = ggs_read_json_body();
$country = strtoupper(trim((string) ($body['country'] ?? '')));
if (!isset(ggs_eurosender_eu_countries()[$country])) ggs_json_response(['success' => false, 'error' => 'We only ship within the European Union'], 422);
if (!is_array($body['items'] ?? null) || !$body['items'] || count($body['items']) > 100) {
  ggs_json_response(['success' => false, 'error' => 'Cart is empty or invalid'], 422);
}

$delivery = [
  'country' => $country,
  'region' => trim((string) ($body['county'] ?? '')),
  'city' => trim((string) ($body['city'] ?? '')),
  'zip' => trim((string) ($body['postal'] ?? '')),
  'street' => trim((string) ($body['street'] ?? '')),
];
foreach (['street', 'city', 'zip'] as $field) {
  if ($delivery[$field] === '') ggs_json_response(['success' => false, 'error' => 'Complete the delivery address before requesting a quote'], 422);
}
if ($country === 'RO' && $delivery['region'] === '') ggs_json_response(['success' => false, 'error' => 'County is required for Romanian delivery'], 422);

$db = ggs_db();
$items = [];
$value = 0.0;
foreach ($body['items'] as $line) {
  $id = (int) ($line['product_id'] ?? 0);
  $qty = (int) ($line['qty'] ?? 0);
  if ($id <= 0 || $qty <= 0) ggs_json_response(['success' => false, 'error' => 'A cart line is invalid'], 422);
  $stmt = $db->prepare('SELECT id, name_en, price_eur, stock, shipping_length_cm, shipping_width_cm, shipping_height_cm, shipping_weight_kg FROM products WHERE id = ? AND status = "published" LIMIT 1');
  $stmt->execute([$id]);
  $product = $stmt->fetch();
  if (!$product || $qty > (int) $product['stock']) ggs_json_response(['success' => false, 'error' => 'A cart product is unavailable'], 422);
  $product = ggs_with_product_content($db, [$product])[0];
  $items[] = $product + ['name' => (string) $product['name_en'], 'qty' => $qty];
  $value += (float) $product['price_eur'] * $qty;
}

try {
  $package = ggs_eurosender_package_from_items($items, $value);
  $quoted = ggs_eurosender_quote_options($delivery, $package);
  $options = array_map(static fn(array $option): array => [
    'service_type' => $option['name'],
    'service_subtype' => $option['subtype'],
    'courier_id' => $option['courier_id'],
    'label' => $option['label'],
    'amount_eur' => $option['amount'],
    'currency' => $option['currency'],
    'eta' => $option['estimated_delivery_time'],
  ], array_values(array_filter($quoted['options'], static fn(array $option): bool => in_array($option['name'], ['selection', 'flexi', 'regular_plus', 'express'], true) && $option['currency'] === 'EUR')));
  if (!$options) throw new RuntimeException('No parcel delivery option is available for this address.');
  ggs_json_response(['success' => true, 'options' => $options, 'used_package_defaults' => $package['used_defaults']]);
} catch (Throwable $error) {
  error_log('GGS Eurosender checkout quote failed: ' . $error->getMessage());
  ggs_json_response(['success' => false, 'error' => 'Eurosender could not calculate this delivery. Check the exact address and try again.'], 502);
}
