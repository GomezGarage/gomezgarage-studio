<?php
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/eurosender.php';
ggs_customer_start_session();
$account = ggs_customer_require_login();
$db = ggs_db();
$error = '';
$notice = '';
$countries = ['RO','AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','SK','SI','ES','SE'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_customer_verify_csrf()) {
    $error = 'Your session expired. Refresh the page and try again.';
  } else {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete') {
      $id = (int) ($_POST['address_id'] ?? 0);
      $stmt = $db->prepare('SELECT is_default FROM customer_addresses WHERE id = ? AND customer_account_id = ? LIMIT 1');
      $stmt->execute([$id, $account['id']]);
      $wasDefault = (int) $stmt->fetchColumn() === 1;
      $db->prepare('DELETE FROM customer_addresses WHERE id = ? AND customer_account_id = ?')->execute([$id, $account['id']]);
      if ($wasDefault) {
        $stmt = $db->prepare('SELECT id FROM customer_addresses WHERE customer_account_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$account['id']]);
        $nextDefault = $stmt->fetchColumn();
        if ($nextDefault) $db->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = ?')->execute([$nextDefault]);
      }
      $notice = 'Address removed.';
    } elseif ($action === 'default') {
      $id = (int) ($_POST['address_id'] ?? 0);
      $db->beginTransaction();
      $db->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_account_id = ?')->execute([$account['id']]);
      $db->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_account_id = ?')->execute([$id, $account['id']]);
      $db->commit();
      $notice = 'Default address updated.';
    } else {
      $id = (int) ($_POST['address_id'] ?? 0);
      $label = trim((string) ($_POST['label'] ?? '')) ?: 'Home';
      $name = trim((string) ($_POST['recipient_name'] ?? ''));
      $phone = trim((string) ($_POST['phone'] ?? ''));
      $street = trim((string) ($_POST['street'] ?? ''));
      $city = trim((string) ($_POST['city'] ?? ''));
      $county = trim((string) ($_POST['county'] ?? ''));
      $postal = trim((string) ($_POST['postal'] ?? ''));
      $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
      $postalValid = ggs_eurosender_postal_valid($country, $postal);
      if (!$name || !$street || !$city || !$postalValid || ($country === 'RO' && !$county) || !in_array($country, $countries, true)) {
        $error = 'Complete the required address fields and select a supported country.';
      } elseif ($id > 0) {
        $db->prepare('UPDATE customer_addresses SET label = ?, recipient_name = ?, phone = ?, street = ?, city = ?, county = ?, postal = ?, country = ? WHERE id = ? AND customer_account_id = ?')
          ->execute([$label, $name, $phone, $street, $city, $county, $postal, $country, $id, $account['id']]);
        $notice = 'Address updated.';
      } else {
        $countStmt = $db->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_account_id = ?');
        $countStmt->execute([$account['id']]);
        $addressCount = (int) $countStmt->fetchColumn();
        if ($addressCount >= 10) {
          $error = 'You can save up to 10 addresses.';
        } else {
          $default = isset($_POST['is_default']) || $addressCount === 0;
          if ($default) $db->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_account_id = ?')->execute([$account['id']]);
          $db->prepare('INSERT INTO customer_addresses (customer_account_id, label, recipient_name, phone, street, city, county, postal, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$account['id'], $label, $name, $phone, $street, $city, $county, $postal, $country, $default ? 1 : 0]);
          $notice = 'Address saved.';
        }
      }
    }
  }
}
$stmt = $db->prepare('SELECT * FROM customer_addresses WHERE customer_account_id = ? ORDER BY is_default DESC, created_at DESC');
$stmt->execute([$account['id']]);
$addresses = $stmt->fetchAll();
$editing = null;
if (isset($_GET['edit'])) {
  $stmt = $db->prepare('SELECT * FROM customer_addresses WHERE id = ? AND customer_account_id = ? LIMIT 1');
  $stmt->execute([(int) $_GET['edit'], $account['id']]);
  $editing = $stmt->fetch() ?: null;
}
$formAddress = $editing ?: [];
if ($error !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? 'save') === 'save') {
  $formAddress = [
    'id' => (int) ($_POST['address_id'] ?? 0),
    'label' => trim((string) ($_POST['label'] ?? '')),
    'recipient_name' => trim((string) ($_POST['recipient_name'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'street' => trim((string) ($_POST['street'] ?? '')),
    'city' => trim((string) ($_POST['city'] ?? '')),
    'county' => trim((string) ($_POST['county'] ?? '')),
    'postal' => trim((string) ($_POST['postal'] ?? '')),
    'country' => strtoupper(trim((string) ($_POST['country'] ?? 'RO'))),
  ];
}
$customerUser = $account;
$customerTitle = 'Saved addresses';
$customerPageScript = '/studio/assets/js/account-addresses.js?v=20261115-guided-addresses';
require __DIR__ . '/../includes/customer-layout.php';
?>
<?php if ($notice): ?><div class="customer-alert" role="status"><?= ggs_customer_escape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="customer-alert customer-alert-error" role="alert"><?= ggs_customer_escape($error) ?></div><?php endif; ?>
<div class="customer-link-row" style="margin:0 0 22px;"><a href="/studio/account/" data-en="Back to account" data-ro="Inapoi la cont">Back to account</a></div>
<section class="customer-card"><h2 data-en="Your addresses" data-ro="Adresele tale">Your addresses</h2>
  <?php if (!$addresses): ?><p class="customer-empty" data-en="No saved addresses yet." data-ro="Nu ai adrese salvate.">No saved addresses yet.</p><?php endif; ?>
  <div class="customer-address-grid">
  <?php foreach ($addresses as $address): ?>
    <article class="customer-address-card">
      <?php if ((int) $address['is_default'] === 1): ?><span class="customer-default-label" data-en="Default address" data-ro="Adresa implicita">Default address</span><?php endif; ?>
      <h3><?= ggs_customer_escape($address['label']) ?></h3><p><strong><?= ggs_customer_escape($address['recipient_name']) ?></strong></p><p><?= ggs_customer_escape($address['street']) ?></p><p><?= ggs_customer_escape($address['postal'] . ' ' . $address['city']) ?><?= $address['county'] ? ', <span' . (strtoupper((string) $address['country']) === 'RO' && ggs_eurosender_is_bucharest_alias((string) $address['county']) ? ' data-en="Bucharest" data-ro="Bucuresti"' : '') . '>' . ggs_customer_escape((string) $address['county']) . '</span>' : '' ?></p><p><?= ggs_customer_escape(ggs_customer_country_name($address['country'])) ?></p><?php if ($address['phone']): ?><p><?= ggs_customer_escape($address['phone']) ?></p><?php endif; ?>
      <div class="customer-link-row"><a href="/studio/account/addresses.php?edit=<?= (int) $address['id'] ?>" data-en="Edit" data-ro="Editeaza">Edit</a>
      <?php if ((int) $address['is_default'] !== 1): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="default"><input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>"><button class="btn btn-outline btn-sm" type="submit" data-en="Set default" data-ro="Seteaza implicita">Set default</button></form><?php endif; ?>
      <form method="post" onsubmit="return confirm('Remove this saved address?');"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>"><button class="btn btn-outline btn-sm" type="submit" data-en="Remove" data-ro="Sterge">Remove</button></form></div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<section class="customer-card"><h2 data-en="<?= $editing ? 'Edit address' : 'Add an address' ?>" data-ro="<?= $editing ? 'Editeaza adresa' : 'Adauga o adresa' ?>"><?= $editing ? 'Edit address' : 'Add an address' ?></h2>
  <form method="post" class="customer-form" data-account-address-form><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="address_id" value="<?= (int) ($formAddress['id'] ?? 0) ?>">
    <div class="form-row"><div class="form-group"><label for="label" data-en="Address label" data-ro="Eticheta adresei">Address label</label><input id="label" name="label" maxlength="80" value="<?= ggs_customer_escape($formAddress['label'] ?? '') ?>" placeholder="Home"></div><div class="form-group"><label for="recipient_name" data-en="Recipient name" data-ro="Numele destinatarului">Recipient name</label><input id="recipient_name" name="recipient_name" required maxlength="200" value="<?= ggs_customer_escape($formAddress['recipient_name'] ?? $account['full_name']) ?>"></div></div>
    <div class="form-group"><label for="phone" data-en="Phone" data-ro="Telefon">Phone</label><input id="phone" name="phone" type="tel" maxlength="50" value="<?= ggs_customer_escape($formAddress['phone'] ?? '') ?>"></div>
    <div class="form-group"><label for="country" data-en="Country" data-ro="Tara">Country</label><select id="country" name="country" required autocomplete="country" data-address-country><?php foreach ($countries as $country): ?><option value="<?= $country ?>" <?= ($formAddress['country'] ?? 'RO') === $country ? 'selected' : '' ?>><?= ggs_customer_escape(ggs_customer_country_name($country)) ?></option><?php endforeach; ?></select></div>
    <div class="form-row">
      <div class="form-group"><label for="county" data-en="County / region" data-ro="Judet / regiune">County / region</label><input id="county" name="county" maxlength="120" value="<?= ggs_customer_escape($formAddress['county'] ?? '') ?>" autocomplete="address-level1" list="account-region-suggestions" data-address-region-input><select class="hidden" disabled aria-label="County / region" data-address-region-select><option value="">Choose county / region</option></select><datalist id="account-region-suggestions"></datalist><small class="form-hint" data-en="Choose the official region used by the courier." data-ro="Alege judetul sau regiunea oficiala folosita de curier.">Choose the official region used by the courier.</small></div>
      <div class="form-group"><label for="city" data-en="City / locality" data-ro="Oras / localitate">City / locality</label><input id="city" name="city" required maxlength="120" value="<?= ggs_customer_escape($formAddress['city'] ?? '') ?>" autocomplete="address-level2" list="account-city-suggestions" data-address-city><datalist id="account-city-suggestions"></datalist><small class="form-hint" data-en="Start typing, then choose the matching locality." data-ro="Incepe sa scrii, apoi alege localitatea corecta.">Start typing, then choose the matching locality.</small></div>
    </div>
    <div class="form-row"><div class="form-group"><label for="postal" data-en="Postal code" data-ro="Cod postal">Postal code</label><input id="postal" name="postal" required maxlength="30" value="<?= ggs_customer_escape($formAddress['postal'] ?? '') ?>" autocomplete="postal-code" data-address-postal><small class="form-hint" data-en="Use the postal code for this exact address." data-ro="Introdu codul postal al adresei exacte.">Use the postal code for this exact address.</small></div><div class="form-group"><label for="street" data-en="Street and number" data-ro="Strada si numar">Street and number</label><input id="street" name="street" required maxlength="255" value="<?= ggs_customer_escape($formAddress['street'] ?? '') ?>" autocomplete="street-address"></div></div>
    <?php if (!$editing): ?><label class="customer-checkbox"><input type="checkbox" name="is_default" value="1"><span data-en="Make this my default address" data-ro="Foloseste ca adresa implicita">Make this my default address</span></label><?php endif; ?>
    <div class="customer-form-actions"><button class="btn btn-primary" type="submit" data-en="Save address" data-ro="Salveaza adresa">Save address</button><?php if ($editing): ?><a class="btn btn-outline" href="/studio/account/addresses.php" data-en="Cancel" data-ro="Anuleaza">Cancel</a><?php endif; ?></div>
  </form>
</section>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
