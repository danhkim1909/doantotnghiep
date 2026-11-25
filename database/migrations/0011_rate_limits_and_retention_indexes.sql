CREATE TABLE IF NOT EXISTS `bf_rate_limit_windows` (
  `user_id` bigint unsigned NOT NULL,
  `scope` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `scope`),
  KEY `idx_rate_limit_window` (`window_started_at`),
  CONSTRAINT `fk_rate_limit_window_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `bf_audit_logs`
  ADD KEY `idx_audit_created` (`created_at`);

ALTER TABLE `bf_login_attempts`
  ADD KEY `idx_login_attempt_created` (`attempted_at`);

ALTER TABLE `bf_meeting_chat`
  ADD KEY `idx_meeting_chat_created` (`created_at`);

ALTER TABLE `bf_meeting_signals`
  ADD KEY `idx_meeting_signals_created` (`created_at`);
