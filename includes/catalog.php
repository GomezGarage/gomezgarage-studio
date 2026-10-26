<?php
/** Catalog labels shared by the storefront and product editor. No DB/config access. */
function ggs_categories(): array
{
    return [
        'desk' => ['en' => 'Desk & Tech', 'ro' => 'Birou & tech'],
        'display' => ['en' => 'Display & Collectibles', 'ro' => 'Expunere & colectii'],
        'home' => ['en' => 'Home & Decor', 'ro' => 'Casa & decor'],
        // Retain legacy values for existing records and service clients.
        'automotive' => ['en' => 'Automotive', 'ro' => 'Auto'],
        'other' => ['en' => 'Other', 'ro' => 'Altele'],
    ];
}

function ggs_product_path(array $product): string
{
    return '/studio/product.html?' . (!empty($product['slug'])
        ? 'slug=' . rawurlencode($product['slug']) : 'id=' . (int) $product['id']);
}

function ggs_catalog_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
