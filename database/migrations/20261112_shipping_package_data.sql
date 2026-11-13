-- Per-product shipping parcel measurements for live carrier quotes.
-- Run once on the deployed database after backing it up.

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS shipping_length_cm DECIMAL(7,2) NULL AFTER specs,
  ADD COLUMN IF NOT EXISTS shipping_width_cm DECIMAL(7,2) NULL AFTER shipping_length_cm,
  ADD COLUMN IF NOT EXISTS shipping_height_cm DECIMAL(7,2) NULL AFTER shipping_width_cm,
  ADD COLUMN IF NOT EXISTS shipping_weight_kg DECIMAL(7,3) NULL AFTER shipping_height_cm;
