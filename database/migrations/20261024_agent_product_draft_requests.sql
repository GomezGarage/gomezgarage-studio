-- API idempotency ledger for synthetic/owner-approved product draft writes.
-- This table contains no customer information and is intentionally not a source of
-- product truth; product drafts remain rows in products with status='draft'.
CREATE TABLE IF NOT EXISTS agent_product_draft_requests (
  idempotency_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  response_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (idempotency_hash),
  KEY idx_agent_product_draft_requests_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
