<?php
/** Pure Eurosender connection tests. No config.php, database or network. */
define('EUROSENDER_INTEGRATION_ACTIVE', false);
define('EUROSENDER_ENABLED', false);
define('EUROSENDER_ENV', 'sandbox');
define('EUROSENDER_API_BASE', 'https://sandbox-api.eurosender.invalid');
define('EUROSENDER_API_KEY', 'fixture-sandbox-key');
define('EUROSENDER_TIMEOUT_SECONDS', 5);
require __DIR__ . '/../includes/eurosender.php';

$checks = 0;
function esCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }

esCheck(ggs_eurosender_configured(), 'synthetic API key accepted');
esCheck(!ggs_eurosender_enabled(), 'runtime integration remains disabled');

$calls = [];
$result = ggs_eurosender_check_configuration(static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = compact('method', 'url', 'headers', 'body');
    return ['status' => 200, 'body' => json_encode([
        ['id' => 1, 'code' => 'RO', 'name' => 'Romania', 'requiresRegion' => true, 'requiresCity' => true, 'active' => true, 'isEu' => true],
        ['id' => 2, 'code' => 'DE', 'name' => 'Germany', 'requiresRegion' => false, 'requiresCity' => false, 'active' => true, 'isEu' => true],
        ['id' => 3, 'code' => 'GB', 'name' => 'United Kingdom', 'requiresRegion' => false, 'requiresCity' => false, 'active' => true, 'isEu' => false],
        ['id' => 4, 'code' => 'ZZ', 'name' => 'Inactive', 'requiresRegion' => false, 'requiresCity' => false, 'active' => false, 'isEu' => false],
    ], JSON_THROW_ON_ERROR), 'content_type' => 'application/json'];
});

esCheck(count($calls) === 1 && $calls[0]['method'] === 'GET', 'configuration check is one GET request');
esCheck($calls[0]['url'] === 'https://sandbox-api.eurosender.invalid/v1/countries', 'sandbox countries endpoint used');
esCheck(in_array('x-api-key: fixture-sandbox-key', $calls[0]['headers'], true), 'x-api-key header supplied');
esCheck($calls[0]['body'] === null, 'read-only check sends no request body');
esCheck($result['countries'] === 4 && $result['active_countries'] === 3, 'active country counts parsed');
esCheck($result['eu_countries'] === 2, 'EU country count parsed');
esCheck($result['romania']['requiresRegion'] === true && $result['romania']['requiresCity'] === true, 'Romania requirements preserved');

try {
    ggs_eurosender_check_configuration(static fn(): array => ['status' => 401, 'body' => json_encode(['message' => 'Invalid API key'])]);
    esCheck(false, 'invalid key accepted');
} catch (GgsEurosenderException $error) {
    esCheck(str_contains($error->getMessage(), 'Invalid API key') && !$error->isUncertain(), 'read-only authentication error is clear and safe');
}

echo "PASS: {$checks} Eurosender sandbox connection checks. No config, database or network loaded.\n";
