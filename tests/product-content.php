<?php
/** Isolated presentation checks; never loads config.php or a live database. */
require __DIR__ . '/../includes/product-content.php';
require __DIR__ . '/../includes/catalog-view.php';
$checks = 0;
function check($value, $message) { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p = ['id' => 7, 'slug' => 'synthetic', 'name_en' => 'Synthetic', 'name_ro' => 'Sintetic',
 'desc_short_en' => 'Short', 'desc_short_ro' => 'Scurt', 'desc_long_en' => '', 'desc_long_ro' => '',
 'how_made_en' => 'Printed', 'how_made_ro' => 'Printat', 'material' => 'PLA', 'price_eur' => 29,
 'stock' => 3, 'images' => ['/studio/assets/images/products/synthetic.webp'],
 'specs' => [['key' => 'Height', 'value' => '10 cm']]];
check(!ggs_content_available($db), 'Absent migration must be supported');
$old = ggs_with_product_content($db, [$p])[0];
check($old['videos'] === [] && $old['stock'] === 3, 'Legacy product fallback');
$db->exec('CREATE TABLE product_content (product_id INTEGER PRIMARY KEY, content TEXT)');
$content = ['sku' => 'TEST-7', 'stock' => 999, 'price_eur' => 1, 'id' => 123,
 'seo_title_en' => 'Custom <title>', 'seo_description_en' => 'Custom description',
 'videos' => [['src' => '/studio/assets/videos/products/' . str_repeat('a',32) . '.mp4', 'title_en' => 'Rotation', 'poster' => '']],
 'specs_ro' => [['key' => 'Inaltime', 'value' => '10 cm']]];
$q = $db->prepare('INSERT INTO product_content VALUES (?, ?)'); $q->execute([7, json_encode($content)]);
$p = ggs_with_product_content($db, [$p])[0];
check($p['stock'] === 3 && $p['price_eur'] === 29 && $p['id'] === 7, 'Metadata cannot override commerce values');
check($p['sku'] === 'TEST-7', 'SKU merge');
check(ggs_product_schema($p)['sku'] === 'TEST-7', 'Schema SKU');
check(str_contains(ggs_product_head($p), 'Custom &lt;title&gt;'), 'SEO safely escaped');
check(str_contains(ggs_product_body($p), '<video controls playsinline preload="none"'), 'No-JS video fallback');
check(str_contains(ggs_product_body($p), 'data-ro="Inaltime"'), 'Bilingual specs');
check(str_contains(ggs_product_body($p), 'Original design by Gomez Garage Studio'), 'Brand authorship');
check(ggs_video_path('/studio/assets/videos/products/' . str_repeat('b',32) . '.mp4'), 'Valid upload path');
check(!ggs_video_path('/studio/assets/videos/products/../../config.php'), 'Traversal rejected');
check(!ggs_video_path('https://example.com/video.mp4'), 'External video paths rejected');
foreach ([['error' => UPLOAD_ERR_INI_SIZE], ['error'=>UPLOAD_ERR_OK, 'size'=>0]] as $file) {
 try { ggs_upload_product_video($file); check(false, 'Bad upload accepted'); }
 catch (RuntimeException $e) { check(true, 'Invalid upload rejected'); }
}
$catalog = json_decode(file_get_contents(__DIR__ . '/../database/catalog-products-20261107.json'), true);
check(count($catalog) === 7, 'Seven products');
foreach ($catalog as $row) {
 check($row['status'] === 'draft' && $row['images'] === [], 'Seed is draft without photos');
 check(str_contains($row['desc_long_en'], 'ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO'), 'Original design EN');
 check(str_contains($row['desc_long_ro'], 'DESIGN ORIGINAL GOMEZ GARAGE STUDIO'), 'Original design RO');
 check(!preg_match('/[ăâîșşțţĂÂÎȘŞȚŢ]/u', json_encode($row, JSON_UNESCAPED_UNICODE)), 'Copy has no Romanian diacritics');
 check(count($row['specs']) === count($row['presentation']['specs_ro']), 'Bilingual specs aligned');
}
echo "PASS: $checks isolated product presentation checks.\n";
