-- Sameday shipping, Easybox/PUDO cache and AWB lifecycle.
-- Back up production first. Safe to import once after the matching code pull.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS shipping_provider VARCHAR(20) NULL AFTER shipping_eur,
  ADD COLUMN IF NOT EXISTS shipping_method VARCHAR(20) NULL AFTER shipping_provider,
  ADD COLUMN IF NOT EXISTS shipping_service_id INT NULL AFTER shipping_method,
  ADD COLUMN IF NOT EXISTS shipping_service_name VARCHAR(120) NULL AFTER shipping_service_id,
  ADD COLUMN IF NOT EXISTS shipping_ooh_id BIGINT NULL AFTER shipping_service_name,
  ADD COLUMN IF NOT EXISTS shipping_ooh_name VARCHAR(200) NULL AFTER shipping_ooh_id,
  ADD COLUMN IF NOT EXISTS shipping_ooh_address VARCHAR(500) NULL AFTER shipping_ooh_name,
  ADD COLUMN IF NOT EXISTS shipping_quote_amount DECIMAL(10,2) NULL AFTER shipping_ooh_address,
  ADD COLUMN IF NOT EXISTS shipping_quote_currency CHAR(3) NULL AFTER shipping_quote_amount,
  ADD COLUMN IF NOT EXISTS shipping_package JSON NULL AFTER shipping_quote_currency,
  ADD COLUMN IF NOT EXISTS sameday_awb_number VARCHAR(100) NULL AFTER tracking_number,
  ADD COLUMN IF NOT EXISTS sameday_awb_status VARCHAR(20) NULL AFTER sameday_awb_number,
  ADD COLUMN IF NOT EXISTS sameday_awb_cost DECIMAL(10,2) NULL AFTER sameday_awb_status,
  ADD COLUMN IF NOT EXISTS sameday_awb_currency CHAR(3) NULL AFTER sameday_awb_cost,
  ADD COLUMN IF NOT EXISTS sameday_parcels JSON NULL AFTER sameday_awb_currency,
  ADD COLUMN IF NOT EXISTS sameday_last_status VARCHAR(200) NULL AFTER sameday_parcels,
  ADD COLUMN IF NOT EXISTS sameday_last_status_at DATETIME NULL AFTER sameday_last_status,
  ADD COLUMN IF NOT EXISTS sameday_error VARCHAR(500) NULL AFTER sameday_last_status_at,
  ADD COLUMN IF NOT EXISTS sameday_attempted_at DATETIME NULL AFTER sameday_error,
  ADD COLUMN IF NOT EXISTS sameday_created_at DATETIME NULL AFTER sameday_attempted_at,
  ADD COLUMN IF NOT EXISTS sameday_cancelled_at DATETIME NULL AFTER sameday_created_at;

SET @sameday_awb_index_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'uq_orders_sameday_awb'
);
SET @sameday_awb_index_sql = IF(
  @sameday_awb_index_exists = 0,
  'ALTER TABLE orders ADD UNIQUE KEY uq_orders_sameday_awb (sameday_awb_number)',
  'SELECT 1'
);
PREPARE sameday_awb_index_statement FROM @sameday_awb_index_sql;
EXECUTE sameday_awb_index_statement;
DEALLOCATE PREPARE sameday_awb_index_statement;

CREATE TABLE IF NOT EXISTS sameday_api_cache (
  cache_key VARCHAR(100) NOT NULL PRIMARY KEY,
  cache_value TEXT NOT NULL,
  expires_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sameday_api_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sameday_services (
  service_id INT NOT NULL PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  raw_json JSON NOT NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_sameday_services_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sameday_pickup_points (
  pickup_point_id INT NOT NULL PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  address VARCHAR(500) NOT NULL DEFAULT '',
  city VARCHAR(120) NOT NULL DEFAULT '',
  county VARCHAR(120) NOT NULL DEFAULT '',
  postal_code VARCHAR(30) NOT NULL DEFAULT '',
  contact_person_id INT NULL,
  raw_json JSON NOT NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_sameday_pickup_location (county, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sameday_ooh_locations (
  ooh_id BIGINT NOT NULL PRIMARY KEY,
  ooh_type TINYINT NOT NULL DEFAULT 0,
  name VARCHAR(200) NOT NULL,
  country VARCHAR(100) NOT NULL DEFAULT 'Romania',
  county VARCHAR(120) NOT NULL DEFAULT '',
  city VARCHAR(120) NOT NULL DEFAULT '',
  address VARCHAR(500) NOT NULL DEFAULT '',
  postal_code VARCHAR(30) NOT NULL DEFAULT '',
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  supported_payment TINYINT(1) NOT NULL DEFAULT 0,
  client_visible TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  raw_json JSON NOT NULL,
  synced_at DATETIME NOT NULL,
  KEY idx_sameday_ooh_search (active, ooh_type, county, city),
  KEY idx_sameday_ooh_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
