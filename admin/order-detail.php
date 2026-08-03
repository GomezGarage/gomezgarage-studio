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

$validStatuses = ['pending','paid','processing','shipped','delivered','cancelled'];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'update_status' && in_array($_POST['status'] ?? '', $validStatuses, true)) {
    $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$_POST['status'], $id]);
    $order['status'] = $_POST['status'];
    $notice = 'Status updated.';
  }

  if ($action === 'update_tracking') {
    $tracking = trim($_POST['tracking_number'] ?? '');
    $db->prepare('UPDATE orders SET tracking_number = ? WHERE id = ?')->execute([$tracking, $id]);
    $order['tracking_number'] = $tracking;
    $notice = 'Tracking number saved.';
  }

  if ($action === 'send_update') {
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

<?php if ($notice): ?><div class="alert-banner" style="border-color:var(--success);color:var(--success);"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<div class="admin-panel">
  <h3>Customer</h3>
  <p><strong><?= htmlspecialchars($order['customer_name']) ?></strong></p>
  <p><?= htmlspecialchars($order['customer_email']) ?><?= $order['customer_phone'] ? ' · ' . htmlspecialchars($order['customer_phone']) : '' ?></p>
  <p><?= htmlspecialchars($address['street'] ?? '') ?>, <?= htmlspecialchars($address['city'] ?? '') ?>, <?= htmlspecialchars($address['postal'] ?? '') ?>, <?= htmlspecialchars($address['country'] ?? '') ?></p>
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
    <input type="hidden" name="action" value="update_status">
    <div class="form-group">
      <label>Order status</label>
      <select name="status">
        <?php foreach ($validStatuses as $s): ?>
          <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><button type="submit" class="btn btn-primary">Update status</button></div>
  </form>
</div>

<div class="admin-panel">
  <h3>Tracking</h3>
  <form method="post" class="form-row" style="align-items:end;">
    <input type="hidden" name="action" value="update_tracking">
    <div class="form-group">
      <label>Tracking number</label>
      <input type="text" name="tracking_number" value="<?= htmlspecialchars($order['tracking_number'] ?? '') ?>">
    </div>
    <div class="form-group"><button type="submit" class="btn btn-outline">Save tracking</button></div>
  </form>
  <form method="post" style="margin-top:12px;">
    <input type="hidden" name="action" value="send_update">
    <button type="submit" class="btn btn-primary">Email status/tracking update to customer</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
