<?php
/**
 * POST /api/create-order.php
 * ARCHITECTURE.md section 6.3, step 2:
 *   - validate customer + cart data
 *   - save order in MySQL with status 'pending'
 *   - Online payment: create the matching order via
 *     the Revolut Merchant API and return the public_id so the client can
 *     mount the embedded Checkout Widget.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/product-content.php';

ggs_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = ggs_read_json_body();

if (($body['payment_method'] ?? 'card') !== 'card') {
  ggs_json_response(['success' => false, 'error' => 'Only online payment is supported'], 422);
}

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
$customerAccount = ggs_customer_current();
if ($customerAccount && !ggs_customer_verify_csrf()) {
  ggs_json_response(['success' => false, 'error' => 'Your session expired. Refresh checkout and try again.'], 403);
}
$customerAccountId = $customerAccount && strtolower($customerAccount['email']) === strtolower(trim((string) $body['email']))
  ? (int) $customerAccount['id']
  : null;

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
  $product = ggs_with_product_content($db, [$product])[0];

  $qty = max(1, (int) $item['qty']);
  if ($product['stock'] < $qty) {
    ggs_json_response(['success' => false, 'error' => "Not enough stock for {$product['name_en']}"], 422);
  }

  $orderItems[] = [
    'product_id' => (int) $product['id'],
    'name' => $product['name_en'],
    'sku' => trim((string) ($product['sku'] ?? '')),
    'price_eur' => (float) $product['price_eur'],
    'qty' => $qty,
  ];
  $subtotalEur += (float) $product['price_eur'] * $qty;
}

// Shipping — Romania / EU / UK, rates from admin settings (section 5.5 / 6.3).
$euCountries = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
$country = strtoupper(substr((string) $body['country'], 0, 2));
$county = trim((string) ($body['county'] ?? ''));
$customerType = (($body['customer_type'] ?? 'pf') === 'pj') ? 'pj' : 'pf';
$companyName = trim((string) ($body['company_name'] ?? ''));
$companyCui = strtoupper(preg_replace('/\s+/', '', trim((string) ($body['company_cui'] ?? ''))));
$companyReg = trim((string) ($body['company_reg'] ?? ''));
$companyAddress = trim((string) ($body['company_address'] ?? ''));
$companyCity = trim((string) ($body['company_city'] ?? ''));
$companyCounty = trim((string) ($body['company_county'] ?? ''));
$companyPostal = trim((string) ($body['company_postal'] ?? ''));
$companyCountry = strtoupper(trim((string) ($body['company_country'] ?? '')));
$companyVatPayer = !empty($body['company_vat_payer']) || str_starts_with($companyCui, 'RO');

if ($country === 'RO' && $county === '') {
  ggs_json_response(['success' => false, 'error' => 'County is required for Romanian fiscal invoices'], 422);
}
if ($customerType === 'pj' && ($companyName === '' || $companyCui === '' || $companyAddress === '' || $companyCity === '' || $companyCounty === '' || $companyPostal === '' || !preg_match('/^[A-Z]{2}$/', $companyCountry))) {
  ggs_json_response(['success' => false, 'error' => 'Complete all required company invoice fields'], 422);
}
if (strlen($companyName) > 200 || strlen($companyCui) > 20 || strlen($companyReg) > 50 || strlen($companyAddress) > 500 || strlen($companyCity) > 120 || strlen($companyCounty) > 120 || strlen($companyPostal) > 30) {
  ggs_json_response(['success' => false, 'error' => 'Company invoice details are too long'], 422);
}

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
$paymentMethod = 'card';

$shippingAddress = [
  'street' => $body['street'],
  'city' => $body['city'],
  'county' => $county,
  'postal' => $body['postal'],
  'country' => $country,
];
$customerName = trim($body['first_name'] . ' ' . $body['last_name']);
$customerPhone = $body['phone'] ?? '';

$stmt = $db->prepare(
  'INSERT INTO orders
    (order_number, customer_account_id, status, payment_method, customer_type, company_name, company_cui, company_reg, company_address, company_city, company_county, company_postal, company_country, company_vat_payer, customer_name, customer_email, customer_phone, shipping_address, customer_note, items, subtotal_eur, shipping_eur, total_eur)
   VALUES (?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
  $orderNumber,
  $customerAccountId,
  $paymentMethod,
  $customerType,
  $customerType === 'pj' ? $companyName : null,
  $customerType === 'pj' ? $companyCui : null,
  $customerType === 'pj' ? ($companyReg ?: null) : null,
  $customerType === 'pj' ? $companyAddress : null,
  $customerType === 'pj' ? $companyCity : null,
  $customerType === 'pj' ? $companyCounty : null,
  $customerType === 'pj' ? $companyPostal : null,
  $customerType === 'pj' ? $companyCountry : null,
  $customerType === 'pj' ? ($companyVatPayer ? 1 : 0) : 0,
  $customerName,
  $body['email'],
  $customerPhone,
  json_encode($shippingAddress),
  $body['note'] ?? '',
  json_encode($orderItems),
  $subtotalEur,
  $shippingEur,
  $totalEur,
]);
$orderId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
  ->execute([$orderId, 'pending', 'Order placed']);

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

$revolut = ggs_create_revolut_order($chargeAmount, $chargeCurrency, $orderNumber, $body['email'], $shippingAddress, $customerName, $customerPhone);

if (!$revolut['success']) {
  ggs_json_response(['success' => false, 'error' => $revolut['error'], 'order_number' => $orderNumber], 502);
}

$update = $db->prepare('UPDATE orders SET revolut_order_id = ?, payment_currency = ?, payment_amount = ? WHERE id = ?');
$update->execute([$revolut['id'], $chargeCurrency, $chargeAmount, $orderId]);

ggs_json_response([
  'success' => true,
  'order_number' => $orderNumber,
  'public_id' => $revolut['public_id'],
  'mode' => APP_ENV === 'sandbox' ? 'sandbox' : 'prod',
]);

/**
 * Creates an order on the Revolut Merchant API.
 * https://developer.revolut.com/docs/merchant/create-order
 *
 * @param array{street: string, city: string, postal: string, country: string} $shippingAddress
 */
function ggs_create_revolut_order(
  float $amount,
  string $currency,
  string $orderNumber,
  string $customerEmail,
  array $shippingAddress,
  string $customerName,
  string $customerPhone
): array {
  $payload = json_encode([
    'amount' => (int) round($amount * 100), // minor units
    'currency' => $currency,
    'merchant_order_ext_ref' => $orderNumber,
    'email' => $customerEmail,
    'capture_mode' => 'AUTOMATIC',
    'shipping_address' => [
      'street_line_1' => $shippingAddress['street'],
      'city' => $shippingAddress['city'],
      'postcode' => $shippingAddress['postal'],
      'country_code' => $shippingAddress['country'],
    ],
    'customer' => [
      'name' => $customerName,
      'email' => $customerEmail,
      'phone' => $customerPhone,
    ],
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
