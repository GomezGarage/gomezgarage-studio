<?php
/**
 * POST /api/create-order.php
 * ARCHITECTURE.md section 6.3, step 2:
 *   - validate customer + cart data
 *   - save order in MySQL with status 'pending'
 *   - Online payment (payment_method 'card'): create the matching order via
 *     the Revolut Merchant API and return the public_id so the client can
 *     mount the embedded Checkout Widget.
 *   - Cash on Delivery (payment_method 'cod'): no payment gateway involved —
 *     the order is confirmed at placement, so stock is decremented and the
 *     confirmation/notification emails go out immediately instead of
 *     waiting on api/webhook.php.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

ggs_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = ggs_read_json_body();

$required = ['first_name', 'last_name', 'email', 'street', 'city', 'postal', 'country', 'items'];
foreach ($required as $field) {
  if (empty($body[$field])) {
    ggs_json_response(['success' => false, 'error' => "Missing field: {$field}"], 422);
  }
}

if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
  ggs_json_response(['success' => false, 'error' => 'Invalid email address'], 422);
}

if (!is_array($body['items']) || count($body['items']) === 0) {
  ggs_json_response(['success' => false, 'error' => 'Cart is empty'], 422);
}

$db = ggs_db();

// Re-price every line item from the database — never trust client-sent prices.
$orderItems = [];
$subtotalEur = 0.0;

foreach ($body['items'] as $item) {
  if (empty($item['product_id']) || empty($item['qty'])) {
    ggs_json_response(['success' => false, 'error' => 'Malformed cart item'], 422);
  }

  $stmt = $db->prepare('SELECT id, name_en, price_eur, stock FROM products WHERE id = ? AND status = ?');
  $stmt->execute([(int) $item['product_id'], 'published']);
  $product = $stmt->fetch();

  if (!$product) {
    ggs_json_response(['success' => false, 'error' => 'Product unavailable: ' . $item['product_id']], 422);
  }

  $qty = max(1, (int) $item['qty']);
  if ($product['stock'] < $qty) {
    ggs_json_response(['success' => false, 'error' => "Not enough stock for {$product['name_en']}"], 422);
  }

  $orderItems[] = [
    'product_id' => (int) $product['id'],
    'name' => $product['name_en'],
    'price_eur' => (float) $product['price_eur'],
    'qty' => $qty,
  ];
  $subtotalEur += (float) $product['price_eur'] * $qty;
}

// Shipping — Romania / EU / UK, rates from admin settings (section 5.5 / 6.3).
$euCountries = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
$country = strtoupper(substr((string) $body['country'], 0, 2));

if ($country === 'RO') {
  $shippingEur = (float) ggs_get_setting('shipping_ro_eur', '5.00');
} elseif ($country === 'GB') {
  $shippingEur = (float) ggs_get_setting('shipping_uk_eur', '18.00');
} elseif (in_array($country, $euCountries, true)) {
  $shippingEur = (float) ggs_get_setting('shipping_eu_eur', '12.00');
} else {
  ggs_json_response(['success' => false, 'error' => 'We only ship within the EU and UK'], 422);
}

$totalEur = round($subtotalEur + $shippingEur, 2);
$orderNumber = ggs_generate_order_number();
$paymentMethod = (($body['payment_method'] ?? '') === 'cod') ? 'cod' : 'card';

$shippingAddress = [
  'street' => $body['street'],
  'city' => $body['city'],
  'postal' => $body['postal'],
  'country' => $country,
];

$stmt = $db->prepare(
  'INSERT INTO orders
    (order_number, status, payment_method, customer_name, customer_email, customer_phone, shipping_address, customer_note, items, subtotal_eur, shipping_eur, total_eur)
   VALUES (?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
  $orderNumber,
  $paymentMethod,
  trim($body['first_name'] . ' ' . $body['last_name']),
  $body['email'],
  $body['phone'] ?? '',
  json_encode($shippingAddress),
  $body['note'] ?? '',
  json_encode($orderItems),
  $subtotalEur,
  $shippingEur,
  $totalEur,
]);
$orderId = (int) $db->lastInsertId();

if ($paymentMethod === 'cod') {
  // No payment gateway in the loop — the order is confirmed the moment it's
  // placed, so reserve stock and notify both sides right away.
  foreach ($orderItems as $item) {
    $db->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?')
      ->execute([$item['qty'], $item['product_id']]);

    $productStmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $productStmt->execute([$item['product_id']]);
    $product = $productStmt->fetch();
    if ($product && $product['stock'] <= $product['stock_threshold']) {
      ggs_notify_low_stock($product);
    }
  }

  $orderRow = [
    'id' => $orderId,
    'order_number' => $orderNumber,
    'customer_name' => trim($body['first_name'] . ' ' . $body['last_name']),
    'customer_email' => $body['email'],
    'shipping_address' => json_encode($shippingAddress),
    'items' => json_encode($orderItems),
    'subtotal_eur' => $subtotalEur,
    'shipping_eur' => $shippingEur,
    'total_eur' => $totalEur,
  ];
  ggs_notify_new_order($orderRow);
  ggs_send_order_confirmation($orderRow);

  ggs_json_response(['success' => true, 'order_number' => $orderNumber]);
}

// Charge in whatever currency the customer was actually shown on the
// checkout page (the order summary), not always EUR — otherwise the
// payment popup/widget shows a different amount than what they agreed to,
// which is exactly the mismatch we saw in testing (220 RON on the page vs
// €44.00 in the widget). subtotal/shipping/total stay stored in EUR as the
// canonical bookkeeping currency; only the Revolut charge amount changes.
$requestedCurrency = strtoupper((string) ($body['currency'] ?? 'EUR'));
$chargeCurrency = $requestedCurrency === 'RON' ? 'RON' : 'EUR';
$chargeAmount = $totalEur;
if ($chargeCurrency === 'RON') {
  $rate = (float) ggs_get_setting('eur_ron_rate', '5.00');
  $chargeAmount = round($totalEur * $rate, 2);
}

$revolut = ggs_create_revolut_order($chargeAmount, $chargeCurrency, $orderNumber, $body['email']);

if (!$revolut['success']) {
  ggs_json_response(['success' => false, 'error' => $revolut['error'], 'order_number' => $orderNumber], 502);
}

$update = $db->prepare('UPDATE orders SET revolut_order_id = ? WHERE id = ?');
$update->execute([$revolut['id'], $orderId]);

ggs_json_response([
  'success' => true,
  'order_number' => $orderNumber,
  'public_id' => $revolut['public_id'],
  'mode' => APP_ENV === 'sandbox' ? 'sandbox' : 'prod',
]);

/**
 * Creates an order on the Revolut Merchant API.
 * https://developer.revolut.com/docs/merchant/create-order
 */
function ggs_create_revolut_order(float $amount, string $currency, string $orderNumber, string $customerEmail): array
{
  $payload = json_encode([
    'amount' => (int) round($amount * 100), // minor units
    'currency' => $currency,
    'merchant_order_ext_ref' => $orderNumber,
    'email' => $customerEmail,
    'capture_mode' => 'AUTOMATIC',
  ]);

  $ch = curl_init(REVOLUT_API_BASE . '/orders');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . REVOLUT_SECRET_KEY,
    ],
    CURLOPT_TIMEOUT => 15,
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);

  if ($response === false || $httpCode >= 400) {
    error_log('Revolut create-order failed: ' . ($curlError ?: $response));
    return ['success' => false, 'error' => 'Payment provider unavailable'];
  }

  $data = json_decode($response, true);
  if (empty($data['id']) || empty($data['public_id'])) {
    return ['success' => false, 'error' => 'Unexpected payment provider response'];
  }

  return ['success' => true, 'id' => $data['id'], 'public_id' => $data['public_id']];
}
