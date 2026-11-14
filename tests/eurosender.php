<?php
/** Pure Eurosender connection tests. No config.php, database or network. */
define('EUROSENDER_INTEGRATION_ACTIVE', false);
define('EUROSENDER_ENABLED', false);
define('EUROSENDER_ENV', 'sandbox');
define('EUROSENDER_API_BASE', 'https://sandbox-api.eurosender.invalid');
define('EUROSENDER_API_KEY', 'fixture-sandbox-key');
define('EUROSENDER_TIMEOUT_SECONDS', 5);
define('EUROSENDER_PICKUP_CONTACT_NAME', 'Lancer Land SRL / Gabriel Petrut');
define('EUROSENDER_PICKUP_COUNTRY', 'RO');
define('EUROSENDER_PICKUP_REGION', 'Ilfov');
define('EUROSENDER_PICKUP_CITY', 'Dumbraveni');
define('EUROSENDER_PICKUP_POSTAL', '077016');
define('EUROSENDER_PICKUP_STREET', 'Strada Test 12D');
require __DIR__ . '/../includes/eurosender.php';

$checks = 0;
function esCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }

esCheck(ggs_eurosender_configured(), 'synthetic API key accepted');
esCheck(!ggs_eurosender_enabled(), 'runtime integration remains disabled');
esCheck(count(ggs_eurosender_eu_countries()) === 27 && !isset(ggs_eurosender_eu_countries()['GB']), 'destination selector contains EU only');
$configuredPickup = ggs_eurosender_pickup_address();
esCheck($configuredPickup['street'] === 'Strada Test 12D' && $configuredPickup['zip'] === '077016', 'configured complete pickup address available');

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

$quoteCalls = [];
$quoteTransport = static function (string $method, string $url, array $headers, ?string $body) use (&$quoteCalls): array {
    $quoteCalls[] = compact('method', 'url', 'headers', 'body');
    if (str_ends_with($url, '/v1/countries/RO/regions')) {
        return ['status' => 200, 'body' => json_encode([
            ['id' => 10, 'name' => 'Ilfov', 'code' => null],
            ['id' => 11, 'name' => 'Bucuresti', 'code' => null],
        ], JSON_THROW_ON_ERROR)];
    }
    if (str_ends_with($url, '/v1/countries/RO/cities')) {
        return ['status' => 200, 'body' => json_encode([
            ['id' => 100, 'name' => 'Dumbrăveni', 'regionId' => 10],
            ['id' => 101, 'name' => 'București', 'regionId' => 11],
        ], JSON_THROW_ON_ERROR)];
    }
    if (str_ends_with($url, '/v1/quotes')) {
        return ['status' => 200, 'body' => json_encode([
            'options' => ['serviceTypes' => []],
            'order' => [
                'totalPrice' => ['original' => ['currencyCode' => 'EUR', 'gross' => 4.75, 'net' => 4.75]],
                'serviceType' => 'selection', 'serviceSubtype' => 'door_to_door', 'courierId' => 42,
                'minPickupDate' => '2026-11-16T00:00:00+02:00', 'estimatedDeliveryTime' => '1-2',
            ],
            'warnings' => [],
        ], JSON_THROW_ON_ERROR)];
    }
    return ['status' => 404, 'body' => '{}'];
};
$quote = ggs_eurosender_quote([
    'country' => 'RO', 'region' => 'Bucuresti', 'city' => 'Bucuresti',
    'zip' => '030167', 'street' => 'Strada Test 15A',
], ['length' => 30, 'width' => 20, 'height' => 10, 'weight' => 1.2, 'value' => 100], $quoteTransport);
esCheck($quote['amount'] === 4.75 && $quote['currency'] === 'EUR', 'sandbox quote total parsed');
esCheck($quote['service_type'] === 'selection' && $quote['service_subtype'] === 'door_to_door', 'sandbox service parsed');
esCheck(count($quoteCalls) === 5 && $quoteCalls[4]['method'] === 'POST', 'Romanian pickup and delivery are resolved before quote');
$quotePayload = json_decode((string) $quoteCalls[4]['body'], true, 512, JSON_THROW_ON_ERROR);
esCheck($quotePayload['shipment']['pickupAddress']['cityId'] === 100 && $quotePayload['shipment']['pickupAddress']['regionId'] === 10, 'pickup canonical IDs included');
esCheck($quotePayload['shipment']['deliveryAddress']['cityId'] === 101 && $quotePayload['shipment']['deliveryAddress']['regionId'] === 11, 'delivery canonical IDs included');
esCheck($quotePayload['paymentMethod'] === 'credit' && $quotePayload['serviceType'] === 'selection', 'safe standard quote selection');
esCheck($quotePayload['parcels']['packages'][0]['weight'] === 1.2, 'package measurements mapped');

$explicitPickup = ['country' => 'DE', 'region' => '', 'city' => 'Berlin', 'zip' => '10115', 'street' => 'Teststrasse 1'];
$explicitDelivery = ['country' => 'FR', 'region' => '', 'city' => 'Paris', 'zip' => '75001', 'street' => 'Rue de Test 1'];
$foreignCalls = [];
$foreignQuote = ggs_eurosender_quote($explicitDelivery, [
    'length' => 20, 'width' => 15, 'height' => 10, 'weight' => 0.5, 'value' => 25,
], static function (string $method, string $url, array $headers, ?string $body) use (&$foreignCalls): array {
    $foreignCalls[] = compact('method', 'url', 'headers', 'body');
    return ['status' => 200, 'body' => json_encode(['order' => [
        'totalPrice' => ['original' => ['currencyCode' => 'EUR', 'gross' => 12.3]],
        'serviceType' => 'selection', 'serviceSubtype' => 'door_to_door',
    ]], JSON_THROW_ON_ERROR)];
}, $explicitPickup);
esCheck($foreignQuote['amount'] === 12.3 && count($foreignCalls) === 1, 'editable non-Romanian pickup and EU destination quote directly');
$foreignPayload = json_decode((string) $foreignCalls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
esCheck($foreignPayload['shipment']['pickupAddress']['country'] === 'DE' && $foreignPayload['shipment']['deliveryAddress']['country'] === 'FR', 'selected pickup and destination countries mapped');

echo "PASS: {$checks} Eurosender sandbox connection checks. No config, database or network loaded.\n";
