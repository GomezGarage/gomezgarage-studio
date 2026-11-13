<?php
/**
 * GOMEZ GARAGE STUDIO — configuration template.
 *
 * Copy this file to config.php (which is gitignored) and fill in real
 * credentials on the server. NEVER commit config.php.
 */

// ---------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------
define('APP_ENV', 'production'); // 'production' | 'sandbox'
define('SITE_URL', 'https://gomezgarage.eu/studio');

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'ggs_studio');
define('DB_USER', 'ggs_studio_user');
define('DB_PASS', 'REPLACE_WITH_DB_PASSWORD');

// ---------------------------------------------------------------------
// Revolut Merchant API
// https://developer.revolut.com/docs/merchant/
// Sandbox keys start with "sk_sandbox_" / "pk_sandbox_", live keys with
// "sk_" / "pk_". Get these from the Revolut Business dashboard.
// ---------------------------------------------------------------------
define('REVOLUT_SECRET_KEY', 'REVOLUT_SECRET_KEY_PLACEHOLDER');
define('REVOLUT_PUBLIC_KEY', 'REVOLUT_PUBLIC_KEY_PLACEHOLDER');
define('REVOLUT_API_BASE', APP_ENV === 'sandbox'
  ? 'https://sandbox-merchant.revolut.com/api/1.0'
  : 'https://merchant.revolut.com/api/1.0');
define('REVOLUT_WEBHOOK_SIGNING_SECRET', 'REVOLUT_WEBHOOK_SIGNING_SECRET_PLACEHOLDER');

// ---------------------------------------------------------------------
// SMTP (used by PHPMailer, includes/mailer.php)
// ---------------------------------------------------------------------
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'contact@gomezgarage.eu');
define('SMTP_PASS', 'REPLACE_WITH_SMTP_PASSWORD');
define('SMTP_FROM_EMAIL', 'contact@gomezgarage.eu');
define('SMTP_FROM_NAME', 'Gomez Garage Studio');

// ---------------------------------------------------------------------
// CORS — restrict API responses to the site's own domain only
// ---------------------------------------------------------------------
define('ALLOWED_ORIGIN', 'https://gomezgarage.eu');

// ---------------------------------------------------------------------
// Company details (fiscal invoices, includes/invoice.php)
// ---------------------------------------------------------------------
define('COMPANY_NAME', 'LANCER LAND SRL');
define('COMPANY_CUI', '24884753');
define('COMPANY_EUID', 'ROONRC.J2008021110403');
define('COMPANY_REG_COM', 'J2008021110403');
define('COMPANY_BANK', 'Revolut UAB Vilnius Sucursala Bucuresti');
define('COMPANY_IBAN', 'RO42REVO0000364714559043');
define('COMPANY_ADDRESS', 'Str. Reconstructiei nr. 1, Sector 3, Bucuresti, Romania');
define('INVOICE_SERIES', 'GGS3D');
define('INVOICE_START', 1524);
define('INVOICE_MAX', 9999);
define('VAT_REGISTERED', false);

// ---------------------------------------------------------------------
// SmartBill Cloud API (disabled until the production account is ready)
// https://api.smartbill.ro/
//
// Create/confirm the GGS3D invoice series in SmartBill with next number 1524
// before enabling this integration. LL is reserved for manual/off-site
// invoices and must never be configured here. The email/token come from
// SmartBill Cloud > Contul meu > Integrari > API. Keep them only in the
// ignored config.php file on the server.
// ---------------------------------------------------------------------
define('SMARTBILL_ENABLED', false);
define('SMARTBILL_API_BASE', 'https://ws.smartbill.ro/SBORO/api');
define('SMARTBILL_EMAIL', 'REPLACE_WITH_SMARTBILL_EMAIL');
define('SMARTBILL_API_TOKEN', 'REPLACE_WITH_SMARTBILL_API_TOKEN');
define('SMARTBILL_COMPANY_VAT_CODE', COMPANY_CUI);
define('SMARTBILL_INVOICE_SERIES', INVOICE_SERIES);
define('SMARTBILL_MEASURING_UNIT', 'buc');
define('SMARTBILL_USE_PRODUCT_CODES', true);
// Must match the exact zero-VAT rate name configured in this SmartBill account.
define('SMARTBILL_TAX_NAME', 'REPLACE_WITH_EXACT_SMARTBILL_ZERO_VAT_RATE_NAME');
define('SMARTBILL_TAX_PERCENTAGE', 0.0);
define('SMARTBILL_TIMEOUT_SECONDS', 20);
