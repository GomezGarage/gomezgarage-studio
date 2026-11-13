# Sameday shipping deployment

The storefront supports Romanian Sameday home delivery and Easybox delivery,
live server-side quotes, automatic AWB generation after Revolut confirms a
captured payment, and an idempotent manual retry from the order admin page.
Credentials and tokens are never exposed to JavaScript.

The implementation follows the Sameday API documentation supplied from the
merchant account (v3.6, 28 September 2026): authentication tokens, account
pickup points and services, OOH locations, quote, AWB, PDF, cancellation,
tracking and recovery by `clientInternalReference`.

## Provider master gate

Sameday is currently parked behind `SAMEDAY_INTEGRATION_ACTIVE`. The provider
is available only when both `SAMEDAY_INTEGRATION_ACTIVE` and
`SAMEDAY_ENABLED` are explicitly `true` in the ignored production
`config.php`. Leaving the master gate absent or `false` hides Sameday from
checkout and blocks quote and AWB creation while preserving the implementation
and historical order data for a possible rollback.

## Production deployment

1. Back up the production database and pull the matching Git revision.
2. Import `database/migrations/20261112_shipping_package_data.sql` if it has
   not already been imported.
3. Import `database/migrations/20261113_sameday_shipping.sql` once. A Git pull
   does not run this migration.
4. Add the Sameday block from `config.example.php` to the ignored production
   `config.php`. Keep `SAMEDAY_INTEGRATION_ACTIVE` and `SAMEDAY_ENABLED` set
   to `false` initially.
5. In **Admin > Settings > Sameday Courier API**, run the read-only connection
   check. It creates no AWB.
6. Run **Synchronize services, pickup & OOH**. Confirm that the pickup point in
   the Sameday account is the real dispatch location.
7. If the account has several pickup points or contacts, put the intended IDs
   in `SAMEDAY_PICKUP_POINT_ID` and `SAMEDAY_CONTACT_PERSON_ID`. Otherwise the
   first synchronized pickup point and its default contact are used.
8. Home delivery and Locker Nextday service IDs are discovered from the
   synchronized account. Explicit service-ID constants are available only as
   an override.
9. Personal drop-off is an account-specific extra option. To take every parcel
   personally to an Easybox/Sameday point instead of requesting courier pickup,
   set `SAMEDAY_PERSONAL_DROPOFF_ENABLED` to `true`. The site resolves the PDO
   tax ID from the synchronized service and the actual package type and sends
   the documented `PDO <id>` value. Re-run synchronization after Sameday
   changes the contract. `SAMEDAY_PDO_SERVICE_TAX_IDS` is only a fail-safe
   override for a non-standard account response; never guess an ID from a
   different service or package type.
10. Complete the packed dimensions and weight for every published product.
    Safe fallback values exist, and the admin/quote data records when they were
    necessary, but exact packed measurements produce the correct price.
11. Set both `SAMEDAY_INTEGRATION_ACTIVE` and `SAMEDAY_ENABLED` to `true` only
    after the account data is synchronized and a deliberate reactivation is
    approved.

## Checkout and AWB lifecycle

- The browser searches a local Easybox cache; it never receives Sameday
  credentials or authentication tokens.
- The server reprices the products and requests a fresh Sameday quote before
  creating the payment order. Client-supplied prices are ignored.
- The selected service, OOH location, quote and package snapshot are stored on
  the order.
- When Personal Drop-Off is enabled, both the quote and the final AWB include
  the package-specific PDO tax. Easybox deliveries send only the recipient
  identity plus `oohLastMile`; the customer's street is not submitted as the
  locker destination.
- After the Revolut `ORDER_COMPLETED` webhook atomically marks the order paid,
  the site asks Sameday for an existing AWB with the unique store order number.
  It creates an AWB only if none exists.
- A timeout is marked `uncertain`, never blindly retried as a new shipment.
  **Generate / recover AWB** in the admin page first attempts recovery by the
  same `clientInternalReference`, preventing duplicates.
- If a PHP worker stops while an AWB is being issued, the five-minute lease
  expires and the same admin action recovers the shipment by reference before
  it considers creating anything new.
- Admin can download A6/A4 labels, refresh tracking, cancel an eligible AWB,
  and open the official Sameday tracking page.
- Sameday failure never reverses a confirmed payment or stock operation. The
  error remains visible on the order for an explicit retry.

## Offline verification

```bash
php -l includes/sameday.php
php tests/sameday.php
```

The test uses a synthetic transport and an in-memory database. It does not load
`config.php` and cannot contact Sameday.
