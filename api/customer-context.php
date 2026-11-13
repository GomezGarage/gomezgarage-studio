<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/customer-auth.php';
ggs_cors_headers();
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$account = ggs_customer_current();
if (!$account) {
  ggs_json_response(['success' => true, 'authenticated' => false, 'csrf_token' => ggs_customer_csrf_token()]);
}

$stmt = ggs_db()->prepare('SELECT id, label, recipient_name, phone, street, city, county, postal, country, is_default FROM customer_addresses WHERE customer_account_id = ? ORDER BY is_default DESC, created_at DESC');
$stmt->execute([$account['id']]);
ggs_json_response([
  'success' => true,
  'authenticated' => true,
  'csrf_token' => ggs_customer_csrf_token(),
  'customer' => ['name' => $account['full_name'], 'email' => $account['email']],
  'addresses' => $stmt->fetchAll(),
]);
