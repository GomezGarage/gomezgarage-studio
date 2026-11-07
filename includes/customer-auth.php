<?php
/** Customer-facing authentication and account helpers. */

require_once __DIR__ . '/db.php';

const GGS_CUSTOMER_SESSION_NAME = 'GGS_CUSTOMER_SESSID';
const GGS_CUSTOMER_SESSION_LIFETIME = 7200; // 2 hours idle timeout.

function ggs_customer_start_session(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name(GGS_CUSTOMER_SESSION_NAME);
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/studio/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

function ggs_customer_csrf_token(): string
{
  ggs_customer_start_session();
  if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['customer_csrf'];
}

function ggs_customer_verify_csrf(): bool
{
  ggs_customer_start_session();
  $submitted = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  return $submitted !== '' && isset($_SESSION['customer_csrf']) && hash_equals($_SESSION['customer_csrf'], $submitted);
}

function ggs_customer_current(): ?array
{
  ggs_customer_start_session();
  $lastActive = (int) ($_SESSION['customer_last_active'] ?? 0);
  if ($lastActive > 0 && time() - $lastActive > GGS_CUSTOMER_SESSION_LIFETIME) {
    ggs_customer_logout();
    return null;
  }
  $id = (int) ($_SESSION['customer_account_id'] ?? 0);
  if ($id <= 0) return null;

  $stmt = ggs_db()->prepare('SELECT id, email, full_name, email_order_updates, email_custom_updates, email_verified_at FROM customer_accounts WHERE id = ? AND email_verified_at IS NOT NULL LIMIT 1');
  $stmt->execute([$id]);
  $account = $stmt->fetch();
  if (!$account) {
    unset($_SESSION['customer_account_id']);
    return null;
  }
  $_SESSION['customer_last_active'] = time();
  return $account;
}

function ggs_customer_require_login(): array
{
  $account = ggs_customer_current();
  if (!$account) {
    $next = rawurlencode($_SERVER['REQUEST_URI'] ?? '/studio/account/');
    header('Location: /studio/account/login.php?next=' . $next);
    exit;
  }
  return $account;
}

function ggs_customer_login(array $account): void
{
  ggs_customer_start_session();
  session_regenerate_id(true);
  $_SESSION['customer_account_id'] = (int) $account['id'];
  $_SESSION['customer_last_active'] = time();
  $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

function ggs_customer_logout(): void
{
  ggs_customer_start_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
  }
  session_destroy();
}

function ggs_customer_claim_guest_records(PDO $db, int $accountId, string $email): void
{
  $db->prepare('UPDATE orders SET customer_account_id = ? WHERE customer_account_id IS NULL AND LOWER(customer_email) = LOWER(?)')
    ->execute([$accountId, $email]);
  $db->prepare('UPDATE custom_inquiries SET customer_account_id = ? WHERE customer_account_id IS NULL AND LOWER(customer_email) = LOWER(?)')
    ->execute([$accountId, $email]);
}

function ggs_customer_escape(?string $value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ggs_customer_country_name(string $code): string
{
  $names = [
    'RO' => 'Romania', 'GB' => 'United Kingdom', 'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
    'HR' => 'Croatia', 'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland',
    'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece', 'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy',
    'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands', 'PL' => 'Poland',
    'PT' => 'Portugal', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
  ];
  return $names[strtoupper($code)] ?? strtoupper($code);
}
