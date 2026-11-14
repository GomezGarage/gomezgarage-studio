<?php
/**
 * PHPMailer wrapper for the three transactional emails in
 * ARCHITECTURE.md section 6.5.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function ggs_mailer(): PHPMailer
{
  require_once __DIR__ . '/../config.php';

  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = SMTP_HOST;
  $mail->Port = SMTP_PORT;
  $mail->SMTPAuth = true;
  $mail->Username = SMTP_USER;
  $mail->Password = SMTP_PASS;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
  $mail->isHTML(true);
  $mail->CharSet = 'UTF-8';

  return $mail;
}

function ggs_send_mail(string $to, string $subject, string $htmlBody, ?string $attachmentPath = null, array $additionalAttachments = []): bool
{
  try {
    $mail = ggs_mailer();
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = strip_tags($htmlBody);
    if ($attachmentPath !== null && is_file($attachmentPath)) {
      $mail->addAttachment($attachmentPath);
    }
    foreach ($additionalAttachments as $attachment) {
      $path = $attachment['path'] ?? '';
      $name = $attachment['name'] ?? '';
      if (is_string($path) && is_file($path) && is_string($name) && $name !== '') {
        $mail->addAttachment($path, $name);
      }
    }
    return $mail->send();
  } catch (PHPMailerException $e) {
    error_log('GGS mailer error: ' . $e->getMessage());
    return false;
  }
}

/** Trigger 1 — new order notification, sent to the shop owner. */
function ggs_notify_new_order(array $order): bool
{
  $to = ggs_get_setting('order_notify_email', SMTP_FROM_EMAIL);
  $subject = sprintf('[GGS] New order #%s — %s EUR', $order['order_number'], number_format((float)$order['total_eur'], 2));

  $itemsHtml = '';
  foreach (json_decode($order['items'], true) as $item) {
    $itemsHtml .= sprintf('<tr><td>%s</td><td>%d</td><td>%.2f EUR</td></tr>', htmlspecialchars($item['name']), $item['qty'], $item['price_eur']);
  }

  $address = json_decode($order['shipping_address'], true);

  $body = "
    <h2>New order #{$order['order_number']}</h2>
    <p><strong>Customer:</strong> {$order['customer_name']} ({$order['customer_email']})</p>
    <p><strong>Address:</strong> {$address['street']}, {$address['city']}, {$address['postal']}, {$address['country']}</p>
    <table border='1' cellpadding='6' cellspacing='0'><tr><th>Item</th><th>Qty</th><th>Price</th></tr>{$itemsHtml}</table>
    <p><strong>Total:</strong> {$order['total_eur']} EUR (incl. {$order['shipping_eur']} EUR shipping)</p>
    <p><a href='" . SITE_URL . "/admin/order-detail.php?id={$order['id']}'>View in admin</a></p>
  ";

  return ggs_send_mail($to, $subject, $body);
}

