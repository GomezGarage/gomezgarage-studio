<?php

/** Verify the raw request body with Eurosender's HMAC-SHA256 signing key. */
function ggs_eurosender_verify_webhook_signature(string $rawBody, string $signature, string $secret): bool
{
    $signature = trim($signature);
    if ($signature === '' || $secret === '') return false;

    // Eurosender's published docs identify HMAC-SHA256 but omit its output
    // encoding. Accept the common hex and Base64 encodings, with an optional
    // sha256= prefix, while always signing the exact raw body bytes.
    $candidate = preg_replace('/^sha256[=:]/i', '', $signature) ?? '';
    $hex = hash_hmac('sha256', $rawBody, $secret);
    $base64 = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    return hash_equals($hex, strtolower($candidate)) || hash_equals($base64, $candidate);
}

/** @return list<array{order_code:string,provider_status:string,tracking_number:string,tracking_url:string}> */
function ggs_eurosender_webhook_updates(string $eventCode, array $payload): array
{
    if ($eventCode !== 'delivery_status_updated') {
        $orderCode = trim((string) ($payload['orderCode'] ?? ''));
        $trackingCodes = is_array($payload['trackingCodes'] ?? null) ? $payload['trackingCodes'] : [];
        $tracking = $trackingCodes[0] ?? [];
        if (!is_array($tracking)) $tracking = [];

        $status = match ($eventCode) {
            'order_label_ready' => 'label_ready',
            'order_submitted_to_courier' => 'submitted_to_courier',
            'order_tracking_ready' => 'tracking_ready',
            'order_cancelled' => 'cancelled',
            default => $eventCode,
        };
        return [[
            'order_code' => $orderCode,
            'provider_status' => $status,
            'tracking_number' => trim((string) ($tracking['trackingNumber'] ?? '')),
            'tracking_url' => trim((string) ($tracking['trackingUrl'] ?? '')),
        ]];
    }

    $updates = [];
    foreach ((array) ($payload['notifications'] ?? []) as $notification) {
        if (!is_array($notification)) continue;
        $fallbackCode = trim((string) ($notification['orderCode'] ?? ''));
        $details = $notification['trackingDetails'] ?? [];
        foreach ((array) ($details['parcels'] ?? []) as $parcel) {
            if (!is_array($parcel)) continue;
            $updates[] = [
                'order_code' => trim((string) ($parcel['orderCode'] ?? $fallbackCode)),
                'provider_status' => trim((string) ($parcel['currentStatus'] ?? '')),
                'tracking_number' => trim((string) ($parcel['trackingNumber'] ?? '')),
                'tracking_url' => trim((string) ($parcel['trackingUrl'] ?? '')),
            ];
        }
    }
    return $updates;
}

/** Keep shop order status monotonic as delayed courier events arrive. */
function ggs_eurosender_webhook_order_status(string $current, string $providerStatus): string
{
    if ($current === 'delivered') return $current;
    $normalized = strtolower(preg_replace('/[^a-z]/i', '', $providerStatus) ?? '');
    if ($normalized === 'delivered') return 'delivered';
    if (in_array($normalized, ['intransit', 'pickedup', 'outfordelivery', 'collected'], true)) {
        return $current === 'delivered' ? $current : 'shipped';
    }
    return $current;
}
