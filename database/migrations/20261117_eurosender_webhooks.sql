-- Deduplicate Eurosender webhook deliveries and make processing retries safe.
-- Import once after deploying the matching PHP files.

CREATE TABLE IF NOT EXISTS eurosender_webhook_events (
  webhook_id VARCHAR(191) NOT NULL PRIMARY KEY,
  event_code VARCHAR(80) NOT NULL,
  order_code VARCHAR(100) NOT NULL DEFAULT '',
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  KEY idx_eurosender_webhook_order (order_code, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
