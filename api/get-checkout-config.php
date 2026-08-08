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

ggs_cors_headers();

ggs_json_response([
  'success' => true,
  'public_key' => REVOLUT_PUBLIC_KEY,
  'mode' => APP_ENV === 'sandbox' ? 'sandbox' : 'prod',
]);
