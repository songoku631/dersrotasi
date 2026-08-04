CREATE TABLE IF NOT EXISTS user_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plan_code ENUM('free', 'premium') NOT NULL DEFAULT 'free',
  status ENUM('active', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
  starts_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_subscriptions_user_key_hash (user_key_hash),
  INDEX idx_user_subscriptions_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_daily_usage (
  user_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  usage_date DATE NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  token_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (user_key_hash, usage_date),
  INDEX idx_ai_daily_usage_date (usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_global_daily_usage (
  usage_date DATE NOT NULL,
  token_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  usage_date DATE NOT NULL,
  status ENUM('processing', 'completed', 'failed') NOT NULL,
  reserved_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  actual_tokens INT UNSIGNED NULL,
  response_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_chat_request_identity (user_key_hash, request_id_hash),
  INDEX idx_ai_chat_requests_usage_date (usage_date),
  INDEX idx_ai_chat_requests_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
