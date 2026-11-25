ALTER TABLE `users`
  ADD COLUMN `deleted_at` datetime DEFAULT NULL AFTER `email_verified_at`,
  ADD INDEX `idx_users_deleted_created` (`deleted_at`, `created_at`);

ALTER TABLE `courses`
  ADD COLUMN `deleted_at` datetime DEFAULT NULL AFTER `updated_at`,
  ADD INDEX `idx_courses_deleted_updated` (`deleted_at`, `updated_at`);
