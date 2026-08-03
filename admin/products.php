<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

ggs_require_admin();

$db = ggs_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $id = (int) ($_POST['id'] ?? 0);
  if ($id) {
    $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
  }
  header('Location: /studio/admin/products.php');
  exit;
}

$products = $db->query(
  'SELECT id, name_en, category, material, price_eur, stock, stock_threshold, images, status, featured FROM products ORDER BY created_at DESC'
)->fetchAll();

$pageTitle = 'Products';
$activeNav = 'products';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Products</h1>
    <div class="subtitle"><?= count($products) ?> total</div>
  </div>
  <a href="/studio/admin/product-edit.php" class="btn btn-primary">+ Add Product</a>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr><th></th><th>Name</th><th>Category</th><th>Material</th><th>Price</th><th>Stock</th><th>Status</th><th>Featured</th><th></th></tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="9">No products yet. <a href="/studio/admin/product-edit.php">Add your first product</a>.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p):
        $images = json_decode($p['images'] ?? '[]', true) ?: [];
        $thumb = $images[0] ?? '/studio/assets/images/products/placeholder.svg';
        $lowStock = $p['stock'] <= $p['stock_threshold'];
      ?>
        <tr>
          <td><img class="thumb" src="<?= htmlspecialchars($thumb) ?>" alt=""></td>
          <td><?= htmlspecialchars($p['name_en']) ?></td>
          <td class="mono"><?= htmlspecialchars($p['category']) ?></td>
          <td><?= htmlspecialchars($p['material']) ?></td>
          <td class="mono"><?= number_format((float) $p['price_eur'], 2) ?> EUR</td>
          <td class="mono <?= $lowStock ? 'badge-danger' : '' ?>"><?= (int) $p['stock'] ?></td>
          <td><span class="status-pill status-<?= $p['status'] === 'published' ? 'paid' : 'pending' ?>"><?= htmlspecialchars($p['status']) ?></span></td>
          <td><?= $p['featured'] ? '★' : '—' ?></td>
          <td class="row-actions">
            <a href="/studio/admin/product-edit.php?id=<?= $p['id'] ?>">Edit</a>
            <form method="post" action="/studio/admin/products.php" onsubmit="return confirm('Delete this product?');" style="display:inline;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
