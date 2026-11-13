<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/smartbill.php';
require_once __DIR__ . '/../config.php';

ggs_require_admin();

$fields = [
  'order_notify_email' => 'contact@gomezgarage.eu',
  'low_stock_notify_email' => 'contact@gomezgarage.eu',
  'default_stock_threshold' => '3',
  'shipping_ro_eur' => '5.00',
  'shipping_eu_eur' => '12.00',
  'shipping_uk_eur' => '18.00',
  'default_currency' => 'EUR',
  'eur_ron_rate' => '5.00',
];

$notice = '';
$noticeType = 'success';
$smartBillCheck = null;
$smartBillError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_admin_verify_csrf()) {
    $notice = 'The security token expired. Reload the page and try again.';
    $noticeType = 'error';
  } elseif (($_POST['action'] ?? '') === 'check_smartbill') {
    try {
      $smartBillCheck = ggs_smartbill_check_configuration();
    } catch (Throwable $error) {
      $smartBillError = $error->getMessage();
    }
  } else {
    foreach (array_keys($fields) as $key) {
      if (isset($_POST[$key])) {
        ggs_set_setting($key, trim((string) $_POST[$key]));
      }
    }
    $notice = 'Settings saved.';
  }
}

foreach ($fields as $key => $default) {
  $fields[$key] = ggs_get_setting($key, $default);
}

function ggs_mask_key(string $key): string
{
  if (strlen($key) <= 8) return str_repeat('•', strlen($key));
  return substr($key, 0, 6) . str_repeat('•', 10) . substr($key, -4);
}

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Settings</h1>
    <div class="subtitle">Notifications, shipping rates &amp; currency</div>
  </div>
</div>

<?php if ($notice): ?><div class="alert-banner" style="border-color:var(--<?= $noticeType === 'error' ? 'danger' : 'success' ?>);color:var(--<?= $noticeType === 'error' ? 'danger' : 'success' ?>);"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<form method="post">
  <input type="hidden" name="action" value="save_settings">
  <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
  <div class="admin-panel">
    <h3>Notifications</h3>
    <div class="form-row">
      <div class="form-group"><label>New order notification email</label><input type="email" name="order_notify_email" value="<?= htmlspecialchars($fields['order_notify_email']) ?>"></div>
      <div class="form-group"><label>Low stock notification email</label><input type="email" name="low_stock_notify_email" value="<?= htmlspecialchars($fields['low_stock_notify_email']) ?>"></div>
    </div>
    <div class="form-group" style="max-width:260px;">
      <label>Default stock threshold</label>
      <input type="number" min="0" name="default_stock_threshold" value="<?= htmlspecialchars($fields['default_stock_threshold']) ?>">
    </div>
  </div>

  <div class="admin-panel">
    <h3>Shipping rates (EUR)</h3>
    <div class="form-row">
      <div class="form-group"><label>Romania</label><input type="number" step="0.01" min="0" name="shipping_ro_eur" value="<?= htmlspecialchars($fields['shipping_ro_eur']) ?>"></div>
      <div class="form-group"><label>European Union</label><input type="number" step="0.01" min="0" name="shipping_eu_eur" value="<?= htmlspecialchars($fields['shipping_eu_eur']) ?>"></div>
    </div>
    <div class="form-group" style="max-width:260px;">
      <label>United Kingdom</label>
      <input type="number" step="0.01" min="0" name="shipping_uk_eur" value="<?= htmlspecialchars($fields['shipping_uk_eur']) ?>">
    </div>
  </div>

  <div class="admin-panel">
    <h3>Currency</h3>
    <div class="form-row">
      <div class="form-group">
        <label>Default currency</label>
        <select name="default_currency">
          <option value="EUR" <?= $fields['default_currency'] === 'EUR' ? 'selected' : '' ?>>EUR</option>
          <option value="RON" <?= $fields['default_currency'] === 'RON' ? 'selected' : '' ?>>RON</option>
        </select>
      </div>
      <div class="form-group"><label>EUR → RON rate</label><input type="number" step="0.0001" min="0" name="eur_ron_rate" value="<?= htmlspecialchars($fields['eur_ron_rate']) ?>"></div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Save Settings</button>
</form>

<div class="admin-panel" style="margin-top:24px;">
  <h3>Revolut API keys</h3>
  <p class="form-hint">Stored in <code>config.php</code> on the server, not in the database, and not editable here. Edit that file directly to change them.</p>
  <table class="specs-table">
    <tr><td>Public key</td><td class="mono"><?= htmlspecialchars(ggs_mask_key(REVOLUT_PUBLIC_KEY)) ?></td></tr>
    <tr><td>Secret key</td><td class="mono"><?= htmlspecialchars(ggs_mask_key(REVOLUT_SECRET_KEY)) ?></td></tr>
    <tr><td>Mode</td><td class="mono"><?= APP_ENV === 'sandbox' ? 'Sandbox' : 'Production' ?></td></tr>
  </table>
</div>

<div class="admin-panel" style="margin-top:24px;">
  <h3>SmartBill API</h3>
  <p class="form-hint">The check below only reads the invoice series and tax rates. It cannot create, change or delete an invoice.</p>

  <?php if ($smartBillCheck): ?>
    <div class="alert-banner" style="border-color:var(--success);color:var(--success);">
      Connection verified. No document was created.
    </div>
  <?php elseif ($smartBillError): ?>
    <div class="alert-banner" style="border-color:var(--danger);color:var(--danger);">
      <?= htmlspecialchars($smartBillError) ?>
    </div>
  <?php endif; ?>

  <table class="specs-table">
    <tr><td>Automatic invoicing</td><td class="mono"><?= ggs_smartbill_enabled() ? 'ENABLED' : 'DISABLED (safe)' ?></td></tr>
    <tr><td>Series</td><td class="mono"><?= htmlspecialchars(defined('SMARTBILL_INVOICE_SERIES') ? (string) SMARTBILL_INVOICE_SERIES : 'not configured') ?></td></tr>
    <tr><td>Tax</td><td class="mono"><?= htmlspecialchars(defined('SMARTBILL_TAX_NAME') ? (string) SMARTBILL_TAX_NAME : 'not configured') ?></td></tr>
    <?php if ($smartBillCheck): ?>
      <tr><td>SmartBill next number</td><td class="mono"><?= htmlspecialchars((string) ($smartBillCheck['series']['nextNumber'] ?? 'unknown')) ?></td></tr>
      <tr><td>Verified tax rate</td><td class="mono"><?= htmlspecialchars((string) ($smartBillCheck['tax']['name'] ?? 'unknown')) ?> (<?= htmlspecialchars((string) ($smartBillCheck['tax']['percentage'] ?? 'unknown')) ?>%)</td></tr>
    <?php endif; ?>
  </table>

  <form method="post" style="margin-top:16px;">
    <input type="hidden" name="action" value="check_smartbill">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <button type="submit" class="btn btn-secondary">Check SmartBill connection (read-only)</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
