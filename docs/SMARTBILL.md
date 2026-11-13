# SmartBill invoicing

The storefront can use SmartBill Cloud V1 as the fiscal invoice provider after
Revolut confirms an online card payment. The feature is disabled by default and
never calls SmartBill until `SMARTBILL_ENABLED` is explicitly set to `true` in
the ignored production `config.php`.

## Accounting and currency rule

- Romanian customer (`shipping_address.country = RO`): invoice in RON. Product
  prices remain canonically stored in EUR and are converted using the shop's
  `eur_ron_rate` setting.
- Foreign customer: invoice follows the actual supported Revolut charge currency
  (`EUR` or `RON`), normally EUR.
- The SmartBill response is authoritative for series and invoice number.
- Series `LL` is reserved for manual/off-site invoices. The storefront must
  never issue from it; the code rejects that configuration locally.
- The website remains authoritative for stock. Requests use `useStock=false`.
- Online card invoices include a non-cash `Card online` receipt in the same API
  request. The storefront does not offer cash on delivery.

## One-time production setup

1. Confirm that the SmartBill subscription includes API access. SmartBill's help
   currently identifies Facturare Platinum for online-shop API integration.
2. In SmartBill confirm invoice series `GGS3D` and configure its next number to
   `1524`, the approved first invoice number for the site. Do not enable the
   site until this is verified. Do not use manual series `LL` here.
3. Enable product codes and foreign-currency reference prices. LANCER LAND SRL
   is configured as a non-VAT issuer, so product tax fields are omitted and the
   SmartBill `/tax` endpoint is intentionally not called.
4. Add/verify both RON and EUR bank accounts displayed on documents.
5. Copy API Email, Token and CIF from **Contul meu > Integrari > API** into the
   server's ignored `config.php`; never commit or paste the token into source.
6. Back up the production database and run
   `database/migrations/20261112_smartbill_invoicing.sql` once.
7. Deploy the matching code with `SMARTBILL_ENABLED=false` first.
8. Before the first automatic invoice, run
   `php scripts/check-smartbill-config.php --expect-next=1524`. This performs
   a read-only `/series` call and fails unless SmartBill reports `GGS3D` with
   next number `1524`; it does not emit a document. For a VAT-registered issuer,
   the same check also validates the configured tax through `/tax`. Later checks
   can omit `--expect-next` and will report the current next number.
9. Enable the production flag for one controlled low-value order and reconcile
   the result in SmartBill before leaving automation enabled.

## Failure behavior

Payment and stock processing do not roll back if SmartBill is unavailable. The
order records `failed` for a definite API rejection or `uncertain` when a network
timeout may have happened after SmartBill created the invoice. An uncertain call
must be reconciled manually in SmartBill Cloud and must not be retried blindly,
because the V1 invoice endpoint has no request idempotency key.

After a successful issue, the site stores the returned series/number and downloads
the official PDF into the existing protected `invoices/` directory so the current
customer email attachment and invoice download flow keep working.
