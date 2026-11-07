<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
ggs_require_admin();
$db = ggs_db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM custom_inquiries WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$inquiry = $stmt->fetch();
if (!$inquiry) { header('Location: /studio/admin/custom-inquiries.php'); exit; }
$statuses = ['received','reviewing','needs_info','quoted','accepted','in_progress','completed','declined'];
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!ggs_admin_verify_csrf()) {
    $error = 'Your session expired. Refresh and try again.';
  } else {
    $status = (string) ($_POST['status'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    if (!in_array($status, $statuses, true) || strlen($note) > 500) {
      $error = 'Select a valid status and keep the update note under 500 characters.';
    } else {
      $changed = $status !== $inquiry['status'] || $note !== '';
      if ($status !== $inquiry['status']) {
        $db->prepare('UPDATE custom_inquiries SET status = ? WHERE id = ?')->execute([$status, $id]);
        $inquiry['status'] = $status;
      }
      if ($changed) {
        $db->prepare('INSERT INTO custom_inquiry_history (inquiry_id, status, note, created_by_admin_id) VALUES (?, ?, ?, ?)')
          ->execute([$id, $status, $note, $_SESSION['admin_id'] ?? null]);
        ggs_send_customer_custom_update($inquiry, $note);
      }
      $notice = 'Request updated. Customer email preferences were applied.';
    }
  }
}
$historyStmt = $db->prepare('SELECT status, note, created_at FROM custom_inquiry_history WHERE inquiry_id = ? ORDER BY created_at DESC');
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();
$attachments = json_decode((string) $inquiry['attachment_names'], true) ?: [];
$activeNav = 'custom-inquiries';
$pageTitle = 'Custom request ' . $inquiry['inquiry_number'];
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="admin-topbar"><div><h1 class="mono"><?= htmlspecialchars($inquiry['inquiry_number']) ?></h1><div class="subtitle"><a href="/studio/admin/custom-inquiries.php">&larr; Back to custom requests</a></div></div><span class="status-pill status-<?= htmlspecialchars($inquiry['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $inquiry['status']))) ?></span></div>
<?php if ($notice): ?><div class="alert-banner"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-banner" style="border-color:var(--danger);color:var(--danger);" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="admin-panel"><h3>Customer</h3><p><strong><?= htmlspecialchars($inquiry['customer_name']) ?></strong></p><p><a href="mailto:<?= rawurlencode($inquiry['customer_email']) ?>"><?= htmlspecialchars($inquiry['customer_email']) ?></a></p><p><strong>Budget:</strong> <?= htmlspecialchars($inquiry['budget'] ?: 'Not specified') ?></p><p><strong>Received:</strong> <?= htmlspecialchars($inquiry['created_at']) ?></p></div>
<div class="admin-panel"><h3>Request details</h3><p style="white-space:pre-wrap;"><?= htmlspecialchars($inquiry['need_text']) ?></p><?php if ($attachments): ?><p><strong>Reference files (attached to the original notification email):</strong> <?= htmlspecialchars(implode(', ', $attachments)) ?></p><?php endif; ?></div>
<div class="admin-panel"><h3>Update request</h3><form method="post"><input type="hidden" name="admin_csrf_token" value="<?= htmlspecialchars(ggs_admin_csrf_token()) ?>"><div class="form-row"><div class="form-group"><label>Status</label><select name="status"><?php foreach ($statuses as $status): ?><option value="<?= htmlspecialchars($status) ?>" <?= $status === $inquiry['status'] ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Message visible to customer (optional)</label><input type="text" name="note" maxlength="500" placeholder="e.g. Could you share the required dimensions?"></div></div><button class="btn btn-primary" type="submit">Save and notify customer</button></form></div>
<div class="admin-panel"><h3>Request timeline</h3><?php if (!$history): ?><p class="form-hint">No updates yet.</p><?php else: ?><ul><?php foreach ($history as $event): ?><li><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $event['status']))) ?></strong> — <?= htmlspecialchars($event['created_at']) ?><?php if ($event['note'] !== ''): ?><br><?= nl2br(htmlspecialchars($event['note'])) ?><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
