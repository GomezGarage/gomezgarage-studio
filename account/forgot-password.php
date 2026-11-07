<?php
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/mailer.php';
ggs_customer_start_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (ggs_customer_verify_csrf()) {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $stmt = ggs_db()->prepare('SELECT id, email, full_name FROM customer_accounts WHERE email = ? AND email_verified_at IS NOT NULL LIMIT 1');
      $stmt->execute([$email]);
      $account = $stmt->fetch();
      if ($account) {
        $token = bin2hex(random_bytes(32));
        ggs_db()->prepare('UPDATE customer_accounts SET reset_token_hash = ?, reset_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = ?')
          ->execute([hash('sha256', $token), $account['id']]);
        if (!ggs_send_customer_password_reset($account, $token)) error_log('GGS customer password reset email failed for account ' . $account['id']);
      }
    }
  }
  $_SESSION['customer_flash'] = ['message' => 'If a verified account uses that email, a password reset link will arrive shortly.', 'type' => 'success'];
  header('Location: /studio/account/forgot-password.php');
  exit;
}
$customerTitle = 'Reset password';
require __DIR__ . '/../includes/customer-layout.php';
?>
<div class="customer-card customer-form">
  <h2 data-en="Reset your password" data-ro="Reseteaza parola">Reset your password</h2>
  <p class="form-hint" data-en="Enter your account email and we will send a one-time reset link." data-ro="Introdu emailul contului si iti vom trimite un link unic pentru resetare.">Enter your account email and we will send a one-time reset link.</p>
  <form method="post" class="customer-form"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="200"></div><div class="customer-form-actions"><button class="btn btn-primary" type="submit" data-en="Send reset link" data-ro="Trimite linkul">Send reset link</button></div></form>
</div>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
