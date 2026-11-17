<?php
/**
 * POST /api/eurosender-webhook.php
 * Receives signed shipment lifecycle updates from Eurosender.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/eurosender.php';
require_once __DIR__ . '/../includes/eurosender-webhook.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (!defined('EUROSENDER_WEBHOOK_SIGNING_KEY')
    || trim((string) EUROSENDER_WEBHOOK_SIGNING_KEY) === ''
    || str_starts_with((string) EUROSENDER_WEBHOOK_SIGNING_KEY, 'REPLACE_WITH_')) {
    ggs_json_response(['success' => false, 'error' => 'Webhook is not configured'], 503);
}

$rawBody = file_get_contents('php://input', false, null, 0, 1048577);
if (!is_string($rawBody) || strlen($rawBody) > 1048576) {
    ggs_json_response(['success' => false, 'error' => 'Payload too large'], 413);
}

$webhookId = trim((string) ($_SERVER['HTTP_WEBHOOK_ID'] ?? ''));
$eventCode = trim((string) ($_SERVER['HTTP_WEBHOOK_EVENT'] ?? ''));
$signature = trim((string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? ''));
if ($webhookId === '' || strlen($webhookId) > 191 || $eventCode === '' || strlen($eventCode) > 80
    || !ggs_eurosender_verify_webhook_signature($rawBody, $signature, (string) EUROSENDER_WEBHOOK_SIGNING_KEY)) {
    ggs_json_response(['success' => false, 'error' => 'Invalid webhook signature or headers'], 401);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    ggs_json_response(['success' => false, 'error' => 'Malformed JSON payload'], 422);
}

$allowedEvents = [
    'order_label_ready',
    'order_submitted_to_courier',
    'order_tracking_ready',
    'order_cancelled',
    'delivery_status_updated',
];
if (!in_array($eventCode, $allowedEvents, true)) {
    // Acknowledge authenticated future event types so Eurosender does not retry
    // an event this version of the integration cannot process.
    ggs_json_response(['success' => true, 'note' => 'Event ignored']);
}

$orderCode = trim((string) ($payload['orderCode'] ?? ''));
if ($orderCode === '' && $eventCode === 'delivery_status_updated') {
    $orderCode = trim((string) ($payload['notifications'][0]['orderCode'] ?? ''));
}

$db = ggs_db();
$customerEmails = [];
try {
    $db->beginTransaction();
    $insert = $db->prepare('INSERT INTO eurosender_webhook_events (webhook_id, event_code, order_code) VALUES (?, ?, ?)');
    try {
        $insert->execute([$webhookId, $eventCode, $orderCode]);
    } catch (PDOException $error) {
        // Duplicate webhook ID means Eurosender retried a delivery already handled.
        if ((string) $error->getCode() === '23000') {
            $db->rollBack();
            ggs_json_response(['success' => true, 'note' => 'Duplicate event']);
        }
        throw $error;
    }

    $updates = ggs_eurosender_webhook_updates($eventCode, $payload);
    foreach ($updates as $update) {
        $code = trim((string) ($update['order_code'] ?? ''));
        if ($code === '') continue;

        $find = $db->prepare('SELECT * FROM orders WHERE eurosender_order_code = ? AND shipping_provider = "eurosender" LIMIT 1 FOR UPDATE');
        $find->execute([$code]);
        $order = $find->fetch();
        if (!$order) continue;

        $providerStatus = trim((string) ($update['provider_status'] ?? ''));
        $trackingNumber = trim((string) ($update['tracking_number'] ?? ''));
        $trackingUrl = trim((string) ($update['tracking_url'] ?? ''));
        $newOrderStatus = ggs_eurosender_webhook_order_status((string) $order['status'], $providerStatus);

        $db->prepare('UPDATE orders SET eurosender_status = NULLIF(?, ""), eurosender_tracking_url = COALESCE(NULLIF(?, ""), eurosender_tracking_url), tracking_number = COALESCE(NULLIF(?, ""), tracking_number), eurosender_last_status_at = UTC_TIMESTAMP(), eurosender_error = NULL, status = ? WHERE id = ?')
            ->execute([$providerStatus, $trackingUrl, $trackingNumber, $newOrderStatus, (int) $order['id']]);

        $note = 'Eurosender: ' . ($providerStatus !== '' ? $providerStatus : $eventCode);
        if ($trackingNumber !== '') $note .= ' · Tracking ' . $trackingNumber;
        $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
            ->execute([(int) $order['id'], $newOrderStatus, substr($note, 0, 500)]);

        if ($newOrderStatus !== (string) $order['status'] && in_array($newOrderStatus, ['shipped', 'delivered'], true)) {
            $order['status'] = $newOrderStatus;
            if ($trackingNumber !== '') $order['tracking_number'] = $trackingNumber;
            if ($trackingUrl !== '') $order['eurosender_tracking_url'] = $trackingUrl;
            $customerEmails[] = $order;
        }
    }

    $db->prepare('UPDATE eurosender_webhook_events SET processed_at = UTC_TIMESTAMP() WHERE webhook_id = ?')->execute([$webhookId]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Eurosender webhook processing failed: ' . $error->getMessage());
    ggs_json_response(['success' => false, 'error' => 'Webhook processing failed'], 500);
}

foreach ($customerEmails as $order) {
    ggs_send_customer_order_status_update($order, 'Eurosender shipment update: ' . ($order['eurosender_status'] ?? ''), true);
}

ggs_json_response(['success' => true]);
