<?php
/** Server-rendered catalog content and SEO. Pure helpers: no credentials or DB access. */
require_once __DIR__ . '/catalog.php';

function ggs_catalog_product(array $row): array
{
    foreach (['images', 'specs'] as $field) {
        $row[$field] = is_array($row[$field] ?? null) ? $row[$field]
            : (json_decode($row[$field] ?? '[]', true) ?: []);
    }
    return $row;
}

function ggs_product_schema(array $p): array
{
    $url = 'https://gomezgarage.eu' . ggs_product_path($p);
    $schema = [
        '@context' => 'https://schema.org', '@type' => 'Product',
        '@id' => $url . '#product', 'url' => $url,
        'name' => $p['name_en'], 'description' => $p['desc_short_en'],
        'sku' => (string) $p['id'], 'material' => $p['material'],
        'brand' => ['@type' => 'Brand', 'name' => 'Gomez Garage Studio'],
    ];
    if ($p['images']) {
        $schema['image'] = array_map(static function ($image): string {
            return str_starts_with($image, '/') ? 'https://gomezgarage.eu' . $image : $image;
        }, $p['images']);
    }
    if ((float) $p['price_eur'] > 0) {
        $schema['offers'] = [
            '@type' => 'Offer', 'url' => $url, 'priceCurrency' => 'EUR',
            'price' => number_format((float) $p['price_eur'], 2, '.', ''),
            'availability' => (int) $p['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => 'Gomez Garage Studio'],
        ];
    }
    return $schema;
}

function ggs_product_head(array $p): string
{
    $e = 'ggs_catalog_escape';
    $url = 'https://gomezgarage.eu' . ggs_product_path($p);
    $title = $p['name_en'] . ' | Gomez Garage Studio';
    $description = $p['desc_short_en'] ?? '';
    $html = '<title>' . $e($title) . '</title>' . "\n"
        . '<meta name="description" content="' . $e($description) . '">' . "\n"
        . '<link rel="canonical" href="' . $e($url) . '">' . "\n"
        . '<meta property="og:type" content="product">' . "\n";
    foreach (['title' => $title, 'description' => $description, 'url' => $url] as $key => $value) {
        $html .= '<meta property="og:' . $key . '" content="' . $e($value) . '">' . "\n";
    }
    $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    if ($p['images']) {
        $image = $p['images'][0];
        if (str_starts_with($image, '/')) $image = 'https://gomezgarage.eu' . $image;
        $html .= '<meta property="og:image" content="' . $e($image) . '">' . "\n"
            . '<meta property="og:image:alt" content="' . $e($p['name_en']) . '">' . "\n";
    }
    // HEX flags prevent source content from closing the JSON-LD script element.
    $html .= '<script type="application/ld+json" id="product-schema">'
        . json_encode(ggs_product_schema($p), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)
        . '</script>';
    return $html;
}

function ggs_product_body(array $p): string
{
    $e = 'ggs_catalog_escape';
    $image = $p['images'][0] ?? '/studio/assets/images/products/placeholder.svg';
    $html = '<div class="gallery"><div class="gallery-main"><img id="gallery-main-img" src="'
        . $e($image) . '" alt="' . $e($p['name_en']) . '"></div></div><div class="product-info">'
        . '<span class="badge mono badge-accent">' . $e($p['material']) . '</span>'
        . '<h1 data-en="' . $e($p['name_en']) . '" data-ro="' . $e($p['name_ro']) . '">' . $e($p['name_en']) . '</h1>'
        . '<div class="product-price-block mono">€' . number_format((float) $p['price_eur'], 2) . '</div>'
        . '<p class="product-desc" data-en="' . $e($p['desc_short_en']) . '" data-ro="' . $e($p['desc_short_ro']) . '">'
        . $e($p['desc_short_en']) . '</p>';
    if ($p['desc_long_en'] || $p['desc_long_ro']) {
        $html .= '<div class="product-long-desc"><h2 data-en="About this piece" data-ro="Despre acest produs">About this piece</h2>'
            . '<p data-en="' . $e($p['desc_long_en']) . '" data-ro="' . $e($p['desc_long_ro']) . '">'
            . $e($p['desc_long_en']) . '</p></div>';
    }
    if ($p['specs']) {
        $html .= '<table class="specs-table">';
        foreach ($p['specs'] as $spec) {
            $html .= '<tr><td>' . $e($spec['key']) . '</td><td>' . $e($spec['value']) . '</td></tr>';
        }
        $html .= '</table>';
    }
    return $html . '<div class="how-made-block"><h3 data-en="How it is made" data-ro="Cum este fabricat">How it is made</h3>'
        . '<p data-en="' . $e($p['how_made_en']) . '" data-ro="' . $e($p['how_made_ro']) . '">'
        . $e($p['how_made_en']) . '</p></div></div>';
}

function ggs_catalog_cards(array $products): string
{
    if (!$products) return '<p class="empty-state" data-en="Products coming soon." data-ro="Produse in curand.">Products coming soon.</p>';
    $html = '';
    $e = 'ggs_catalog_escape';
    foreach ($products as $p) {
        $url = $e(ggs_product_path($p));
        $image = $e($p['images'][0] ?? '/studio/assets/images/products/placeholder.svg');
        $html .= '<article class="product-card"><a class="product-card-media" href="' . $url . '">'
            . '<img class="img-primary" loading="lazy" src="' . $image . '" alt="' . $e($p['name_en']) . '"></a>'
            . '<div class="product-card-body"><span class="badge mono material-badge">' . $e($p['material']) . '</span>'
            . '<h3><a href="' . $url . '" data-en="' . $e($p['name_en']) . '" data-ro="' . $e($p['name_ro']) . '">'
            . $e($p['name_en']) . '</a></h3><div class="product-card-price mono">€'
            . number_format((float) $p['price_eur'], 2) . '</div><div class="product-card-actions">'
            . '<a href="' . $url . '" class="btn btn-outline btn-sm" data-en="View" data-ro="Vezi">View</a></div></div></article>';
    }
    return $html;
}

function ggs_catalog_slot(string $template, string $slot, string $content): string
{
    return preg_replace_callback('~<!--' . $slot . '_START-->.*?<!--' . $slot . '_END-->~s',
        static fn() => $content, $template);
}
