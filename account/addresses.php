<?php
require_once __DIR__ . '/../includes/customer-auth.php';
ggs_customer_start_session();
$account = ggs_customer_require_login();
$db = ggs_db();
$error = '';
$notice = '';
$countries = ['RO','GB','AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','SK','SI','ES','SE'];

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
      $postal = trim((string) ($_POST['postal'] ?? ''));
      $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
      if (!$name || !$street || !$city || !$postal || !in_array($country, $countries, true)) {
        $error = 'Complete the required address fields and select a supported country.';
      } elseif ($id > 0) {
        $db->prepare('UPDATE customer_addresses SET label = ?, recipient_name = ?, phone = ?, street = ?, city = ?, postal = ?, country = ? WHERE id = ? AND customer_account_id = ?')
          ->execute([$label, $name, $phone, $street, $city, $postal, $country, $id, $account['id']]);
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
          $db->prepare('INSERT INTO customer_addresses (customer_account_id, label, recipient_name, phone, street, city, postal, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$account['id'], $label, $name, $phone, $street, $city, $postal, $country, $default ? 1 : 0]);
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
$customerUser = $account;
$customerTitle = 'Saved addresses';
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
      <h3><?= ggs_customer_escape($address['label']) ?></h3><p><strong><?= ggs_customer_escape($address['recipient_name']) ?></strong></p><p><?= ggs_customer_escape($address['street']) ?></p><p><?= ggs_customer_escape($address['postal'] . ' ' . $address['city']) ?></p><p><?= ggs_customer_escape(ggs_customer_country_name($address['country'])) ?></p><?php if ($address['phone']): ?><p><?= ggs_customer_escape($address['phone']) ?></p><?php endif; ?>
      <div class="customer-link-row"><a href="/studio/account/addresses.php?edit=<?= (int) $address['id'] ?>" data-en="Edit" data-ro="Editeaza">Edit</a>
      <?php if ((int) $address['is_default'] !== 1): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="default"><input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>"><button class="btn btn-outline btn-sm" type="submit" data-en="Set default" data-ro="Seteaza implicita">Set default</button></form><?php endif; ?>
      <form method="post" onsubmit="return confirm('Remove this saved address?');"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>"><button class="btn btn-outline btn-sm" type="submit" data-en="Remove" data-ro="Sterge">Remove</button></form></div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<section class="customer-card"><h2 data-en="<?= $editing ? 'Edit address' : 'Add an address' ?>" data-ro="<?= $editing ? 'Editeaza adresa' : 'Adauga o adresa' ?>"><?= $editing ? 'Edit address' : 'Add an address' ?></h2>
  <form method="post" class="customer-form"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="address_id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="form-row"><div class="form-group"><label for="label" data-en="Address label" data-ro="Eticheta adresei">Address label</label><input id="label" name="label" maxlength="80" value="<?= ggs_customer_escape($editing['label'] ?? '') ?>" placeholder="Home"></div><div class="form-group"><label for="recipient_name" data-en="Recipient name" data-ro="Numele destinatarului">Recipient name</label><input id="recipient_name" name="recipient_name" required maxlength="200" value="<?= ggs_customer_escape($editing['recipient_name'] ?? $account['full_name']) ?>"></div></div>
    <div class="form-group"><label for="phone" data-en="Phone" data-ro="Telefon">Phone</label><input id="phone" name="phone" type="tel" maxlength="50" value="<?= ggs_customer_escape($editing['phone'] ?? '') ?>"></div>
    <div class="form-group"><label for="street" data-en="Street address" data-ro="Adresa">Street address</label><input id="street" name="street" required maxlength="255" value="<?= ggs_customer_escape($editing['street'] ?? '') ?>"></div>
    <div class="form-row"><div class="form-group"><label for="city" data-en="City" data-ro="Oras">City</label><input id="city" name="city" required maxlength="120" value="<?= ggs_customer_escape($editing['city'] ?? '') ?>"></div><div class="form-group"><label for="postal" data-en="Postal code" data-ro="Cod postal">Postal code</label><input id="postal" name="postal" required maxlength="30" value="<?= ggs_customer_escape($editing['postal'] ?? '') ?>"></div></div>
    <div class="form-group"><label for="country" data-en="Country" data-ro="Tara">Country</label><select id="country" name="country" required><?php foreach ($countries as $country): ?><option value="<?= $country ?>" <?= ($editing['country'] ?? 'RO') === $country ? 'selected' : '' ?>><?= ggs_customer_escape(ggs_customer_country_name($country)) ?></option><?php endforeach; ?></select></div>
    <?php if (!$editing): ?><label class="customer-checkbox"><input type="checkbox" name="is_default" value="1"><span data-en="Make this my default address" data-ro="Foloseste ca adresa implicita">Make this my default address</span></label><?php endif; ?>
    <div class="customer-form-actions"><button class="btn btn-primary" type="submit" data-en="Save address" data-ro="Salveaza adresa">Save address</button><?php if ($editing): ?><a class="btn btn-outline" href="/studio/account/addresses.php" data-en="Cancel" data-ro="Anuleaza">Cancel</a><?php endif; ?></div>
  </form>
</section>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
