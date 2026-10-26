<?php
/** Published catalog links/content are available before JavaScript runs. */
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/catalog-view.php';
$template = file_get_contents(__DIR__ . '/products.html');
try {
    require_once __DIR__ . '/includes/db.php';
    $rows = ggs_db()->query("SELECT * FROM products WHERE status = 'published' ORDER BY created_at DESC")->fetchAll();
    $template = ggs_catalog_slot($template, 'CATALOG_GRID', ggs_catalog_cards(array_map('ggs_catalog_product', $rows)));
} catch (Throwable $error) {
    http_response_code(503);
    header('Retry-After: 300');
    $template = str_replace('</head>', '<meta name="robots" content="noindex"></head>', $template);
    $template = ggs_catalog_slot($template, 'CATALOG_GRID', '<p class="empty-state">Please try again shortly.</p>');
}
header('Content-Type: text/html; charset=UTF-8');
echo $template;
