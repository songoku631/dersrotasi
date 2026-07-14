CREATE TABLE IF NOT EXISTS universities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  program_code VARCHAR(32) NOT NULL,
  university_name VARCHAR(255) NOT NULL,
  faculty_name VARCHAR(255) NOT NULL DEFAULT '',
  department_name VARCHAR(255) NOT NULL,
  city VARCHAR(100) NOT NULL,
  university_type ENUM('devlet', 'vakif', 'kktc', 'yabanci') NOT NULL,
  score_type ENUM('say', 'ea', 'soz', 'dil', 'tyt') NOT NULL,
  education_type ENUM('orgun', 'ikinci_ogretim', 'uzaktan', 'acikogretim', 'diger') NOT NULL,
  education_language VARCHAR(100) NOT NULL DEFAULT '',
  scholarship_type ENUM('ucretsiz', 'burslu', 'yuzde_50', 'yuzde_25', 'ucretli', 'diger') NOT NULL,
  base_score DECIMAL(10, 5) NULL,
  base_rank INT UNSIGNED NULL,
  quota INT UNSIGNED NULL,
  placed_count INT UNSIGNED NULL,
  duration_years TINYINT UNSIGNED NULL,
  year SMALLINT UNSIGNED NOT NULL,
  source_name VARCHAR(255) NOT NULL,
  source_url VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY universities_program_code_unique (program_code),
  INDEX universities_year_index (year),
  INDEX universities_score_type_index (score_type),
  INDEX universities_city_index (city),
  INDEX universities_type_index (university_type),
  INDEX universities_department_index (department_name),
  INDEX universities_name_index (university_name),
  INDEX universities_base_rank_index (base_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  firebase_uid VARCHAR(128) NOT NULL,
  university_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY favorites_user_university_unique (firebase_uid, university_id),
  INDEX favorites_user_index (firebase_uid),
  INDEX favorites_university_index (university_id),
  CONSTRAINT favorites_university_fk
    FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preference_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  firebase_uid VARCHAR(128) NOT NULL,
  university_id BIGINT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY preferences_user_university_unique (firebase_uid, university_id),
  INDEX preferences_user_position_index (firebase_uid, position),
  INDEX preferences_university_index (university_id),
  CONSTRAINT preferences_university_fk
    FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
