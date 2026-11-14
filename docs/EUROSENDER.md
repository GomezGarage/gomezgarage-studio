# Eurosender deployment

Eurosender is introduced in phases. The current phase adds an isolated sandbox
configuration and a read-only credential check. It does **not** replace the
store's static shipping rates, request quotes, charge sandbox credit, book a
shipment or expose the API key to the browser.

## Phase 1: sandbox connection

1. Pull the release from GitHub.
2. In the ignored production `config.php`, add the Eurosender block shown in
   `config.example.php`.
3. Keep both `EUROSENDER_INTEGRATION_ACTIVE` and `EUROSENDER_ENABLED` set to
   `false`.
4. Set `EUROSENDER_ENV` to `sandbox` and paste the sandbox key into
   `EUROSENDER_API_KEY`. Never commit or send that key in chat.
5. Open **Admin > Settings > Eurosender API** and click **Check Eurosender
   connection (read-only)**.

The connection check makes exactly one authenticated `GET /v1/countries` request. It does
not need wallet credit and cannot create a quote, order, label or charge.

Once the connection succeeds, configure the five `EUROSENDER_PICKUP_*`
constants. The Admin page then offers a Romania-only sandbox quote form. A
quote resolves the canonical region/city IDs and calls `POST /v1/quotes`; it
does not create a shipment or debit the sandbox wallet. Both runtime flags
remain `false` during this phase.

Eurosender's onboarding email says the sandbox wallet can be funded with an
arbitrary test balance and no real money. If the interface asks for a real card
charge, stop rather than paying; the read-only Phase 1 check does not need it.

## Later phases

After the sandbox credential check succeeds, implement and validate in order:

1. connect live checkout quotes to packed product measurements;
2. sandbox order, label and tracking flow using synthetic customer data;
3. webhook verification and idempotent paid-order booking;
4. production access review and a separate production API key.

Production and sandbox keys are environment-specific and cannot be reused. Do
not enable storefront booking merely because the read-only check succeeds.
