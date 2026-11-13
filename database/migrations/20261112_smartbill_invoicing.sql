-- SmartBill invoicing state, company billing fields, and the exact Revolut
-- charge amount/currency.
-- Safe to re-run on MariaDB: every column is added only when missing.
-- Import on the existing production database before enabling SMARTBILL_ENABLED.
-- Back up the database first. This migration does not emit or modify invoices.

ALTER TABLE customer_addresses
  ADD COLUMN IF NOT EXISTS county VARCHAR(120) NULL AFTER city;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(20) NULL AFTER order_number,
  ADD COLUMN IF NOT EXISTS customer_type ENUM('pf','pj') NOT NULL DEFAULT 'pf' AFTER invoice_number,
  ADD COLUMN IF NOT EXISTS company_name VARCHAR(200) NULL AFTER customer_type,
  ADD COLUMN IF NOT EXISTS company_cui VARCHAR(20) NULL AFTER company_name,
  ADD COLUMN IF NOT EXISTS company_reg VARCHAR(50) NULL AFTER company_cui,
  ADD COLUMN IF NOT EXISTS company_address TEXT NULL AFTER company_reg,
  ADD COLUMN IF NOT EXISTS company_city VARCHAR(120) NULL AFTER company_address,
  ADD COLUMN IF NOT EXISTS company_county VARCHAR(120) NULL AFTER company_city,
  ADD COLUMN IF NOT EXISTS company_postal VARCHAR(30) NULL AFTER company_county,
  ADD COLUMN IF NOT EXISTS company_country CHAR(2) NULL AFTER company_postal,
  ADD COLUMN IF NOT EXISTS company_vat_payer TINYINT(1) NOT NULL DEFAULT 0 AFTER company_country,
  ADD COLUMN IF NOT EXISTS payment_currency CHAR(3) NULL AFTER revolut_order_id,
  ADD COLUMN IF NOT EXISTS payment_amount DECIMAL(12,2) NULL AFTER payment_currency,
  ADD COLUMN IF NOT EXISTS invoice_provider VARCHAR(20) NULL AFTER total_eur,
  ADD COLUMN IF NOT EXISTS invoice_status ENUM('pending','issuing','issued','failed','uncertain') NULL AFTER invoice_provider,
  ADD COLUMN IF NOT EXISTS invoice_currency CHAR(3) NULL AFTER invoice_status,
  ADD COLUMN IF NOT EXISTS invoice_exchange_rate DECIMAL(12,6) NULL AFTER invoice_currency,
  ADD COLUMN IF NOT EXISTS invoice_document_id VARCHAR(100) NULL AFTER invoice_exchange_rate,
  ADD COLUMN IF NOT EXISTS invoice_view_url TEXT NULL AFTER invoice_document_id,
  ADD COLUMN IF NOT EXISTS invoice_error VARCHAR(500) NULL AFTER invoice_view_url,
  ADD COLUMN IF NOT EXISTS invoice_issued_at DATETIME NULL AFTER invoice_error;
