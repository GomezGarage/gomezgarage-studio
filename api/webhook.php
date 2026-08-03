<?php
/**
 * POST /api/webhook.php
 * Revolut Merchant API webhook receiver (ARCHITECTURE.md section 6.3 step 4).
 *
 * https://developer.revolut.com/docs/merchant/webhooks
 * Verifies the `Revolut-Signature` header, then on a completed payment:
 *   - marks the order 'paid'
 *   - decrements stock for each line item
 *   - sends the customer confirmation email + the owner notification email
 *   - sends a low-stock alert if any item dropped below its threshold
 *
 * No session/auth here — Revolut calls this endpoint directly, so trust is
 * established purely through signature verification.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '';
$timestampHeader = $_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? '';

if (!ggs_verify_revolut_signature($rawBody, $signatureHeader, $timestampHeader)) {
  ggs_json_response(['success' => false, 'error' => 'Invalid signature'], 401);
}

$event = json_decode($rawBody, true);
if (!is_array($event) || empty($event['event']) || empty($event['order_id'])) {
  ggs_json_response(['success' => false, 'error' => 'Malformed payload'], 422);
}

$db = ggs_db();
$stmt = $db->prepare('SELECT * FROM orders WHERE revolut_order_id = ? LIMIT 1');
$stmt->execute([$event['order_id']]);
$order = $stmt->fetch();

if (!$order) {
  ggs_json_response(['success' => false, 'error' => 'Order not found'], 404);
}

$paidEvents = ['ORDER_COMPLETED', 'ORDER_AUTHORISED'];
$failedEvents = ['ORDER_PAYMENT_FAILED', 'ORDER_CANCELLED'];

// Idempotency: only act the first time we see the order move to paid/cancelled.
if ($order['status'] !== 'pending') {
  ggs_json_response(['success' => true, 'note' => 'Already processed']);
}

if (in_array($event['event'], $paidEvents, true)) {
  $db->prepare('UPDATE orders SET status = "paid" WHERE id = ?')->execute([$order['id']]);
  $order['status'] = 'paid';

  foreach (json_decode($order['items'], true) as $item) {
    $db->prepare('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?')
      ->execute([$item['qty'], $item['product_id']]);

    $productStmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $productStmt->execute([$item['product_id']]);
    $product = $productStmt->fetch();

    if ($product && $product['stock'] <= $product['stock_threshold']) {
      ggs_notify_low_stock($product);
    }
  }

  ggs_notify_new_order($order);
  ggs_send_order_confirmation($order);
} elseif (in_array($event['event'], $failedEvents, true)) {
  $db->prepare('UPDATE orders SET status = "cancelled" WHERE id = ?')->execute([$order['id']]);
}

ggs_json_response(['success' => true]);

/**
 * Verifies the HMAC-SHA256 signature Revolut sends with every webhook call.
 * Signed string is "v1." + timestamp + "." + raw body.
 */
function ggs_verify_revolut_signature(string $rawBody, string $signatureHeader, string $timestamp): bool
{
  if ($signatureHeader === '' || $timestamp === '') {
    return false;
  }

  $expected = 'v1=' . hash_hmac('sha256', "v1.{$timestamp}.{$rawBody}", REVOLUT_WEBHOOK_SIGNING_SECRET);

  foreach (explode(',', $signatureHeader) as $candidate) {
    if (hash_equals($expected, trim($candidate))) {
      return true;
    }
  }

  return false;
}
