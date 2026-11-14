<?php
/** Pure Eurosender connection tests. No config.php, database or network. */
define('EUROSENDER_INTEGRATION_ACTIVE', false);
define('EUROSENDER_ENABLED', false);
define('EUROSENDER_AUTO_BOOKING_ENABLED', false);
define('EUROSENDER_ENV', 'sandbox');
define('EUROSENDER_API_BASE', 'https://sandbox-api.eurosender.invalid');
define('EUROSENDER_API_KEY', 'fixture-sandbox-key');
define('EUROSENDER_TIMEOUT_SECONDS', 5);
define('EUROSENDER_PICKUP_CONTACT_NAME', 'Lancer Land SRL / Gabriel Petrut');
define('EUROSENDER_PICKUP_CONTACT_EMAIL', 'sender@example.invalid');
define('EUROSENDER_PICKUP_CONTACT_PHONE', '+40700000000');
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
esCheck(!ggs_eurosender_checkout_enabled() && !ggs_eurosender_booking_enabled(), 'checkout and booking remain disabled by default');
esCheck(count(ggs_eurosender_eu_countries()) === 27 && !isset(ggs_eurosender_eu_countries()['GB']), 'destination selector contains EU only');
esCheck(
    ggs_eurosender_normalize_geo_name('București') === ggs_eurosender_normalize_geo_name('Bucharest'),
    'Romanian Bucuresti alias matches Eurosender Bucharest'
);
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

