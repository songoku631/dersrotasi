CREATE TABLE IF NOT EXISTS ai_rate_limits (
  identifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  window_started_at DATETIME(6) NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (identifier_hash),
  INDEX idx_ai_rate_limits_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
