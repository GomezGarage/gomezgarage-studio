<?php
/** Only published product URLs enter the sitemap; no fixed database IDs. */
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/catalog.php';
try {
    require_once __DIR__ . '/includes/db.php';
    $products = ggs_db()->query("SELECT id, slug FROM products WHERE status = 'published' ORDER BY id")->fetchAll();
    $paths = ['/studio/', '/studio/products.html', '/studio/custom.html', '/studio/about.html'];
    foreach ($products as $product) $paths[] = ggs_product_path($product);
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($paths as $path) echo '<url><loc>' . ggs_catalog_escape('https://gomezgarage.eu' . $path) . '</loc></url>';
    echo '</urlset>';
} catch (Throwable $error) {
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Sitemap temporarily unavailable.';
}
