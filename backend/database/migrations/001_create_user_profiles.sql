CREATE TABLE IF NOT EXISTS user_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  firebase_uid VARCHAR(128) NOT NULL,
  score_type ENUM('sayisal', 'esit_agirlik', 'sozel', 'dil') NOT NULL DEFAULT 'sayisal',
  target_rank INT UNSIGNED NULL,
  target_department VARCHAR(160) NOT NULL DEFAULT '',
  preferred_cities VARCHAR(255) NOT NULL DEFAULT '',
  university_type ENUM('devlet', 'vakif', 'fark_etmez') NOT NULL DEFAULT 'fark_etmez',
  daily_study_hours DECIMAL(4, 1) NULL,
  strong_lessons TEXT NULL,
  improvement_lessons TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY user_profiles_firebase_uid_unique (firebase_uid),
  INDEX user_profiles_score_type_index (score_type),
  INDEX user_profiles_target_rank_index (target_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
