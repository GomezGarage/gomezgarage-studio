<?php
/**
 * Eurosender courier integration foundation.
 *
 * The first deployment phase deliberately exposes only a read-only account
 * check. Checkout quotes and shipment booking remain disconnected until the
 * sandbox flow has been reviewed with the account owner.
 */

final class GgsEurosenderException extends RuntimeException
{
    private bool $uncertain;

    public function __construct(string $message, bool $uncertain = false)
    {
        parent::__construct($message);
        $this->uncertain = $uncertain;
    }

    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}

function ggs_eurosender_enabled(): bool
{
    return defined('EUROSENDER_INTEGRATION_ACTIVE')
        && EUROSENDER_INTEGRATION_ACTIVE === true
        && defined('EUROSENDER_ENABLED')
        && EUROSENDER_ENABLED === true;
}

function ggs_eurosender_configured(): bool
{
    foreach (['EUROSENDER_API_BASE', 'EUROSENDER_API_KEY'] as $constant) {
        $value = defined($constant) ? trim((string) constant($constant)) : '';
        if ($value === '' || str_starts_with($value, 'REPLACE_WITH_')) return false;
    }
    return true;
}

function ggs_eurosender_timeout(): int
{
    return defined('EUROSENDER_TIMEOUT_SECONDS') ? max(5, (int) EUROSENDER_TIMEOUT_SECONDS) : 25;
}

/** @return array<string,string> */
function ggs_eurosender_eu_countries(): array
{
    return [
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria', 'HR' => 'Croatia',
        'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DK' => 'Denmark', 'EE' => 'Estonia',
        'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece',
        'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy', 'LV' => 'Latvia',
        'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'NL' => 'Netherlands',
        'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
    ];
}

/**
 * Low-level request with an injectable transport for offline tests.
 *
 * @param callable|null $transport Receives method, URL, headers and body.
 * @return array{status:int,body:string,content_type:string}
 */
function ggs_eurosender_http(string $method, string $url, array $headers, ?string $body = null, ?callable $transport = null): array
{
    if ($transport !== null) {
        $result = $transport($method, $url, $headers, $body);
        if (!is_array($result) || !isset($result['status'], $result['body'])) {
            throw new LogicException('The Eurosender test transport returned an invalid response.');
        }
        return [
            'status' => (int) $result['status'],
            'body' => (string) $result['body'],
            'content_type' => (string) ($result['content_type'] ?? ''),
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => ggs_eurosender_timeout(),
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new GgsEurosenderException(
            'Eurosender connection failed: ' . ($curlError !== '' ? $curlError : 'unknown network error'),
            in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        );
    }

    return ['status' => $status, 'body' => (string) $responseBody, 'content_type' => $contentType];
}

function ggs_eurosender_error_message(mixed $data, int $status): string
{
    if (is_array($data)) {
        foreach (['message', 'detail', 'title', 'error', 'errorMessage'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        $messages = [];
        foreach (['violations', 'errors'] as $key) {
            foreach (($data[$key] ?? []) as $error) {
                if (is_scalar($error)) $messages[] = trim((string) $error);
                elseif (is_array($error)) {
                    $field = trim((string) ($error['propertyPath'] ?? $error['field'] ?? ''));
                    $message = trim((string) ($error['message'] ?? $error['error'] ?? $error['detail'] ?? ''));
                    if ($message !== '') $messages[] = ($field !== '' ? $field . ': ' : '') . $message;
                }
            }
        }
        $messages = array_values(array_unique(array_filter($messages)));
        if ($messages) return implode('; ', $messages);
    }

    return 'HTTP ' . $status;
}

/** @return array{status:int,body:string,content_type:string} */
function ggs_eurosender_request(string $method, string $path, ?array $payload = null, ?callable $transport = null): array
{
    if (!ggs_eurosender_configured()) {
        throw new RuntimeException('Eurosender API key is missing or still a placeholder.');
    }

    $url = rtrim((string) EUROSENDER_API_BASE, '/') . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'x-api-key: ' . trim((string) EUROSENDER_API_KEY),
    ];
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    $response = ggs_eurosender_http(strtoupper($method), $url, $headers, $body, $transport);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400) {
        throw new GgsEurosenderException(
            'Eurosender request failed (HTTP ' . $response['status'] . '): ' . ggs_eurosender_error_message($data, $response['status']),
            in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        );
    }
    if (!is_array($data)) throw new GgsEurosenderException('Eurosender returned an invalid JSON response.');

    return $response;
}

/**
 * Read-only credential and environment check. It cannot quote or book a shipment.
 *
 * @return array{countries:int,active_countries:int,eu_countries:int,romania:array}
 */
function ggs_eurosender_check_configuration(?callable $transport = null): array
{
    $response = ggs_eurosender_request('GET', '/v1/countries', null, $transport);
    $countries = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    if (!array_is_list($countries)) throw new GgsEurosenderException('Eurosender returned an unexpected countries response.');

    $active = array_values(array_filter($countries, static fn(mixed $country): bool => is_array($country) && ($country['active'] ?? true) !== false));
    $eu = array_values(array_filter($active, static fn(array $country): bool => ($country['isEu'] ?? false) === true));
    $romania = null;
    foreach ($active as $country) {
        if (strtoupper(trim((string) ($country['code'] ?? ''))) === 'RO') {
            $romania = $country;
            break;
        }
    }
    if ($romania === null) throw new GgsEurosenderException('Eurosender did not return Romania as an active country.');

    return [
        'countries' => count($countries),
        'active_countries' => count($active),
        'eu_countries' => count($eu),
        'romania' => $romania,
    ];
}

/** @return array<int,array> */
function ggs_eurosender_get_list(string $path, ?callable $transport = null): array
{
    $response = ggs_eurosender_request('GET', $path, null, $transport);
    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !array_is_list($data)) {
        throw new GgsEurosenderException('Eurosender returned an unexpected geographic-data response.');
    }
    return array_values(array_filter($data, 'is_array'));
}

