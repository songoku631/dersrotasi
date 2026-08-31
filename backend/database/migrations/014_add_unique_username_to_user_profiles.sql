SET @username_column_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'user_profiles'
    AND column_name = 'username'
);
SET @username_column_sql = IF(
  @username_column_exists = 0,
  'ALTER TABLE user_profiles ADD COLUMN username VARCHAR(24) NULL AFTER firebase_uid',
  'SELECT 1'
);
PREPARE username_column_statement FROM @username_column_sql;
EXECUTE username_column_statement;
DEALLOCATE PREPARE username_column_statement;

SET @username_index_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'user_profiles'
    AND index_name = 'user_profiles_username_unique'
);
SET @username_index_sql = IF(
  @username_index_exists = 0,
  'CREATE UNIQUE INDEX user_profiles_username_unique ON user_profiles (username)',
  'SELECT 1'
);
PREPARE username_index_statement FROM @username_index_sql;
EXECUTE username_index_statement;
DEALLOCATE PREPARE username_index_statement;
