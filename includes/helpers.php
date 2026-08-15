<?php
/**
 * Shared small helpers used across admin/ and api/.
 */

require_once __DIR__ . '/db.php';

function ggs_get_setting(string $key, ?string $default = null): ?string
{
  $stmt = ggs_db()->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  return $row ? $row['value'] : $default;
}

function ggs_set_setting(string $key, string $value): void
{
  $stmt = ggs_db()->prepare(
    'INSERT INTO settings (key_name, value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value)'
  );
  $stmt->execute([$key, $value]);
}

/** Generates the next order number in the GGS-YYYY-NNNN format. */
function ggs_generate_order_number(): string
{
  $year = date('Y');
  $stmt = ggs_db()->prepare(
    "SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1"
  );
  $stmt->execute(["GGS-{$year}-%"]);
  $last = $stmt->fetchColumn();

  $next = 1;
  if ($last) {
    $parts = explode('-', $last);
    $next = (int) end($parts) + 1;
  }

  return sprintf('GGS-%s-%04d', $year, $next);
}

/** Generates the next sequential fiscal invoice number, e.g. "GGS3D-1524". */
function ggs_generate_invoice_number(PDO $db): string
{
  $stmt = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as max_num FROM orders WHERE invoice_number IS NOT NULL");
  $row = $stmt->fetch();
  $next = max(INVOICE_START, ($row['max_num'] ?? INVOICE_START - 1) + 1);
  if ($next > INVOICE_MAX) throw new RuntimeException('Invoice number range exhausted');
  return INVOICE_SERIES . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function ggs_json_response(array $data, int $status = 200): void
{
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

/** Restricts API responses to the site's own domain (ARCHITECTURE.md section 9). */
function ggs_cors_headers(): void
{
  require_once __DIR__ . '/../config.php';
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin === ALLOWED_ORIGIN || $origin === SITE_URL) {
    header('Access-Control-Allow-Origin: ' . $origin);
  }
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');

  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

function ggs_read_json_body(): array
{
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
