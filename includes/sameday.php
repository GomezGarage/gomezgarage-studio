<?php
/**
 * Sameday courier integration.
 *
 * Credentials stay in the ignored config.php file. Public checkout code only
 * reads locally synchronized OOH locations and asks this server-side client
 * for a quote. AWB creation is idempotent through clientInternalReference.
 */

final class GgsSamedayException extends RuntimeException
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

function ggs_sameday_enabled(): bool
{
    return defined('SAMEDAY_ENABLED') && SAMEDAY_ENABLED === true;
}

function ggs_sameday_configured(): bool
{
    foreach (['SAMEDAY_API_BASE', 'SAMEDAY_USERNAME', 'SAMEDAY_PASSWORD'] as $constant) {
        $value = defined($constant) ? trim((string) constant($constant)) : '';
        if ($value === '' || str_starts_with($value, 'REPLACE_WITH_')) return false;
    }
    return true;
}

function ggs_sameday_timeout(): int
{
    return defined('SAMEDAY_TIMEOUT_SECONDS') ? max(5, (int) SAMEDAY_TIMEOUT_SECONDS) : 25;
}

/** @return array{value:string,expires_at:?string}|null */
function ggs_sameday_cache_get(PDO $db, string $key): ?array
{
    $stmt = $db->prepare('SELECT cache_value, expires_at FROM sameday_api_cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? ['value' => (string) $row['cache_value'], 'expires_at' => $row['expires_at']] : null;
}

function ggs_sameday_cache_set(PDO $db, string $key, string $value, ?string $expiresAt): void
{
    $update = $db->prepare('UPDATE sameday_api_cache SET cache_value = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP WHERE cache_key = ?');
    $update->execute([$value, $expiresAt, $key]);
    if ($update->rowCount() === 0) {
        try {
            $db->prepare('INSERT INTO sameday_api_cache (cache_key, cache_value, expires_at) VALUES (?, ?, ?)')->execute([$key, $value, $expiresAt]);
        } catch (PDOException $error) {
            // A concurrent request may have inserted the token after our
            // UPDATE. Store the latest token without exposing either value.
            if ((string) $error->getCode() !== '23000') throw $error;
            $update->execute([$value, $expiresAt, $key]);
        }
    }
}

function ggs_sameday_cache_delete(PDO $db, string $key): void
{
    $db->prepare('DELETE FROM sameday_api_cache WHERE cache_key = ?')->execute([$key]);
}

/**
 * Low-level request with an injectable transport for offline tests.
 *
 * @param callable|null $transport Receives method, URL, headers and body.
 * @return array{status:int,body:string,content_type:string}
 */
function ggs_sameday_http(string $method, string $url, array $headers, ?string $body = null, ?callable $transport = null): array
{
    if ($transport !== null) {
        $result = $transport($method, $url, $headers, $body);
        if (!is_array($result) || !isset($result['status'], $result['body'])) {
            throw new LogicException('The Sameday test transport returned an invalid response.');
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
        CURLOPT_TIMEOUT => ggs_sameday_timeout(),
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($responseBody === false) {
        $uncertain = in_array($method, ['POST', 'DELETE'], true);
        throw new GgsSamedayException('Sameday connection failed: ' . $curlError, $uncertain);
    }
    return ['status' => $status, 'body' => (string) $responseBody, 'content_type' => $contentType];
}

/** @return array{token:string,expires_at:?string} */
function ggs_sameday_authenticate(PDO $db, ?callable $transport = null, bool $force = false): array
{
    if (!ggs_sameday_configured()) throw new RuntimeException('Sameday credentials are missing or still placeholders.');
    if (!$force) {
        $cached = ggs_sameday_cache_get($db, 'auth_token');
        if ($cached && trim($cached['value']) !== '') return ['token' => $cached['value'], 'expires_at' => $cached['expires_at']];
    }

    $url = rtrim((string) SAMEDAY_API_BASE, '/') . '/api/authenticate';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'X-AUTH-USERNAME: ' . (string) SAMEDAY_USERNAME,
        'X-AUTH-PASSWORD: ' . (string) SAMEDAY_PASSWORD,
    ];
    $response = ggs_sameday_http('POST', $url, $headers, http_build_query(['remember_me' => 1]), $transport);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400 || !is_array($data) || trim((string) ($data['token'] ?? '')) === '') {
        throw new GgsSamedayException('Sameday authentication failed (HTTP ' . $response['status'] . '): ' . ggs_sameday_error_message($data, $response['status']));
    }
    $token = trim((string) $data['token']);
    $expiresRaw = trim((string) ($data['expire_at_utc'] ?? $data['expire_at'] ?? ''));
    $expiresAt = null;
    if ($expiresRaw !== '') {
        try {
            $expiresAt = (new DateTimeImmutable($expiresRaw, new DateTimeZone('UTC')))->modify('-15 minutes')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $expiresAt = null;
        }
    }
    if ($expiresAt === null) $expiresAt = gmdate('Y-m-d H:i:s', time() + 11 * 3600);
    ggs_sameday_cache_set($db, 'auth_token', $token, $expiresAt);
    return ['token' => $token, 'expires_at' => $expiresAt];
}

/** @return array{status:int,body:string,content_type:string} */
function ggs_sameday_request(PDO $db, string $method, string $path, array $query = [], ?array $form = null, bool $binary = false, ?callable $transport = null): array
{
    $token = ggs_sameday_authenticate($db, $transport)['token'];
    $base = rtrim((string) SAMEDAY_API_BASE, '/');
    $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = [
        'Accept: ' . ($binary ? 'application/pdf, application/octet-stream' : 'application/json'),
        'X-AUTH-TOKEN: ' . $token,
    ];
    $body = null;
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query($form);
    }
    $response = ggs_sameday_http($method, $url, $headers, $body, $transport);
    if ($response['status'] === 401) {
        ggs_sameday_cache_delete($db, 'auth_token');
        $token = ggs_sameday_authenticate($db, $transport, true)['token'];
        $headers = array_values(array_filter($headers, static fn(string $header): bool => !str_starts_with(strtoupper($header), 'X-AUTH-TOKEN:')));
        $headers[] = 'X-AUTH-TOKEN: ' . $token;
        $response = ggs_sameday_http($method, $url, $headers, $body, $transport);
    }
    return $response;
}

