<?php
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/mailer.php';
ggs_customer_start_session();
if (ggs_customer_current()) { header('Location: /studio/account/'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_customer_verify_csrf()) {
    $error = 'Your session expired. Refresh the page and try again.';
  } else {
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if ($name === '' || strlen($name) > 200 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
      $error = 'Enter your name and a valid email address.';
    } elseif (strlen($password) < 12) {
      $error = 'Use a password with at least 12 characters.';
    } elseif ($password !== $confirm) {
      $error = 'The passwords do not match.';
    } else {
      $db = ggs_db();
      $stmt = $db->prepare('SELECT id, email_verified_at FROM customer_accounts WHERE email = ? LIMIT 1');
      $stmt->execute([$email]);
      $existing = $stmt->fetch();
      if ($existing && $existing['email_verified_at'] !== null) {
        $error = 'An account may already exist for this email. Sign in or reset your password.';
      } else {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        if ($existing) {
          // A second signup attempt must not replace the password on an
          // unverified account. Otherwise the original owner could verify a
          // link requested by someone else and activate their chosen password.
          $db->prepare('UPDATE customer_accounts SET verification_token_hash = ?, verification_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) WHERE id = ?')
            ->execute([$tokenHash, $existing['id']]);
          $accountId = (int) $existing['id'];
        } else {
          $db->prepare('INSERT INTO customer_accounts (email, full_name, password_hash, verification_token_hash, verification_expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))')
            ->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), $tokenHash]);
          $accountId = (int) $db->lastInsertId();
        }
        $account = ['id' => $accountId, 'email' => $email, 'full_name' => $name];
        if (!ggs_send_customer_verification($account, $token)) {
          error_log('GGS customer verification email failed for account ' . $accountId);
          $error = 'We could not send the verification email right now. Please try again shortly.';
        } else {
          $_SESSION['customer_flash'] = ['message' => 'Check your email for a verification link. It expires in 24 hours.', 'type' => 'success'];
          header('Location: /studio/account/login.php');
          exit;
        }
      }
    }
  }
}
$customerTitle = 'Create account';
require __DIR__ . '/../includes/customer-layout.php';
?>
<div class="customer-card customer-form">
  <h2 data-en="Create your account" data-ro="Creeaza-ti contul">Create your account</h2>
  <p class="form-hint" data-en="Use the same email as your previous guest orders or custom requests to add them after verification." data-ro="Foloseste acelasi email ca la comenzile sau cererile custom facute ca vizitator, pentru a le adauga dupa verificare.">Use the same email as your previous guest orders or custom requests to add them after verification.</p>
  <?php if ($error): ?><div class="customer-alert customer-alert-error" role="alert"><?= ggs_customer_escape($error) ?></div><?php endif; ?>
  <form method="post" class="customer-form">
    <input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>">
    <div class="form-group"><label for="full_name" data-en="Full name" data-ro="Nume complet">Full name</label><input id="full_name" name="full_name" autocomplete="name" required maxlength="200" value="<?= ggs_customer_escape($_POST['full_name'] ?? '') ?>"></div>
    <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="200" value="<?= ggs_customer_escape($_POST['email'] ?? '') ?>"></div>
    <div class="form-group"><label for="password" data-en="Password (12 characters minimum)" data-ro="Parola (minimum 12 caractere)">Password (12 characters minimum)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="form-group"><label for="password_confirm" data-en="Confirm password" data-ro="Confirma parola">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="customer-form-actions"><button class="btn btn-primary" type="submit" data-en="Create account" data-ro="Creeaza contul">Create account</button></div>
  </form>
  <div class="customer-link-row"><a href="/studio/account/login.php" data-en="Already have an account? Sign in" data-ro="Ai deja cont? Autentifica-te">Already have an account? Sign in</a></div>
</div>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
