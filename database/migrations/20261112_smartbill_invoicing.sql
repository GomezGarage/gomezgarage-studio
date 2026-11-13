-- SmartBill invoicing state and the exact Revolut charge amount/currency.
-- Run once on the existing production database before enabling SMARTBILL_ENABLED.
-- Back up the database first. This migration does not emit or modify invoices.

ALTER TABLE customer_addresses
  ADD COLUMN county VARCHAR(120) NULL AFTER city;

ALTER TABLE orders
  ADD COLUMN payment_currency CHAR(3) NULL AFTER revolut_order_id,
  ADD COLUMN payment_amount DECIMAL(12,2) NULL AFTER payment_currency,
  ADD COLUMN company_city VARCHAR(120) NULL AFTER company_address,
  ADD COLUMN company_county VARCHAR(120) NULL AFTER company_city,
  ADD COLUMN company_postal VARCHAR(30) NULL AFTER company_county,
  ADD COLUMN company_country CHAR(2) NULL AFTER company_postal,
  ADD COLUMN company_vat_payer TINYINT(1) NOT NULL DEFAULT 0 AFTER company_country,
  ADD COLUMN invoice_provider VARCHAR(20) NULL AFTER total_eur,
  ADD COLUMN invoice_status ENUM('pending','issuing','issued','failed','uncertain') NULL AFTER invoice_provider,
  ADD COLUMN invoice_currency CHAR(3) NULL AFTER invoice_status,
  ADD COLUMN invoice_exchange_rate DECIMAL(12,6) NULL AFTER invoice_currency,
  ADD COLUMN invoice_document_id VARCHAR(100) NULL AFTER invoice_exchange_rate,
  ADD COLUMN invoice_view_url TEXT NULL AFTER invoice_document_id,
  ADD COLUMN invoice_error VARCHAR(500) NULL AFTER invoice_view_url,
  ADD COLUMN invoice_issued_at DATETIME NULL AFTER invoice_error;
