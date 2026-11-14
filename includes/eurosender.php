<?php
/**
 * Eurosender quotes, checkout selection and paid-order booking.
 *
 * Public checkout is deliberately environment-locked: a production shop can
 * only use a production Eurosender key, while sandbox quotes/bookings are
 * available only when the whole application is in sandbox mode.
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

function ggs_eurosender_checkout_enabled(): bool
{
    if (!ggs_eurosender_enabled() || !ggs_eurosender_configured()) return false;
    $providerEnv = defined('EUROSENDER_ENV') ? strtolower(trim((string) EUROSENDER_ENV)) : 'sandbox';
    $appEnv = defined('APP_ENV') ? strtolower(trim((string) APP_ENV)) : 'production';
    return ($providerEnv === 'production' && $appEnv === 'production')
        || ($providerEnv === 'sandbox' && $appEnv === 'sandbox');
}

function ggs_eurosender_booking_enabled(): bool
{
    return ggs_eurosender_checkout_enabled()
        && defined('EUROSENDER_AUTO_BOOKING_ENABLED')
        && EUROSENDER_AUTO_BOOKING_ENABLED === true
        && ggs_eurosender_pickup_contact_configured();
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
function ggs_eurosender_http(string $method, string $url, array $headers, ?string $body = null, ?callable $transport = null, bool $uncertainOnFailure = false): array
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
            $uncertainOnFailure
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
function ggs_eurosender_request(string $method, string $path, ?array $payload = null, ?callable $transport = null, bool $uncertainOnFailure = false): array
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

    $response = ggs_eurosender_http(strtoupper($method), $url, $headers, $body, $transport, $uncertainOnFailure);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400) {
        throw new GgsEurosenderException(
            'Eurosender request failed (HTTP ' . $response['status'] . '): ' . ggs_eurosender_error_message($data, $response['status']),
            $uncertainOnFailure
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

/** @return array{name:string,email:string,phone:string} */
function ggs_eurosender_pickup_contact_configured(): bool
{
    foreach (['EUROSENDER_PICKUP_CONTACT_NAME', 'EUROSENDER_PICKUP_CONTACT_EMAIL', 'EUROSENDER_PICKUP_CONTACT_PHONE'] as $constant) {
        $value = defined($constant) ? trim((string) constant($constant)) : '';
        if ($value === '' || str_starts_with($value, 'REPLACE_WITH_')) return false;
        if ($constant === 'EUROSENDER_PICKUP_CONTACT_EMAIL' && !filter_var($value, FILTER_VALIDATE_EMAIL)) return false;
    }
    return true;
}

/** @return array{name:string,email:string,phone:string} */
function ggs_eurosender_pickup_contact(): array
{
    $contact = [
        'name' => defined('EUROSENDER_PICKUP_CONTACT_NAME') ? trim((string) EUROSENDER_PICKUP_CONTACT_NAME) : '',
        'email' => defined('EUROSENDER_PICKUP_CONTACT_EMAIL') ? trim((string) EUROSENDER_PICKUP_CONTACT_EMAIL) : '',
        'phone' => defined('EUROSENDER_PICKUP_CONTACT_PHONE') ? trim((string) EUROSENDER_PICKUP_CONTACT_PHONE) : '',
    ];
    if (!ggs_eurosender_pickup_contact_configured()) throw new RuntimeException('Eurosender pickup contact is incomplete or invalid.');
    return $contact;
}

/**
 * Collapse packed product measurements into one conservative parcel.
 *
 * @return array{length:float,width:float,height:float,weight:float,value:float,content:string,used_defaults:bool}
 */
