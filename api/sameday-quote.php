<?php
/** Server-side Sameday quote. Prices and package measurements come from DB. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/sameday.php';

ggs_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!ggs_sameday_enabled() || !ggs_sameday_configured()) {
  ggs_json_response(['success' => false, 'error' => 'Sameday checkout is not available'], 503);
}
$body = ggs_read_json_body();
$method = (string) ($body['shipping_method'] ?? 'home');
if (!in_array($method, ['home', 'easybox'], true)) ggs_json_response(['success' => false, 'error' => 'Invalid shipping method'], 422);
if (strtoupper((string) ($body['country'] ?? '')) !== 'RO') ggs_json_response(['success' => false, 'error' => 'Sameday quotes are available for Romania only'], 422);
if (!is_array($body['items'] ?? null) || !$body['items']) ggs_json_response(['success' => false, 'error' => 'Cart is empty'], 422);
if (count($body['items']) > 100) ggs_json_response(['success' => false, 'error' => 'Cart contains too many lines'], 422);

$db = ggs_db();
$items = [];
foreach ($body['items'] as $item) {
  $id = (int) ($item['product_id'] ?? 0);
  $qty = (int) ($item['qty'] ?? 0);
  if ($id <= 0 || $qty <= 0) ggs_json_response(['success' => false, 'error' => 'A cart line is invalid'], 422);
  $stmt = $db->prepare('SELECT id, stock, shipping_length_cm, shipping_width_cm, shipping_height_cm, shipping_weight_kg FROM products WHERE id = ? AND status = "published" LIMIT 1');
  $stmt->execute([$id]);
  $product = $stmt->fetch();
  if (!$product) ggs_json_response(['success' => false, 'error' => 'A cart product is unavailable'], 422);
  if ($qty > (int) $product['stock']) ggs_json_response(['success' => false, 'error' => 'A cart quantity exceeds available stock'], 422);
  $items[] = $product + ['qty' => $qty];
}

$order = [
  'order_number' => 'QUOTE-' . bin2hex(random_bytes(6)),
  'customer_type' => 'pf',
  'customer_name' => trim((string) ($body['customer_name'] ?? 'Checkout customer')) ?: 'Checkout customer',
  'customer_email' => trim((string) ($body['email'] ?? 'quote@example.invalid')) ?: 'quote@example.invalid',
  'customer_phone' => trim((string) ($body['phone'] ?? '')),
  'shipping_address' => [
    'street' => trim((string) ($body['street'] ?? '')),
    'city' => trim((string) ($body['city'] ?? '')),
    'county' => trim((string) ($body['county'] ?? '')),
    'postal' => trim((string) ($body['postal'] ?? '')),
    'country' => 'RO',
  ],
  'items' => $items,
];
foreach (['phone' => $order['customer_phone'], 'street' => $order['shipping_address']['street'], 'city' => $order['shipping_address']['city'], 'county' => $order['shipping_address']['county'], 'postal' => $order['shipping_address']['postal']] as $field => $value) {
  if ($value === '') ggs_json_response(['success' => false, 'error' => 'Complete the delivery ' . $field . ' before requesting a quote'], 422);
}
if (strlen($order['customer_phone']) > 40 || strlen($order['shipping_address']['street']) > 500 || strlen($order['shipping_address']['city']) > 120 || strlen($order['shipping_address']['county']) > 120 || strlen($order['shipping_address']['postal']) > 30) {
  ggs_json_response(['success' => false, 'error' => 'Delivery details are too long'], 422);
}

try {
  $quote = ggs_sameday_estimate($db, $order, $method, isset($body['shipping_ooh_id']) ? (int) $body['shipping_ooh_id'] : null);
  $rate = (float) ggs_get_setting('eur_ron_rate', '5.00');
  $amountEur = $quote['currency'] === 'RON' ? round($quote['amount'] / max($rate, 0.0001), 2) : round($quote['amount'], 2);
  ggs_json_response(['success' => true, 'amount' => $quote['amount'], 'currency' => $quote['currency'], 'amount_eur' => $amountEur, 'service_id' => $quote['service_id'], 'used_package_defaults' => !empty($quote['package']['used_defaults'])]);
} catch (Throwable $error) {
  error_log('GGS Sameday quote failed: ' . $error->getMessage());
  ggs_json_response(['success' => false, 'error' => 'Sameday could not calculate this delivery. Check the address and try again.'], 502);
}
