CREATE TABLE IF NOT EXISTS study_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  week_start DATE NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_study_plans_user_week (user_key_hash, week_start),
  INDEX idx_study_plans_user_week (user_key_hash, week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS study_plan_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  subject VARCHAR(80) NOT NULL,
  topic VARCHAR(160) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  question_target SMALLINT UNSIGNED NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  is_completed TINYINT(1) NOT NULL DEFAULT 0,
  source ENUM('manual', 'ai') NOT NULL DEFAULT 'manual',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  INDEX idx_study_plan_tasks_plan_day (plan_id, day_of_week, id),
  CONSTRAINT fk_study_plan_tasks_plan FOREIGN KEY (plan_id) REFERENCES study_plans (id) ON DELETE CASCADE,
  CONSTRAINT chk_study_plan_tasks_day CHECK (day_of_week BETWEEN 1 AND 7),
  CONSTRAINT chk_study_plan_tasks_duration CHECK (duration_minutes BETWEEN 5 AND 720)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
