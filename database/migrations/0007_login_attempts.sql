CREATE TABLE IF NOT EXISTS `bf_login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email_hash` char(64) COLLATE ascii_bin NOT NULL,
  `ip_address` varchar(45) COLLATE ascii_bin NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempt_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_login_attempt_email_time` (`email_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
