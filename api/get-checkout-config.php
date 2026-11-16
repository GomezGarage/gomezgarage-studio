<?php
/**
 * GET /api/get-checkout-config.php
 * Exposes the Revolut merchant public key + environment mode to the static
 * frontend, which has no server-side templating and so no other way to
 * read config.php values. REVOLUT_PUBLIC_KEY is a publishable key — safe
 * to expose client-side — used to mount the embedded Revolut Checkout
 * widget (window.RevolutCheckout.embeddedCheckout({ publicToken, ... })).
 * Never expose REVOLUT_SECRET_KEY here; that stays server-side only.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/sameday.php';
require_once __DIR__ . '/../includes/eurosender.php';

ggs_cors_headers();
$dummyCodEnabled = ggs_eurosender_sandbox_dummy_checkout_enabled() && ggs_eurosender_booking_enabled();

ggs_json_response([
  'success' => true,
  'public_key' => ((!defined('REVOLUT_ENABLED') || REVOLUT_ENABLED === true) && !$dummyCodEnabled) ? REVOLUT_PUBLIC_KEY : null,
  'mode' => APP_ENV === 'sandbox' ? 'sandbox' : 'prod',
  'dummy_cod_enabled' => $dummyCodEnabled,
  'sameday_enabled' => !$dummyCodEnabled && ggs_sameday_enabled(),
  'eurosender_enabled' => ggs_eurosender_quote_enabled(),
  'eurosender_preview' => ggs_eurosender_sandbox_preview_enabled(),
]);
