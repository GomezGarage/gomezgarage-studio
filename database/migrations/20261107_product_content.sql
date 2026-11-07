-- Additive and repeatable. Run on the shop database after the GitHub/cPanel pull.
-- Does not change products, prices, stock, orders, invoices or payment settings.
CREATE TABLE IF NOT EXISTS product_content (
  product_id INT NOT NULL PRIMARY KEY,
  content JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
