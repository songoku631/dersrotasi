SET @program_year_unique_exists = (
  SELECT COUNT(*)
  FROM (
    SELECT INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'universities'
      AND NON_UNIQUE = 0
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'program_code,year'
  ) AS matching_unique_indexes
);

SET @add_program_year_unique_sql = IF(
  @program_year_unique_exists = 0,
  'ALTER TABLE universities ADD UNIQUE KEY universities_program_code_year_unique (program_code, year)',
  'SELECT 1'
);
PREPARE add_program_year_unique_statement FROM @add_program_year_unique_sql;
EXECUTE add_program_year_unique_statement;
DEALLOCATE PREPARE add_program_year_unique_statement;

SET @program_code_only_unique_exists = (
  SELECT COUNT(*)
  FROM (
    SELECT INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'universities'
      AND INDEX_NAME = 'universities_program_code_unique'
      AND NON_UNIQUE = 0
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'program_code'
  ) AS matching_legacy_indexes
);

SET @drop_program_code_only_unique_sql = IF(
  @program_code_only_unique_exists > 0,
  'ALTER TABLE universities DROP INDEX universities_program_code_unique',
  'SELECT 1'
);
PREPARE drop_program_code_only_unique_statement FROM @drop_program_code_only_unique_sql;
EXECUTE drop_program_code_only_unique_statement;
DEALLOCATE PREPARE drop_program_code_only_unique_statement;
