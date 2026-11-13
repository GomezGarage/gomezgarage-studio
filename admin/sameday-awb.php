<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sameday.php';

ggs_require_admin();
$id = (int) ($_GET['id'] ?? 0);
$format = strtoupper((string) ($_GET['format'] ?? 'A6')) === 'A4' ? 'A4' : 'A6';
$stmt = ggs_db()->prepare('SELECT order_number, sameday_awb_number FROM orders WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order || trim((string) ($order['sameday_awb_number'] ?? '')) === '') {
  http_response_code(404);
  exit('AWB not found.');
}
try {
  $pdf = ggs_sameday_download_awb_pdf(ggs_db(), (string) $order['sameday_awb_number'], $format);
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $order['order_number']) . '-AWB-' . $format . '.pdf"');
  header('Content-Length: ' . strlen($pdf));
  echo $pdf;
} catch (Throwable $error) {
  error_log('GGS Sameday PDF failed: ' . $error->getMessage());
  http_response_code(502);
  echo 'The Sameday label could not be downloaded.';
}
