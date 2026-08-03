<?php
/**
 * GET /api/get-rate.php
 * Returns the current EUR -> RON exchange rate used for currency display.
 * Rate is set in admin/settings.php; hardcoded fallback if missing.
 */

require_once __DIR__ . '/../includes/helpers.php';

ggs_cors_headers();

const FALLBACK_RATE = 5.00;

$rate = ggs_get_setting('eur_ron_rate', (string) FALLBACK_RATE);

ggs_json_response([
  'success' => true,
  'base' => 'EUR',
  'quote' => 'RON',
  'rate' => (float) $rate,
]);
