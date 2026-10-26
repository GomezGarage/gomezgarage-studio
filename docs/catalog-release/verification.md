# Local verification - 26 October 2026

All fixtures use disposable local data. No shop config, existing database, payment,
mail or courier service is loaded or invoked. These results do not certify the
current production schema, hosting configuration or a live Revolut payment.

| Check | Result |
| --- | --- |
| Changed/new PHP lint, PHP 8.1.34 and 8.5.9 | PASS |
| `node --check assets/js/shop.js` | PASS |
| `php tests/catalog.php` | PASS, 62 checks |
| `python3 scripts/build-catalog-sql.py --check` | PASS |
| `php tests/agent/run.php` | PASS, 124 synthetic SQLite checks |
| `python3 tests/agent/http_smoke.py` | PASS, loopback entry points and service boundaries |
| `python3 tests/agent/validate_contract.py` | PASS, OpenAPI 3.1, 80 response samples, 5 documented response examples and 2 draft requests |
| `python3 tests/agent/mysql_smoke.py --engine mariadb --catalog-checks ...` | PASS, 61 existing checks + 29 catalog checks; MariaDB 10.6.28 / PHP 8.1.34, private Unix socket, no TCP |
| `python3 tests/agent/apache_smoke.py --catalog-checks ...` | PASS, Apache 2.4.69 / mod_php 8.1.34 / loopback HTTPS |
| Payment/order/invoice/mail/auth/DB implementation compared to pre-change Git content | Identical |

The catalog checks cover exact bilingual SQL copy round trips, materials/categories,
six unique drafts, rainbow quantity one, preservation of historical product IDs and
paid-order item snapshots, and repeat imports preserving admin edits and later products.

Apache checks use a fixture-only SQLite catalog in a temporary document root. They
verify the actual root rewrite rules, content/links in initial HTML, canonical slug
URLs, legacy IDs, Product JSON-LD, Open Graph, draft/missing/malformed URLs returning
404/noindex, published-only sitemap, blocked includes, and DB errors returning 503.

Unchanged implementation files include `assets/js/checkout.js`, `api/create-order.php`,
`api/webhook.php`, `includes/helpers.php`, `includes/invoice.php`, `includes/mailer.php`,
`includes/db.php`, `includes/auth.php`, `admin/orders.php`, and `admin/order-detail.php`.
Shared shop CSS/JS asset URL versions change in the HTML pages, including checkout;
the payment logic itself does not change.

The available cached MySQL 8.4 executable could not start because an Abseil runtime
library is missing. No MySQL 8.4 PASS is claimed. The import was verified on the
disposable MariaDB instance above. Confirm the existing production category column
before applying the documented migration; the repository schema is not proof of live state.

## Re-running

The existing runners document flags for pre-existing local database/Apache binaries:

```sh
php tests/catalog.php
php tests/agent/run.php
python3 scripts/build-catalog-sql.py --check
python3 tests/agent/validate_contract.py --php /path/to/php
python3 tests/agent/http_smoke.py --php /path/to/php
python3 tests/agent/mysql_smoke.py --mysql-basedir /path/to/mariadb --engine mariadb --php /path/to/php --catalog-checks
python3 tests/agent/apache_smoke.py --httpd-basedir /path/to/httpd --php-module /path/to/libphp.so --catalog-checks
```

Use `--library-path` only when required for extracted local binary dependencies.
Never point a test at an existing shop database or replace a fixture helper with live config.
