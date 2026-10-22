<?php
declare(strict_types=1);
namespace Ggs\Agent;

use Closure;
use Throwable;

final class Api
{
    public const VERSION = '1.1.0';
    private Closure $configuration;
    private Closure $repository;
    private Closure $limiter;
    private Closure $audit;
    private Closure $clock;

    /** Dependencies are injected only by local PHP code, never by HTTP input. */
    public function __construct(callable $configuration, callable $repository, callable $limiter, callable $audit, ?callable $clock = null)
    {
        $this->configuration = Closure::fromCallable($configuration);
        $this->repository = Closure::fromCallable($repository);
        $this->limiter = Closure::fromCallable($limiter);
        $this->audit = Closure::fromCallable($audit);
        $this->clock = Closure::fromCallable($clock ?? 'time');
    }

    public function handle(string $route, string $method, string $authorization, string $query, bool $https): array
    {
        $started = microtime(true);
        $now = ($this->clock)();
        $requestId = bin2hex(random_bytes(16));
        $identity = null;
        $count = 0;
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $requestId];
        try {
            if (!in_array($route, ['health', 'products', 'orders'], true)) {
                throw new ApiError(404, 'not_found', 'Route not found.');
            }
            if ($method !== 'GET') {
                throw new ApiError(405, 'method_not_allowed', 'Only GET is supported.');
            }
            if (!$https) {
                throw new ApiError(403, 'https_required', 'HTTPS is required.');
            }
            // Reject a missing credential even when configuration/database is unavailable.
            if (strlen($authorization) > 256 || !preg_match('/^Bearer ([A-Za-z0-9_-]{43,128})$/Di', $authorization, $match)) {
                throw new ApiError(401, 'unauthorized', 'Service credential required.');
            }
            $config = ($this->configuration)();
            $credential = null;
            $digest = hash('sha256', $match[1]);
            foreach ($config['credentials'] as $candidate) {
                if (hash_equals($candidate['token_sha256'], $digest) && $candidate['active']
                    && timestamp($candidate['expires_at']) > $now) {
                    $credential = $candidate;
                }
            }
            if ($credential === null) {
                throw new ApiError(401, 'unauthorized', 'Service credential required.');
            }
            $identity = $credential['id'];
            ($this->limiter)($config, $identity, $now);
            $scope = $route === 'health' ? 'agent.health.read' : $route . '.read';
            if (!in_array($scope, $credential['scopes'], true)) {
                throw new ApiError(403, 'forbidden', 'Required scope is missing.');
            }
            $filters = $this->filters($route, $query);
            $cursor = $filters['cursor'] ?? null;
            unset($filters['cursor']);
            if ($route === 'health') {
                $data = ['status' => 'ok', 'contract_version' => self::VERSION, 'server_time' => utc($now), 'check' => 'liveness'];
                $before = null;
                $next = null;
            } else {
                if ($route === 'products' && $filters['status'] !== 'published'
                    && !in_array('products.drafts.read', $credential['scopes'], true)) {
                    throw new ApiError(403, 'forbidden', 'Draft product scope is required.');
                }
                $codec = new Cursor(hex2bin($config['cursor_key_hex']));
                $after = null;
                $expires = $now + 900;
                $before = null;
                if ($cursor !== null) {
                    $state = $codec->decode($cursor);
                    if (($state['v'] ?? null) !== self::VERSION || ($state['route'] ?? null) !== $route
                        || ($state['identity'] ?? null) !== $identity || ($state['filters'] ?? null) !== $filters
                        || !is_int($state['expires'] ?? null) || $state['expires'] <= $now
                        || !is_int($state['before'] ?? null) || !is_array($state['after'] ?? null)) {
                        invalid();
                    }
                    $before = $state['before'];
                    $after = $state['after'];
                    $expires = $state['expires'];
                }
                /** @var ReadRepository $repository */
                $repository = ($this->repository)($config);
                if ($before === null) {
                    $before = $repository->cutoff();
                    if (isset($filters['updated_before'])) {
                        $before = min($before, timestamp($filters['updated_before']));
                    }
                }
                if (isset($filters['updated_from']) && timestamp($filters['updated_from']) >= $before) {
                    invalid();
                }
                $rows = $repository->page($route, $filters, $before, $after);
                $hasMore = count($rows) > $filters['limit'];
                if ($hasMore) {
                    array_pop($rows);
                }
                if ($route === 'products') {
                    $data = array_map([Dto::class, 'product'], $rows);
                } else {
                    // Purpose-separate a stable pseudonymous customer reference from cursor signing.
                    $customerRefKey = hash_hmac('sha256', 'studio-customer-ref-key-v1', hex2bin($config['cursor_key_hex']), true);
                    $data = array_map(static fn(array $row): array => Dto::order($row, $customerRefKey), $rows);
                }
                $count = count($data);
                $next = null;
                if ($hasMore) {
                    $last = end($rows);
                    $next = $codec->encode(['v' => self::VERSION, 'route' => $route, 'identity' => $identity,
                        'filters' => $filters, 'before' => $before, 'expires' => $expires,
                        'after' => ['updated_at' => $last['updated_at'], 'id' => (string) $last['id']]]);
                }
            }
            $status = 200;
            $body = ['schema_version' => self::VERSION, 'request_id' => $requestId, 'generated_at' => utc($now),
                'data' => $data, 'next_cursor' => $next];
            if ($before !== null) {
                $body['sync_before'] = utc($before);
            }
            // Verify serializability before recording success or sending headers.
            if (strlen(json_encode($body, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024) {
                throw new \RuntimeException('Response size limit exceeded.');
            }
        } catch (Throwable $error) {
            $status = $error instanceof ApiError ? $error->status : 503;
            $body = ['schema_version' => self::VERSION, 'request_id' => $requestId, 'generated_at' => utc($now),
                'error' => ['code' => $error instanceof ApiError ? $error->errorCode : 'unavailable',
                    'message' => $error instanceof ApiError ? $error->getMessage() : 'Service temporarily unavailable.']];
            $count = 0;
            if ($status === 401) {
                $headers['WWW-Authenticate'] = 'Bearer realm="studio-agent"';
            } elseif ($status === 405) {
                $headers['Allow'] = 'GET';
            } elseif ($status === 429 || $status === 503) {
                $headers['Retry-After'] = $status === 429 ? (string) (60 - $now % 60) : '5';
            }
        }
        try {
            ($this->audit)(['event' => 'agent_api_access', 'identity' => $identity, 'request_id' => $requestId,
                'route' => in_array($route, ['health', 'products', 'orders'], true) ? $route : 'unknown',
                'status' => $status, 'result' => $body['error']['code'] ?? 'ok', 'time' => utc($now),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'record_count' => $count]);
        } catch (Throwable $error) {
            // Do not return commercial data if the audit sink cannot accept an event.
            $status = 503;
            $headers['Retry-After'] = '5';
            $body = ['schema_version' => self::VERSION, 'request_id' => $requestId, 'generated_at' => utc($now),
                'error' => ['code' => 'unavailable', 'message' => 'Service temporarily unavailable.']];
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function filters(string $route, string $query): array
    {
        if (strlen($query) > 8192) {
            invalid();
        }
        $allowed = $route === 'health' ? [] : ['limit', 'cursor', 'updated_from', 'updated_before', 'status'];
        $allowed = array_merge($allowed, $route === 'orders' ? ['created_from', 'created_before'] : ($route === 'products' ? ['category'] : []));
        $input = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            if (!str_contains($pair, '=')) {
                invalid();
            }
            [$key, $value] = array_map('urldecode', explode('=', $pair, 2));
            if (!in_array($key, $allowed, true) || isset($input[$key]) || $value === '') {
                invalid();
            }
            $input[$key] = $value;
        }
        if ($route === 'health') {
            return [];
        }
        $limit = $input['limit'] ?? '50';
        if (!preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (int) $limit > 100) {
            invalid();
        }
        $input['limit'] = (int) $limit;
        $statuses = $route === 'products' ? ['published', 'draft', 'all'] : ['all', 'pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'];
        $input['status'] = $input['status'] ?? $statuses[0];
        if (!in_array($input['status'], $statuses, true)
            || (isset($input['category']) && !in_array($input['category'], ['desk', 'display', 'automotive', 'other'], true))) {
            invalid();
        }
        foreach (['updated_from', 'updated_before', 'created_from', 'created_before'] as $key) {
            if (isset($input[$key])) {
                timestamp($input[$key]);
            }
        }
        foreach (['updated', 'created'] as $prefix) {
            if (isset($input[$prefix . '_from'], $input[$prefix . '_before'])
                && timestamp($input[$prefix . '_from']) >= timestamp($input[$prefix . '_before'])) {
                invalid();
            }
        }
        ksort($input);
        return $input;
    }
}
