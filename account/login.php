<?php
require_once __DIR__ . '/../includes/customer-auth.php';
ggs_customer_start_session();
if (ggs_customer_current()) { header('Location: /studio/account/'); exit; }

$error = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '/studio/account/');
if (!str_starts_with($next, '/studio/account/') || str_contains($next, "\r") || str_contains($next, "\n")) $next = '/studio/account/';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_customer_verify_csrf()) {
    $error = 'Your session expired. Refresh the page and try again.';
  } else {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = ggs_db()->prepare('SELECT id, email, full_name, password_hash, email_verified_at FROM customer_accounts WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $account = $stmt->fetch();
    if (!$account || !password_verify($password, $account['password_hash']) || $account['email_verified_at'] === null) {
      usleep(250000);
      $error = 'Email, password or verification status is incorrect.';
    } else {
      ggs_customer_login($account);
      ggs_db()->prepare('UPDATE customer_accounts SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$account['id']]);
      header('Location: ' . $next);
      exit;
    }
  }
}
$customerTitle = 'Sign in';
require __DIR__ . '/../includes/customer-layout.php';
?>
<div class="customer-card customer-form">
  <h2 data-en="Sign in to your account" data-ro="Autentifica-te in cont">Sign in to your account</h2>
  <?php if ($error): ?><div class="customer-alert customer-alert-error" role="alert"><?= ggs_customer_escape($error) ?></div><?php endif; ?>
  <form method="post" class="customer-form">
    <input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>">
    <input type="hidden" name="next" value="<?= ggs_customer_escape($next) ?>">
    <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="200" value="<?= ggs_customer_escape($_POST['email'] ?? '') ?>"></div>
    <div class="form-group"><label for="password" data-en="Password" data-ro="Parola">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <div class="customer-form-actions"><button class="btn btn-primary" type="submit" data-en="Sign in" data-ro="Autentificare">Sign in</button></div>
  </form>
  <div class="customer-link-row"><a href="/studio/account/forgot-password.php" data-en="Forgot password?" data-ro="Ai uitat parola?">Forgot password?</a><a href="/studio/account/register.php" data-en="Create an account" data-ro="Creeaza un cont">Create an account</a></div>
</div>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
