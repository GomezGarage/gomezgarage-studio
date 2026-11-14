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
