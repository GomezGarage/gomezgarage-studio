<?php
/**
 * Admin session auth. Session-based, 2 hour idle timeout (ARCHITECTURE.md 5.1 / 9).
 */

require_once __DIR__ . '/db.php';

const ADMIN_SESSION_LIFETIME = 7200; // 2 hours

function ggs_start_admin_session(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

function ggs_require_admin(): void
{
  ggs_start_admin_session();

  $isLoggedIn = !empty($_SESSION['admin_id']);
  $isFresh = $isLoggedIn && (time() - ($_SESSION['admin_last_active'] ?? 0)) < ADMIN_SESSION_LIFETIME;

  if (!$isFresh) {
    session_unset();
    session_destroy();
    header('Location: /studio/admin/login.php');
    exit;
  }

  $_SESSION['admin_last_active'] = time();
}

function ggs_attempt_login(string $username, string $password): bool
{
  ggs_start_admin_session();

  $stmt = ggs_db()->prepare('SELECT id, password_hash FROM admin_users WHERE username = ? LIMIT 1');
  $stmt->execute([$username]);
  $user = $stmt->fetch();

  if (!$user || !password_verify($password, $user['password_hash'])) {
    return false;
  }

  session_regenerate_id(true);
  $_SESSION['admin_id'] = $user['id'];
  $_SESSION['admin_username'] = $username;
  $_SESSION['admin_last_active'] = time();

  return true;
}

function ggs_admin_logout(): void
{
  ggs_start_admin_session();
  session_unset();
  session_destroy();
}
