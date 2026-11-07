<?php /** Presentation fields in the authenticated product editor. */ ?>
<div class="admin-panel">
  <h3>Videos, SKU and search preview</h3>
  <?php if (!$contentEnabled): ?>
    <p class="form-hint">Import database/migrations/20261107_product_content.sql in phpMyAdmin to enable these fields. Existing products and photo uploads continue to work.</p>
  <?php endif; ?>
  <fieldset <?= !$contentEnabled ? 'disabled' : '' ?> style="border:0;padding:0;min-width:0;">
    <div class="form-group"><label>SKU</label><input name="sku" maxlength="80" value="<?= htmlspecialchars($product['sku']) ?>" placeholder="GGS-TLH3-PLA-RBW-001"></div>
    <p class="form-hint">Upload MP4 encoded with H.264, up to 100 MB each, maximum four per product. Use the prepared web clips. Playback starts only when the visitor presses Play.</p>
    <p class="form-hint">Server limits: upload_max_filesize <?= htmlspecialchars(ini_get('upload_max_filesize')) ?>; post_max_size <?= htmlspecialchars(ini_get('post_max_size')) ?>. The request limit must cover all files in one save.</p>
    <?php foreach ($product['videos'] as $i => $video): ?>
      <div class="product-video-editor">
        <video controls playsinline preload="none" src="<?= htmlspecialchars($video['src']) ?>" style="width:100%;max-width:360px;"></video>
        <div class="form-row">
          <div class="form-group"><label>Video title EN</label><input name="video_title_en[<?= $i ?>]" maxlength="150" value="<?= htmlspecialchars($video['title_en'] ?? '') ?>" placeholder="360 degree product view"></div>
          <div class="form-group"><label>Video title RO</label><input name="video_title_ro[<?= $i ?>]" maxlength="150" value="<?= htmlspecialchars($video['title_ro'] ?? '') ?>" placeholder="Prezentare produs 360 grade"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Order</label><input type="number" min="0" name="video_order[<?= $i ?>]" value="<?= $i ?>"></div>
          <div class="form-group"><label>Cover photograph</label><select name="video_poster[<?= $i ?>]"><option value="">Main product photograph</option>
            <?php foreach ($product['images'] as $n => $image): ?><option value="<?= htmlspecialchars($image) ?>" <?= ($video['poster'] ?? '') === $image ? 'selected' : '' ?>>Photo <?= $n + 1 ?></option><?php endforeach; ?>
          </select></div>
        </div>
        <label><input type="checkbox" name="remove_video[]" value="<?= $i ?>"> Remove from gallery</label>
      </div>
    <?php endforeach; ?>
    <div class="form-group"><label>Upload videos</label><input type="file" name="videos[]" multiple accept="video/mp4,.mp4"></div>
    <p class="form-hint">New videos are appended. Save once to edit their titles, cover photograph and order. Removing a video unlinks it from this product; the original file is retained on the server.</p>
    <?php foreach (['en' => 'EN', 'ro' => 'RO'] as $lang => $label): ?>
      <div class="form-group"><label>SEO title <?= $label ?></label><input name="seo_title_<?= $lang ?>" maxlength="300" value="<?= htmlspecialchars($product['seo_title_' . $lang]) ?>"></div>
      <div class="form-group"><label>SEO description <?= $label ?></label><textarea name="seo_description_<?= $lang ?>" rows="3" maxlength="300"><?= htmlspecialchars($product['seo_description_' . $lang]) ?></textarea></div>
    <?php endforeach; ?>
    <p class="form-hint">Optional. Empty fields use the product name and short description. The initial indexed page uses English; Romanian metadata follows the language switch.</p>
  </fieldset>
</div>
