# Eurosender deployment

The shop supports live Eurosender delivery quotes and idempotent shipment
booking after Revolut confirms payment. The provider is disabled by default,
its API key stays only in the ignored production `config.php`, and sandbox and
production environments cannot be mixed.

## What the integration does

- checkout requests every available parcel service for the exact EU address
  and packed product measurements;
- the customer selects a service and sees its live price in the order total;
- `create-order.php` repeats the quote server-side and never trusts a price
  submitted by the browser;
- the order is saved with the selected service and remains `pending` until the
  signed Revolut webhook confirms payment;
- only a paid order can be booked with Eurosender;
- validation runs before creation, and booking is claimed atomically so a
  webhook retry cannot intentionally create a duplicate shipment;
- an ambiguous network result is marked `uncertain` and is never retried
  automatically;
- the admin order page can retry a definite failure, refresh status, and open
  the tracking or label URL when Eurosender returns them.

No real Revolut payment is required for sandbox quote and payload validation.
An actual sandbox shipment may require a virtual sandbox wallet balance, but
it must never use a real customer payment or the live Revolut account.

## Database deployment

1. Back up the production database.
2. Pull the matching release from GitHub.
3. Import `database/migrations/20261114_eurosender_shipping.sql` exactly once.

Git pull does not run the migration. The migration is additive and stores the
selected service, remote order code, status, label/tracking links and booking
attempt state.

## Configuration

Copy the Eurosender block from `config.example.php` into the ignored server
`config.php`. Never commit or paste the API key into chat.

Required sender data:

- `EUROSENDER_PICKUP_CONTACT_NAME`: `Lancer Land SRL / Gabriel Petrut`
- `EUROSENDER_PICKUP_CONTACT_EMAIL`: the operational sender email
- `EUROSENDER_PICKUP_CONTACT_PHONE`: the operational sender phone, including
  country prefix
- pickup address: Strada Magnoliei 12D, Dumbraveni, Ilfov, 077016, Romania

All three provider gates start disabled:

```php
define('EUROSENDER_INTEGRATION_ACTIVE', false);
define('EUROSENDER_ENABLED', false);
define('EUROSENDER_AUTO_BOOKING_ENABLED', false);
define('EUROSENDER_ENV', 'sandbox');
```

`EUROSENDER_INTEGRATION_ACTIVE` and `EUROSENDER_ENABLED` expose quotes only
when the provider environment matches `APP_ENV`. Automatic booking additionally
requires `EUROSENDER_AUTO_BOOKING_ENABLED`.

## Safe sandbox verification

Use an isolated staging installation with `APP_ENV = 'sandbox'` and the
Eurosender sandbox key. Never switch the public production shop to sandbox.

1. Leave all provider gates off and use **Admin > Settings > Eurosender API >
   Check connection**. This performs only `GET /v1/countries`.
2. Use the admin sandbox service-options test. It calls `POST /v1/quotes` and
   displays every returned option; it cannot create a shipment or debit a
   wallet.
3. Run the local synthetic tests. They inject a fake Eurosender transport and
   validate the full order payload without contacting Eurosender or Revolut.
4. If Eurosender requires a sandbox shipment before production approval, use
   synthetic customer data in isolated staging. A virtual sandbox balance may
   be needed; no real Revolut transaction is necessary.

Sandbox prices are test values. Production negotiated prices appear only with
the production account/key.

## Production activation

After Eurosender enables production API access:

1. set `EUROSENDER_ENV` to `production` and add the separate production key;
2. keep `EUROSENDER_AUTO_BOOKING_ENABLED` false;
3. enable `EUROSENDER_INTEGRATION_ACTIVE` and `EUROSENDER_ENABLED` and request
   a live checkout quote; a quote does not create a shipment;
4. verify sender data, product package measurements, live prices and every
   supported destination used by the shop;
5. enable `EUROSENDER_AUTO_BOOKING_ENABLED` only after that review.

The normal production sequence is:

1. checkout obtains a live quote;
2. customer selects the service and pays through Revolut;
3. the signed Revolut webhook marks the order paid;
4. SmartBill invoicing and Eurosender booking run independently;
5. a courier failure does not undo payment, and the admin page provides a
   controlled retry.

Rollback is immediate: set `EUROSENDER_INTEGRATION_ACTIVE` to false. Existing
Eurosender order data stays visible in admin, while checkout falls back to the
configured static EU rates.
