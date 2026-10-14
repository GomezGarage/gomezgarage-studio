<?php
/**
 * Shared admin shell header. Include after calling ggs_require_admin().
 * Expects $pageTitle and $activeNav to be set by the calling script.
 */
$pageTitle = $pageTitle ?? 'Admin';
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Gomez Garage Studio Admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" sizes="32x32" href="/studio/assets/images/favicon-32.png?v=2">
<link rel="icon" href="/studio/assets/images/favicon.ico?v=2" sizes="any">
<link rel="apple-touch-icon" href="/studio/assets/images/apple-touch-icon.png?v=2">
<link rel="stylesheet" href="/studio/assets/css/main.css">
<link rel="stylesheet" href="/studio/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-shell">
  <aside class="admin-sidebar">
    <div class="nav-logo">
      <img src="/studio/assets/images/logo-ggs3d.png" alt="Gomez Garage">
    </div>
    <a href="/studio/admin/index.php" class="admin-nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="/studio/admin/products.php" class="admin-nav-link <?= $activeNav === 'products' ? 'active' : '' ?>">Products</a>
    <a href="/studio/admin/orders.php" class="admin-nav-link <?= $activeNav === 'orders' ? 'active' : '' ?>">Orders</a>
    <a href="/studio/admin/settings.php" class="admin-nav-link <?= $activeNav === 'settings' ? 'active' : '' ?>">Settings</a>
    <div class="admin-sidebar-footer">
      <a href="/studio/admin/logout.php" class="admin-nav-link">Log out</a>
    </div>
  </aside>
  <main class="admin-main">
