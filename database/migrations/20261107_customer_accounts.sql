-- Customer accounts, order timelines, saved addresses and custom inquiry tracking.
-- Run once on an existing installation after deploying the matching PHP files.
-- Back up the database before importing through phpMyAdmin.

SET NAMES utf8mb4;

CREATE TABLE customer_accounts (
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

CREATE TABLE customer_addresses (
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
  MODIFY status ENUM('pending','paid','processing','printing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN customer_account_id INT UNSIGNED NULL,
  ADD KEY idx_orders_customer_account (customer_account_id),
  ADD CONSTRAINT fk_orders_customer_account FOREIGN KEY (customer_account_id) REFERENCES customer_accounts(id) ON DELETE SET NULL;

CREATE TABLE order_status_history (
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
SELECT id, status, 'Order status when customer tracking was enabled', created_at FROM orders;

CREATE TABLE custom_inquiries (
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

CREATE TABLE custom_inquiry_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inquiry_id INT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_by_admin_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_custom_inquiry_history_item (inquiry_id, created_at),
  CONSTRAINT fk_custom_inquiry_history_item FOREIGN KEY (inquiry_id) REFERENCES custom_inquiries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