function ggs_sameday_error_message(mixed $data, int $status): string
{
    if (is_array($data)) {
        foreach (['message', 'error', 'errorMessage', 'detail'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') return trim((string) $data[$key]);
        }
        $messages = [];
        foreach (($data['errors'] ?? []) as $error) {
            if (is_scalar($error)) $messages[] = (string) $error;
            elseif (is_array($error)) $messages[] = (string) ($error['message'] ?? $error['error'] ?? '');
        }
        $messages = array_values(array_filter(array_map('trim', $messages)));
        if ($messages) return implode('; ', $messages);
    }
    return 'HTTP ' . $status;
}

/** @return array<int,array> */
function ggs_sameday_list_data(array $data): array
{
    foreach (['data', 'services', 'pickupPoints', 'pickup_points', 'list'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) return array_values(array_filter($data[$key], 'is_array'));
    }
    return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
}

/** Read-only account check. No AWB or pickup point is created. */
function ggs_sameday_check_configuration(PDO $db, ?callable $transport = null): array
{
    $servicesResponse = ggs_sameday_request($db, 'GET', '/api/client/services', [], null, false, $transport);
    $pickupResponse = ggs_sameday_request($db, 'GET', '/api/client/pickup-points', [], null, false, $transport);
    $services = json_decode($servicesResponse['body'], true);
    $pickups = json_decode($pickupResponse['body'], true);
    if ($servicesResponse['status'] >= 400 || !is_array($services)) throw new RuntimeException('Sameday services check failed: ' . ggs_sameday_error_message($services, $servicesResponse['status']));
    if ($pickupResponse['status'] >= 400 || !is_array($pickups)) throw new RuntimeException('Sameday pickup-points check failed: ' . ggs_sameday_error_message($pickups, $pickupResponse['status']));
    $serviceList = ggs_sameday_list_data($services);
    $pickupList = ggs_sameday_list_data($pickups);
    if (!$serviceList) throw new RuntimeException('The Sameday account returned no active delivery services.');
    if (!$pickupList) throw new RuntimeException('The Sameday account returned no pickup points.');
    return ['services' => $serviceList, 'pickup_points' => $pickupList];
}

function ggs_sameday_item_id(array $item, array $keys): int
{
    foreach ($keys as $key) if (isset($item[$key]) && is_numeric($item[$key])) return (int) $item[$key];
    return 0;
}

function ggs_sameday_item_name(array $item): string
{
    foreach (['name', 'serviceName', 'alias', 'title'] as $key) {
        if (isset($item[$key]) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') return trim((string) $item[$key]);
    }
    return '';
}

/** Synchronize services and pickup points. Returns row counts. */
function ggs_sameday_sync_account(PDO $db, ?callable $transport = null): array
{
    $check = ggs_sameday_check_configuration($db, $transport);
    $db->beginTransaction();
    try {
        // These are account nomenclatures, not local business records. Replace
        // the cache atomically so a service removed from the contract cannot
        // remain selectable after the next synchronization.
        $db->exec('DELETE FROM sameday_services');
        $db->exec('DELETE FROM sameday_pickup_points');
        $serviceStmt = $db->prepare('INSERT INTO sameday_services (service_id, name, raw_json, synced_at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name = VALUES(name), raw_json = VALUES(raw_json), synced_at = VALUES(synced_at)');
        foreach ($check['services'] as $service) {
            $id = ggs_sameday_item_id($service, ['id', 'serviceId', 'service_id']);
            if ($id <= 0) continue;
            $serviceStmt->execute([$id, ggs_sameday_item_name($service) ?: ('Service ' . $id), json_encode($service, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $pickupStmt = $db->prepare('INSERT INTO sameday_pickup_points (pickup_point_id, name, address, city, county, postal_code, contact_person_id, raw_json, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address), city = VALUES(city), county = VALUES(county), postal_code = VALUES(postal_code), contact_person_id = VALUES(contact_person_id), raw_json = VALUES(raw_json), synced_at = VALUES(synced_at)');
        foreach ($check['pickup_points'] as $pickup) {
            $id = ggs_sameday_item_id($pickup, ['id', 'pickupPointId', 'pickup_point_id']);
            if ($id <= 0) continue;
            $contacts = [];
            foreach (['pickupPointContactPerson', 'contactPersons', 'contactPerson', 'contact_persons'] as $key) if (isset($pickup[$key]) && is_array($pickup[$key])) $contacts = $pickup[$key];
            if (isset($contacts['id'])) $contacts = [$contacts];
            $contactId = 0;
            foreach ($contacts as $contact) {
                if (!is_array($contact)) continue;
                $candidate = ggs_sameday_item_id($contact, ['id', 'contactPersonId']);
                if ($candidate > 0 && ($contactId === 0 || !empty($contact['default']) || !empty($contact['defaultContactPerson']))) $contactId = $candidate;
            }
            $city = is_array($pickup['city'] ?? null) ? (string) ($pickup['city']['name'] ?? '') : (string) ($pickup['city'] ?? $pickup['cityName'] ?? '');
            $county = is_array($pickup['county'] ?? null) ? (string) ($pickup['county']['name'] ?? '') : (string) ($pickup['county'] ?? $pickup['countyName'] ?? '');
            $pickupStmt->execute([
                $id,
                ggs_sameday_item_name($pickup) ?: ('Pickup ' . $id),
                (string) ($pickup['address'] ?? $pickup['pickupPointAddress'] ?? ''),
                $city,
                $county,
                (string) ($pickup['postalCode'] ?? $pickup['postal_code'] ?? ''),
                $contactId ?: null,
                json_encode($pickup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    return [
        'services' => (int) $db->query('SELECT COUNT(*) FROM sameday_services')->fetchColumn(),
        'pickup_points' => (int) $db->query('SELECT COUNT(*) FROM sameday_pickup_points')->fetchColumn(),
    ];
}

/** Synchronize active Easybox and PUDO locations to the local checkout cache. */
function ggs_sameday_sync_ooh(PDO $db, ?callable $transport = null): int
{
    $seen = [];
    $page = 1;
    $pages = 1;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO sameday_ooh_locations (ooh_id, ooh_type, name, country, county, city, address, postal_code, latitude, longitude, supported_payment, client_visible, raw_json, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ooh_type = VALUES(ooh_type), name = VALUES(name), country = VALUES(country), county = VALUES(county), city = VALUES(city), address = VALUES(address), postal_code = VALUES(postal_code), latitude = VALUES(latitude), longitude = VALUES(longitude), supported_payment = VALUES(supported_payment), client_visible = VALUES(client_visible), raw_json = VALUES(raw_json), synced_at = VALUES(synced_at)');
        do {
            $response = ggs_sameday_request($db, 'GET', '/api/client/ooh-locations', ['countPerPage' => 500, 'page' => $page], null, false, $transport);
            $data = json_decode($response['body'], true);
            if ($response['status'] >= 400 || !is_array($data)) throw new RuntimeException('Sameday OOH synchronization failed: ' . ggs_sameday_error_message($data, $response['status']));
            $pages = max(1, (int) ($data['pages'] ?? 1));
            foreach (ggs_sameday_list_data($data) as $location) {
                $id = ggs_sameday_item_id($location, ['oohId', 'id']);
                if ($id <= 0) continue;
                $seen[] = $id;
                $stmt->execute([
                    $id,
                    isset($location['oohType']) ? (int) $location['oohType'] : ($id >= 500000 ? 1 : 0),
                    ggs_sameday_item_name($location) ?: ('OOH ' . $id),
                    (string) ($location['country'] ?? 'Romania'),
                    (string) ($location['county'] ?? ''),
                    (string) ($location['city'] ?? ''),
                    (string) ($location['address'] ?? ''),
                    (string) ($location['postalCode'] ?? ''),
                    isset($location['lat']) ? (float) $location['lat'] : null,
                    isset($location['lng']) ? (float) $location['lng'] : null,
                    !empty($location['supportedPayment']) ? 1 : 0,
                    array_key_exists('clientVisible', $location) ? (!empty($location['clientVisible']) ? 1 : 0) : 1,
                    json_encode($location, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
            $page++;
            if ($page > 100) throw new RuntimeException('Sameday returned too many OOH pages; synchronization stopped safely.');
        } while ($page <= $pages);

        if ($seen) {
            foreach (array_chunk($seen, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $db->prepare("UPDATE sameday_ooh_locations SET active = 1 WHERE ooh_id IN ({$placeholders})")->execute($chunk);
            }
            $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
            $db->prepare('UPDATE sameday_ooh_locations SET active = 0 WHERE synced_at < ?')->execute([$cutoff]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    return count($seen);
}

/** @return array{pickup_point_id:int,contact_person_id:?int} */
function ggs_sameday_pickup(PDO $db): array
{
    $configuredPickup = defined('SAMEDAY_PICKUP_POINT_ID') ? (int) SAMEDAY_PICKUP_POINT_ID : 0;
    $configuredContact = defined('SAMEDAY_CONTACT_PERSON_ID') ? (int) SAMEDAY_CONTACT_PERSON_ID : 0;
    if ($configuredPickup > 0) return ['pickup_point_id' => $configuredPickup, 'contact_person_id' => $configuredContact ?: null];
    $row = $db->query('SELECT pickup_point_id, contact_person_id FROM sameday_pickup_points ORDER BY pickup_point_id LIMIT 1')->fetch();
    if (!$row) throw new RuntimeException('Synchronize a Sameday pickup point before requesting quotes or AWBs.');
    return ['pickup_point_id' => (int) $row['pickup_point_id'], 'contact_person_id' => $configuredContact ?: ((int) ($row['contact_person_id'] ?? 0) ?: null)];
}

function ggs_sameday_service_id(PDO $db, string $method): int
{
    $constant = $method === 'easybox' ? 'SAMEDAY_LOCKER_SERVICE_ID' : 'SAMEDAY_HOME_SERVICE_ID';
    $configured = defined($constant) ? (int) constant($constant) : 0;
    if ($configured > 0) return $configured;
    $needle = $method === 'easybox' ? '%Locker%Nextday%' : '%24H%';
    $stmt = $db->prepare('SELECT service_id FROM sameday_services WHERE name LIKE ? ORDER BY service_id LIMIT 1');
    $stmt->execute([$needle]);
    $id = (int) $stmt->fetchColumn();
    if ($id <= 0) throw new RuntimeException('The required Sameday delivery service is not active or has not been synchronized.');
    return $id;
}

/** @return array{length:float,width:float,height:float,weight:float,used_defaults:bool} */
function ggs_sameday_package_from_items(array $items): array
{
    $defaultLength = defined('SAMEDAY_DEFAULT_LENGTH_CM') ? (float) SAMEDAY_DEFAULT_LENGTH_CM : 30.0;
    $defaultWidth = defined('SAMEDAY_DEFAULT_WIDTH_CM') ? (float) SAMEDAY_DEFAULT_WIDTH_CM : 20.0;
    $defaultHeight = defined('SAMEDAY_DEFAULT_HEIGHT_CM') ? (float) SAMEDAY_DEFAULT_HEIGHT_CM : 10.0;
    $defaultWeight = defined('SAMEDAY_DEFAULT_WEIGHT_KG') ? (float) SAMEDAY_DEFAULT_WEIGHT_KG : 1.0;
    $length = $width = $height = $weight = 0.0;
    $usedDefaults = false;
    foreach ($items as $item) {
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $itemLength = (float) ($item['shipping_length_cm'] ?? 0);
        $itemWidth = (float) ($item['shipping_width_cm'] ?? 0);
        $itemHeight = (float) ($item['shipping_height_cm'] ?? 0);
        $itemWeight = (float) ($item['shipping_weight_kg'] ?? 0);
        if ($itemLength <= 0 || $itemWidth <= 0 || $itemHeight <= 0 || $itemWeight <= 0) $usedDefaults = true;
        $itemLength = $itemLength > 0 ? $itemLength : $defaultLength;
        $itemWidth = $itemWidth > 0 ? $itemWidth : $defaultWidth;
        $itemHeight = $itemHeight > 0 ? $itemHeight : $defaultHeight;
        $itemWeight = $itemWeight > 0 ? $itemWeight : $defaultWeight;
        $length = max($length, $itemLength);
        $width = max($width, $itemWidth);
        $height += $itemHeight * $qty;
        $weight += $itemWeight * $qty;
    }
    if ($weight <= 0) throw new InvalidArgumentException('The order has no valid package items.');
    return ['length' => round($length, 2), 'width' => round($width, 2), 'height' => round($height, 2), 'weight' => round($weight, 3), 'used_defaults' => $usedDefaults];
}

function ggs_sameday_package_type(float $weight): int
{
    if ($weight > 38) return 2;
    if ($weight <= 1) return 1;
    return 0;
}

function ggs_sameday_personal_dropoff_enabled(): bool
{
    return defined('SAMEDAY_PERSONAL_DROPOFF_ENABLED') && SAMEDAY_PERSONAL_DROPOFF_ENABLED === true;
}

/**
 * Collect Personal Drop-Off taxes from one synchronized service response.
 * Sameday assigns a different PDO tax ID per client, service and package
 * type, so an ID from another service must never be reused blindly.
 *
 * @param array<int,array{id:int,package_type:?int,name:string}> $found
 */
function ggs_sameday_collect_pdo_taxes(mixed $value, ?int $inheritedPackageType, array &$found): void
{
    if (!is_array($value)) return;
    $packageType = $inheritedPackageType;
    foreach (['packageType', 'package_type', 'packageTypeId', 'package_type_id', 'parcelType', 'parcel_type'] as $key) {
        if (isset($value[$key]) && is_numeric($value[$key])) {
            $candidate = (int) $value[$key];
            if (in_array($candidate, [0, 1, 2], true)) $packageType = $candidate;
        }
    }

    $scalars = [];
    foreach ($value as $item) if (is_scalar($item)) $scalars[] = (string) $item;
    $text = mb_strtoupper(implode(' ', $scalars));
    $isPdo = str_contains($text, 'PDO')
        || str_contains($text, 'PREDARE PERSONALA')
        || str_contains($text, 'PREDARE PERSONALĂ')
        || (str_contains($text, 'PERSONAL') && str_contains($text, 'DROP'));
    if ($isPdo && isset($value['id']) && is_numeric($value['id']) && (int) $value['id'] > 0) {
        $found[] = [
            'id' => (int) $value['id'],
            'package_type' => $packageType,
            'name' => trim((string) ($value['name'] ?? $value['code'] ?? 'PDO')),
        ];
    }
    foreach ($value as $child) if (is_array($child)) ggs_sameday_collect_pdo_taxes($child, $packageType, $found);
}

function ggs_sameday_pdo_tax_id(PDO $db, int $serviceId, int $packageType): int
{
    if (!ggs_sameday_personal_dropoff_enabled()) return 0;

    // An explicit map is available for unusual account responses. Supported
    // keys are "service:package", package type, or its string equivalent.
    if (defined('SAMEDAY_PDO_SERVICE_TAX_IDS') && is_array(SAMEDAY_PDO_SERVICE_TAX_IDS)) {
        $map = SAMEDAY_PDO_SERVICE_TAX_IDS;
        foreach ([$serviceId . ':' . $packageType, $packageType, (string) $packageType] as $key) {
            if (isset($map[$key]) && (int) $map[$key] > 0) return (int) $map[$key];
        }
    }

    // Backward-compatible single-ID override. It is safe only when all shop
    // parcels use the one package type assigned to that ID.
    if (defined('SAMEDAY_PDO_SERVICE_TAX_ID') && (int) SAMEDAY_PDO_SERVICE_TAX_ID > 0) {
        return (int) SAMEDAY_PDO_SERVICE_TAX_ID;
    }

    $stmt = $db->prepare('SELECT raw_json FROM sameday_services WHERE service_id = ? LIMIT 1');
    $stmt->execute([$serviceId]);
    $raw = json_decode((string) $stmt->fetchColumn(), true);
    if (!is_array($raw)) {
        throw new RuntimeException('PDO is enabled, but the synchronized Sameday service details are missing. Synchronize services again.');
    }
    $candidates = [];
    ggs_sameday_collect_pdo_taxes($raw, null, $candidates);
    $candidates = array_values(array_filter($candidates, static fn(array $item): bool => $item['package_type'] === $packageType));
    $ids = array_values(array_unique(array_map(static fn(array $item): int => $item['id'], $candidates)));
    if (count($ids) === 1) return $ids[0];

    throw new RuntimeException('PDO is enabled, but Sameday did not expose one unambiguous Personal Drop-Off tax for service #' . $serviceId . ' and package type ' . $packageType . '. Synchronize the account or configure SAMEDAY_PDO_SERVICE_TAX_IDS.');
}

/** Build the common estimate/AWB payload from an order-like array. */
function ggs_sameday_build_payload(PDO $db, array $order, string $method, ?int $oohId = null): array
{
    if (!in_array($method, ['home', 'easybox'], true)) throw new InvalidArgumentException('Unsupported Sameday delivery method.');
    $address = is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : json_decode((string) ($order['shipping_address'] ?? ''), true);
    $items = is_array($order['items'] ?? null) ? $order['items'] : json_decode((string) ($order['items'] ?? ''), true);
    if (!is_array($address) || !is_array($items) || !$items) throw new InvalidArgumentException('The order address or package data is invalid.');
    if (strtoupper((string) ($address['country'] ?? '')) !== 'RO') throw new InvalidArgumentException('This Sameday integration currently supports Romanian deliveries only.');
    $phone = preg_replace('/[^0-9+]/', '', (string) ($order['customer_phone'] ?? ''));
    if ($phone === '') throw new InvalidArgumentException('A customer phone number is required for Sameday delivery.');
    $pickup = ggs_sameday_pickup($db);
    $package = ggs_sameday_package_from_items($items);
    $service = ggs_sameday_service_id($db, $method);
    $packageType = ggs_sameday_package_type($package['weight']);
    $recipient = [
        'name' => trim((string) ($order['customer_name'] ?? '')),
        'phoneNumber' => $phone,
        'personType' => (($order['customer_type'] ?? 'pf') === 'pj') ? 1 : 0,
        'email' => trim((string) ($order['customer_email'] ?? '')),
    ];
    if ($method === 'home') {
        $recipient += [
            'countyString' => trim((string) ($address['county'] ?? '')),
            'cityString' => trim((string) ($address['city'] ?? '')),
            'address' => trim((string) ($address['street'] ?? '')),
            'postalCode' => trim((string) ($address['postal'] ?? '')),
        ];
    }
    $payload = [
        'pickupPoint' => $pickup['pickup_point_id'],
        'packageType' => $packageType,
        'packageNumber' => 1,
        'packageWeight' => $package['weight'],
        'service' => $service,
        'awbPayment' => 1,
        'cashOnDelivery' => 0,
        'cashOnDeliveryReturns' => 0,
        'insuredValue' => 0,
        'thirdPartyPickup' => 0,
        'awbRecipient' => $recipient,
        'parcels' => [[
            'weight' => $package['weight'],
            'width' => $package['width'],
            'length' => $package['length'],
            'height' => $package['height'],
        ]],
        'clientInternalReference' => trim((string) ($order['order_number'] ?? '')),
        'observation' => 'Gomez Garage Studio ' . trim((string) ($order['order_number'] ?? '')),
    ];
    if ($pickup['contact_person_id']) $payload['contactPerson'] = $pickup['contact_person_id'];
    if ($method === 'easybox') {
        if (!$oohId || $oohId <= 0) throw new InvalidArgumentException('Select an Easybox for this delivery.');
        $stmt = $db->prepare('SELECT ooh_id, ooh_type, active, client_visible FROM sameday_ooh_locations WHERE ooh_id = ? LIMIT 1');
        $stmt->execute([$oohId]);
        $location = $stmt->fetch();
        if (!$location || (int) $location['ooh_type'] !== 0 || !(int) $location['active'] || !(int) $location['client_visible']) throw new InvalidArgumentException('The selected Easybox is unavailable.');
        $payload['oohLastMile'] = $oohId;
    }
    $pdoTaxId = ggs_sameday_pdo_tax_id($db, $service, $packageType);
    if ($pdoTaxId > 0) $payload['serviceTaxes'] = 'PDO ' . $pdoTaxId;
    return ['payload' => $payload, 'package' => $package, 'service_id' => $service];
}

/** @return array{amount:float,currency:string,time:mixed,service_id:int,package:array} */
function ggs_sameday_estimate(PDO $db, array $order, string $method, ?int $oohId = null, ?callable $transport = null): array
{
    $built = ggs_sameday_build_payload($db, $order, $method, $oohId);
    $response = ggs_sameday_request($db, 'POST', '/api/awb/estimate-cost', [], $built['payload'], false, $transport);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400 || !is_array($data) || !isset($data['amount'])) throw new GgsSamedayException('Sameday quote failed: ' . ggs_sameday_error_message($data, $response['status']));
    return ['amount' => round((float) $data['amount'], 2), 'currency' => strtoupper((string) ($data['currency'] ?? 'RON')), 'time' => $data['time'] ?? null, 'service_id' => $built['service_id'], 'package' => $built['package']];
}

/** @return array{awb_number:string,cost:float,currency:string,parcels:array} */
function ggs_sameday_create_awb(PDO $db, array $order, ?callable $transport = null): array
{
    $method = (string) ($order['shipping_method'] ?? 'home');
    $oohId = isset($order['shipping_ooh_id']) ? (int) $order['shipping_ooh_id'] : null;
    $built = ggs_sameday_build_payload($db, $order, $method, $oohId);
    $response = ggs_sameday_request($db, 'POST', '/api/awb', [], $built['payload'], false, $transport);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 500) throw new GgsSamedayException('Sameday server error while creating the AWB.', true);
    if ($response['status'] >= 400 || !is_array($data)) throw new GgsSamedayException('Sameday rejected the AWB: ' . ggs_sameday_error_message($data, $response['status']));
    $awb = trim((string) ($data['awbNumber'] ?? ''));
    if ($awb === '' || !preg_match('/^[A-Za-z0-9-]{5,100}$/', $awb)) throw new GgsSamedayException('Sameday accepted the request without returning a safe AWB number.', true);
    return ['awb_number' => $awb, 'cost' => round((float) ($data['awbCost'] ?? 0), 2), 'currency' => strtoupper((string) ($data['currency'] ?? 'RON')), 'parcels' => is_array($data['parcels'] ?? null) ? $data['parcels'] : []];
}

/** Recover an AWB by the unique storefront order number. */
function ggs_sameday_find_awb(PDO $db, string $reference, ?callable $transport = null): ?array
{
    $response = ggs_sameday_request($db, 'GET', '/api/client/awb/' . rawurlencode($reference), [], null, false, $transport);
    if ($response['status'] === 404) return null;
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400 || !is_array($data)) throw new GgsSamedayException('Sameday AWB recovery failed: ' . ggs_sameday_error_message($data, $response['status']));
    if (isset($data['data']) && is_array($data['data']) && !isset($data['awbNumber'])) $data = $data['data'];
    $awb = trim((string) ($data['awbNumber'] ?? ''));
    return $awb === '' ? null : ['awb_number' => $awb, 'cost' => round((float) ($data['awbCost'] ?? 0), 2), 'currency' => strtoupper((string) ($data['currency'] ?? 'RON')), 'parcels' => is_array($data['parcels'] ?? null) ? $data['parcels'] : []];
}

/** Create exactly one AWB for an already-paid order, with retry/recovery. */
function ggs_sameday_issue_order_awb(PDO $db, int $orderId, ?callable $transport = null): array
{
    if (!ggs_sameday_enabled()) throw new RuntimeException('Automatic Sameday AWB generation is disabled.');
    if (!ggs_sameday_configured()) throw new RuntimeException('Sameday credentials are not configured.');
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) throw new RuntimeException('Order not found.');
        if (($order['shipping_provider'] ?? '') !== 'sameday') throw new RuntimeException('This order does not use Sameday.');
        if (!in_array((string) $order['status'], ['paid', 'processing', 'printing', 'shipped'], true)) throw new RuntimeException('The order must be paid before generating an AWB.');
        if (trim((string) ($order['sameday_awb_number'] ?? '')) !== '') {
            $db->commit();
            return ['awb_number' => (string) $order['sameday_awb_number'], 'existing' => true];
        }
        if (($order['sameday_awb_status'] ?? '') === 'issuing') {
            $attemptedAt = trim((string) ($order['sameday_attempted_at'] ?? ''));
            $attemptedTimestamp = $attemptedAt !== '' ? strtotime($attemptedAt . ' UTC') : false;
            if ($attemptedTimestamp !== false && $attemptedTimestamp > time() - 300) {
                throw new RuntimeException('Sameday AWB generation is already in progress.');
            }
            // A PHP worker may have stopped after Sameday accepted the AWB but
            // before the response was persisted. After five minutes, recover
            // by clientInternalReference instead of creating blindly.
        }
        $db->prepare('UPDATE orders SET sameday_awb_status = "issuing", sameday_attempted_at = UTC_TIMESTAMP(), sameday_error = NULL WHERE id = ?')->execute([$orderId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }

    try {
        $existing = ggs_sameday_find_awb($db, (string) $order['order_number'], $transport);
        $issued = $existing ?: ggs_sameday_create_awb($db, $order, $transport);
        $db->prepare('UPDATE orders SET sameday_awb_number = ?, tracking_number = ?, sameday_awb_status = "created", sameday_awb_cost = ?, sameday_awb_currency = ?, sameday_parcels = ?, sameday_created_at = COALESCE(sameday_created_at, UTC_TIMESTAMP()), sameday_error = NULL WHERE id = ? AND sameday_awb_number IS NULL')
            ->execute([$issued['awb_number'], $issued['awb_number'], $issued['cost'] ?? 0, $issued['currency'] ?? 'RON', json_encode($issued['parcels'] ?? [], JSON_UNESCAPED_UNICODE), $orderId]);
        return $issued + ['existing' => $existing !== null];
    } catch (GgsSamedayException $error) {
        $status = $error->isUncertain() ? 'uncertain' : 'failed';
        $db->prepare('UPDATE orders SET sameday_awb_status = ?, sameday_error = ? WHERE id = ?')->execute([$status, substr($error->getMessage(), 0, 500), $orderId]);
        throw $error;
    } catch (Throwable $error) {
        $db->prepare('UPDATE orders SET sameday_awb_status = "failed", sameday_error = ? WHERE id = ?')->execute([substr($error->getMessage(), 0, 500), $orderId]);
        throw $error;
    }
}

function ggs_sameday_download_awb_pdf(PDO $db, string $awb, string $format = 'A6', ?callable $transport = null): string
{
    if (!preg_match('/^[A-Za-z0-9-]{5,100}$/', $awb)) throw new InvalidArgumentException('Invalid AWB number.');
    $format = strtoupper($format) === 'A4' ? 'A4' : 'A6';
    $response = ggs_sameday_request($db, 'GET', '/api/awb/download/' . rawurlencode($awb) . '/' . $format, [], null, true, $transport);
    if ($response['status'] >= 400 || !str_starts_with($response['body'], '%PDF-')) throw new GgsSamedayException('Sameday did not return a valid AWB PDF.');
    return $response['body'];
}

function ggs_sameday_cancel_awb(PDO $db, string $awb, ?callable $transport = null): void
{
    if (!preg_match('/^[A-Za-z0-9-]{5,100}$/', $awb)) throw new InvalidArgumentException('Invalid AWB number.');
    $response = ggs_sameday_request($db, 'DELETE', '/api/awb/' . rawurlencode($awb), [], null, false, $transport);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400) throw new GgsSamedayException('Sameday AWB cancellation failed: ' . ggs_sameday_error_message($data, $response['status']), $response['status'] >= 500);
}

function ggs_sameday_awb_history(PDO $db, string $awb, ?callable $transport = null): array
{
    if (!preg_match('/^[A-Za-z0-9-]{5,100}$/', $awb)) throw new InvalidArgumentException('Invalid AWB number.');
    $response = ggs_sameday_request($db, 'GET', '/api/client/awb/' . rawurlencode($awb) . '/status', [], null, false, $transport);
    $data = json_decode($response['body'], true);
    if ($response['status'] >= 400 || !is_array($data)) throw new GgsSamedayException('Sameday AWB status check failed: ' . ggs_sameday_error_message($data, $response['status']));
    return $data;
}

function ggs_sameday_tracking_url(string $awb): string
{
    return preg_match('/^[A-Za-z0-9-]{5,100}$/', $awb) ? 'https://sameday.ro/#awb=' . rawurlencode($awb) : '';
}
