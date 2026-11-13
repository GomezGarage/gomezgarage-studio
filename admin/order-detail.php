<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

ggs_require_admin();

$db = ggs_db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
  header('Location: /studio/admin/orders.php');
  exit;
}

$validStatuses = ['pending','paid','processing','printing','shipped','delivered','cancelled'];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if (!ggs_admin_verify_csrf()) {
    $notice = 'Your session expired. Refresh and try again.';
  } elseif ($action === 'update_status' && in_array($_POST['status'] ?? '', $validStatuses, true)) {
    $nextStatus = (string) $_POST['status'];
    $note = trim((string) ($_POST['status_note'] ?? ''));
    $changed = $nextStatus !== $order['status'] || $note !== '';
    if ($nextStatus !== $order['status']) {
      $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$nextStatus, $id]);
      $order['status'] = $nextStatus;
    }
    if ($changed) {
      $db->prepare('INSERT INTO order_status_history (order_id, status, note, created_by_admin_id) VALUES (?, ?, ?, ?)')
        ->execute([$id, $nextStatus, $note, $_SESSION['admin_id'] ?? null]);
      ggs_send_customer_order_status_update($order, $note, in_array($nextStatus, ['shipped', 'delivered'], true));
    }
    $notice = 'Status updated. Customer email preferences were applied.';
  } elseif ($action === 'update_tracking') {
    $tracking = trim((string) ($_POST['tracking_number'] ?? ''));
    if (strlen($tracking) > 100) {
      $notice = 'Tracking number must be 100 characters or fewer.';
    } else {
      $changed = $tracking !== (string) ($order['tracking_number'] ?? '');
      $db->prepare('UPDATE orders SET tracking_number = ? WHERE id = ?')->execute([$tracking, $id]);
      $order['tracking_number'] = $tracking;
      if ($changed) {
        $note = $tracking === '' ? 'Tracking number removed.' : 'Tracking number added or updated.';
        $db->prepare('INSERT INTO order_status_history (order_id, status, note, created_by_admin_id) VALUES (?, ?, ?, ?)')
          ->execute([$id, $order['status'], $note, $_SESSION['admin_id'] ?? null]);
        if ($tracking !== '') ggs_send_customer_order_status_update($order, $note, true);
      }
      $notice = 'Tracking number saved. Customer email preferences were applied.';
    }
  } elseif ($action === 'send_update') {
    ggs_send_order_update($order);
    $notice = 'Update email sent to customer.';
  }
}

$items = json_decode($order['items'], true) ?: [];
$address = json_decode($order['shipping_address'], true) ?: [];

$pageTitle = 'Order ' . $order['order_number'];
$activeNav = 'orders';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topbar">
  <div>
    <h1 class="mono"><?= htmlspecialchars($order['order_number']) ?></h1>
    <div class="subtitle"><a href="/studio/admin/orders.php">&larr; Back to orders</a></div>
  </div>
  <span class="status-pill status-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span>
</div>

