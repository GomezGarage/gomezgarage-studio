<?php
/** Optional presentation metadata. No payment, order or stock writes. */
function ggs_content_defaults(): array
{
    return ['sku' => '', 'seo_title_en' => '', 'seo_title_ro' => '',
        'seo_description_en' => '', 'seo_description_ro' => '',
        'videos' => [], 'image_alt' => [], 'specs_ro' => []];
}

function ggs_content_available(PDO $db): bool
{
    try {
        $db->query('SELECT product_id FROM product_content LIMIT 0');
        return true;
    } catch (PDOException $e) {
        if (in_array($e->getCode(), ['42S02', 'HY000'], true)
            && preg_match('/(no such table|doesn.t exist|not found)/i', $e->getMessage())) return false;
        throw $e;
    }
}

function ggs_with_product_content(PDO $db, array $products): array
{
    if (!$products) return [];
    $content = [];
    if (ggs_content_available($db)) {
        $ids = array_map(static fn($p) => (int) $p['id'], $products);
        $query = $db->prepare('SELECT product_id, content FROM product_content WHERE product_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $query->execute($ids);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = json_decode($row['content'], true);
            $content[(int) $row['product_id']] = is_array($value) ? $value : [];
        }
    }
    return array_map(static function ($p) use ($content) {
        return array_merge($p, ggs_content_defaults(),
            array_intersect_key($content[(int) $p['id']] ?? [], ggs_content_defaults()));
    }, $products);
}

function ggs_save_product_content(PDO $db, int $id, array $content): void
{
    $query = $db->prepare('INSERT INTO product_content (product_id, content) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE content = VALUES(content)');
    $query->execute([$id, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

function ggs_video_path(string $path): bool
{
    return preg_match('~^/studio/assets/videos/products/[a-f0-9]{32}\.mp4$~D', $path) === 1;
}

/** Validate actual upload content; generated filenames only, no executable formats. */
function ggs_upload_product_video(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Video upload failed. Check the PHP upload and request size limits.');
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > 100 * 1024 * 1024) {
        throw new RuntimeException('Each video must be between 1 byte and 100 MB.');
    }
    $tmp = $file['tmp_name'];
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $head = file_get_contents($tmp, false, null, 0, 32);
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'mp4'
        || !in_array($type, ['video/mp4', 'application/mp4'], true)
        || substr($head ?: '', 4, 4) !== 'ftyp') {
        throw new RuntimeException('Upload an MP4 video encoded with H.264. Convert MOV files before uploading.');
    }
    $dir = __DIR__ . '/../assets/videos/products/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('The video upload directory could not be created.');
    }
    $name = bin2hex(random_bytes(16)) . '.mp4';
    if (!move_uploaded_file($tmp, $dir . $name)) throw new RuntimeException('The video could not be saved.');
    return '/studio/assets/videos/products/' . $name;
}
