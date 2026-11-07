<?php
require_once __DIR__ . '/../includes/customer-auth.php';
ggs_customer_start_session();
$token = trim((string) ($_GET['token'] ?? ''));
$error = '';
if ($token === '' || strlen($token) !== 64) {
  $error = 'This verification link is invalid or has expired.';
} else {
  $db = ggs_db();
  $stmt = $db->prepare('SELECT id, email FROM customer_accounts WHERE verification_token_hash = ? AND verification_expires_at > UTC_TIMESTAMP() AND email_verified_at IS NULL LIMIT 1');
  $stmt->execute([hash('sha256', $token)]);
  $account = $stmt->fetch();
  if (!$account) {
    $error = 'This verification link is invalid or has expired.';
  } else {
    $db->beginTransaction();
    try {
      $db->prepare('UPDATE customer_accounts SET email_verified_at = UTC_TIMESTAMP(), verification_token_hash = NULL, verification_expires_at = NULL WHERE id = ?')->execute([$account['id']]);
      ggs_customer_claim_guest_records($db, (int) $account['id'], $account['email']);
      $db->commit();
      $_SESSION['customer_flash'] = ['message' => 'Your email is verified. You can now sign in; previous guest orders and requests using this email have been added.', 'type' => 'success'];
      header('Location: /studio/account/login.php');
      exit;
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      error_log('GGS customer email verification failed: ' . $e->getMessage());
      $error = 'We could not verify your email right now. Please try again.';
    }
  }
}
$customerTitle = 'Verify email';
require __DIR__ . '/../includes/customer-layout.php';
?>
<div class="customer-card"><h2 data-en="Email verification" data-ro="Verificarea emailului">Email verification</h2><?php if ($error): ?><div class="customer-alert customer-alert-error" role="alert"><?= ggs_customer_escape($error) ?></div><p><a class="customer-inline-link" href="/studio/account/register.php" data-en="Create a new verification link" data-ro="Genereaza un nou link de verificare">Create a new verification link</a></p><?php endif; ?></div>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
