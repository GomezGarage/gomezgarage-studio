<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

ggs_require_admin();

$db = ggs_db();

$validStatuses = ['pending','paid','processing','shipped','delivered','cancelled'];
$filter = $_GET['status'] ?? 'all';

if ($filter !== 'all' && in_array($filter, $validStatuses, true)) {
  $stmt = $db->prepare('SELECT * FROM orders WHERE status = ? ORDER BY created_at DESC');
  $stmt->execute([$filter]);
} else {
  $filter = 'all';
  $stmt = $db->query('SELECT * FROM orders ORDER BY created_at DESC');
}
$orders = $stmt->fetchAll();

$pageTitle = 'Orders';
$activeNav = 'orders';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Orders</h1>
    <div class="subtitle"><?= count($orders) ?> shown</div>
  </div>
</div>

<div class="filter-bar" style="margin-bottom:24px;">
  <a href="/studio/admin/orders.php" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">All</a>
  <?php foreach ($validStatuses as $s): ?>
    <a href="/studio/admin/orders.php?status=<?= $s ?>" class="filter-btn <?= $filter === $s ? 'active' : '' ?>"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th>Order</th><th>Customer</th><th>Email</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
      <?php if (!$orders): ?>
        <tr><td colspan="7">No orders found.</td></tr>
      <?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($o['order_number']) ?></td>
          <td><?= htmlspecialchars($o['customer_name']) ?></td>
          <td><?= htmlspecialchars($o['customer_email']) ?></td>
          <td class="mono"><?= number_format((float) $o['total_eur'], 2) ?> EUR</td>
          <td><span class="status-pill status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars($o['status']) ?></span></td>
          <td><?= date('d M Y H:i', strtotime($o['created_at'])) ?></td>
          <td><a href="/studio/admin/order-detail.php?id=<?= $o['id'] ?>">View →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
