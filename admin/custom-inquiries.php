<?php
require_once __DIR__ . '/../includes/auth.php';
ggs_require_admin();
$activeNav = 'custom-inquiries';
$pageTitle = 'Custom requests';
$db = ggs_db();
$stmt = $db->query('SELECT id, inquiry_number, customer_name, customer_email, status, created_at FROM custom_inquiries ORDER BY created_at DESC LIMIT 100');
$inquiries = $stmt->fetchAll();
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="admin-topbar"><div><h1>Custom requests</h1><div class="subtitle">Requests submitted from the customer form</div></div><span class="mono"><?= count($inquiries) ?> requests</span></div>
<div class="admin-panel">
  <?php if (!$inquiries): ?><p class="form-hint">No custom requests yet.</p><?php else: ?>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Request</th><th>Customer</th><th>Status</th><th>Received</th><th></th></tr></thead><tbody>
  <?php foreach ($inquiries as $inquiry): ?><tr><td class="mono"><?= htmlspecialchars($inquiry['inquiry_number']) ?></td><td><?= htmlspecialchars($inquiry['customer_name']) ?><br><span class="form-hint"><?= htmlspecialchars($inquiry['customer_email']) ?></span></td><td><span class="status-pill status-<?= htmlspecialchars($inquiry['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $inquiry['status']))) ?></span></td><td><?= htmlspecialchars($inquiry['created_at']) ?></td><td><a href="/studio/admin/custom-inquiry.php?id=<?= (int) $inquiry['id'] ?>">View →</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
