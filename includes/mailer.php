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

function ggs_send_mail(string $to, string $subject, string $htmlBody, ?string $attachmentPath = null): bool
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

  $body = "
    <h2>Thank you for your order, {$order['customer_name']}!</h2>
    <p>Your order <strong>#{$order['order_number']}</strong> is confirmed.</p>
    <ul>{$itemsHtml}</ul>
    <p><strong>Total:</strong> {$order['total_eur']} EUR</p>
    <p><strong>Shipping to:</strong> {$address['street']}, {$address['city']}, {$address['postal']}, {$address['country']}</p>
    {$invoiceLine}
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
function ggs_send_order_update(array $order): bool
{
  $subject = sprintf('Update on your Gomez Garage Studio order #%s', $order['order_number']);
  $trackingLine = $order['tracking_number']
    ? "<p><strong>Tracking number:</strong> {$order['tracking_number']}</p>"
    : '';
  $body = "
    <h2>Order #{$order['order_number']} update</h2>
    <p>Your order status is now: <strong>" . ucfirst($order['status']) . "</strong></p>
    {$trackingLine}
    <p>— Gomez Garage Studio</p>
  ";
  return ggs_send_mail($order['customer_email'], $subject, $body);
}
