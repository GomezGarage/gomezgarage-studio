<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

ggs_require_admin();

$db = ggs_db();

$monthStart = date('Y-m-01 00:00:00');
$revenueStmt = $db->prepare("SELECT COALESCE(SUM(total_eur), 0) AS total FROM orders WHERE status != 'cancelled' AND created_at >= ?");
$revenueStmt->execute([$monthStart]);
$revenueEur = (float) $revenueStmt->fetchColumn();
$rate = (float) ggs_get_setting('eur_ron_rate', '5.00');
$revenueRon = $revenueEur * $rate;

$counts = $db->query("SELECT
    COUNT(*) AS total,
    SUM(status = 'paid') AS paid,
    SUM(status = 'processing') AS processing,
    SUM(status = 'shipped') AS shipped
  FROM orders")->fetch();

$lowStock = $db->query(
  'SELECT id, name_en, stock, stock_threshold FROM products WHERE stock <= stock_threshold AND status = "published" ORDER BY stock ASC'
)->fetchAll();

$recentOrders = $db->query(
  'SELECT id, order_number, status, customer_name, total_eur, created_at FROM orders ORDER BY created_at DESC LIMIT 5'
)->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Dashboard</h1>
    <div class="subtitle">Welcome back, <?= htmlspecialchars($_SESSION['admin_username']) ?></div>
  </div>
</div>

<?php if ($lowStock): ?>
  <div class="alert-banner">
    ⚠ <?= count($lowStock) ?> product<?= count($lowStock) > 1 ? 's' : '' ?> at or below stock threshold —
    <?php foreach ($lowStock as $i => $p): ?>
      <a href="/studio/admin/product-edit.php?id=<?= $p['id'] ?>" style="color:inherit;text-decoration:underline;"><?= htmlspecialchars($p['name_en']) ?> (<?= $p['stock'] ?>)</a><?= $i < count($lowStock) - 1 ? ', ' : '' ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Revenue this month</div>
    <div class="stat-value"><?= number_format($revenueRon, 0) ?> RON</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total orders</div>
    <div class="stat-value"><?= (int) $counts['total'] ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Paid</div>
    <div class="stat-value"><?= (int) $counts['paid'] ?></div>
  </div>
  <div class="stat-card <?= $lowStock ? 'alert' : '' ?>">
    <div class="stat-label">Low stock alerts</div>
    <div class="stat-value"><?= count($lowStock) ?></div>
  </div>
</div>

<div class="admin-panel">
  <h3>Recent orders</h3>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php if (!$recentOrders): ?>
          <tr><td colspan="6">No orders yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($o['order_number']) ?></td>
            <td><?= htmlspecialchars($o['customer_name']) ?></td>
            <td class="mono"><?= number_format((float) $o['total_eur'], 2) ?> EUR</td>
            <td><span class="status-pill status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars($o['status']) ?></span></td>
            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
            <td><a href="/studio/admin/order-detail.php?id=<?= $o['id'] ?>">View →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
