-- Aggregate product-detail page loads by UTC date. Stores no IP, cookie, account,
-- user-agent, or other visitor-level identifier.
CREATE TABLE IF NOT EXISTS product_views_daily (
  product_id INT NOT NULL,
  view_date DATE NOT NULL,
  view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, view_date),
  KEY idx_product_views_daily_date (view_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
