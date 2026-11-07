<?php
require_once __DIR__ . '/../includes/customer-auth.php';
require_once __DIR__ . '/../includes/customer-display.php';
ggs_customer_start_session();
$account = ggs_customer_require_login();
$db = ggs_db();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_customer_verify_csrf()) {
    $error = 'Your session expired. Refresh the page and try again.';
  } elseif (($_POST['action'] ?? '') === 'save_preferences') {
    $ordersEnabled = isset($_POST['email_order_updates']) ? 1 : 0;
    $customEnabled = isset($_POST['email_custom_updates']) ? 1 : 0;
    $db->prepare('UPDATE customer_accounts SET email_order_updates = ?, email_custom_updates = ? WHERE id = ?')
      ->execute([$ordersEnabled, $customEnabled, $account['id']]);
    $account['email_order_updates'] = $ordersEnabled;
    $account['email_custom_updates'] = $customEnabled;
    $notice = 'Notification preferences saved.';
  }
}

$stmt = $db->prepare('SELECT * FROM orders WHERE customer_account_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$account['id']]);
$orders = $stmt->fetchAll();
$stmt = $db->prepare('SELECT * FROM custom_inquiries WHERE customer_account_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$account['id']]);
$inquiries = $stmt->fetchAll();
$histories = [];
if ($orders) {
  $ids = array_map(static fn(array $order): int => (int) $order['id'], $orders);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $db->prepare("SELECT * FROM order_status_history WHERE order_id IN ({$placeholders}) ORDER BY created_at ASC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $event) $histories[(int) $event['order_id']][] = $event;
}
$inquiryHistories = [];
if ($inquiries) {
  $ids = array_map(static fn(array $inquiry): int => (int) $inquiry['id'], $inquiries);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $db->prepare("SELECT * FROM custom_inquiry_history WHERE inquiry_id IN ({$placeholders}) ORDER BY created_at ASC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $event) $inquiryHistories[(int) $event['inquiry_id']][] = $event;
}
$customerUser = $account;
$customerTitle = 'Your account';
require __DIR__ . '/../includes/customer-layout.php';
?>
<?php if ($notice): ?><div class="customer-alert" role="status"><?= ggs_customer_escape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="customer-alert customer-alert-error" role="alert"><?= ggs_customer_escape($error) ?></div><?php endif; ?>

<section class="customer-card">
  <div class="customer-dashboard-head"><div><p class="customer-meta" data-en="Signed in as" data-ro="Autentificat ca">Signed in as</p><h2><?= ggs_customer_escape($account['full_name']) ?></h2><p><?= ggs_customer_escape($account['email']) ?></p></div><form method="post" action="/studio/account/logout.php"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><button class="btn btn-outline" type="submit" data-en="Sign out" data-ro="Deconectare">Sign out</button></form></div>
  <div class="customer-link-row"><a href="/studio/account/addresses.php" data-en="Manage saved addresses" data-ro="Gestioneaza adresele salvate">Manage saved addresses</a><a href="/studio/products.html" data-en="Continue shopping" data-ro="Continua cumparaturile">Continue shopping</a></div>
</section>

<section class="customer-card">
  <h2 data-en="Order history" data-ro="Istoric comenzi">Order history</h2>
  <?php if (!$orders): ?><p class="customer-empty" data-en="No orders are linked to this account yet. Orders placed as a guest with this verified email will appear here." data-ro="Inca nu exista comenzi legate de acest cont. Comenzile ca vizitator facute cu acest email verificat vor aparea aici.">No orders are linked to this account yet. Orders placed as a guest with this verified email will appear here.</p><?php endif; ?>
  <?php foreach ($orders as $order): ?>
    <article class="customer-order">
      <div class="customer-item-head"><div><h3><?= ggs_customer_escape($order['order_number']) ?></h3><span class="customer-meta"><?= ggs_customer_escape(ggs_customer_format_date($order['created_at'])) ?></span></div><?= ggs_customer_status_badge($order['status']) ?></div>
      <p class="customer-meta" style="margin:10px 0 0;"><?= number_format((float) $order['total_eur'], 2) ?> EUR</p>
      <?php $orderItems = json_decode((string) $order['items'], true) ?: []; ?><p><?= ggs_customer_escape(implode(', ', array_map(static fn(array $item): string => ($item['name'] ?? 'Item') . ' × ' . (int) ($item['qty'] ?? 1), $orderItems))) ?></p>
      <?php if (!empty($order['tracking_number'])): ?><p><strong data-en="Tracking number:" data-ro="Numar de urmarire:">Tracking number:</strong> <?= ggs_customer_escape($order['tracking_number']) ?></p><?php endif; ?>
      <ul class="customer-timeline">
        <?php foreach ($histories[(int) $order['id']] ?? [] as $event): ?>
          <li><?= ggs_customer_status_badge((string) $event['status']) ?><?php if ($event['note'] !== ''): ?><p><?= nl2br(ggs_customer_escape($event['note'])) ?></p><?php endif; ?><time><?= ggs_customer_escape(ggs_customer_format_date($event['created_at'])) ?></time></li>
        <?php endforeach; ?>
      </ul>
    </article>
  <?php endforeach; ?>
</section>

<section class="customer-card">
  <div class="customer-dashboard-head"><h2 data-en="Custom requests" data-ro="Cereri custom">Custom requests</h2><a class="btn btn-outline" href="/studio/custom.html" data-en="Start a custom request" data-ro="Trimite o cerere custom">Start a custom request</a></div>
  <?php if (!$inquiries): ?><p class="customer-empty" data-en="Custom requests sent with this verified email will appear here." data-ro="Cererile custom trimise cu acest email verificat vor aparea aici.">Custom requests sent with this verified email will appear here.</p><?php endif; ?>
  <?php foreach ($inquiries as $inquiry): ?>
    <article class="customer-inquiry">
      <div class="customer-item-head"><div><h3><?= ggs_customer_escape($inquiry['inquiry_number']) ?></h3><span class="customer-meta"><?= ggs_customer_escape(ggs_customer_format_date($inquiry['created_at'])) ?></span></div><?= ggs_customer_status_badge($inquiry['status'], true) ?></div>
      <?php if ($inquiry['budget'] !== ''): ?><p class="customer-meta"><strong data-en="Budget:" data-ro="Buget:">Budget:</strong> <?= ggs_customer_escape($inquiry['budget']) ?></p><?php endif; ?>
      <p style="white-space:pre-wrap;"><?= ggs_customer_escape($inquiry['need_text']) ?></p>
      <?php $files = json_decode((string) $inquiry['attachment_names'], true) ?: []; if ($files): ?><p class="customer-meta"><strong data-en="Reference files:" data-ro="Fisiere de referinta:">Reference files:</strong> <?= ggs_customer_escape(implode(', ', $files)) ?></p><?php endif; ?>
      <ul class="customer-timeline">
        <?php foreach ($inquiryHistories[(int) $inquiry['id']] ?? [] as $event): ?><li><?= ggs_customer_status_badge((string) $event['status'], true) ?><?php if ($event['note'] !== ''): ?><p><?= nl2br(ggs_customer_escape($event['note'])) ?></p><?php endif; ?><time><?= ggs_customer_escape(ggs_customer_format_date($event['created_at'])) ?></time></li><?php endforeach; ?>
      </ul>
    </article>
  <?php endforeach; ?>
</section>

<section class="customer-card">
  <h2 data-en="Email preferences" data-ro="Preferinte email">Email preferences</h2>
  <p class="form-hint" data-en="Order confirmations and essential payment or shipping messages are always sent. Choose whether to receive additional progress updates." data-ro="Confirmarile comenzilor si mesajele esentiale despre plata sau livrare sunt trimise intotdeauna. Alege daca vrei si actualizari suplimentare despre progres.">Order confirmations and essential payment or shipping messages are always sent. Choose whether to receive additional progress updates.</p>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= ggs_customer_escape(ggs_customer_csrf_token()) ?>"><input type="hidden" name="action" value="save_preferences">
    <label class="customer-checkbox"><input type="checkbox" name="email_order_updates" value="1" <?= (int) $account['email_order_updates'] === 1 ? 'checked' : '' ?>><span data-en="Progress updates for product orders" data-ro="Actualizari de progres pentru comenzile de produse">Progress updates for product orders</span></label>
    <label class="customer-checkbox"><input type="checkbox" name="email_custom_updates" value="1" <?= (int) $account['email_custom_updates'] === 1 ? 'checked' : '' ?>><span data-en="Updates about custom requests" data-ro="Actualizari despre cererile custom">Updates about custom requests</span></label>
    <button class="btn btn-outline" type="submit" data-en="Save preferences" data-ro="Salveaza preferintele">Save preferences</button>
  </form>
</section>
<?php require __DIR__ . '/../includes/customer-layout-footer.php'; ?>
