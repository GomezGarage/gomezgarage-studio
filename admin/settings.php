<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/smartbill.php';
require_once __DIR__ . '/../includes/sameday.php';
require_once __DIR__ . '/../includes/eurosender.php';
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
$samedayCheck = null;
$samedayError = '';
$samedaySync = null;
$eurosenderCheck = null;
$eurosenderError = '';
$eurosenderQuote = null;
$eurosenderQuoteError = '';
$eurosenderCountries = ggs_eurosender_eu_countries();
$eurosenderConfigValue = static function (string $constant, string $fallback = ''): string {
  if (!defined($constant)) return $fallback;
  $value = trim((string) constant($constant));
  return str_starts_with($value, 'REPLACE_WITH_') ? $fallback : $value;
};
$eurosenderQuoteInput = [
  'pickup_contact_name' => $eurosenderConfigValue('EUROSENDER_PICKUP_CONTACT_NAME', 'Lancer Land SRL / Gabriel Petrut'),
  'pickup_country' => $eurosenderConfigValue('EUROSENDER_PICKUP_COUNTRY', 'RO'),
  'pickup_street' => $eurosenderConfigValue('EUROSENDER_PICKUP_STREET', 'Strada Magnoliei 12D'),
  'pickup_city' => $eurosenderConfigValue('EUROSENDER_PICKUP_CITY', 'Dumbraveni'),
  'pickup_region' => $eurosenderConfigValue('EUROSENDER_PICKUP_REGION', 'Ilfov'),
  'pickup_postal' => $eurosenderConfigValue('EUROSENDER_PICKUP_POSTAL', '077016'),
  'country' => 'RO', 'street' => '', 'city' => '', 'region' => '', 'postal' => '',
  'length' => '30', 'width' => '20', 'height' => '10', 'weight' => '1', 'value' => '100',
];

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
  } elseif (($_POST['action'] ?? '') === 'check_sameday') {
    try {
      $samedayCheck = ggs_sameday_check_configuration(ggs_db());
    } catch (Throwable $error) {
      $samedayError = $error->getMessage();
    }
  } elseif (($_POST['action'] ?? '') === 'sync_sameday') {
    try {
      $accountCounts = ggs_sameday_sync_account(ggs_db());
      $oohCount = ggs_sameday_sync_ooh(ggs_db());
      $samedaySync = $accountCounts + ['ooh_locations' => $oohCount];
    } catch (Throwable $error) {
      $samedayError = $error->getMessage();
    }
  } elseif (($_POST['action'] ?? '') === 'check_eurosender') {
    try {
      $eurosenderCheck = ggs_eurosender_check_configuration();
    } catch (Throwable $error) {
      $eurosenderError = $error->getMessage();
    }
  } elseif (($_POST['action'] ?? '') === 'quote_eurosender') {
    foreach (array_keys($eurosenderQuoteInput) as $key) $eurosenderQuoteInput[$key] = trim((string) ($_POST[$key] ?? ''));
    try {
      if (!defined('EUROSENDER_ENV') || EUROSENDER_ENV !== 'sandbox') throw new RuntimeException('The Admin quote test is available only in the Eurosender sandbox environment.');
      $eurosenderQuote = ggs_eurosender_quote_options([
        'country' => strtoupper($eurosenderQuoteInput['country']),
        'street' => $eurosenderQuoteInput['street'],
        'city' => $eurosenderQuoteInput['city'],
        'region' => $eurosenderQuoteInput['region'],
        'zip' => $eurosenderQuoteInput['postal'],
      ], [
        'length' => (float) $eurosenderQuoteInput['length'],
        'width' => (float) $eurosenderQuoteInput['width'],
        'height' => (float) $eurosenderQuoteInput['height'],
        'weight' => (float) $eurosenderQuoteInput['weight'],
        'value' => (float) $eurosenderQuoteInput['value'],
      ], null, [
        'country' => strtoupper($eurosenderQuoteInput['pickup_country']),
        'street' => $eurosenderQuoteInput['pickup_street'],
        'city' => $eurosenderQuoteInput['pickup_city'],
        'region' => $eurosenderQuoteInput['pickup_region'],
        'zip' => $eurosenderQuoteInput['pickup_postal'],
      ]);
    } catch (Throwable $error) {
      $eurosenderQuoteError = $error->getMessage();
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

function ggs_collect_sameday_pdo_ids(mixed $value, array &$found): void
{
  if (!is_array($value)) return;
  $text = strtoupper(implode(' ', array_map(static fn($item): string => is_scalar($item) ? (string) $item : '', $value)));
  if ((str_contains($text, 'PDO') || str_contains($text, 'PERSONAL') || str_contains($text, 'DROP')) && isset($value['id']) && is_numeric($value['id'])) {
    $found[(int) $value['id']] = trim((string) ($value['name'] ?? $value['code'] ?? 'PDO'));
  }
  foreach ($value as $child) if (is_array($child)) ggs_collect_sameday_pdo_ids($child, $found);
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
  <h3>Eurosender API</h3>
  <p class="form-hint">Sandbox and production use different API keys. This first-phase check only reads the supported-country list; it cannot request a quote, debit the wallet or create a shipment.</p>

  <?php if ($eurosenderCheck): ?>
    <div class="alert-banner" style="border-color:var(--success);color:var(--success);">Connection verified. No quote or shipment was created.</div>
  <?php elseif ($eurosenderError): ?>
    <div class="alert-banner" style="border-color:var(--danger);color:var(--danger);"><?= htmlspecialchars($eurosenderError) ?></div>
  <?php endif; ?>

  <?php if ($eurosenderQuote): ?>
    <div class="alert-banner" style="border-color:var(--success);color:var(--success);">
      <?= count($eurosenderQuote['options']) ?> sandbox service option<?= count($eurosenderQuote['options']) === 1 ? '' : 's' ?> received. No shipment was created. Sandbox prices are test values, not the negotiated production rates.
    </div>
    <table class="specs-table" style="margin-top:12px;">
      <thead><tr><th>Service</th><th>Route</th><th>Sandbox price</th><th>ETA</th></tr></thead>
      <tbody>
      <?php foreach ($eurosenderQuote['options'] as $option): ?>
        <tr>
          <td><strong><?= htmlspecialchars($option['label']) ?></strong><br><span class="mono"><?= htmlspecialchars($option['name']) ?></span></td>
          <td class="mono"><?= htmlspecialchars($option['subtype'] !== '' ? $option['subtype'] : 'not specified') ?></td>
          <td class="mono"><?= htmlspecialchars(number_format((float) $option['amount'], 2, '.', '')) ?> <?= htmlspecialchars($option['currency']) ?></td>
          <td><?= htmlspecialchars($option['estimated_delivery_time'] !== '' ? $option['estimated_delivery_time'] . ' working days' : 'not supplied') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php elseif ($eurosenderQuoteError): ?>
    <div class="alert-banner" style="border-color:var(--danger);color:var(--danger);"><?= htmlspecialchars($eurosenderQuoteError) ?></div>
  <?php endif; ?>

  <table class="specs-table">
    <tr><td>Checkout / booking</td><td class="mono"><?= ggs_eurosender_enabled() ? 'ENABLED' : 'DISABLED (safe)' ?></td></tr>
    <tr><td>Credentials</td><td class="mono"><?= ggs_eurosender_configured() ? 'configured' : 'missing / placeholder' ?></td></tr>
    <tr><td>Environment</td><td class="mono"><?= htmlspecialchars(defined('EUROSENDER_ENV') ? (string) EUROSENDER_ENV : 'not configured') ?></td></tr>
    <tr><td>API endpoint</td><td class="mono"><?= htmlspecialchars(defined('EUROSENDER_API_BASE') ? (string) EUROSENDER_API_BASE : 'not configured') ?></td></tr>
    <?php if ($eurosenderCheck): ?>
      <tr><td>Supported countries</td><td class="mono"><?= (int) $eurosenderCheck['active_countries'] ?> active · <?= (int) $eurosenderCheck['eu_countries'] ?> EU</td></tr>
      <tr><td>Romania</td><td class="mono">available<?= ($eurosenderCheck['romania']['requiresRegion'] ?? false) ? ' · region required' : '' ?><?= ($eurosenderCheck['romania']['requiresCity'] ?? false) ? ' · exact city required' : '' ?></td></tr>
    <?php endif; ?>
  </table>

  <form method="post" style="margin-top:16px;">
    <input type="hidden" name="action" value="check_eurosender">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <button type="submit" class="btn btn-secondary">Check Eurosender connection (read-only)</button>
  </form>

  <hr style="border:0;border-top:1px solid var(--border);margin:24px 0;">
  <h4>Sandbox service-options test</h4>
  <p class="form-hint">This requests every service option available in the sandbox but cannot book a shipment or debit the wallet. Sandbox prices are test values and can differ substantially from the negotiated production prices. Pickup defaults come from <code>config.php</code> when present; all fields remain editable and none of these test values are saved.</p>
  <form method="post">
    <input type="hidden" name="action" value="quote_eurosender">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <h5>Pickup address</h5>
    <div class="form-group"><label>Sender</label><input name="pickup_contact_name" required value="<?= htmlspecialchars($eurosenderQuoteInput['pickup_contact_name']) ?>"></div>
    <div class="form-row">
      <div class="form-group"><label>Pickup country</label><select name="pickup_country" required><?php foreach ($eurosenderCountries as $code => $name): ?><option value="<?= htmlspecialchars($code) ?>" <?= $eurosenderQuoteInput['pickup_country'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Pickup street and number</label><input name="pickup_street" required value="<?= htmlspecialchars($eurosenderQuoteInput['pickup_street']) ?>"></div>
      <div class="form-group"><label>Pickup postal code</label><input name="pickup_postal" required value="<?= htmlspecialchars($eurosenderQuoteInput['pickup_postal']) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Pickup exact city</label><input name="pickup_city" required value="<?= htmlspecialchars($eurosenderQuoteInput['pickup_city']) ?>"><div class="form-hint">Dumbraveni, comuna Balotesti. Eurosender receives the exact locality name Dumbraveni.</div></div>
      <div class="form-group"><label>Pickup county / region</label><input name="pickup_region" value="<?= htmlspecialchars($eurosenderQuoteInput['pickup_region']) ?>"><div class="form-hint">Required for Romania and other countries that use regions.</div></div>
    </div>
    <h5 style="margin-top:20px;">Destination address</h5>
    <div class="form-row">
      <div class="form-group"><label>Destination country</label><select name="country" required><?php foreach ($eurosenderCountries as $code => $name): ?><option value="<?= htmlspecialchars($code) ?>" <?= $eurosenderQuoteInput['country'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Destination street and number</label><input name="street" required value="<?= htmlspecialchars($eurosenderQuoteInput['street']) ?>"></div>
      <div class="form-group"><label>Destination postal code</label><input name="postal" required value="<?= htmlspecialchars($eurosenderQuoteInput['postal']) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Destination exact city</label><input name="city" required value="<?= htmlspecialchars($eurosenderQuoteInput['city']) ?>"><div class="form-hint">Romanian Bucuresti/București is translated automatically to Eurosender's canonical Bucharest.</div></div>
      <div class="form-group"><label>Destination county / region</label><input name="region" value="<?= htmlspecialchars($eurosenderQuoteInput['region']) ?>"><div class="form-hint">Required when the selected country uses regions, including Romania.</div></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Length (cm)</label><input type="number" min="0.1" step="0.1" name="length" required value="<?= htmlspecialchars($eurosenderQuoteInput['length']) ?>"></div>
      <div class="form-group"><label>Width (cm)</label><input type="number" min="0.1" step="0.1" name="width" required value="<?= htmlspecialchars($eurosenderQuoteInput['width']) ?>"></div>
      <div class="form-group"><label>Height (cm)</label><input type="number" min="0.1" step="0.1" name="height" required value="<?= htmlspecialchars($eurosenderQuoteInput['height']) ?>"></div>
      <div class="form-group"><label>Weight (kg)</label><input type="number" min="0.01" step="0.01" name="weight" required value="<?= htmlspecialchars($eurosenderQuoteInput['weight']) ?>"></div>
      <div class="form-group"><label>Contents value (EUR)</label><input type="number" min="0.01" step="0.01" name="value" required value="<?= htmlspecialchars($eurosenderQuoteInput['value']) ?>"></div>
    </div>
    <button type="submit" class="btn btn-outline">Request all sandbox options (no booking)</button>
  </form>
</div>

<div class="admin-panel" style="margin-top:24px;">
  <h3>SmartBill API</h3>
  <p class="form-hint">The check below reads the invoice series and, only for VAT-registered issuers, tax rates. It cannot create, change or delete an invoice.</p>

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
    <tr><td>VAT</td><td class="mono"><?= defined('VAT_REGISTERED') && VAT_REGISTERED === true ? htmlspecialchars(defined('SMARTBILL_TAX_NAME') ? (string) SMARTBILL_TAX_NAME : 'not configured') : 'Not applicable (company is not VAT registered)' ?></td></tr>
    <?php if ($smartBillCheck): ?>
      <tr><td>SmartBill next number</td><td class="mono"><?= htmlspecialchars((string) ($smartBillCheck['series']['nextNumber'] ?? 'unknown')) ?></td></tr>
      <?php if ($smartBillCheck['tax']): ?><tr><td>Verified tax rate</td><td class="mono"><?= htmlspecialchars((string) ($smartBillCheck['tax']['name'] ?? 'unknown')) ?> (<?= htmlspecialchars((string) ($smartBillCheck['tax']['percentage'] ?? 'unknown')) ?>%)</td></tr><?php endif; ?>
    <?php endif; ?>
  </table>

  <form method="post" style="margin-top:16px;">
    <input type="hidden" name="action" value="check_smartbill">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <button type="submit" class="btn btn-secondary">Check SmartBill connection (read-only)</button>
  </form>
</div>

<div class="admin-panel" style="margin-top:24px;">
  <h3>Sameday Courier API</h3>
  <p class="form-hint">Credentials stay in <code>config.php</code>. Connection check and synchronization only read account data; they never create an AWB. Paid Sameday orders receive an AWB automatically when this integration is enabled, with a manual retry available on each order.</p>

  <?php if ($samedayCheck): ?>
    <div class="alert-banner" style="border-color:var(--success);color:var(--success);">Connection verified. No AWB was created.</div>
  <?php elseif ($samedaySync): ?>
    <div class="alert-banner" style="border-color:var(--success);color:var(--success);">Synchronized <?= (int) $samedaySync['services'] ?> services, <?= (int) $samedaySync['pickup_points'] ?> pickup points and <?= (int) $samedaySync['ooh_locations'] ?> Easybox/PUDO locations.</div>
  <?php elseif ($samedayError): ?>
    <div class="alert-banner" style="border-color:var(--danger);color:var(--danger);"><?= htmlspecialchars($samedayError) ?></div>
  <?php endif; ?>

  <?php
    $samedayCounts = ['services' => 0, 'pickups' => 0, 'ooh' => 0];
    try {
      $samedayCounts['services'] = (int) ggs_db()->query('SELECT COUNT(*) FROM sameday_services')->fetchColumn();
      $samedayCounts['pickups'] = (int) ggs_db()->query('SELECT COUNT(*) FROM sameday_pickup_points')->fetchColumn();
      $samedayCounts['ooh'] = (int) ggs_db()->query('SELECT COUNT(*) FROM sameday_ooh_locations WHERE active = 1 AND client_visible = 1')->fetchColumn();
    } catch (Throwable) {}
    $samedayServices = $samedayPickups = [];
    $samedayPdoIds = [];
    try {
      $samedayServices = ggs_db()->query('SELECT service_id, name, raw_json FROM sameday_services ORDER BY service_id')->fetchAll();
      $samedayPickups = ggs_db()->query('SELECT pickup_point_id, name, address, city, county, contact_person_id FROM sameday_pickup_points ORDER BY pickup_point_id')->fetchAll();
      foreach ($samedayServices as $service) {
        $raw = json_decode((string) $service['raw_json'], true);
        if (is_array($raw)) ggs_collect_sameday_pdo_ids($raw, $samedayPdoIds);
      }
    } catch (Throwable) {}
  ?>
  <table class="specs-table">
    <tr><td>Automatic AWB</td><td class="mono"><?= ggs_sameday_enabled() ? 'ENABLED' : 'DISABLED (safe)' ?></td></tr>
    <tr><td>Credentials</td><td class="mono"><?= ggs_sameday_configured() ? 'configured' : 'missing / placeholder' ?></td></tr>
    <tr><td>Environment</td><td class="mono"><?= htmlspecialchars(defined('SAMEDAY_API_BASE') ? (string) SAMEDAY_API_BASE : 'not configured') ?></td></tr>
    <tr><td>Account data</td><td class="mono"><?= $samedayCounts['services'] ?> services · <?= $samedayCounts['pickups'] ?> pickup points · <?= $samedayCounts['ooh'] ?> active OOH</td></tr>
    <tr><td>Pickup point override</td><td class="mono"><?= defined('SAMEDAY_PICKUP_POINT_ID') && (int) SAMEDAY_PICKUP_POINT_ID > 0 ? (int) SAMEDAY_PICKUP_POINT_ID : 'first synchronized point' ?></td></tr>
    <tr><td>Personal drop-off (PDO)</td><td class="mono"><?= ggs_sameday_personal_dropoff_enabled() ? 'ENABLED (resolved per service + package)' : 'off (courier pickup)' ?></td></tr>
  </table>
  <?php if ($samedayServices): ?><p class="form-hint" style="margin-top:12px;"><strong>Active services:</strong> <?= htmlspecialchars(implode(' · ', array_map(static fn(array $row): string => '#' . $row['service_id'] . ' ' . $row['name'], $samedayServices))) ?></p><?php endif; ?>
  <?php if ($samedayPickups): ?><p class="form-hint"><strong>Pickup points:</strong> <?= htmlspecialchars(implode(' · ', array_map(static fn(array $row): string => '#' . $row['pickup_point_id'] . ' ' . $row['name'] . ' (' . trim($row['address'] . ', ' . $row['city'] . ', ' . $row['county'], ', ') . '; contact #' . ($row['contact_person_id'] ?: '?') . ')', $samedayPickups))) ?></p><?php endif; ?>
  <?php if ($samedayPdoIds): ?><p class="form-hint"><strong>Detected PDO candidates:</strong> <?= htmlspecialchars(implode(' · ', array_map(static fn(int $id, string $name): string => '#' . $id . ' ' . $name, array_keys($samedayPdoIds), array_values($samedayPdoIds)))) ?>. When PDO is enabled, the site selects the candidate attached to the actual service and package type; these IDs are diagnostic only.</p><?php endif; ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;">
    <form method="post"><input type="hidden" name="action" value="check_sameday"><input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>"><button type="submit" class="btn btn-secondary">Check Sameday connection (read-only)</button></form>
    <form method="post"><input type="hidden" name="action" value="sync_sameday"><input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>"><button type="submit" class="btn btn-outline">Synchronize services, pickup &amp; OOH</button></form>
  </div>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