/** Trigger 2 — order confirmation, sent to the customer. $invoicePath attaches the PDF invoice, if generated. */
function ggs_send_order_confirmation(array $order, ?string $invoicePath = null): bool
{
  $subject = sprintf('Your Gomez Garage Studio order #%s', $order['order_number']);
  $address = json_decode($order['shipping_address'], true);

  $itemsHtml = '';
  foreach (json_decode($order['items'], true) as $item) {
    $itemsHtml .= sprintf('<li>%s × %d — %.2f EUR</li>', htmlspecialchars($item['name']), $item['qty'], $item['price_eur']);
  }

  $invoiceLine = $invoicePath ? '<p>Your invoice is attached to this email.</p>' : '';
  if (!$invoicePath && !empty($order['invoice_view_url'])) {
    $invoiceUrl = htmlspecialchars((string) $order['invoice_view_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $invoiceLine = '<p><a href="' . $invoiceUrl . '">View your fiscal invoice</a>.</p>';
  }
  $trackingLine = '';
  if (($order['shipping_provider'] ?? '') === 'sameday' && !empty($order['tracking_number'])) {
    $trackingNumber = htmlspecialchars((string) $order['tracking_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $trackingUrl = htmlspecialchars('https://sameday.ro/#awb=' . rawurlencode((string) $order['tracking_number']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $trackingLine = '<p><strong>Sameday AWB:</strong> ' . $trackingNumber . ' · <a href="' . $trackingUrl . '">Track the shipment</a>.</p>';
  } elseif (($order['shipping_provider'] ?? '') === 'eurosender' && (!empty($order['tracking_number']) || !empty($order['eurosender_order_code']))) {
    $trackingNumber = htmlspecialchars((string) ($order['tracking_number'] ?: $order['eurosender_order_code']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $trackingUrl = trim((string) ($order['eurosender_tracking_url'] ?? ''));
    $trackingLine = '<p><strong>Eurosender shipment:</strong> ' . $trackingNumber . ($trackingUrl !== '' ? ' · <a href="' . htmlspecialchars($trackingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Track the shipment</a>' : '') . '.</p>';
  }
  $accountLine = !empty($order['customer_account_id'])
    ? '<p><a href="' . SITE_URL . '/account/">View your order history in your account</a>.</p>'
    : '<p>Create an account with this email to keep this and future orders together: <a href="' . SITE_URL . '/account/register.php">Create an account</a>.</p>';

  $body = "
    <h2>Thank you for your order, {$order['customer_name']}!</h2>
    <p>Your order <strong>#{$order['order_number']}</strong> is confirmed.</p>
    <ul>{$itemsHtml}</ul>
    <p><strong>Total:</strong> {$order['total_eur']} EUR</p>
    <p><strong>Shipping to:</strong> {$address['street']}, {$address['city']}, {$address['postal']}, {$address['country']}</p>
    {$trackingLine}
    {$invoiceLine}
    {$accountLine}
    <p>Custom and made-to-order items typically ship in 7-14 days. We'll email you again once your order ships.</p>
    <p>— Gomez Garage Studio</p>
  ";

  return ggs_send_mail($order['customer_email'], $subject, $body, $invoicePath);
}

/** Trigger 3 — low stock alert, sent to the shop owner. */
function ggs_notify_low_stock(array $product): bool
{
  $to = ggs_get_setting('low_stock_notify_email', SMTP_FROM_EMAIL);
  $subject = sprintf('[GGS] Low stock — %s (%d left)', $product['name_en'], $product['stock']);
  $body = "
    <h2>Low stock alert</h2>
    <p><strong>{$product['name_en']}</strong> is down to {$product['stock']} units (threshold: {$product['stock_threshold']}).</p>
    <p><a href='" . SITE_URL . "/admin/product-edit.php?id={$product['id']}'>Update stock in admin</a></p>
  ";
  return ggs_send_mail($to, $subject, $body);
}

/** Order status / tracking update, sent to the customer from admin/order-detail.php. */
function ggs_send_order_update(array $order, string $note = ''): bool
{
  $subject = sprintf('Update on your Gomez Garage Studio order #%s', $order['order_number']);
  $trackingUrl = '';
  if (($order['shipping_provider'] ?? '') === 'sameday' && !empty($order['tracking_number'])) {
    $trackingUrl = 'https://sameday.ro/#awb=' . rawurlencode((string) $order['tracking_number']);
  } elseif (($order['shipping_provider'] ?? '') === 'eurosender') {
    $trackingUrl = trim((string) ($order['eurosender_tracking_url'] ?? ''));
  }
  $trackingLine = $order['tracking_number']
    ? '<p><strong>Tracking number:</strong> ' . htmlspecialchars((string) $order['tracking_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ($trackingUrl !== '' ? ' · <a href="' . htmlspecialchars($trackingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Track shipment</a>' : '') . '</p>'
    : '';
  $status = htmlspecialchars(ucfirst((string) $order['status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $noteLine = trim($note) !== '' ? '<p>' . nl2br(htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>' : '';
  $body = "
    <h2>Order #{$order['order_number']} update</h2>
    <p>Your order status is now: <strong>{$status}</strong></p>
    {$trackingLine}
    {$noteLine}
    <p>— Gomez Garage Studio</p>
  ";
  return ggs_send_mail($order['customer_email'], $subject, $body);
}

function ggs_send_customer_verification(array $account, string $token): bool
{
  $url = SITE_URL . '/account/verify.php?token=' . rawurlencode($token);
  $name = htmlspecialchars((string) $account['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $body = "<h2>Verify your email</h2><p>Hello {$name},</p><p>Confirm your Gomez Garage Studio account using this link:</p><p><a href=\"{$url}\">Verify email address</a></p><p>This link expires in 24 hours. If you did not create this account, you can ignore this email.</p>";
  return ggs_send_mail((string) $account['email'], 'Verify your Gomez Garage Studio account', $body);
}

function ggs_send_customer_password_reset(array $account, string $token): bool
{
  $url = SITE_URL . '/account/reset-password.php?token=' . rawurlencode($token);
  $body = "<h2>Reset your password</h2><p>We received a request to reset the password for your Gomez Garage Studio account.</p><p><a href=\"{$url}\">Choose a new password</a></p><p>This link expires in one hour. If you did not request a reset, you can ignore this email.</p>";
  return ggs_send_mail((string) $account['email'], 'Reset your Gomez Garage Studio password', $body);
}

function ggs_customer_order_updates_enabled(array $order): bool
{
  $accountId = (int) ($order['customer_account_id'] ?? 0);
  if ($accountId <= 0) return true; // Guest orders receive transactional progress messages by default.
  $stmt = ggs_db()->prepare('SELECT email_order_updates FROM customer_accounts WHERE id = ? LIMIT 1');
  $stmt->execute([$accountId]);
  $enabled = $stmt->fetchColumn();
  return $enabled === false || (int) $enabled === 1;
}

function ggs_send_customer_order_status_update(array $order, string $note = '', bool $essential = false): bool
{
  $essential = $essential || in_array((string) ($order['status'] ?? ''), ['shipped', 'delivered'], true);
  if (!$essential && !ggs_customer_order_updates_enabled($order)) return true;
  $sent = ggs_send_order_update($order, $note);
  if (!$sent) error_log('GGS order status email failed for ' . ($order['order_number'] ?? 'unknown order'));
  return $sent;
}

function ggs_send_customer_custom_update(array $inquiry, string $note = ''): bool
{
  $accountId = (int) ($inquiry['customer_account_id'] ?? 0);
  if ($accountId > 0) {
    $stmt = ggs_db()->prepare('SELECT email_custom_updates FROM customer_accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    if ((int) $stmt->fetchColumn() === 0) return true;
  }
  $number = htmlspecialchars((string) $inquiry['inquiry_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $status = htmlspecialchars(ucwords(str_replace('_', ' ', (string) $inquiry['status'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $safeNote = trim($note) !== '' ? '<p>' . nl2br(htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>' : '';
  $body = "<h2>Custom request {$number}</h2><p>Status: <strong>{$status}</strong></p>{$safeNote}<p>— Gomez Garage Studio</p>";
  $sent = ggs_send_mail((string) $inquiry['customer_email'], "Update on your custom request {$number}", $body);
  if (!$sent) error_log('GGS custom request update email failed for ' . $number);
  return $sent;
}

function ggs_send_customer_custom_received(array $inquiry): bool
{
  $number = htmlspecialchars((string) $inquiry['inquiry_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $accountLine = '<p>You can create an account with this email to see this request and its updates: <a href="' . SITE_URL . '/account/register.php">Create an account</a>.</p>';
  if (!empty($inquiry['customer_account_id'])) $accountLine = '<p><a href="' . SITE_URL . '/account/">View your account</a>.</p>';
  $body = "<h2>We received your custom request</h2><p>Your request number is <strong>{$number}</strong>.</p><p>We will review the details and get back to you.</p>{$accountLine}<p>— Gomez Garage Studio</p>";
  $sent = ggs_send_mail((string) $inquiry['customer_email'], "We received your custom request {$number}", $body);
  if (!$sent) error_log('GGS custom request confirmation email failed for ' . $number);
  return $sent;
}