<?php if (array_key_exists('invoice_status', $order)): ?>
<div class="admin-panel">
  <h3>Invoice</h3>
  <p>
    Provider: <strong><?= htmlspecialchars($order['invoice_provider'] ?: 'not selected') ?></strong><br>
    Status: <strong><?= htmlspecialchars($order['invoice_status'] ?: 'not issued') ?></strong><br>
    <?php if ($order['invoice_number']): ?>Number: <span class="mono"><?= htmlspecialchars($order['invoice_number']) ?></span><br><?php endif; ?>
    <?php if ($order['invoice_currency']): ?>Currency: <?= htmlspecialchars($order['invoice_currency']) ?><br><?php endif; ?>
    <?php if ($order['invoice_view_url']): ?><a href="<?= htmlspecialchars($order['invoice_view_url']) ?>" target="_blank" rel="noopener noreferrer">Open SmartBill invoice</a><?php endif; ?>
  </p>
  <?php if ($order['invoice_error']): ?><div class="alert-banner"><?= htmlspecialchars($order['invoice_error']) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($notice): ?><div class="alert-banner" style="border-color:var(--success);color:var(--success);"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<div class="admin-panel">
  <h3>Customer</h3>
  <p><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
  <p><?= htmlspecialchars($order['customer_email']) ?><?= $order['customer_phone'] ? ' · ' . htmlspecialchars($order['customer_phone']) : '' ?></p>
  <p><?= htmlspecialchars($address['street'] ?? '') ?>, <?= htmlspecialchars($address['city'] ?? '') ?><?= !empty($address['county']) ? ', ' . htmlspecialchars($address['county']) : '' ?>, <?= htmlspecialchars($address['postal'] ?? '') ?>, <?= htmlspecialchars($address['country'] ?? '') ?></p>
  <?php if (($order['customer_type'] ?? 'pf') === 'pj'): ?>
    <p><strong>Company invoice:</strong> <?= htmlspecialchars($order['company_name'] ?? '') ?><br>
      CUI/CIF: <?= htmlspecialchars($order['company_cui'] ?? '') ?><?= !empty($order['company_reg']) ? ' · Reg. Com: ' . htmlspecialchars($order['company_reg']) : '' ?><br>
      <?= htmlspecialchars($order['company_address'] ?? '') ?>, <?= htmlspecialchars($order['company_city'] ?? '') ?>, <?= htmlspecialchars($order['company_county'] ?? '') ?>, <?= htmlspecialchars($order['company_postal'] ?? '') ?>, <?= htmlspecialchars($order['company_country'] ?? '') ?><br>
      VAT registered: <?= !empty($order['company_vat_payer']) ? 'yes' : 'no' ?>
    </p>
  <?php endif; ?>
  <?php if ($order['customer_note']): ?>
    <p class="form-hint">Note: <?= nl2br(htmlspecialchars($order['customer_note'])) ?></p>
  <?php endif; ?>
</div>

<div class="admin-panel">
  <h3>Items</h3>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Line total</th></tr></thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['name']) ?></td>
            <td><?= (int) $item['qty'] ?></td>
            <td class="mono"><?= number_format((float) $item['price_eur'], 2) ?> EUR</td>
            <td class="mono"><?= number_format((float) $item['price_eur'] * $item['qty'], 2) ?> EUR</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="margin-top:16px;">
    Subtotal: <span class="mono"><?= number_format((float) $order['subtotal_eur'], 2) ?> EUR</span><br>
    Shipping: <span class="mono"><?= number_format((float) $order['shipping_eur'], 2) ?> EUR</span><br>
    <strong>Total: <span class="mono"><?= number_format((float) $order['total_eur'], 2) ?> EUR</span></strong>
  </p>
</div>

<div class="admin-panel">
  <h3>Status</h3>
  <form method="post" class="form-row" style="align-items:end;">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="update_status">
    <div class="form-group">
      <label>Order status</label>
      <select name="status">
        <?php foreach ($validStatuses as $s): ?>
          <option value="<?= htmlspecialchars($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Customer update note (optional)</label><input type="text" name="status_note" maxlength="500" placeholder="e.g. Your part has entered the print queue"></div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Update status and notify</button></div>
  </form>
</div>

<div class="admin-panel">
  <h3>Tracking</h3>
  <form method="post" class="form-row" style="align-items:end;">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="update_tracking">
    <div class="form-group">
      <label>Tracking number</label>
      <input type="text" name="tracking_number" value="<?= htmlspecialchars($order['tracking_number'] ?? '') ?>">
    </div>
    <div class="form-group"><button type="submit" class="btn btn-outline">Save tracking</button></div>
  </form>
  <form method="post" style="margin-top:12px;">
    <input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>">
    <input type="hidden" name="action" value="send_update">
    <button type="submit" class="btn btn-primary">Email status/tracking update to customer</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
