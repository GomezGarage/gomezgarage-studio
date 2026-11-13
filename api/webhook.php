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
require_once __DIR__ . '/../includes/invoice.php';
require_once __DIR__ . '/../includes/smartbill.php';

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
  // Atomically claim the pending order. Two simultaneous Revolut deliveries
  // must never decrement stock or emit two invoices.
  $claim = $db->prepare('UPDATE orders SET status = "paid" WHERE id = ? AND status = "pending"');
  $claim->execute([(int) $order['id']]);
  if ($claim->rowCount() !== 1) {
    ggs_json_response(['success' => true, 'note' => 'Already processed']);
  }
  $db->prepare('INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)')
    ->execute([(int) $order['id'], 'paid', 'Payment confirmed']);
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

  // Invoicing is best-effort: payment processing and stock remain authoritative
  // even when the external fiscal service needs manual reconciliation.
  $invoicePath = null;
  if (ggs_smartbill_enabled()) {
    try {
      $db->prepare('UPDATE orders SET invoice_provider = "smartbill", invoice_status = "issuing", invoice_error = NULL WHERE id = ?')
        ->execute([(int) $order['id']]);
      $invoiceRate = (float) ggs_get_setting('eur_ron_rate', '5.00');
      // If Revolut charged RON, use the exact effective checkout rate so the
      // fiscal document and the captured card amount cannot drift apart if an
      // administrator changes the configured rate between order and webhook.
      if (($order['payment_currency'] ?? '') === 'RON' && (float) ($order['payment_amount'] ?? 0) > 0 && (float) ($order['total_eur'] ?? 0) > 0) {
        $invoiceRate = (float) $order['payment_amount'] / (float) $order['total_eur'];
      }
      $built = ggs_smartbill_build_invoice($order, $invoiceRate);
      $issued = ggs_smartbill_issue($built['payload']);
      $invoiceNumber = $issued['series'] . '-' . $issued['number'];
      $db->prepare('UPDATE orders SET invoice_number = ?, invoice_status = "issued", invoice_currency = ?, invoice_exchange_rate = ?, invoice_document_id = ?, invoice_view_url = ?, invoice_issued_at = NOW(), invoice_error = NULL WHERE id = ?')
        ->execute([$invoiceNumber, $built['currency'], $built['exchange_rate'], $issued['document_id'], $issued['view_url'], (int) $order['id']]);
      $order['invoice_number'] = $invoiceNumber;
      $order['invoice_view_url'] = $issued['view_url'];
      $invoicePath = __DIR__ . '/../invoices/' . $invoiceNumber . '.pdf';
      try {
        ggs_smartbill_download_pdf($issued['series'], $issued['number'], $invoicePath);
      } catch (Throwable $pdfError) {
        $invoicePath = null;
        error_log('GGS SmartBill PDF download failed for ' . $order['order_number'] . ': ' . $pdfError->getMessage());
      }
    } catch (GgsSmartBillException $e) {
      $invoiceStatus = $e->isUncertain() ? 'uncertain' : 'failed';
      $db->prepare('UPDATE orders SET invoice_status = ?, invoice_error = ? WHERE id = ?')
        ->execute([$invoiceStatus, substr($e->getMessage(), 0, 500), (int) $order['id']]);
      error_log('GGS SmartBill invoice failed for ' . $order['order_number'] . ': ' . $e->getMessage());
    } catch (Throwable $e) {
      $db->prepare('UPDATE orders SET invoice_status = "failed", invoice_error = ? WHERE id = ?')
        ->execute([substr($e->getMessage(), 0, 500), (int) $order['id']]);
      error_log('GGS SmartBill invoice failed for ' . $order['order_number'] . ': ' . $e->getMessage());
    }
  } else {
    try {
      $invoiceNumber = ggs_generate_invoice_number($db);
      $db->prepare('UPDATE orders SET invoice_number = ?, invoice_provider = "local", invoice_status = "issued", invoice_currency = "RON", invoice_issued_at = NOW() WHERE id = ?')
        ->execute([$invoiceNumber, (int) $order['id']]);
      $order['invoice_number'] = $invoiceNumber;
      $invoicePath = ggs_generate_invoice_pdf($order);
    } catch (Throwable $e) {
      error_log('GGS invoice generation failed for ' . $order['order_number'] . ': ' . $e->getMessage());
    }
  }

  ggs_notify_new_order($order);
  ggs_send_order_confirmation($order, $invoicePath);
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
