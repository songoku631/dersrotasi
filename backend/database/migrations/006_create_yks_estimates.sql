CREATE TABLE IF NOT EXISTS yks_estimates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  firebase_uid VARCHAR(128) NOT NULL,
  exam_year SMALLINT UNSIGNED NOT NULL,
  score_type ENUM('SAY', 'EA', 'SÖZ', 'DİL', 'TYT') NOT NULL,
  input_data_json JSON NOT NULL,
  calculated_nets_json JSON NOT NULL,
  raw_score DECIMAL(8, 4) NULL,
  placement_score DECIMAL(8, 4) NULL,
  estimated_rank_center INT UNSIGNED NULL,
  estimated_rank_min INT UNSIGNED NULL,
  estimated_rank_max INT UNSIGNED NULL,
  confidence ENUM('high', 'medium', 'low', 'unavailable') NOT NULL DEFAULT 'unavailable',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_yks_estimates_user_created (firebase_uid, created_at),
  INDEX idx_yks_estimates_year_type (exam_year, score_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
