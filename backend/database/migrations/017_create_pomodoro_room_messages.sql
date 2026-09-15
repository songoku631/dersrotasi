CREATE TABLE IF NOT EXISTS pomodoro_room_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  user_uid VARCHAR(128) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_pomodoro_messages_room_cursor (room_id, id),
  CONSTRAINT fk_pomodoro_message_room
    FOREIGN KEY (room_id) REFERENCES pomodoro_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
