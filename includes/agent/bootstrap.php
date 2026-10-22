<?php
declare(strict_types=1);
namespace Ggs\Agent;

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Dto.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/Api.php';

function serve(string $route): void
{
    ini_set('display_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
    // Suppress raw PHP warnings: only sanitized access events leave this boundary.
    set_error_handler(static function (): bool {
        throw new \RuntimeException('Agent runtime failure.');
    });
    $api = new Api(
        static function (): array {
            // cPanel shared hosting may not expose PHP-FPM pool environment settings.
            // A private config file is the activation switch there; an optional env
            // value remains an emergency off switch on hosts that support it.
            if (getenv('GGS_AGENT_ENABLED') === '0') {
                throw new \RuntimeException('Agent API disabled.');
            }
            $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $path = getenv('GGS_AGENT_CONFIG_FILE') ?: Config::defaultPath($documentRoot);
            $config = Config::load($path, $documentRoot);
            if ($config['enabled'] !== true) {
                throw new \RuntimeException('Agent API disabled.');
            }
            return $config;
        },
        [Repository::class, 'connect'],
        static function (array $config, string $identity, int $now): void {
            (new FileRateLimiter($config['rate_limit_dir']))($identity, $now);
        },
        static function (array $event): void {
            if (!error_log(json_encode($event, JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Audit unavailable.');
            }
        }
    );
    $https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
    // Forwarded headers are intentionally ignored; configure the trusted server's HTTPS flag.
    $result = $api->handle($route, $_SERVER['REQUEST_METHOD'] ?? '',
        $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['QUERY_STRING'] ?? '', $https);
    http_response_code($result['status']);
    foreach ($result['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    restore_error_handler();
}
