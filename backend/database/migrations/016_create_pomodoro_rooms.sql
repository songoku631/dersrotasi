CREATE TABLE IF NOT EXISTS pomodoro_rooms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_uid VARCHAR(128) NOT NULL,
  name VARCHAR(80) NOT NULL, description VARCHAR(300) NOT NULL DEFAULT '', category VARCHAR(40) NOT NULL,
  visibility ENUM('public','private') NOT NULL DEFAULT 'public', room_code_hash VARCHAR(255) NULL,
  work_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 25, break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  current_phase ENUM('idle','work','break','paused') NOT NULL DEFAULT 'idle', phase_before_pause ENUM('work','break') NULL,
  phase_started_at DATETIME(3) NULL, phase_ends_at DATETIME(3) NULL, remaining_seconds INT UNSIGNED NULL,
  cycle_number INT UNSIGNED NOT NULL DEFAULT 1, voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
  music_enabled TINYINT(1) NOT NULL DEFAULT 0, max_members SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  status ENUM('active','closed') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rooms_discovery (status, visibility, category, created_at), INDEX idx_rooms_owner (owner_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pomodoro_room_members (
  room_id BIGINT UNSIGNED NOT NULL, user_uid VARCHAR(128) NOT NULL, joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, role ENUM('owner','member') NOT NULL DEFAULT 'member',
  status ENUM('active','left','kicked','blocked') NOT NULL DEFAULT 'active', microphone_enabled TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (room_id, user_uid), INDEX idx_presence (room_id, status, last_seen_at), INDEX idx_member_user (user_uid, status),
  CONSTRAINT fk_pomodoro_member_room FOREIGN KEY (room_id) REFERENCES pomodoro_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pomodoro_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, room_id BIGINT UNSIGNED NOT NULL, user_uid VARCHAR(128) NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_accounted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL, focused_seconds INT UNSIGNED NOT NULL DEFAULT 0, completed_cycles INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_sessions_user_day (user_uid, started_at), INDEX idx_sessions_room (room_id, started_at),
  CONSTRAINT fk_pomodoro_session_room FOREIGN KEY (room_id) REFERENCES pomodoro_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pomodoro_music_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, room_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('youtube','spotify') NOT NULL, external_url VARCHAR(500) NOT NULL, external_id VARCHAR(100) NOT NULL,
  title VARCHAR(160) NOT NULL DEFAULT '', added_by_uid VARCHAR(128) NOT NULL, position INT UNSIGNED NOT NULL,
  started_at DATETIME(3) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_music_queue (room_id, position), CONSTRAINT fk_pomodoro_music_room FOREIGN KEY (room_id) REFERENCES pomodoro_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pomodoro_signals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, room_id BIGINT UNSIGNED NOT NULL, sender_uid VARCHAR(128) NOT NULL,
  recipient_uid VARCHAR(128) NOT NULL, signal_type ENUM('offer','answer','ice') NOT NULL, payload JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), INDEX idx_signals_recipient (room_id, recipient_uid, id),
  CONSTRAINT fk_pomodoro_signal_room FOREIGN KEY (room_id) REFERENCES pomodoro_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pomodoro_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, room_id BIGINT UNSIGNED NOT NULL, reporter_uid VARCHAR(128) NOT NULL,
  reported_uid VARCHAR(128) NOT NULL, reason VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reports_room_time (room_id, created_at), CONSTRAINT fk_pomodoro_report_room FOREIGN KEY (room_id) REFERENCES pomodoro_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pomodoro_rate_limits (
  action_key VARCHAR(190) PRIMARY KEY, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