function ggs_eurosender_normalize_geo_name(string $value): string
{
    $value = strtr(trim($value), [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
    ]);
    $value = strtolower($value);
    $normalized = preg_replace('/[^a-z0-9]+/u', '', $value) ?? '';
    // Eurosender's Romanian nomenclature uses the English "Bucharest" while
    // Romanian customers and addresses naturally use "Bucuresti".
    return match ($normalized) {
        'bucuresti', 'municipiulbucuresti' => 'bucharest',
        default => $normalized,
    };
}

/** @return array{id:int,name:string,code:?string} */
function ggs_eurosender_match_geo_item(array $items, string $wanted, string $label, ?int $regionId = null): array
{
    $needle = ggs_eurosender_normalize_geo_name($wanted);
    $candidates = [];
    foreach ($items as $item) {
        if ($regionId !== null && isset($item['regionId']) && (int) $item['regionId'] !== $regionId) continue;
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') continue;
        $normalized = ggs_eurosender_normalize_geo_name($name);
        if ($normalized === $needle) {
            return ['id' => (int) ($item['id'] ?? 0), 'name' => $name, 'code' => isset($item['code']) ? (string) $item['code'] : null];
        }
        if ($needle !== '' && (str_contains($normalized, $needle) || str_contains($needle, $normalized))) $candidates[] = $name;
    }

    $suffix = $candidates ? ' Closest matches: ' . implode(', ', array_slice(array_unique($candidates), 0, 8)) . '.' : '';
    throw new InvalidArgumentException('Eurosender could not match the exact ' . $label . ' "' . $wanted . '".' . $suffix);
}

/**
 * Resolve the canonical region/city IDs required by countries such as Romania.
 *
 * @param array{country:string,zip?:string,city?:string,street?:string,region?:string} $address
 * @return array{country:string,zip:string,city:string,cityId?:int,street:string,region?:string,regionId?:int}
 */
