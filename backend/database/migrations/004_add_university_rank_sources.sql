SET @rank_source_name_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'universities'
    AND COLUMN_NAME = 'rank_source_name'
);
SET @rank_source_name_sql = IF(
  @rank_source_name_exists = 0,
  'ALTER TABLE universities ADD COLUMN rank_source_name VARCHAR(255) NULL AFTER base_rank',
  'SELECT 1'
);
PREPARE rank_source_name_statement FROM @rank_source_name_sql;
EXECUTE rank_source_name_statement;
DEALLOCATE PREPARE rank_source_name_statement;

SET @rank_source_url_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'universities'
    AND COLUMN_NAME = 'rank_source_url'
);
SET @rank_source_url_sql = IF(
  @rank_source_url_exists = 0,
  'ALTER TABLE universities ADD COLUMN rank_source_url TEXT NULL AFTER rank_source_name',
  'SELECT 1'
);
PREPARE rank_source_url_statement FROM @rank_source_url_sql;
EXECUTE rank_source_url_statement;
DEALLOCATE PREPARE rank_source_url_statement;

SET @rank_updated_at_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'universities'
    AND COLUMN_NAME = 'rank_updated_at'
);
SET @rank_updated_at_sql = IF(
  @rank_updated_at_exists = 0,
  'ALTER TABLE universities ADD COLUMN rank_updated_at DATETIME NULL AFTER rank_source_url',
  'SELECT 1'
);
PREPARE rank_updated_at_statement FROM @rank_updated_at_sql;
EXECUTE rank_updated_at_statement;
DEALLOCATE PREPARE rank_updated_at_statement;
