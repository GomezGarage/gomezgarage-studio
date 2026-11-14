-- Eurosender checkout selection and shipment lifecycle.
-- Back up production first. Import once after pulling the matching code.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS eurosender_service_type VARCHAR(40) NULL AFTER shipping_package,
  ADD COLUMN IF NOT EXISTS eurosender_service_subtype VARCHAR(80) NULL AFTER eurosender_service_type,
  ADD COLUMN IF NOT EXISTS eurosender_courier_id INT NULL AFTER eurosender_service_subtype,
  ADD COLUMN IF NOT EXISTS eurosender_order_code VARCHAR(100) NULL AFTER eurosender_courier_id,
  ADD COLUMN IF NOT EXISTS eurosender_status VARCHAR(100) NULL AFTER eurosender_order_code,
  ADD COLUMN IF NOT EXISTS eurosender_label_url TEXT NULL AFTER eurosender_status,
  ADD COLUMN IF NOT EXISTS eurosender_tracking_url TEXT NULL AFTER eurosender_label_url,
  ADD COLUMN IF NOT EXISTS eurosender_error VARCHAR(500) NULL AFTER eurosender_tracking_url,
  ADD COLUMN IF NOT EXISTS eurosender_attempted_at DATETIME NULL AFTER eurosender_error,
  ADD COLUMN IF NOT EXISTS eurosender_created_at DATETIME NULL AFTER eurosender_attempted_at,
  ADD COLUMN IF NOT EXISTS eurosender_last_status_at DATETIME NULL AFTER eurosender_created_at;

SET @eurosender_order_index_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'uq_orders_eurosender_code'
);
SET @eurosender_order_index_sql = IF(
  @eurosender_order_index_exists = 0,
  'ALTER TABLE orders ADD UNIQUE KEY uq_orders_eurosender_code (eurosender_order_code)',
  'SELECT 1'
);
PREPARE eurosender_order_index_statement FROM @eurosender_order_index_sql;
EXECUTE eurosender_order_index_statement;
DEALLOCATE PREPARE eurosender_order_index_statement;