function ggs_eurosender_resolve_address(array $address, ?callable $transport = null): array
{
    $country = strtoupper(trim((string) ($address['country'] ?? '')));
    $city = trim((string) ($address['city'] ?? ''));
    $region = trim((string) ($address['region'] ?? ''));
    $zip = trim((string) ($address['zip'] ?? ''));
    $street = trim((string) ($address['street'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $country)) throw new InvalidArgumentException('Eurosender pickup/delivery country must be a two-letter code.');
    if ($city === '' || $zip === '' || $street === '') throw new InvalidArgumentException('Eurosender address requires street, city and postal code.');

    $resolved = ['country' => $country, 'zip' => $zip, 'city' => $city, 'street' => $street];
    if ($country !== 'RO') {
        if ($region !== '') $resolved['region'] = $region;
        return $resolved;
    }
    if ($region === '') throw new InvalidArgumentException('Eurosender requires a region/county for Romanian addresses.');

    $regionMatch = ggs_eurosender_match_geo_item(
        ggs_eurosender_get_list('/v1/countries/RO/regions', $transport),
        $region,
        'Romanian region'
    );
    $cityMatch = ggs_eurosender_match_geo_item(
        ggs_eurosender_get_list('/v1/countries/RO/cities', $transport),
        $city,
        'Romanian city',
        $regionMatch['id']
    );
    if ($regionMatch['id'] <= 0 || $cityMatch['id'] <= 0) throw new GgsEurosenderException('Eurosender geographic data did not include valid IDs.');

    $resolved['region'] = $regionMatch['name'];
    $resolved['regionId'] = $regionMatch['id'];
    $resolved['city'] = $cityMatch['name'];
    $resolved['cityId'] = $cityMatch['id'];
    return $resolved;
}

/** @return array{country:string,zip:string,city:string,street:string,region:string} */
function ggs_eurosender_pickup_address(): array
{
    $defaults = [
        'country' => 'RO',
        'zip' => '077016',
        'city' => 'Dumbraveni',
        'street' => 'Strada Magnoliei 12D',
        'region' => 'Ilfov',
    ];
    $map = [
        'country' => 'EUROSENDER_PICKUP_COUNTRY',
        'zip' => 'EUROSENDER_PICKUP_POSTAL',
        'city' => 'EUROSENDER_PICKUP_CITY',
        'street' => 'EUROSENDER_PICKUP_STREET',
        'region' => 'EUROSENDER_PICKUP_REGION',
    ];
    $address = [];
    foreach ($map as $field => $constant) {
        $value = defined($constant) ? trim((string) constant($constant)) : $defaults[$field];
        if ($value === '' || str_starts_with($value, 'REPLACE_WITH_')) $value = $defaults[$field];
        $address[$field] = $value;
    }
    return $address;
}

/**
 * Build a quote payload. Omitting the service type asks Eurosender for every
 * available option instead of forcing Standard/selection.
 *
 * @param array{country:string,zip:string,city:string,street:string,region?:string} $deliveryAddress
 * @param array{length:float,width:float,height:float,weight:float,value:float,content?:string} $package
 */
function ggs_eurosender_quote_payload(
    array $deliveryAddress,
    array $package,
    ?callable $transport = null,
    ?array $pickupAddress = null,
    ?string $serviceType = 'selection'
): array
{
    foreach (['length', 'width', 'height', 'weight', 'value'] as $field) {
        if (!isset($package[$field]) || !is_numeric($package[$field]) || (float) $package[$field] <= 0) {
            throw new InvalidArgumentException('Eurosender package ' . $field . ' must be greater than zero.');
        }
    }

    $pickup = ggs_eurosender_resolve_address($pickupAddress ?? ggs_eurosender_pickup_address(), $transport);
    $delivery = ggs_eurosender_resolve_address($deliveryAddress, $transport);
    $payload = [
        'shipment' => [
            'pickupAddress' => $pickup,
            'deliveryAddress' => $delivery,
            'pickupType' => 'new_pickup_request',
        ],
        'parcels' => ['packages' => [[
            'parcelId' => 'GGS3D-TEST-1',
            'quantity' => 1,
            'width' => round((float) $package['width'], 2),
            'height' => round((float) $package['height'], 2),
            'length' => round((float) $package['length'], 2),
            'weight' => round((float) $package['weight'], 3),
            'content' => trim((string) ($package['content'] ?? '3D printed products')) ?: '3D printed products',
            'value' => round((float) $package['value'], 2),
        ]]],
        'paymentMethod' => 'credit',
        'currencyCode' => 'EUR',
    ];
    if ($serviceType !== null && trim($serviceType) !== '') {
        $payload['serviceType'] = trim($serviceType);
    }

    return $payload;
}

/**
 * Build and request one non-mutating Standard sandbox quote. No wallet credit
 * is debited. Kept for the future order-selection flow.
 *
 * @param array{country:string,zip:string,city:string,street:string,region?:string} $deliveryAddress
 * @param array{length:float,width:float,height:float,weight:float,value:float,content?:string} $package
 * @return array{amount:float,currency:string,service_type:string,service_subtype:string,courier_id:int,minimum_pickup_date:string,estimated_delivery_time:string,warnings:array,payload:array}
 */
function ggs_eurosender_quote(array $deliveryAddress, array $package, ?callable $transport = null, ?array $pickupAddress = null): array
{
    $payload = ggs_eurosender_quote_payload($deliveryAddress, $package, $transport, $pickupAddress, 'selection');

    $response = ggs_eurosender_request('POST', '/v1/quotes', $payload, $transport);
    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $order = is_array($data['order'] ?? null) ? $data['order'] : [];
    $amount = $order['totalPrice']['original']['gross'] ?? null;
    $currency = $order['totalPrice']['original']['currencyCode'] ?? null;
    if (!is_numeric($amount) || !is_string($currency) || $currency === '') {
        throw new GgsEurosenderException('Eurosender returned a quote without a usable total price.');
    }

    return [
        'amount' => round((float) $amount, 2),
        'currency' => strtoupper($currency),
        'service_type' => trim((string) ($order['serviceType'] ?? 'selection')),
        'service_subtype' => trim((string) ($order['serviceSubtype'] ?? '')),
        'courier_id' => (int) ($order['courierId'] ?? 0),
        'minimum_pickup_date' => trim((string) ($order['minPickupDate'] ?? '')),
        'estimated_delivery_time' => trim((string) ($order['estimatedDeliveryTime'] ?? '')),
        'warnings' => is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
        'payload' => $payload,
    ];
}

function ggs_eurosender_service_label(string $serviceType): string
{
    return match (strtolower(trim($serviceType))) {
        'selection' => 'Standard',
        'flexi' => 'Standard Flexi',
        'regular_plus' => 'Priority',
        'express' => 'Priority Express',
        'freight' => 'Freight',
        'freight_priority' => 'Freight Priority',
        'freight_priority_express' => 'Freight Priority Express',
        'van' => 'Van',
        'ftl' => 'Full Truck Load',
        default => ucwords(str_replace('_', ' ', trim($serviceType))),
    };
}

/**
 * Request every non-booking sandbox quote option returned for an address pair.
 * No shipment is created and the wallet is not debited.
 *
 * @param array{country:string,zip:string,city:string,street:string,region?:string} $deliveryAddress
 * @param array{length:float,width:float,height:float,weight:float,value:float,content?:string} $package
 * @return array{options:array<int,array{name:string,label:string,subtype:string,amount:float,currency:string,estimated_delivery_time:string,minimum_pickup_date:string,courier_id:int,label_required:bool}>,warnings:array,payload:array}
 */
function ggs_eurosender_quote_options(array $deliveryAddress, array $package, ?callable $transport = null, ?array $pickupAddress = null): array
{
    $payload = ggs_eurosender_quote_payload($deliveryAddress, $package, $transport, $pickupAddress, null);
    $response = ggs_eurosender_request('POST', '/v1/quotes', $payload, $transport);
    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $serviceTypes = $data['options']['serviceTypes'] ?? [];
    if (!is_array($serviceTypes)) $serviceTypes = [];

    $options = [];
    foreach ($serviceTypes as $service) {
        if (!is_array($service)) continue;
        $name = trim((string) ($service['name'] ?? $service['serviceType'] ?? ''));
        $amount = $service['price']['original']['gross']
            ?? $service['totalPrice']['original']['gross']
            ?? null;
        $currency = $service['price']['original']['currencyCode']
            ?? $service['totalPrice']['original']['currencyCode']
            ?? null;
        if ($name === '' || !is_numeric($amount) || !is_string($currency) || trim($currency) === '') continue;

        $options[] = [
            'name' => $name,
            'label' => ggs_eurosender_service_label($name),
            'subtype' => trim((string) ($service['serviceSubtype'] ?? '')),
            'amount' => round((float) $amount, 2),
            'currency' => strtoupper(trim($currency)),
            'estimated_delivery_time' => trim((string) ($service['edt'] ?? $service['estimatedDeliveryTime'] ?? '')),
            'minimum_pickup_date' => trim((string) ($service['minPickupDate'] ?? '')),
            'courier_id' => (int) ($service['courierId'] ?? 0),
            'label_required' => (bool) ($service['isLabelRequired'] ?? false),
        ];
    }
    if (!$options) {
        throw new GgsEurosenderException('Eurosender returned no usable service options for this route and parcel.');
    }

    usort($options, static fn(array $left, array $right): int => $left['amount'] <=> $right['amount']);
    return [
        'options' => $options,
        'warnings' => is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
        'payload' => $payload,
    ];
}
