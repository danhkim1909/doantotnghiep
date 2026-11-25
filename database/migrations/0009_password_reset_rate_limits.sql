CREATE TABLE IF NOT EXISTS `bf_password_reset_limits` (
  `scope` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`scope`, `subject_hash`),
  KEY `idx_password_reset_limit_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