function ggs_eurosender_package_from_items(array $items, ?float $value = null): array
{
    $defaultLength = defined('EUROSENDER_DEFAULT_LENGTH_CM') ? (float) EUROSENDER_DEFAULT_LENGTH_CM : 30.0;
    $defaultWidth = defined('EUROSENDER_DEFAULT_WIDTH_CM') ? (float) EUROSENDER_DEFAULT_WIDTH_CM : 20.0;
    $defaultHeight = defined('EUROSENDER_DEFAULT_HEIGHT_CM') ? (float) EUROSENDER_DEFAULT_HEIGHT_CM : 10.0;
    $defaultWeight = defined('EUROSENDER_DEFAULT_WEIGHT_KG') ? (float) EUROSENDER_DEFAULT_WEIGHT_KG : 1.0;
    $length = $width = $height = $weight = $calculatedValue = 0.0;
    $usedDefaults = false;
    $names = [];
    foreach ($items as $item) {
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $itemLength = (float) ($item['shipping_length_cm'] ?? 0);
        $itemWidth = (float) ($item['shipping_width_cm'] ?? 0);
        $itemHeight = (float) ($item['shipping_height_cm'] ?? 0);
        $itemWeight = (float) ($item['shipping_weight_kg'] ?? 0);
        if ($itemLength <= 0 || $itemWidth <= 0 || $itemHeight <= 0 || $itemWeight <= 0) $usedDefaults = true;
        $length = max($length, $itemLength > 0 ? $itemLength : $defaultLength);
        $width = max($width, $itemWidth > 0 ? $itemWidth : $defaultWidth);
        $height += ($itemHeight > 0 ? $itemHeight : $defaultHeight) * $qty;
        $weight += ($itemWeight > 0 ? $itemWeight : $defaultWeight) * $qty;
        $calculatedValue += (float) ($item['price_eur'] ?? 0) * $qty;
        $name = trim((string) ($item['name'] ?? ''));
        if ($name !== '') $names[] = $name;
    }
    if ($weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0) throw new InvalidArgumentException('The order has no valid parcel items.');
    $contents = $names ? implode(', ', array_slice(array_unique($names), 0, 4)) : '3D printed products';
    return [
        'length' => round($length, 2), 'width' => round($width, 2), 'height' => round($height, 2),
        'weight' => round($weight, 3), 'value' => round(max(0.01, $value ?? $calculatedValue), 2),
        'content' => substr($contents, 0, 120), 'used_defaults' => $usedDefaults,
    ];
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

/** @return array{name:string,label:string,subtype:string,amount:float,currency:string,estimated_delivery_time:string,minimum_pickup_date:string,courier_id:int,label_required:bool} */
function ggs_eurosender_select_quote(array $deliveryAddress, array $package, string $serviceType, string $serviceSubtype = '', int $courierId = 0, ?callable $transport = null): array
{
    $allowed = ['selection', 'flexi', 'regular_plus', 'express'];
    $serviceType = strtolower(trim($serviceType));
    if (!in_array($serviceType, $allowed, true)) throw new InvalidArgumentException('Choose a valid Eurosender parcel service.');
    $quoted = ggs_eurosender_quote_options($deliveryAddress, $package, $transport);
    foreach ($quoted['options'] as $option) {
        if ($option['name'] !== $serviceType) continue;
        if ($serviceSubtype !== '' && $option['subtype'] !== $serviceSubtype) continue;
        if ($courierId > 0 && $option['courier_id'] !== $courierId) continue;
        return $option;
    }
    throw new GgsEurosenderException('The selected Eurosender service is no longer available. Recalculate delivery.');
}

function ggs_eurosender_next_pickup_date(): string
{
    $date = new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Bucharest'));
    while (in_array((int) $date->format('N'), [6, 7], true)) $date = $date->modify('+1 day');
    return $date->format('Y-m-d\T00:00:00P');
}

/** Build the payload shared by validation and creation. */
function ggs_eurosender_order_payload(array $order, ?callable $transport = null): array
{
    $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
    $items = json_decode((string) ($order['items'] ?? ''), true);
    $storedPackage = json_decode((string) ($order['shipping_package'] ?? ''), true);
    if (!is_array($address) || !is_array($items) || !$items) throw new InvalidArgumentException('The order address or parcel data is invalid.');
    $package = is_array($storedPackage) && $storedPackage ? $storedPackage : ggs_eurosender_package_from_items($items, (float) ($order['subtotal_eur'] ?? 0));
    $pickup = ggs_eurosender_resolve_address(ggs_eurosender_pickup_address(), $transport);
    $delivery = ggs_eurosender_resolve_address([
        'country' => strtoupper((string) ($address['country'] ?? '')),
        'region' => (string) ($address['county'] ?? ''),
        'city' => (string) ($address['city'] ?? ''),
        'zip' => (string) ($address['postal'] ?? ''),
        'street' => (string) ($address['street'] ?? ''),
    ], $transport);
    $pickupContact = ggs_eurosender_pickup_contact();
    $deliveryContact = [
        'name' => trim((string) ($order['customer_name'] ?? '')),
        'email' => trim((string) ($order['customer_email'] ?? '')),
        'phone' => trim((string) ($order['customer_phone'] ?? '')),
    ];
    if ($deliveryContact['name'] === '' || !filter_var($deliveryContact['email'], FILTER_VALIDATE_EMAIL) || $deliveryContact['phone'] === '') {
        throw new InvalidArgumentException('The customer contact is incomplete for courier booking.');
    }
    $serviceType = strtolower(trim((string) ($order['eurosender_service_type'] ?? '')));
    if (!in_array($serviceType, ['selection', 'flexi', 'regular_plus', 'express'], true)) throw new InvalidArgumentException('The stored Eurosender service is invalid.');

    return [
        'shipment' => [
            'pickupAddress' => $pickup,
            'deliveryAddress' => $delivery,
            'pickupDate' => ggs_eurosender_next_pickup_date(),
            'pickupContact' => $pickupContact,
            'deliveryContact' => $deliveryContact,
            'addOns' => [],
        ],
        'parcels' => [
            'envelopes' => [],
            'packages' => [[
                'parcelId' => 'GGS3D-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) ($order['order_number'] ?? 'ORDER')),
                'quantity' => 1,
                'width' => round((float) $package['width'], 2),
                'height' => round((float) $package['height'], 2),
                'length' => round((float) $package['length'], 2),
                'weight' => round((float) $package['weight'], 3),
                'content' => trim((string) ($package['content'] ?? '3D printed products')),
                'value' => round((float) ($package['value'] ?? $order['subtotal_eur'] ?? 0), 2),
            ]],
            'pallets' => [],
        ],
        'serviceType' => $serviceType,
        'serviceSubtype' => trim((string) ($order['eurosender_service_subtype'] ?? '')) ?: null,
        'courierTag' => null,
        'courierId' => !empty($order['eurosender_courier_id']) ? (int) $order['eurosender_courier_id'] : null,
        'paymentMethod' => 'credit',
        'currencyCode' => 'EUR',
        'insuranceId' => null,
        'orderContact' => ['email' => $pickupContact['email']],
        'comment' => trim((string) ($order['customer_note'] ?? '')) ?: null,
        'customerInternalReference' => (string) ($order['order_number'] ?? ''),
        'labelFormat' => 'pdf',
    ];
}

/** @return array{order_code:string,status:string,label_url:string,tracking_number:string,tracking_url:string,raw:array} */
function ggs_eurosender_create_order(array $order, ?callable $transport = null): array
{
    $payload = ggs_eurosender_order_payload($order, $transport);
    ggs_eurosender_request('POST', '/v1/orders/validate_creation', $payload, $transport);
    // Only the creation request is mutation-ambiguous on a timeout. Quotes and
    // validate_creation are POST requests too, but are safe to repeat.
    $response = ggs_eurosender_request('POST', '/v1/orders', $payload, $transport, true);
    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $code = trim((string) ($data['orderCode'] ?? ''));
    if ($code === '') throw new GgsEurosenderException('Eurosender created no usable order code.', true);
    $trackingNumber = '';
    $trackingUrl = '';
    foreach ((array) ($data['parcels']['packages'] ?? []) as $parcel) {
        if (!is_array($parcel)) continue;
        $trackingNumber = trim((string) ($parcel['tracking']['number'] ?? $parcel['trackingNumber'] ?? $trackingNumber));
        $trackingUrl = trim((string) ($parcel['tracking']['url'] ?? $parcel['trackingUrl'] ?? $trackingUrl));
    }
    return [
        'order_code' => $code,
        'status' => trim((string) ($data['status'] ?? 'Order Received')),
        'label_url' => trim((string) ($data['labelLink'] ?? '')),
        'tracking_number' => $trackingNumber,
        'tracking_url' => $trackingUrl,
        'raw' => $data,
    ];
}

/** Idempotent paid-order booking. Uncertain remote results are never auto-retried. */
function ggs_eurosender_issue_order(PDO $db, int $orderId, ?callable $transport = null): array
{
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) throw new InvalidArgumentException('Order not found.');
    if (($order['shipping_provider'] ?? '') !== 'eurosender') throw new InvalidArgumentException('This order does not use Eurosender.');
    if (!in_array((string) ($order['status'] ?? ''), ['paid', 'processing', 'printing', 'shipped'], true)) {
        throw new RuntimeException('Eurosender booking requires a confirmed payment.');
    }
    if (!empty($order['eurosender_order_code'])) {
        return ['existing' => true, 'order_code' => $order['eurosender_order_code'], 'status' => $order['eurosender_status'] ?? '', 'tracking_number' => $order['tracking_number'] ?? ''];
    }
    if (($order['eurosender_status'] ?? '') === 'uncertain') throw new RuntimeException('The previous Eurosender result is uncertain. Reconcile it with Eurosender before retrying.');
    $claim = $db->prepare("UPDATE orders SET eurosender_status = 'creating', eurosender_attempted_at = UTC_TIMESTAMP(), eurosender_error = NULL WHERE id = ? AND eurosender_order_code IS NULL AND (eurosender_status IS NULL OR eurosender_status IN ('failed', 'quoted'))");
    $claim->execute([$orderId]);
    if ($claim->rowCount() !== 1) throw new RuntimeException('Eurosender booking is already in progress or requires reconciliation.');
    try {
        $created = ggs_eurosender_create_order($order, $transport);
        $db->prepare('UPDATE orders SET eurosender_order_code = ?, eurosender_status = ?, eurosender_label_url = ?, eurosender_tracking_url = ?, tracking_number = NULLIF(?, ""), eurosender_created_at = UTC_TIMESTAMP(), eurosender_last_status_at = UTC_TIMESTAMP(), eurosender_error = NULL WHERE id = ?')
            ->execute([$created['order_code'], $created['status'], $created['label_url'] ?: null, $created['tracking_url'] ?: null, $created['tracking_number'], $orderId]);
        return $created + ['existing' => false];
    } catch (Throwable $error) {
        $uncertain = $error instanceof GgsEurosenderException && $error->isUncertain();
        $db->prepare('UPDATE orders SET eurosender_status = ?, eurosender_error = ? WHERE id = ?')
            ->execute([$uncertain ? 'uncertain' : 'failed', substr($error->getMessage(), 0, 500), $orderId]);
        throw $error;
    }
}

function ggs_eurosender_get_order(string $orderCode, ?callable $transport = null): array
{
    $response = ggs_eurosender_request('GET', '/v1/orders/' . rawurlencode($orderCode), null, $transport);
    return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
}

function ggs_eurosender_get_labels(string $orderCode, ?callable $transport = null): array
{
    $response = ggs_eurosender_request('GET', '/v1/orders/' . rawurlencode($orderCode) . '/labels', null, $transport);
    return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
}
