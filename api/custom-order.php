<?php
/**
 * POST /api/custom-order.php
 * Handles the custom.html contact form (ARCHITECTURE.md section 4.4):
 * forwards the request as an email to contact@gomezgarage.eu.
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

ggs_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ggs_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = ggs_read_json_body();

$name = trim($body['name'] ?? '');
$email = trim($body['email'] ?? '');
$need = trim($body['need'] ?? '');
$budget = trim($body['budget'] ?? '');

if ($name === '' || $need === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  ggs_json_response(['success' => false, 'error' => 'Please fill in your name, a valid email, and what you need.'], 422);
}

$html = '
  <h2>New custom order inquiry</h2>
  <p><strong>Name:</strong> ' . htmlspecialchars($name) . '</p>
  <p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>
  <p><strong>Budget:</strong> ' . htmlspecialchars($budget ?: 'Not specified') . '</p>
  <p><strong>What they need:</strong><br>' . nl2br(htmlspecialchars($need)) . '</p>
';

$sent = ggs_send_mail('contact@gomezgarage.eu', "[GGS] Custom order inquiry from {$name}", $html);

ggs_json_response(['success' => $sent], $sent ? 200 : 502);
