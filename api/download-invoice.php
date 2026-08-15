<?php
/**
 * GET /api/download-invoice.php?order=GGS-2026-XXXX
 * Serves the PDF invoice for a completed order, if one exists. Keyed
 * purely by order number, same trust model as order-success.html?order=
 * elsewhere in this app — no separate auth beyond knowing the order number.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';

ggs_cors_headers();

$orderNumber = $_GET['order'] ?? '';

if (!preg_match('/^GGS-\d{4}-\d{4,}$/', $orderNumber)) {
  http_response_code(400);
  header('Content-Type: text/plain');
  exit('Invalid order number.');
}

$db = ggs_db();
$stmt = $db->prepare('SELECT invoice_number FROM orders WHERE order_number = ? LIMIT 1');
$stmt->execute([$orderNumber]);
$row = $stmt->fetch();

if (!$row || !$row['invoice_number'] || !preg_match('/^[A-Za-z0-9]+-\d{4}$/', $row['invoice_number'])) {
  http_response_code(404);
  header('Content-Type: text/plain');
  exit('Invoice not found.');
}

$path = __DIR__ . '/../invoices/' . $row['invoice_number'] . '.pdf';

if (!is_file($path)) {
  http_response_code(404);
  header('Content-Type: text/plain');
  exit('Invoice not found.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $row['invoice_number'] . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
