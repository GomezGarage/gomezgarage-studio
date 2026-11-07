<?php
$customerTitle = $customerTitle ?? 'Your account';
$customerUser = $customerUser ?? null;
$customerTitleRo = $customerTitleRo ?? ([
  'Your account' => 'Contul tau', 'Create account' => 'Creeaza cont', 'Sign in' => 'Autentificare',
  'Verify email' => 'Verificare email', 'Reset password' => 'Resetare parola',
  'Choose a new password' => 'Alege o parola noua', 'Saved addresses' => 'Adrese salvate',
][$customerTitle] ?? $customerTitle);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= ggs_customer_escape($customerTitle) ?> — Gomez Garage Studio</title>
<link rel="icon" href="/studio/assets/images/favicon.ico?v=2" sizes="any">
<link rel="stylesheet" href="/studio/assets/css/main.css?v=20261107-about-process-responsive">
<link rel="stylesheet" href="/studio/assets/css/shop.css?v=20261026-catalog">
</head>
<body class="customer-account-body">
<header class="site-header">
  <div class="container nav-inner">
    <a href="/studio/index.html" class="nav-logo"><img src="/studio/assets/images/logo-ggs3d.png" alt="Gomez Garage"></a>
    <nav id="primary-navigation" class="nav-links">
      <a href="/studio/products.html" data-en="Products" data-ro="Produse">Products</a>
      <a href="/studio/custom.html" data-en="Custom Order" data-ro="Comanda Custom">Custom Order</a>
      <a href="/studio/about.html" data-en="About" data-ro="Despre">About</a>
      <a href="/studio/account/" data-en="Account" data-ro="Cont">Account</a>
    </nav>
    <div class="nav-actions">
      <div class="nav-toggle" data-lang-toggle><button data-lang-btn="en" class="active">EN</button><button data-lang-btn="ro">RO</button></div>
      <div class="nav-toggle" data-currency-toggle><button data-currency-btn="EUR" class="active">EUR</button><button data-currency-btn="RON">RON</button></div>
      <a href="/studio/cart.html" class="cart-icon-link" aria-label="Cart">🛒<span class="cart-count hidden" data-cart-count>0</span></a>
      <button class="nav-burger" data-nav-burger type="button" aria-label="Open menu" aria-expanded="false" aria-controls="primary-navigation">☰</button>
    </div>
  </div>
</header>
<main class="customer-account-main">
  <section class="page-hero customer-page-hero"><div class="container"><span class="section-label mono" data-en="Customer account" data-ro="Cont client">Customer account</span><h1 data-en="<?= ggs_customer_escape($customerTitle) ?>" data-ro="<?= ggs_customer_escape($customerTitleRo) ?>"><?= ggs_customer_escape($customerTitle) ?></h1></div></section>
  <section class="customer-account-section"><div class="container customer-account-container">
<?php if (!empty($_SESSION['customer_flash'])): ?>
  <div class="customer-alert <?= ($_SESSION['customer_flash']['type'] ?? '') === 'error' ? 'customer-alert-error' : '' ?>" role="status"><?= ggs_customer_escape($_SESSION['customer_flash']['message'] ?? '') ?></div>
  <?php unset($_SESSION['customer_flash']); ?>
<?php endif; ?>
