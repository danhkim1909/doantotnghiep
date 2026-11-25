CREATE TABLE IF NOT EXISTS `bf_lesson_discussion_replies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `discussion_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_discussion_replies` (`discussion_id`, `id`),
  KEY `idx_discussion_replies_teacher` (`teacher_id`),
  CONSTRAINT `fk_discussion_reply_discussion`
    FOREIGN KEY (`discussion_id`) REFERENCES `bf_lesson_discussions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_discussion_reply_teacher`
    FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bf_student_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `teacher_id` bigint unsigned NOT NULL,
  `lesson_id` bigint unsigned NOT NULL,
  `discussion_id` bigint unsigned NOT NULL,
  `reply_id` bigint unsigned NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_notification_reply` (`reply_id`),
  KEY `idx_student_notifications_inbox` (`student_id`, `is_read`, `id`),
  KEY `idx_student_notifications_teacher` (`teacher_id`),
  KEY `idx_student_notifications_lesson` (`lesson_id`),
  KEY `idx_student_notifications_discussion` (`discussion_id`),
  CONSTRAINT `fk_student_notification_student`
    FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_notification_teacher`
    FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_notification_lesson`
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_notification_discussion`
    FOREIGN KEY (`discussion_id`) REFERENCES `bf_lesson_discussions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_notification_reply`
    FOREIGN KEY (`reply_id`) REFERENCES `bf_lesson_discussion_replies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bf_teacher_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint unsigned NOT NULL,
  `lesson_id` bigint unsigned NOT NULL,
  `discussion_id` bigint unsigned NOT NULL,
  `sender_id` bigint unsigned NOT NULL,
  `kind` enum('question', 'issue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teacher_notification_discussion` (`discussion_id`),
  KEY `idx_teacher_notifications_inbox` (`teacher_id`, `is_read`, `id`),
  KEY `idx_teacher_notifications_sender` (`sender_id`),
  KEY `idx_teacher_notifications_lesson` (`lesson_id`),
  CONSTRAINT `fk_teacher_notification_teacher`
    FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teacher_notification_lesson`
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teacher_notification_discussion`
    FOREIGN KEY (`discussion_id`) REFERENCES `bf_lesson_discussions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teacher_notification_sender`
    FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
