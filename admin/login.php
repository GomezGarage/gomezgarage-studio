<?php
require_once __DIR__ . '/../includes/auth.php';

ggs_start_admin_session();

if (!empty($_SESSION['admin_id'])) {
  header('Location: /studio/admin/index.php');
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username !== '' && $password !== '' && ggs_attempt_login($username, $password)) {
    header('Location: /studio/admin/index.php');
    exit;
  }

  $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — Gomez Garage Studio</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="/studio/assets/images/favicon.svg">
<link rel="stylesheet" href="/studio/assets/css/main.css">
<link rel="stylesheet" href="/studio/assets/css/admin.css">
</head>
<body class="admin-body">
  <div class="admin-login-wrap">
    <div class="admin-login-card">
      <div class="nav-logo">
        <img src="/studio/assets/images/logo-gomez.svg" alt="Gomez Garage">
      </div>
      <h1>Studio Admin</h1>

      <?php if ($error): ?>
        <div class="admin-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="/studio/admin/login.php">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" autocomplete="username" required autofocus>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Log in</button>
      </form>
      <p class="login-footer-note">Gomez Group / Lancer Land SRL</p>
    </div>
  </div>
</body>
</html>
