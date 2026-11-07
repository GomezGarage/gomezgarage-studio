<?php
require_once __DIR__ . '/../includes/customer-auth.php';
ggs_customer_start_session();
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$account = null;
if (strlen($token) === 64) {
  $stmt = ggs_db()->prepare('SELECT id, email FROM customer_accounts WHERE reset_token_hash = ? AND reset_expires_at > UTC_TIMESTAMP() AND email_verified_at IS NOT NULL LIMIT 1');
  $stmt->execute([hash('sha256', $token)]);
  $account = $stmt->fetch() ?: null;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_customer_verify_csrf()) {
    $error = 'Your session expired. Refresh the page and try again.';
  } elseif (!$account) {
    $error = 'This password reset link is invalid or has expired.';
  } else {
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) < 12) $error = 'Use a password with at least 12 characters.';
    elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) $error = 'The passwords do not match.';
    else {
      ggs_db()->prepare('UPDATE customer_accounts SET password_hash = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
      $_SESSION['customer_flash'] = ['message' => 'Your password has been updated. Sign in with your new password.', 'type' => 'success'];
      header('Location: /studio/account/login.php');
      exit;
    }
  }
}
$customerTitle = 'Choose a new password';
require __DIR__ . '/../includes/customer-layout.php';
?>
<div class="customer-card customer-form"><h2 data-en="Choose a new password" data-ro="Alege o parola noua">Choose a new password</h2><?php if ($error): ?><div class="customer-alert customer-alert-error" role="alert"><?= ggs_customer_escape($error) ?></div><?php endif; ?><?php if ($account): ?><form method="post" class="customer-form"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="token" value="<?= ggs_customer_escape($token) ?>"><div class="form-group"><label for="password" data-en="New password (12 characters minimum)" data-ro="Parola noua (minimum 12 caractere)">New password (12 characters minimum)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div><div class="form-group"><label for="password_confirm" data-en="Confirm password" data-ro="Confirma parola">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" required></div><div class="customer-form-actions"><button class="btn btn-primary" type="submit" data-en="Update password" data-ro="Actualizeaza parola">Update password</button></div></form><?php else: ?><p data-en="This password reset link is invalid or has expired." data-ro="Linkul de resetare a parolei nu este valid sau a expirat.">This password reset link is invalid or has expired.</p><a class="customer-inline-link" href="/studio/account/forgot-password.php" data-en="Request another link" data-ro="Cere un alt link">Request another link</a><?php endif; ?></div>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
