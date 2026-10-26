<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/catalog.php';

ggs_require_admin();

$db = ggs_db();
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;
if (isset($_POST['id']) && ctype_digit($_POST['id'])) {
  $id = (int) $_POST['id'];
}

$product = [
  'slug' => '', 'name_en' => '', 'name_ro' => '',
  'desc_short_en' => '', 'desc_short_ro' => '',
  'desc_long_en' => '', 'desc_long_ro' => '',
  'how_made_en' => '', 'how_made_ro' => '',
  'material' => '', 'category' => 'desk',
  'price_eur' => '', 'stock' => 0, 'stock_threshold' => 3,
  'images' => [], 'specs' => [],
  'status' => 'draft', 'featured' => 0,
];

if ($id) {
  $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
  $stmt->execute([$id]);
  $existing = $stmt->fetch();
  if (!$existing) {
    header('Location: /studio/admin/products.php');
    exit;
  }
  $existing['images'] = json_decode($existing['images'] ?? '[]', true) ?: [];
  $existing['specs'] = json_decode($existing['specs'] ?? '[]', true) ?: [];
  $product = array_merge($product, $existing);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save') === 'save') {
  $product['name_en'] = trim($_POST['name_en'] ?? '');
  $product['name_ro'] = trim($_POST['name_ro'] ?? '');
  $product['desc_short_en'] = trim($_POST['desc_short_en'] ?? '');
  $product['desc_short_ro'] = trim($_POST['desc_short_ro'] ?? '');
  $product['desc_long_en'] = trim($_POST['desc_long_en'] ?? '');
  $product['desc_long_ro'] = trim($_POST['desc_long_ro'] ?? '');
  $product['how_made_en'] = trim($_POST['how_made_en'] ?? '');
  $product['how_made_ro'] = trim($_POST['how_made_ro'] ?? '');
  $product['material'] = trim($_POST['material'] ?? '');
  $product['category'] = in_array($_POST['category'] ?? '', ['desk','display','automotive','other','home'], true) ? $_POST['category'] : 'desk';
  $product['price_eur'] = (float) ($_POST['price_eur'] ?? 0);
  $product['stock'] = max(0, (int) ($_POST['stock'] ?? 0));
  $product['stock_threshold'] = max(0, (int) ($_POST['stock_threshold'] ?? 3));
  $product['status'] = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
  $product['featured'] = !empty($_POST['featured']) ? 1 : 0;

  if ($product['name_en'] === '') $errors[] = 'English name is required.';
  if (!is_finite($product['price_eur']) || $product['price_eur'] < 0) $errors[] = 'Enter a valid price.';
  if ($product['status'] === 'published' && $product['price_eur'] <= 0) $errors[] = 'Set a price greater than 0 before publishing.';

  // ---- Specs (key/value repeatable rows) ----
  $specs = [];
  $specKeys = $_POST['spec_key'] ?? [];
  $specValues = $_POST['spec_value'] ?? [];
  foreach ($specKeys as $i => $key) {
    $key = trim($key);
    $value = trim($specValues[$i] ?? '');
    if ($key !== '' && $value !== '') {
      $specs[] = ['key' => $key, 'value' => $value];
    }
  }
  $product['specs'] = $specs;

  // ---- Images: keep existing minus removed, reorder for primary, append new uploads ----
  $existingImages = $product['images'];
  $removed = $_POST['remove_image'] ?? [];
  $kept = array_values(array_diff($existingImages, $removed));

  $primary = $_POST['primary_image'] ?? '';
  if ($primary && in_array($primary, $kept, true)) {
    $kept = array_values(array_merge([$primary], array_diff($kept, [$primary])));
  }

  $uploadDir = __DIR__ . '/../assets/images/products/';
  $newImages = [];
  if (!empty($_FILES['images']['name'][0] ?? null)) {
    foreach ($_FILES['images']['name'] as $i => $name) {
      if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg','png','webp','svg'], true)) continue;
      $safeName = bin2hex(random_bytes(8)) . '.' . $ext;
      if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir . $safeName)) {
        $newImages[] = '/studio/assets/images/products/' . $safeName;
      }
    }
  }
  $product['images'] = array_merge($kept, $newImages);
  if ($product['status'] === 'published' && !$product['images']) $errors[] = 'Add a product photograph before publishing.';

  if (!$errors) {
    if (!$id) {
      $product['slug'] = ggs_unique_slug($db, $product['name_en']);
      $stmt = $db->prepare(
        'INSERT INTO products
          (slug, name_en, name_ro, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro,
           material, category, price_eur, stock, stock_threshold, images, specs, status, featured)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
      );
      $stmt->execute([
        $product['slug'], $product['name_en'], $product['name_ro'],
        $product['desc_short_en'], $product['desc_short_ro'],
        $product['desc_long_en'], $product['desc_long_ro'],
        $product['how_made_en'], $product['how_made_ro'],
        $product['material'], $product['category'], $product['price_eur'],
        $product['stock'], $product['stock_threshold'],
        json_encode($product['images']), json_encode($product['specs']),
        $product['status'], $product['featured'],
      ]);
      $id = (int) $db->lastInsertId();
    } else {
      $stmt = $db->prepare(
        'UPDATE products SET name_en=?, name_ro=?, desc_short_en=?, desc_short_ro=?, desc_long_en=?, desc_long_ro=?,
           how_made_en=?, how_made_ro=?, material=?, category=?, price_eur=?, stock=?, stock_threshold=?,
           images=?, specs=?, status=?, featured=? WHERE id=?'
      );
      $stmt->execute([
        $product['name_en'], $product['name_ro'],
        $product['desc_short_en'], $product['desc_short_ro'],
        $product['desc_long_en'], $product['desc_long_ro'],
        $product['how_made_en'], $product['how_made_ro'],
        $product['material'], $product['category'], $product['price_eur'],
        $product['stock'], $product['stock_threshold'],
        json_encode($product['images']), json_encode($product['specs']),
        $product['status'], $product['featured'], $id,
      ]);
    }

    header('Location: /studio/admin/products.php');
    exit;
  }
}

