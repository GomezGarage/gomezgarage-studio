<?php
/** Pure catalog checks. No shop config, services or existing database. */
require_once __DIR__ . '/../includes/catalog-view.php';
require_once __DIR__ . '/../includes/agent/bootstrap.php';
$checks = 0;
function catalogCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$products = json_decode(file_get_contents(__DIR__ . '/../database/catalog-products.json'), true, 32, JSON_THROW_ON_ERROR);
catalogCheck(count($products) === 6, 'six_real_listings');
catalogCheck(count(array_unique(array_column($products, 'slug'))) === 6, 'unique_slugs');
catalogCheck(array_count_values(array_column($products, 'category')) === ['desk' => 3, 'display' => 1, 'home' => 2], 'category_assignment');
foreach ($products as $index => $p) {
    $p['id'] = $index + 1;
    catalogCheck($p['status'] === 'draft' && $p['price_eur'] === 0 && $p['images'] === [], 'unpriced_draft_' . $index);
    foreach (['name_ro', 'desc_short_ro', 'desc_long_ro', 'how_made_ro'] as $field) {
        catalogCheck(!preg_match('/[ăâîșțĂÂÎȘȚşţŞŢ]/u', $p[$field]), 'no_diacritics_' . $index . '_' . $field);
    }
    catalogCheck(strlen($p['name_en']) <= 200 && strlen($p['desc_short_en']) <= 170, 'name_and_snippet_length_' . $index);
    catalogCheck(!str_contains(ggs_product_head($p), '"offers"'), 'no_zero_price_offer_' . $index);
    catalogCheck(str_contains(ggs_product_body($p), ggs_catalog_escape($p['desc_long_en'])), 'long_description_visible_' . $index);
}
catalogCheck($products[5]['stock'] === 1 && $products[5]['material'] === 'PLA Rainbow', 'unique_rainbow_set');
$p = $products[0] + ['id' => 42];
$p['price_eur'] = '49.00'; $p['stock'] = 2; $p['images'] = ['/studio/assets/images/products/fixture.webp'];
$schema = ggs_product_schema($p);
catalogCheck($schema['offers']['price'] === '49.00' && $schema['offers']['availability'] === 'https://schema.org/InStock', 'accurate_offer');
catalogCheck($schema['url'] === 'https://gomezgarage.eu/studio/product.html?slug=magsafe-apple-watch-dock', 'descriptive_canonical');
catalogCheck($schema['image'][0] === 'https://gomezgarage.eu/studio/assets/images/products/fixture.webp', 'absolute_image_url');
catalogCheck(!isset($schema['aggregateRating']) && !isset($schema['review']), 'no_invented_reviews');
$p['name_en'] = '</script><script>alert("fixture")</script>';
catalogCheck(!str_contains(ggs_product_head($p), '</script><script>'), 'jsonld_and_html_escaped');
catalogCheck(!str_contains(ggs_product_body($p), '<script>'), 'body_escaped');
catalogCheck(ggs_product_path(['id' => 7]) === '/studio/product.html?id=7', 'legacy_id_support');
catalogCheck(ggs_catalog_slot('a<!--X_START-->old<!--X_END-->z', 'X', '$1\\x') === 'a$1\\xz', 'slot_replacement_preserves_content');
catalogCheck(isset(ggs_categories()['home'], ggs_categories()['automotive']), 'new_and_legacy_categories');
\Ggs\Agent\ProductDraftRepository::validateRequest('POST', ['slug' => 'synthetic-home', 'name_en' => 'Synthetic home', 'category' => 'home', 'price_eur' => '1.00'], 'catalog-home-fixture-001');
catalogCheck(true, 'service_accepts_home_draft');
echo "PASS: {$checks} catalog, copy and SEO checks. No shop config or services loaded.\n";
