# Gomez Garage Studio

Source for `gomezgarage.eu/studio` — see [ARCHITECTURE.md](ARCHITECTURE.md) for
full design decisions, page structure, schema and payment flow.

## Stack

- Frontend: static HTML/CSS/vanilla JS (no framework, no build step)
- Backend: PHP 8 + MySQL, PHPMailer, Revolut Merchant API

## Local / server setup

1. **Dependencies**
   ```bash
   composer install
   ```

2. **Config**
   ```bash
   cp config.example.php config.php
   ```
   Fill in `config.php` with real DB credentials, SMTP credentials, and
   Revolut keys (`REVOLUT_SECRET_KEY`, `REVOLUT_PUBLIC_KEY`,
   `REVOLUT_WEBHOOK_SIGNING_SECRET`). This file is gitignored — never commit it.

3. **Database**
   ```bash
   mysql -u <user> -p <database_name> < database/schema.sql
   ```
   This creates all tables and seeds a default admin user:
   - Username: `admin`
   - Password: `ChangeMe123!`

   **Change this password immediately** after your first login at
   `/admin/login.php` (there's no UI for it yet — update the `password_hash`
   column in `admin_users` directly, e.g. via `password_hash()` in a one-off
   PHP snippet).

4. **Web server**
   Point the document root at this folder so it's served at `/studio/`.
   `.htaccess` files already restrict `/admin/`, `/api/`, `/includes/` and
   `/database/` from direct/unwanted access — make sure `AllowOverride All`
   (or equivalent) is enabled so they're honored.

5. **Revolut webhook**
   In the Revolut Business dashboard, point the order webhook at
   `https://gomezgarage.eu/studio/api/webhook.php`.

## Notes

- A fresh `database/schema.sql` installs the six real catalog listings as drafts.
  Complete photographs, prices, stock and specifications in the admin editor.
  For an existing installation, follow [the catalog release guide](docs/catalog-release/README.md)
  and import only its two migration files; do not re-run the full schema.
- Apache renders `product.html`, `products.html` and `sitemap.xml` through their PHP
  counterparts. Product copy/metadata and published catalog links are available in
  initial HTML; the sitemap follows published products automatically. Existing product
  ID URLs still work; canonical links use descriptive slugs.

## Service API

The separate, disabled-by-default service API is documented in
[docs/agent-api/README.md](docs/agent-api/README.md), including its OpenAPI 3.1
contract, read-only catalog/order access, draft-only product writes, synthetic tests,
synchronization limits, configuration and deployment/rollback gates. It does not load
the shop credentials or change checkout behavior. Draft writes require a separate
database connection and remain unpublished until reviewed in the admin panel. The
default connection reuses the configured service DB user; a separate writer user is
optional when the hosting setup needs one.

The API reports privacy-minimal product-detail view events after the
`product_views_daily` migration is installed. Counts are aggregate by UTC day and
deduplicated at most once per product per browser tab session; when session storage is
unavailable the event is skipped. They are not unique visitors or conversions, and the
browser stores no visitor identifier.
