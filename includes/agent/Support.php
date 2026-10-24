<?php
declare(strict_types=1);
namespace Ggs\Agent;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

function invalid(): void
{
    throw new ApiError(422, 'invalid_request', 'Invalid filters or cursor.');
}

function invalidDraft(): void
{
    throw new ApiError(422, 'invalid_request', 'Invalid product draft request.');
}

function utc(int $timestamp): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

function timestamp(string $value): int
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
        invalid();
    }
    return $date->getTimestamp();
}

final class Config
{
    public const SCOPES = ['agent.health.read', 'products.read', 'products.drafts.read', 'products.drafts.write', 'orders.read'];

    /** Default shared-hosting location, next to (never inside) the active web root. */
    public static function defaultPath(string $documentRoot): string
    {
        $root = realpath($documentRoot);
        if (!$root || !is_dir($root)) {
            throw new RuntimeException('Invalid document root.');
        }
        return dirname($root) . '/private/studio-agent/config.json';
    }

    public static function load(string $path, string $documentRoot): array
    {
        // Recheck permissions and symlink targets on every credential load, including
        // repeated calls in one PHP worker/request (PHP 8.1 caches stat results).
        clearstatcache(true);
        $real = realpath($path);
        $roots = [dirname(__DIR__, 2), realpath($documentRoot) ?: dirname(__DIR__, 2)];
        if (!$real || !is_file($real) || !is_readable($real) || (fileperms($real) & 0007) !== 0) {
            throw new RuntimeException('Invalid private configuration.');
        }
        foreach ($roots as $root) {
            if ($real === $root || str_starts_with($real, rtrim($root, '/') . '/')) {
                throw new RuntimeException('Configuration must be outside web root.');
            }
        }
        $config = json_decode(file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || !is_bool($config['enabled'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $config['cursor_key_hex'] ?? '')
            || !is_array($config['credentials'] ?? null) || !$config['credentials']) {
            throw new RuntimeException('Invalid configuration.');
        }
        $ids = $hashes = [];
        foreach ($config['credentials'] as $credential) {
            if (!is_array($credential)
                || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $credential['id'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/D', $credential['token_sha256'] ?? '')
                || !is_bool($credential['active'] ?? null)
                || !is_array($credential['scopes'] ?? null)
                || array_diff($credential['scopes'], self::SCOPES)
                || isset($ids[$credential['id']]) || isset($hashes[$credential['token_sha256']])) {
                throw new RuntimeException('Invalid credential configuration.');
            }
            try {
                timestamp($credential['expires_at'] ?? '');
            } catch (\Throwable $error) {
                throw new RuntimeException('Invalid credential expiry.');
            }
            $ids[$credential['id']] = $hashes[$credential['token_sha256']] = true;
        }
        if (isset($config['write_database'])) {
            $writer = $config['write_database'];
            if (!is_array($writer) || !in_array($writer['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
                || !preg_match('/^[a-zA-Z0-9_]+$/D', $writer['name'] ?? '')
                || !is_string($writer['user'] ?? null) || !is_string($writer['password'] ?? null)
                || !is_array($config['database'] ?? null)
                || ($writer['host'] ?? null) !== ($config['database']['host'] ?? null)
                || ($writer['name'] ?? null) !== ($config['database']['name'] ?? null)
                || !in_array($writer['engine'] ?? '', ['mysql', 'mariadb'], true)
                || $writer['engine'] !== ($config['database']['engine'] ?? null)) {
                throw new RuntimeException('Invalid isolated write database configuration.');
            }
            $config['write_database'] = $writer;
        }
        $dir = realpath($config['rate_limit_dir'] ?? '');
        if (!$dir || !is_dir($dir) || !is_writable($dir) || (fileperms($dir) & 0077) !== 0) {
            throw new RuntimeException('Invalid private rate limit directory.');
        }
        foreach ($roots as $root) {
            if ($dir === $root || str_starts_with($dir, rtrim($root, '/') . '/')) {
                throw new RuntimeException('Rate limit storage must be private.');
            }
        }
        $config['rate_limit_dir'] = $dir;
        return $config;
    }
}

final class FileRateLimiter
{
    public function __construct(private string $directory) {}

    public function __invoke(string $identity, int $now): void
    {
        $path = $this->directory . '/' . hash('sha256', $identity) . '.json';
        $handle = fopen($path, 'c+');
        if (!$handle) {
            throw new RuntimeException('Rate limiter unavailable.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Rate limiter busy.');
            }
            chmod($path, 0600);
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            $window = intdiv($now, 60);
            $count = ($state['window'] ?? null) === $window ? ($state['count'] ?? 0) : 0;
            if ($count >= 60) {
                throw new ApiError(429, 'rate_limited', 'Request limit reached. Retry later.');
            }
            $encoded = json_encode(['window' => $window, 'count' => $count + 1], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('Rate limiter unavailable.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

final class Cursor
{
    public function __construct(private string $key) {}

    public function encode(array $state): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($state, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $this->key);
    }

    public function decode(string $cursor): array
    {
        if (strlen($cursor) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $parts)
            || !hash_equals(hash_hmac('sha256', $parts[1], $this->key), $parts[2])) {
            invalid();
        }
        try {
            $state = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            invalid();
        }
        if (!is_array($state)) {
            invalid();
        }
        return $state;
    }
}
