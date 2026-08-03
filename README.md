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

- Seed products in `database/schema.sql` use placeholder SVG imagery in
  `assets/images/products/` — replace via the admin product editor.
- `sitemap.xml` lists product URLs by id manually; update it when products
  are added/removed (see ARCHITECTURE.md section 8).