$optionsCalls = [];
$allOptions = ggs_eurosender_quote_options($explicitDelivery, [
    'length' => 20, 'width' => 15, 'height' => 10, 'weight' => 0.5, 'value' => 25,
], static function (string $method, string $url, array $headers, ?string $body) use (&$optionsCalls): array {
    $optionsCalls[] = compact('method', 'url', 'headers', 'body');
    return ['status' => 200, 'body' => json_encode([
        'options' => ['serviceTypes' => [
            ['name' => 'express', 'serviceSubtype' => 'door_to_door', 'price' => ['original' => ['currencyCode' => 'EUR', 'gross' => 31.4]], 'edt' => '1'],
            ['name' => 'selection', 'serviceSubtype' => 'door_to_door', 'price' => ['original' => ['currencyCode' => 'EUR', 'gross' => 12.3]], 'edt' => '3-4'],
            ['name' => 'regular_plus', 'serviceSubtype' => 'door_to_door', 'price' => ['original' => ['currencyCode' => 'EUR', 'gross' => 20.1]], 'edt' => '2-3'],
        ]],
        'warnings' => [],
    ], JSON_THROW_ON_ERROR)];
}, $explicitPickup);
esCheck(count($allOptions['options']) === 3 && count($optionsCalls) === 1, 'all available service options parsed from one non-booking quote request');
esCheck(
    array_column($allOptions['options'], 'label') === ['Standard', 'Priority', 'Priority Express'],
    'service options labelled and sorted by price'
);
esCheck($allOptions['options'][0]['estimated_delivery_time'] === '3-4', 'service-option EDT parsed from official response field');
$optionsPayload = json_decode((string) $optionsCalls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
esCheck(!array_key_exists('serviceType', $optionsPayload), 'all-options request does not force Standard selection');

$packed = ggs_eurosender_package_from_items([
    ['name' => 'Part A', 'price_eur' => 20, 'qty' => 2, 'shipping_length_cm' => 12, 'shipping_width_cm' => 8, 'shipping_height_cm' => 4, 'shipping_weight_kg' => 0.25],
    ['name' => 'Part B', 'price_eur' => 10, 'qty' => 1, 'shipping_length_cm' => 20, 'shipping_width_cm' => 10, 'shipping_height_cm' => 3, 'shipping_weight_kg' => 0.1],
]);
esCheck($packed['length'] === 20.0 && $packed['height'] === 11.0 && $packed['weight'] === 0.6, 'cart packed into one conservative parcel');
esCheck($packed['value'] === 50.0 && !$packed['used_defaults'], 'parcel value and measurement provenance retained');

$orderFixture = [
    'order_number' => 'GGS-2026-9999', 'status' => 'paid',
    'customer_name' => 'Fixture Customer', 'customer_email' => 'customer@example.invalid', 'customer_phone' => '+33123456789',
    'shipping_address' => json_encode(['country' => 'FR', 'county' => '', 'city' => 'Paris', 'postal' => '75001', 'street' => 'Rue de Test 1']),
    'items' => json_encode([['name' => 'Part A', 'price_eur' => 50, 'qty' => 1, 'shipping_length_cm' => 20, 'shipping_width_cm' => 15, 'shipping_height_cm' => 10, 'shipping_weight_kg' => 0.5]]),
    'shipping_package' => json_encode(['length' => 20, 'width' => 15, 'height' => 10, 'weight' => 0.5, 'value' => 50, 'content' => 'Part A']),
    'subtotal_eur' => 50, 'eurosender_service_type' => 'flexi', 'eurosender_service_subtype' => 'door_to_door', 'eurosender_courier_id' => 7,
];
$orderCalls = [];
$created = ggs_eurosender_create_order($orderFixture, static function (string $method, string $url, array $headers, ?string $body) use (&$orderCalls): array {
    $orderCalls[] = compact('method', 'url', 'headers', 'body');
    if (str_ends_with($url, '/v1/countries/RO/regions')) return ['status' => 200, 'body' => json_encode([['id' => 10, 'name' => 'Ilfov']])];
    if (str_ends_with($url, '/v1/countries/RO/cities')) return ['status' => 200, 'body' => json_encode([['id' => 100, 'name' => 'Dumbraveni', 'regionId' => 10]])];
    if (str_ends_with($url, '/validate_creation')) return ['status' => 200, 'body' => json_encode(['valid' => true])];
    return ['status' => 201, 'body' => json_encode([
        'orderCode' => '123456-26', 'status' => 'Order Received', 'labelLink' => 'https://example.invalid/label.pdf',
        'parcels' => ['packages' => [['tracking' => ['number' => 'TRACK-1', 'url' => 'https://example.invalid/track']]]],
    ], JSON_THROW_ON_ERROR)];
});
esCheck(count($orderCalls) === 4 && str_ends_with($orderCalls[2]['url'], '/validate_creation') && str_ends_with($orderCalls[3]['url'], '/v1/orders'), 'order validated before creation');
$createPayload = json_decode((string) $orderCalls[3]['body'], true, 512, JSON_THROW_ON_ERROR);
esCheck($createPayload['customerInternalReference'] === 'GGS-2026-9999' && $createPayload['serviceType'] === 'flexi' && $createPayload['serviceSubtype'] === 'door_to_door', 'order reference and selected service preserved');
esCheck($createPayload['shipment']['pickupContact']['email'] === 'sender@example.invalid' && $createPayload['shipment']['deliveryContact']['name'] === 'Fixture Customer', 'sender and recipient contacts mapped');
esCheck($created['order_code'] === '123456-26' && $created['tracking_number'] === 'TRACK-1', 'created shipment identifiers parsed');

try {
    ggs_eurosender_request('POST', '/v1/orders/validate_creation', ['fixture' => true], static fn(): array => [
        'status' => 422, 'body' => json_encode(['message' => 'Invalid fixture']),
    ]);
    esCheck(false, 'validation error accepted');
} catch (GgsEurosenderException $error) {
    esCheck(!$error->isUncertain(), 'non-mutating validation failure remains safe to retry');
}
try {
    ggs_eurosender_request('POST', '/v1/orders', ['fixture' => true], static fn(): array => [
        'status' => 504, 'body' => json_encode(['message' => 'Gateway timeout']),
    ], true);
    esCheck(false, 'ambiguous creation failure accepted');
} catch (GgsEurosenderException $error) {
    esCheck($error->isUncertain(), 'ambiguous creation failure blocks automatic retry');
}

echo "PASS: {$checks} Eurosender sandbox connection checks. No config, database or network loaded.\n";
