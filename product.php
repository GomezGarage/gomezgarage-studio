<?php
/** Apache serves product.html through this renderer; existing id links remain valid. */
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/catalog-view.php';
$template = file_get_contents(__DIR__ . '/product.html');
try {
    require_once __DIR__ . '/includes/db.php';
    $slug = $_GET['slug'] ?? null;
    $id = $_GET['id'] ?? null;
    $product = false;
    if (is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug)) {
        $stmt = ggs_db()->prepare("SELECT * FROM products WHERE slug = ? AND status = 'published' LIMIT 1");
        $stmt->execute([$slug]);
        $product = $stmt->fetch();
    } elseif ($slug === null && is_string($id) && ctype_digit($id) && (int) $id > 0) {
        $stmt = ggs_db()->prepare("SELECT * FROM products WHERE id = ? AND status = 'published' LIMIT 1");
        $stmt->execute([(int) $id]);
        $product = $stmt->fetch();
    }
    if (!$product) {
        http_response_code(404);
        $template = ggs_catalog_slot($template, 'PRODUCT_HEAD', '<title>Product not found | Gomez Garage Studio</title><meta name="robots" content="noindex">');
        $template = ggs_catalog_slot($template, 'PRODUCT_DETAIL', '<p class="empty-state" data-en="Product not found." data-ro="Produsul nu a fost gasit.">Product not found.</p>');
    } else {
        $product = ggs_catalog_product($product);
        $template = ggs_catalog_slot($template, 'PRODUCT_HEAD', ggs_product_head($product));
        $template = ggs_catalog_slot($template, 'PRODUCT_DETAIL', ggs_product_body($product));
        $template = str_replace('<!--PRODUCT_BREADCRUMB-->', '<span data-en="' . ggs_catalog_escape($product['name_en'])
            . '" data-ro="' . ggs_catalog_escape($product['name_ro']) . '">' . ggs_catalog_escape($product['name_en']) . '</span>', $template);
    }
} catch (Throwable $error) {
    http_response_code(503);
    header('Retry-After: 300');
    $template = ggs_catalog_slot($template, 'PRODUCT_HEAD', '<title>Temporarily unavailable | Gomez Garage Studio</title><meta name="robots" content="noindex">');
    $template = ggs_catalog_slot($template, 'PRODUCT_DETAIL', '<p class="empty-state">Please try again shortly.</p>');
}
header('Content-Type: text/html; charset=UTF-8');
echo $template;
