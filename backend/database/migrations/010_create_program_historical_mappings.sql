CREATE TABLE IF NOT EXISTS program_historical_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  current_program_code VARCHAR(32) NOT NULL,
  historical_program_code VARCHAR(32) NOT NULL,
  historical_year SMALLINT UNSIGNED NOT NULL,
  confidence ENUM('high', 'medium', 'low') NOT NULL,
  verification_status ENUM('verified', 'pending', 'rejected') NOT NULL DEFAULT 'pending',
  match_method VARCHAR(100) NOT NULL,
  evidence_json JSON NOT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_program_historical_mapping_current_year (current_program_code, historical_year),
  INDEX idx_program_historical_mapping_historical (historical_program_code, historical_year),
  INDEX idx_program_historical_mapping_lookup (current_program_code, historical_year, verification_status, confidence),
  CONSTRAINT chk_program_historical_mapping_year CHECK (historical_year BETWEEN 2000 AND 2100),
  CONSTRAINT chk_program_historical_mapping_different_codes CHECK (current_program_code <> historical_program_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
