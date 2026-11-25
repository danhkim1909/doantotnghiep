ALTER TABLE `users`
  ADD COLUMN `email_verified_at` datetime DEFAULT NULL AFTER `updated_at`;

UPDATE `users`
SET `email_verified_at` = COALESCE(`email_verified_at`, created_at);

CREATE TABLE IF NOT EXISTS `bf_email_verifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_verification_token` (`token_hash`),
  KEY `idx_email_verification_user` (`user_id`, `expires_at`),
  CONSTRAINT `fk_email_verification_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
