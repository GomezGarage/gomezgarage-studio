<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/product-views.php';

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ggs_view_response(int $status): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['success' => $status < 400], JSON_THROW_ON_ERROR);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  ggs_view_response(405);
}

$raw = file_get_contents('php://input', false, null, 0, 257);
try {
  if (!is_string($raw)) {
    ggs_view_response(400);
  }
  $productId = ggs_parse_product_view_request($_SERVER, $raw, ALLOWED_ORIGIN);
} catch (GgsProductViewRequestError $error) {
  if ($error->httpStatus === 405) {
    header('Allow: POST');
  }
  ggs_view_response($error->httpStatus);
}

try {
  ggs_record_product_view(ggs_db(), $productId);
  ggs_view_response(200);
} catch (Throwable $error) {
  // Keep database details out of a public endpoint response.
  ggs_view_response(503);
}
