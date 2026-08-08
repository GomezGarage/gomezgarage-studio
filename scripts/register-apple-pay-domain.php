<?php
/**
 * One-off CLI script — registers gomezgarage.eu with Revolut for Apple Pay.
 *
 * Run once, after the domain validation file is live at:
 *   https://gomezgarage.eu/.well-known/apple-developer-merchantid-domain-association
 *
 *   php scripts/register-apple-pay-domain.php
 *
 * Docs: https://developer.revolut.com/docs/merchant/register-domain-apple-pay
 *
 * This endpoint is unversioned (no /1.0/ segment, unlike REVOLUT_API_BASE)
 * and always targets the live merchant.revolut.com — Apple Pay domain
 * verification isn't a sandbox concept, so this ignores APP_ENV.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';

$domain = 'gomezgarage.eu';

$payload = json_encode(['domain' => $domain]);

$ch = curl_init('https://merchant.revolut.com/api/apple-pay/domains/register');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . REVOLUT_SECRET_KEY,
  ],
  CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode === 204) {
  echo "Success — {$domain} is registered for Apple Pay.\n";
  exit(0);
}

echo "Failed (HTTP {$httpCode}).\n";
if ($curlError !== '') {
  echo "cURL error: {$curlError}\n";
}
if ($response !== false && $response !== '') {
  echo "Response: {$response}\n";
}
exit(1);