function ggs_unique_slug(PDO $db, string $name): string
{
  $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
  $slug = $base;
  $i = 1;
  while (true) {
    $stmt = $db->prepare('SELECT id FROM products WHERE slug = ?');
    $stmt->execute([$slug]);
    if (!$stmt->fetch()) return $slug;
    $slug = $base . '-' . (++$i);
  }
}

$pageTitle = $id ? 'Edit Product' : 'Add Product';
$activeNav = 'products';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topbar">
  <div>
    <h1><?= $id ? 'Edit Product' : 'Add Product' ?></h1>
    <div class="subtitle"><a href="/studio/admin/products.php">&larr; Back to products</a></div>
  </div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="admin-error" style="margin-bottom:16px;"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<form method="post" action="/studio/admin/product-edit.php<?= $id ? '?id=' . $id : '' ?>" enctype="multipart/form-data">
  <input type="hidden" name="id" value="<?= $id ?? '' ?>">

  <div class="admin-panel">
    <h3>Name &amp; description</h3>
    <div class="form-row">
      <div class="form-group"><label>Name (EN)</label><input type="text" name="name_en" value="<?= htmlspecialchars($product['name_en']) ?>" required></div>
      <div class="form-group"><label>Name (RO)</label><input type="text" name="name_ro" value="<?= htmlspecialchars($product['name_ro']) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Short description (EN)</label><textarea name="desc_short_en" rows="3"><?= htmlspecialchars($product['desc_short_en']) ?></textarea></div>
      <div class="form-group"><label>Short description (RO)</label><textarea name="desc_short_ro" rows="3"><?= htmlspecialchars($product['desc_short_ro']) ?></textarea></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Long description (EN)</label><textarea name="desc_long_en" rows="5"><?= htmlspecialchars($product['desc_long_en']) ?></textarea></div>
      <div class="form-group"><label>Long description (RO)</label><textarea name="desc_long_ro" rows="5"><?= htmlspecialchars($product['desc_long_ro']) ?></textarea></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>How it's made (EN)</label><textarea name="how_made_en" rows="4"><?= htmlspecialchars($product['how_made_en']) ?></textarea></div>
      <div class="form-group"><label>How it's made (RO)</label><textarea name="how_made_ro" rows="4"><?= htmlspecialchars($product['how_made_ro']) ?></textarea></div>
    </div>
  </div>

  <div class="admin-panel">
    <h3>Classification &amp; pricing</h3>
    <div class="form-row">
      <div class="form-group"><label>Material</label><input type="text" name="material" value="<?= htmlspecialchars($product['material']) ?>" placeholder="ASA-CF"></div>
      <div class="form-group">
        <label>Category</label>
        <select name="category">
          <?php foreach (ggs_categories() as $c => $label): ?>
            <option value="<?= $c ?>" <?= $product['category'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($label['en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Price (EUR)</label><input type="number" step="0.01" min="0.01" name="price_eur" value="<?= htmlspecialchars((string) ($product['price_eur'] > 0 ? $product['price_eur'] : '')) ?>"></div>
      <div class="form-group"><p class="form-hint">Price can stay empty while this product is a draft. Set the final price before publishing.</p></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Stock</label><input type="number" min="0" name="stock" value="<?= (int) $product['stock'] ?>"></div>
      <div class="form-group"><label>Low stock threshold</label><input type="number" min="0" name="stock_threshold" value="<?= (int) $product['stock_threshold'] ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="draft" <?= $product['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
          <option value="published" <?= $product['status'] === 'published' ? 'selected' : '' ?>>Published</option>
        </select>
      </div>
      <div class="form-group">
        <label>Featured</label>
        <div class="toggle-switch">
          <input type="checkbox" id="featured" name="featured" value="1" <?= $product['featured'] ? 'checked' : '' ?>>
          <label for="featured" style="margin:0;">Show on homepage</label>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-panel">
    <h3>Images</h3>
    <?php if ($product['images']): ?>
      <div class="image-upload-grid">
        <?php foreach ($product['images'] as $i => $img): ?>
          <div class="thumb-wrap">
            <?php if ($i === 0): ?><span class="primary-flag">PRIMARY</span><?php endif; ?>
            <img src="<?= htmlspecialchars($img) ?>" alt="">
            <label style="display:block;font-size:11px;margin-top:4px;">
              <input type="radio" name="primary_image" value="<?= htmlspecialchars($img) ?>" <?= $i === 0 ? 'checked' : '' ?>> Primary
            </label>
            <label style="display:block;font-size:11px;">
              <input type="checkbox" name="remove_image[]" value="<?= htmlspecialchars($img) ?>"> Remove
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="form-group">
      <label>Upload new images</label>
      <input type="file" name="images[]" multiple accept="image/png,image/jpeg,image/webp,image/svg+xml">
      <div class="form-hint">First image (or the one marked Primary) is used as the main product photo.</div>
    </div>
  </div>

  <div class="admin-panel">
    <h3>Technical specs</h3>
    <div id="specs-editor">
      <?php $specs = $product['specs'] ?: [['key' => '', 'value' => '']]; ?>
      <?php foreach ($specs as $spec): ?>
        <div class="specs-editor-row">
          <input type="text" name="spec_key[]" placeholder="Dimensions" value="<?= htmlspecialchars($spec['key']) ?>">
          <input type="text" name="spec_value[]" placeholder="220 x 100 x 60 mm" value="<?= htmlspecialchars($spec['value']) ?>">
          <button type="button" class="btn btn-outline btn-sm" data-remove-spec-row>×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-outline btn-sm" id="add-spec-row">+ Add spec row</button>
  </div>

  <button type="submit" class="btn btn-primary">Save Product</button>
</form>

<script>
document.getElementById('add-spec-row').addEventListener('click', function () {
  var wrap = document.getElementById('specs-editor');
  var row = document.createElement('div');
  row.className = 'specs-editor-row';
  row.innerHTML = '<input type="text" name="spec_key[]" placeholder="Key"><input type="text" name="spec_value[]" placeholder="Value"><button type="button" class="btn btn-outline btn-sm" data-remove-spec-row>×</button>';
  wrap.appendChild(row);
});
document.getElementById('specs-editor').addEventListener('click', function (e) {
  if (e.target.matches('[data-remove-spec-row]')) {
    e.target.closest('.specs-editor-row').remove();
  }
});
</script>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
