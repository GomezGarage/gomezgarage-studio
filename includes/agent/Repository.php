<?php
declare(strict_types=1);
namespace Ggs\Agent;

use PDO;
use RuntimeException;

interface ReadRepository
{
    public function cutoff(): int;
    public function page(string $resource, array $filters, int $before, ?array $after): array;
}

final class Repository implements ReadRepository
{
    public function __construct(private PDO $db) {}

    public static function connect(array $config): self
    {
        if (($config['timestamp_storage_verified'] ?? false) !== true) {
            throw new RuntimeException('Timestamp semantics must be verified before activation.');
        }
        $db = $config['database'] ?? [];
        // A local database socket/TCP connection only; remote DB transport is outside this pilot.
        if (!in_array($db['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $db['name'] ?? '')
            || !is_string($db['user'] ?? null) || !is_string($db['password'] ?? null)) {
            throw new RuntimeException('Invalid database configuration.');
        }
        $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_TIMEOUT => 3]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        // Fail closed if the verified engine does not support this timeout.
        if (($db['engine'] ?? '') === 'mysql') {
            $pdo->exec('SET SESSION max_execution_time = 3000');
        } elseif (($db['engine'] ?? '') === 'mariadb') {
            $pdo->exec('SET SESSION max_statement_time = 3');
        } else {
            throw new RuntimeException('Unverified database engine.');
        }
        // Inspect metadata only; never infer UTC from an unzoned DATETIME column.
        $columns = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, DATETIME_PRECISION
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('products', 'orders') AND COLUMN_NAME IN ('created_at', 'updated_at')")->fetchAll();
        if (count($columns) !== 4) {
            throw new RuntimeException('Unsupported timestamp schema.');
        }
        foreach ($columns as $column) {
            if (strtolower($column['DATA_TYPE']) !== 'timestamp' || (int) $column['DATETIME_PRECISION'] !== 0) {
                throw new RuntimeException('Unsupported timestamp schema.');
            }
        }
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        return new self($pdo);
    }

    public function cutoff(): int
    {
        return timestamp(Dto::date($this->db->query('SELECT UTC_TIMESTAMP()')->fetchColumn())) - 5;
    }

    public function page(string $resource, array $filters, int $before, ?array $after): array
    {
        if (!in_array($resource, ['products', 'orders'], true)) {
            throw new RuntimeException('Unknown resource.');
        }
        // NULL would otherwise disappear from every incremental/full traversal.
        if ($this->db->query('SELECT id FROM ' . $resource . ' WHERE updated_at IS NULL LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('Missing source update timestamp.');
        }
        if ($resource === 'products') {
            $sqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $minus6 = $sqlite ? "date(UTC_DATE(), '-6 days')" : 'DATE_SUB(UTC_DATE(), INTERVAL 6 DAY)';
            $minus13 = $sqlite ? "date(UTC_DATE(), '-13 days')" : 'DATE_SUB(UTC_DATE(), INTERVAL 13 DAY)';
            $columns = 'p.id, p.slug, p.status, p.name_ro, p.name_en, p.desc_short_ro, p.desc_short_en,
               p.desc_long_ro, p.desc_long_en, p.how_made_ro, p.how_made_en, p.material, p.category,
               CAST(p.price_eur AS CHAR) AS price_eur, p.images, p.specs, p.stock, p.updated_at,
               COALESCE(v.views_current_7d, 0) AS views_current_7d,
               COALESCE(v.views_previous_7d, 0) AS views_previous_7d';
            $from = "products p LEFT JOIN (
                SELECT product_id,
                    SUM(CASE WHEN view_date >= {$minus6} THEN view_count ELSE 0 END) AS views_current_7d,
                    SUM(CASE WHEN view_date >= {$minus13} AND view_date < {$minus6} THEN view_count ELSE 0 END) AS views_previous_7d
                FROM product_views_daily WHERE view_date >= {$minus13} GROUP BY product_id
            ) v ON v.product_id = p.id";
            $idColumn = 'p.id';
            $updatedColumn = 'p.updated_at';
        } else {
            $columns = "id, order_number, status, payment_method, created_at, updated_at,
               CAST(subtotal_eur AS CHAR) AS subtotal_eur, CAST(shipping_eur AS CHAR) AS shipping_eur,
               CAST(total_eur AS CHAR) AS total_eur, items,
               NULLIF(JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.country')), 'null') AS country, tracking_number,
               customer_email";
            $from = 'orders';
            $idColumn = 'id';
            $updatedColumn = 'updated_at';
        }
        $where = ["{$updatedColumn} < ?"];
        $params = [gmdate('Y-m-d H:i:s', $before)];
        foreach (['updated_from' => 'updated_at >= ?', 'created_from' => 'created_at >= ?', 'created_before' => 'created_at < ?'] as $key => $clause) {
            if (isset($filters[$key])) {
                $where[] = $clause;
                $params[] = gmdate('Y-m-d H:i:s', timestamp($filters[$key]));
            }
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = $filters['category'];
        }
        if ($after !== null) {
            $where[] = "({$updatedColumn} > ? OR ({$updatedColumn} = ? AND {$idColumn} > ?))";
            $params[] = $after['updated_at'];
            $params[] = $after['updated_at'];
            $params[] = $after['id'];
        }
        $sql = 'SELECT ' . $columns . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY {$updatedColumn} ASC, {$idColumn} ASC LIMIT " . ($filters['limit'] + 1);
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
