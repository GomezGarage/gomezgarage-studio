<?php
declare(strict_types=1);
namespace Ggs\Agent;

use PDO;
use PDOException;
use RuntimeException;

/** Narrow, draft-only write boundary for the site's product table. */
final class ProductDraftRepository
{
    private const TEXT_LIMITS = [
        'name_en' => 200, 'name_ro' => 200, 'desc_short_en' => 5000, 'desc_short_ro' => 5000,
        'desc_long_en' => 20000, 'desc_long_ro' => 20000, 'how_made_en' => 10000,
        'how_made_ro' => 10000, 'material' => 100,
    ];
    private const CONTENT_FIELDS = [
        'slug', 'name_en', 'name_ro', 'desc_short_en', 'desc_short_ro', 'desc_long_en', 'desc_long_ro',
        'how_made_en', 'how_made_ro', 'material', 'category', 'price_eur', 'images', 'specs',
    ];

    public function __construct(private PDO $db) {}

    public static function connect(array $config): self
    {
        // Reuse the configured service DB account by default. Hosting may provide
        // an optional write_database identity when it wants a separate principal.
        $db = $config['write_database'] ?? $config['database'] ?? [];
        if (!in_array($db['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $db['name'] ?? '')
            || !is_string($db['user'] ?? null) || !is_string($db['password'] ?? null)) {
            throw new RuntimeException('Invalid isolated write database configuration.');
        }
        $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_TIMEOUT => 3]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
        if (($db['engine'] ?? '') === 'mysql') {
            $pdo->exec('SET SESSION max_execution_time = 3000');
        } elseif (($db['engine'] ?? '') === 'mariadb') {
            $pdo->exec('SET SESSION max_statement_time = 3');
        } else {
            throw new RuntimeException('Unverified database engine.');
        }
        return new self($pdo);
    }

    /** @return array{product_id:string,slug:string,status:string,revision:string,updated_at:string,replayed:bool} */
    public function apply(string $method, array $input, string $idempotencyKey): array
    {
        $data = self::validateRequest($method, $input, $idempotencyKey);
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', $method . "\0" . json_encode($data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $this->db->beginTransaction();
            $replay = $this->lookup($keyHash, $requestHash);
            if ($replay !== null) {
                $this->db->commit();
                $replay['replayed'] = true;
                return $replay;
            }
            $result = $method === 'POST' ? $this->create($data) : $this->update($data);
            $statement = $this->db->prepare('INSERT INTO agent_product_draft_requests (idempotency_hash, request_hash, response_json) VALUES (?, ?, ?)');
            $statement->execute([$keyHash, $requestHash, json_encode($result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $this->db->commit();
            $result['replayed'] = false;
            return $result;
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // A concurrent retry can race the initial idempotency lookup. The unique
            // key makes the losing product write roll back with the transaction.
            if (str_starts_with((string) $error->getCode(), '23') || in_array($error->errorInfo[1] ?? null, [19, 1062], true)) {
                $replay = $this->lookup($keyHash, $requestHash);
                if ($replay !== null) {
                    $replay['replayed'] = true;
                    return $replay;
                }
                throw new ApiError(409, 'conflict', 'The product draft conflicts with an existing record.');
            }
            throw $error;
        } catch (ApiError $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** Validate the full bounded request before opening a write connection. */
    public static function validateRequest(string $method, array $input, string $idempotencyKey): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $idempotencyKey)) {
            invalidDraft();
        }
        return self::validate($method, $input);
    }

    private static function validate(string $method, array $input): array
    {
        $allowed = array_merge(['id', 'expected_revision'], self::CONTENT_FIELDS);
        if (array_diff(array_keys($input), $allowed)) {
            invalidDraft();
        }
        if ($method === 'POST') {
            if (array_key_exists('id', $input) || array_key_exists('expected_revision', $input)) {
                invalidDraft();
            }
            foreach (['slug', 'name_en', 'category', 'price_eur'] as $required) {
                if (!array_key_exists($required, $input)) {
                    invalidDraft();
                }
            }
        } elseif ($method === 'PATCH') {
            if (!isset($input['id'], $input['expected_revision'])
                || !array_intersect(self::CONTENT_FIELDS, array_keys($input))) {
                invalidDraft();
            }
            if (!is_string($input['id']) || !preg_match('/^[1-9][0-9]{0,9}$/D', $input['id'])
                || !is_string($input['expected_revision']) || !preg_match('/^[a-f0-9]{64}$/D', $input['expected_revision'])) {
                invalidDraft();
            }
        } else {
            invalidDraft();
        }

        $result = [];
        if ($method === 'PATCH') {
            $result['id'] = $input['id'];
            $result['expected_revision'] = $input['expected_revision'];
        }
        foreach (self::CONTENT_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($field === 'slug') {
                if (!is_string($value) || strlen($value) > 100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)) {
                    invalidDraft();
                }
            } elseif (isset(self::TEXT_LIMITS[$field])) {
                $limit = self::TEXT_LIMITS[$field];
                if ($value !== null && (!is_string($value) || strlen($value) > $limit || !preg_match('//u', $value)
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/', $value))) {
                    invalidDraft();
                }
                if ($field === 'name_en' && ($value === null || trim($value) === '')) {
                    invalidDraft();
                }
            } elseif ($field === 'category') {
                if (!in_array($value, ['desk', 'display', 'automotive', 'other', 'home'], true)) {
                    invalidDraft();
                }
            } elseif ($field === 'price_eur') {
                if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})\.[0-9]{2}$/D', $value)
                    || (float) $value <= 0) {
                    invalidDraft();
                }
            } elseif ($field === 'images') {
                if (!is_array($value) || count($value) > 10 || array_keys($value) !== array_keys(array_values($value))) {
                    invalidDraft();
                }
                foreach ($value as $image) {
                    if (!is_string($image) || strlen($image) > 170
                        || !preg_match('~^/studio/assets/images/products/[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.(?:jpe?g|png|webp)$~D', $image)) {
                        invalidDraft();
                    }
                }
            } elseif ($field === 'specs') {
                if (!is_array($value) || count($value) > 30 || array_keys($value) !== array_keys(array_values($value))) {
                    invalidDraft();
                }
                foreach ($value as $spec) {
                    if (!is_array($spec) || array_diff(array_keys($spec), ['key', 'value'])
                        || !is_string($spec['key'] ?? null) || !is_string($spec['value'] ?? null)
                        || strlen($spec['key']) > 80 || strlen($spec['value']) > 500
                        || !preg_match('//u', $spec['key'] . $spec['value'])
                        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/', $spec['key'] . $spec['value'])) {
                        invalidDraft();
                    }
                }
            }
            $result[$field] = $value;
        }
        if ($method === 'POST') {
            $result = array_merge(['slug' => null, 'name_en' => null, 'name_ro' => null,
                'desc_short_en' => null, 'desc_short_ro' => null, 'desc_long_en' => null, 'desc_long_ro' => null,
                'how_made_en' => null, 'how_made_ro' => null, 'material' => null, 'category' => null,
                'price_eur' => null, 'images' => [], 'specs' => []], $result);
        }
        return $result;
    }

    private function create(array $data): array
    {
        $sql = 'INSERT INTO products (slug, name_en, name_ro, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro,
            how_made_en, how_made_ro, material, category, price_eur, images, specs) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $statement = $this->db->prepare($sql);
        $statement->execute([$data['slug'], $data['name_en'], $data['name_ro'], $data['desc_short_en'], $data['desc_short_ro'],
            $data['desc_long_en'], $data['desc_long_ro'], $data['how_made_en'], $data['how_made_ro'], $data['material'],
            $data['category'], $data['price_eur'], json_encode($data['images'], JSON_THROW_ON_ERROR),
            json_encode($data['specs'], JSON_THROW_ON_ERROR)]);
        return $this->readResult((string) $this->db->lastInsertId());
    }

    private function update(array $data): array
    {
        $current = $this->selectProduct($data['id'], true);
        if (!$current || $current['status'] !== 'draft') {
            throw new ApiError(404, 'not_found', 'Draft product not found.');
        }
        if (!hash_equals(Dto::product($current)['revision'], $data['expected_revision'])) {
            throw new ApiError(409, 'stale_revision', 'The draft changed; read it again before editing.');
        }
        $sets = [];
        $values = [];
        foreach (self::CONTENT_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $sets[] = $field . ' = ?';
            $values[] = in_array($field, ['images', 'specs'], true)
                ? json_encode($data[$field], JSON_THROW_ON_ERROR) : $data[$field];
        }
        $values[] = $data['id'];
        $values[] = $current['updated_at'];
        $touchTimestamp = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? ', updated_at = UTC_TIMESTAMP()' : '';
        $statement = $this->db->prepare('UPDATE products SET ' . implode(', ', $sets) . $touchTimestamp
            . ' WHERE id = ? AND status = \'draft\' AND updated_at = ?');
        $statement->execute($values);
        if ($statement->rowCount() !== 1) {
            $after = $this->selectProduct($data['id']);
            if (!$after || $after['status'] !== 'draft') {
                throw new ApiError(404, 'not_found', 'Draft product not found.');
            }
            if (!hash_equals(Dto::product($after)['revision'], $data['expected_revision'])) {
                throw new ApiError(409, 'stale_revision', 'The draft changed; read it again before editing.');
            }
        }
        return $this->readResult($data['id']);
    }

    private function selectProduct(string $id, bool $lock = false): ?array
    {
        $suffix = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare('SELECT id, slug, status, name_ro, name_en, desc_short_ro, desc_short_en,
            desc_long_ro, desc_long_en, how_made_ro, how_made_en, material, category, CAST(price_eur AS CHAR) AS price_eur,
            images, specs, 0 AS stock, updated_at, 0 AS views_current_7d, 0 AS views_previous_7d FROM products WHERE id = ?' . $suffix);
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    private function readResult(string $id): array
    {
        $row = $this->selectProduct($id);
        if (!$row || $row['status'] !== 'draft') {
            throw new RuntimeException('Draft product result unavailable.');
        }
        $product = Dto::product($row);
        return ['product_id' => $product['id'], 'slug' => $product['slug'], 'status' => $product['status'],
            'revision' => $product['revision'], 'updated_at' => $product['updated_at']];
    }

    private function lookup(string $keyHash, string $requestHash): ?array
    {
        $statement = $this->db->prepare('SELECT request_hash, response_json FROM agent_product_draft_requests WHERE idempotency_hash = ?');
        $statement->execute([$keyHash]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        if (!hash_equals($row['request_hash'], $requestHash)) {
            throw new ApiError(409, 'idempotency_conflict', 'Idempotency key was already used for another request.');
        }
        $result = json_decode($row['response_json'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !isset($result['product_id'], $result['slug'], $result['status'], $result['revision'], $result['updated_at'])) {
            throw new RuntimeException('Stored idempotency response is invalid.');
        }
        return $result;
    }
}
