-- Resume the customer-accounts migration after a partial phpMyAdmin import.
-- Safe to run whether the first account tables were created or not.
-- Back up the database before importing.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS customer_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(200) NOT NULL,
  full_name VARCHAR(200) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  email_order_updates TINYINT(1) NOT NULL DEFAULT 1,
  email_custom_updates TINYINT(1) NOT NULL DEFAULT 1,
  verification_token_hash CHAR(64) NULL,
  verification_expires_at DATETIME NULL,
  reset_token_hash CHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_accounts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_account_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'Home',
  recipient_name VARCHAR(200) NOT NULL,
  phone VARCHAR(50) NOT NULL DEFAULT '',
  street VARCHAR(255) NOT NULL,
  city VARCHAR(120) NOT NULL,
  postal VARCHAR(30) NOT NULL,
  country CHAR(2) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer_addresses_owner (customer_account_id),
  CONSTRAINT fk_customer_addresses_owner FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
  MODIFY status ENUM('pending','paid','processing','printing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending';

SET @ggs_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_account_id'
);
SET @ggs_sql = IF(@ggs_column_exists = 0,
  'ALTER TABLE orders ADD COLUMN customer_account_id INT UNSIGNED NULL',
  'SELECT 1');
PREPARE ggs_stmt FROM @ggs_sql;
EXECUTE ggs_stmt;
DEALLOCATE PREPARE ggs_stmt;

SET @ggs_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_customer_account'
);
SET @ggs_sql = IF(@ggs_index_exists = 0,
  'ALTER TABLE orders ADD KEY idx_orders_customer_account (customer_account_id)',
  'SELECT 1');
PREPARE ggs_stmt FROM @ggs_sql;
EXECUTE ggs_stmt;
DEALLOCATE PREPARE ggs_stmt;

SET @ggs_constraint_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_customer_account'
);
SET @ggs_sql = IF(@ggs_constraint_exists = 0,
  'ALTER TABLE orders ADD CONSTRAINT fk_orders_customer_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE ggs_stmt FROM @ggs_sql;
EXECUTE ggs_stmt;
DEALLOCATE PREPARE ggs_stmt;

CREATE TABLE IF NOT EXISTS order_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  status VARCHAR(30) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_by_admin_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_status_history_order (order_id, created_at),
  CONSTRAINT fk_order_status_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO order_status_history (order_id, status, note, created_at)
SELECT orders.id, orders.status, 'Order status when customer tracking was enabled', orders.created_at
FROM orders
WHERE NOT EXISTS (
  SELECT 1 FROM order_status_history history
  WHERE history.order_id = orders.id
    AND history.note = 'Order status when customer tracking was enabled'
);

CREATE TABLE IF NOT EXISTS custom_inquiries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inquiry_number VARCHAR(24) NOT NULL,
  customer_account_id INT UNSIGNED NULL,
  customer_name VARCHAR(200) NOT NULL,
  customer_email VARCHAR(200) NOT NULL,
  need_text TEXT NOT NULL,
  budget VARCHAR(40) NOT NULL DEFAULT '',
  attachment_names JSON NULL,
  status ENUM('received','reviewing','needs_info','quoted','accepted','in_progress','completed','declined') NOT NULL DEFAULT 'received',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_custom_inquiries_number (inquiry_number),
  KEY idx_custom_inquiries_owner (customer_account_id),
  KEY idx_custom_inquiries_email (customer_email),
  CONSTRAINT fk_custom_inquiries_owner FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_inquiry_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inquiry_id INT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_by_admin_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_custom_inquiry_history_item (inquiry_id, created_at),
  CONSTRAINT fk_custom_inquiry_history_item FOREIGN KEY (inquiry_id) REFERENCES custom_inquiries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
