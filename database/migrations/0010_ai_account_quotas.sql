CREATE TABLE IF NOT EXISTS `bf_ai_usage_limits` (
  `user_id` bigint unsigned NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` smallint unsigned NOT NULL DEFAULT 0,
  `last_request_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_ai_usage_window` (`window_started_at`),
  CONSTRAINT `fk_ai_usage_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
