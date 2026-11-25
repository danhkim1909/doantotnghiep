-- BrightFuture database schema
-- Canonical MySQL 8 baseline; contains schema only and no application data.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Table: bf_audit_logs
CREATE TABLE IF NOT EXISTS `bf_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint unsigned DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor_created` (`actor_id`,`created_at`),
  KEY `idx_audit_action_created` (`action`,`created_at`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_target` (`target_type`,`target_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_blocks
CREATE TABLE IF NOT EXISTS `bf_blocks` (
  `blocker_id` bigint unsigned NOT NULL,
  `blocked_id` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`blocker_id`,`blocked_id`),
  KEY `blocked_id` (`blocked_id`),
  CONSTRAINT `bf_blocks_ibfk_1` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_blocks_ibfk_2` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_certificates
CREATE TABLE IF NOT EXISTS `bf_certificates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `verification_code` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `issued_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `verification_code` (`verification_code`),
  UNIQUE KEY `uq_certificate_course` (`student_id`,`course_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `bf_certificates_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_certificates_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_chat_groups
CREATE TABLE IF NOT EXISTS `bf_chat_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_id` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `bf_chat_groups_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_chat_members
CREATE TABLE IF NOT EXISTS `bf_chat_members` (
  `group_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`,`user_id`),
  KEY `idx_group_member_user` (`user_id`),
  CONSTRAINT `bf_chat_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `bf_chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_chat_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_direct_messages
CREATE TABLE IF NOT EXISTS `bf_direct_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` bigint unsigned NOT NULL,
  `receiver_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dm_pair` (`sender_id`,`receiver_id`,`id`),
  KEY `idx_dm_received` (`receiver_id`,`sender_id`,`id`),
  CONSTRAINT `bf_direct_messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_direct_messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_email_verifications
CREATE TABLE IF NOT EXISTS `bf_email_verifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_verification_token` (`token_hash`),
  KEY `idx_email_verification_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_friendships
CREATE TABLE IF NOT EXISTS `bf_friendships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_low` bigint unsigned NOT NULL,
  `user_high` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `status` enum('pending','accepted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_friend_pair` (`user_low`,`user_high`),
  KEY `idx_friend_requested` (`requested_by`),
  KEY `user_high` (`user_high`),
  CONSTRAINT `bf_friendships_ibfk_1` FOREIGN KEY (`user_low`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_friendships_ibfk_2` FOREIGN KEY (`user_high`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_friendships_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_group_messages
CREATE TABLE IF NOT EXISTS `bf_group_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint unsigned NOT NULL,
  `sender_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_messages` (`group_id`,`id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `bf_group_messages_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `bf_chat_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_group_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_lesson_discussions
CREATE TABLE IF NOT EXISTS `bf_lesson_discussions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id` bigint unsigned NOT NULL,
  `author_id` bigint unsigned NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_discussion_lesson` (`lesson_id`,`id`),
  KEY `author_id` (`author_id`),
  CONSTRAINT `bf_lesson_discussions_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_lesson_discussions_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_lesson_discussion_replies
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

-- Table: bf_student_notifications
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

-- Table: bf_teacher_notifications
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

-- Table: bf_student_notes
CREATE TABLE IF NOT EXISTS `bf_student_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `lesson_id` bigint unsigned NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note_student_lesson` (`student_id`, `lesson_id`),
  KEY `idx_note_lesson` (`lesson_id`),
  CONSTRAINT `fk_student_note_student`
    FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_note_lesson`
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_login_attempts
CREATE TABLE IF NOT EXISTS `bf_login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ip_address` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempt_ip_time` (`ip_address`,`attempted_at`),
  KEY `idx_login_attempt_email_time` (`email_hash`,`attempted_at`),
  KEY `idx_login_attempt_created` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_meeting_chat
CREATE TABLE IF NOT EXISTS `bf_meeting_chat` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `body` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_meeting_chat` (`room_id`,`id`),
  KEY `idx_meeting_chat_created` (`created_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bf_meeting_chat_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `bf_meeting_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_meeting_chat_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_meeting_participants
CREATE TABLE IF NOT EXISTS `bf_meeting_participants` (
  `room_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `last_seen` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`room_id`,`user_id`),
  KEY `idx_meeting_user` (`user_id`),
  CONSTRAINT `bf_meeting_participants_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `bf_meeting_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_meeting_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_meeting_rooms
CREATE TABLE IF NOT EXISTS `bf_meeting_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` char(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `host_id` bigint unsigned NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('open','ended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_room_host` (`host_id`,`status`),
  CONSTRAINT `bf_meeting_rooms_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_meeting_signals
CREATE TABLE IF NOT EXISTS `bf_meeting_signals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `sender_id` bigint unsigned NOT NULL,
  `receiver_id` bigint unsigned NOT NULL,
  `kind` enum('offer','answer','candidate') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room_signal_receiver` (`room_id`,`receiver_id`,`id`),
  KEY `idx_meeting_signals_created` (`created_at`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `bf_meeting_signals_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `bf_meeting_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_meeting_signals_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_meeting_signals_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_password_resets
CREATE TABLE IF NOT EXISTS `bf_password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_reset_token` (`token_hash`),
  KEY `idx_password_reset_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_password_reset_limits
CREATE TABLE IF NOT EXISTS `bf_password_reset_limits` (
  `scope` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`scope`,`subject_hash`),
  KEY `idx_password_reset_limit_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_totp_backup_codes
CREATE TABLE IF NOT EXISTS `bf_totp_backup_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `code_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_totp_backup_code` (`code_hash`),
  KEY `idx_totp_backup_user` (`user_id`,`used_at`),
  CONSTRAINT `fk_totp_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_totp_secrets
CREATE TABLE IF NOT EXISTS `bf_totp_secrets` (
  `user_id` bigint unsigned NOT NULL,
  `secret_base32` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `enabled_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_totp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_user_reports
CREATE TABLE IF NOT EXISTS `bf_user_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reporter_id` bigint unsigned NOT NULL,
  `reported_id` bigint unsigned NOT NULL,
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('open','resolved','dismissed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `resolution` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_status` (`status`,`created_at`),
  KEY `reporter_id` (`reporter_id`),
  KEY `reported_id` (`reported_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `bf_user_reports_ibfk_1` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_user_reports_ibfk_2` FOREIGN KEY (`reported_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bf_user_reports_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: course_chapters
CREATE TABLE IF NOT EXISTS `course_chapters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `position` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chapters_course_position` (`course_id`,`position`,`id`),
  CONSTRAINT `fk_chapters_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: course_unlock_rules
CREATE TABLE IF NOT EXISTS `course_unlock_rules` (
  `course_id` bigint unsigned NOT NULL,
  `prerequisite_course_id` bigint unsigned NOT NULL,
  `required_test_exercise_id` bigint unsigned NOT NULL,
  `min_score_percent` decimal(5,2) NOT NULL DEFAULT '70.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`course_id`),
  KEY `fk_bf_rule_previous` (`prerequisite_course_id`),
  KEY `fk_bf_rule_test` (`required_test_exercise_id`),
  CONSTRAINT `fk_bf_rule_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bf_rule_previous` FOREIGN KEY (`prerequisite_course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bf_rule_test` FOREIGN KEY (`required_test_exercise_id`) REFERENCES `exercises` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table: courses
CREATE TABLE IF NOT EXISTS `courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `level` enum('beginner','intermediate','advanced') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'beginner',
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_courses_teacher` (`teacher_id`),
  KEY `idx_courses_listing` (`status`,`created_at`),
  KEY `idx_courses_category` (`category`),
  KEY `idx_courses_deleted_updated` (`deleted_at`,`updated_at`),
  CONSTRAINT `fk_courses_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: enrollments
CREATE TABLE IF NOT EXISTS `enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `status` enum('active','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enroll_student_course` (`student_id`,`course_id`),
  KEY `idx_enroll_course_status` (`course_id`,`status`),
  KEY `idx_enroll_student_status` (`student_id`,`status`),
  CONSTRAINT `fk_enroll_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enroll_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: exercise_questions
CREATE TABLE IF NOT EXISTS `exercise_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exercise_id` bigint unsigned NOT NULL,
  `question_text` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `question_type` enum('single_choice','multiple_choice') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single_choice',
  `points` decimal(8,2) NOT NULL DEFAULT '1.00',
  `position` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_questions_exercise_position` (`exercise_id`,`position`,`id`),
  CONSTRAINT `fk_questions_exercise` FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: exercise_submissions
CREATE TABLE IF NOT EXISTS `exercise_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exercise_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `attempt_number` int unsigned NOT NULL DEFAULT '1',
  `submitted_code` longtext COLLATE utf8mb4_unicode_ci,
  `submitted_text` longtext COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('submitted','grading','graded','returned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `score` decimal(8,2) DEFAULT NULL,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `graded_by` bigint unsigned DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submissions_attempt` (`exercise_id`,`student_id`,`attempt_number`),
  KEY `idx_submissions_student_date` (`student_id`,`submitted_at`),
  KEY `idx_submissions_exercise_status` (`exercise_id`,`status`,`submitted_at`),
  KEY `idx_submissions_grader` (`graded_by`),
  CONSTRAINT `fk_submissions_exercise` FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_grader` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: exercises
CREATE TABLE IF NOT EXISTS `exercises` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id` bigint unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` longtext,
  `exercise_type` enum('quiz','coding','file_upload','text') NOT NULL DEFAULT 'text',
  `programming_language` varchar(50) DEFAULT NULL,
  `starter_code` longtext,
  `max_score` decimal(8,2) NOT NULL DEFAULT '10.00',
  `deadline` datetime DEFAULT NULL,
  `max_attempts` int unsigned DEFAULT NULL COMMENT 'NULL = khong gioi han',
  `position` int unsigned NOT NULL DEFAULT '1',
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exercises_lesson_position` (`lesson_id`,`position`,`id`),
  KEY `idx_exercises_status_deadline` (`status`,`deadline`),
  CONSTRAINT `fk_exercises_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table: lesson_progress
CREATE TABLE IF NOT EXISTS `lesson_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `lesson_id` bigint unsigned NOT NULL,
  `status` enum('not_started','in_progress','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `last_position_seconds` int unsigned NOT NULL DEFAULT '0',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_progress_student_lesson` (`student_id`,`lesson_id`),
  KEY `idx_progress_lesson_status` (`lesson_id`,`status`),
  CONSTRAINT `fk_progress_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_progress_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: lesson_resources
CREATE TABLE IF NOT EXISTS `lesson_resources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lesson_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_type` enum('pdf','document','code','link','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'link',
  `resource_url` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resources_lesson` (`lesson_id`),
  CONSTRAINT `fk_resources_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: lessons
CREATE TABLE IF NOT EXISTS `lessons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chapter_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `lesson_type` enum('video','text','code','mixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `video_source` enum('none','youtube','upload') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `video_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_language` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_example` longtext COLLATE utf8mb4_unicode_ci,
  `duration_minutes` int unsigned NOT NULL DEFAULT '0',
  `video_duration_seconds` int unsigned NOT NULL DEFAULT '0',
  `position` int unsigned NOT NULL DEFAULT '1',
  `is_preview` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lessons_chapter_position` (`chapter_id`,`position`,`id`),
  KEY `idx_lessons_status` (`status`),
  CONSTRAINT `fk_lessons_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `course_chapters` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: question_options
CREATE TABLE IF NOT EXISTS `question_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `question_id` bigint unsigned NOT NULL,
  `option_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT '0',
  `position` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_options_question_position` (`question_id`,`position`,`id`),
  CONSTRAINT `fk_options_question` FOREIGN KEY (`question_id`) REFERENCES `exercise_questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: student_profiles
CREATE TABLE IF NOT EXISTS `student_profiles` (
  `user_id` bigint unsigned NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `school` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `learning_goal` text COLLATE utf8mb4_unicode_ci,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_bf_student_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: submission_answers
CREATE TABLE IF NOT EXISTS `submission_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `option_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_question_option` (`submission_id`,`question_id`,`option_id`),
  KEY `idx_answers_question` (`question_id`),
  KEY `idx_answers_option` (`option_id`),
  CONSTRAINT `fk_answers_option` FOREIGN KEY (`option_id`) REFERENCES `question_options` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`) REFERENCES `exercise_questions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_answers_submission` FOREIGN KEY (`submission_id`) REFERENCES `exercise_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: teacher_profiles
CREATE TABLE IF NOT EXISTS `teacher_profiles` (
  `user_id` bigint unsigned NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specialization` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `organization` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `years_experience` tinyint unsigned DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_bf_teacher_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: users
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('student','teacher','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `email_verified_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_created` (`role`,`created_at`),
  KEY `idx_users_deleted_created` (`deleted_at`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_ai_usage_limits
CREATE TABLE IF NOT EXISTS `bf_ai_usage_limits` (
  `user_id` bigint unsigned NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` smallint unsigned NOT NULL DEFAULT '0',
  `last_request_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_ai_usage_window` (`window_started_at`),
  CONSTRAINT `fk_ai_usage_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_rate_limit_windows
CREATE TABLE IF NOT EXISTS `bf_rate_limit_windows` (
  `user_id` bigint unsigned NOT NULL,
  `scope` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_started_at` datetime NOT NULL,
  `request_count` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`,`scope`),
  KEY `idx_rate_limit_window` (`window_started_at`),
  CONSTRAINT `fk_rate_limit_window_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: bf_teacher_applications
CREATE TABLE IF NOT EXISTS `bf_teacher_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `expertise` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci NULL,
  `status` enum('pending', 'approved', 'rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teacher_app_user` (`user_id`),
  KEY `idx_teacher_app_status` (`status`),
  CONSTRAINT `fk_teacher_app_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teacher_app_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View: view_course_statistics
DROP VIEW IF EXISTS `view_course_statistics`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `view_course_statistics` AS
SELECT
  c.id AS course_id,
  c.teacher_id,
  c.title,
  c.status,
  (SELECT COUNT(*) FROM enrollments e
   WHERE e.course_id = c.id AND e.status IN ('active', 'completed')) AS student_count,
  (SELECT COUNT(*) FROM course_chapters ch
   WHERE ch.course_id = c.id) AS chapter_count,
  (SELECT COUNT(*) FROM lessons l
   JOIN course_chapters ch ON ch.id = l.chapter_id
   WHERE ch.course_id = c.id) AS lesson_count,
  (SELECT COUNT(*) FROM exercises ex
   JOIN lessons l ON l.id = ex.lesson_id
   JOIN course_chapters ch ON ch.id = l.chapter_id
   WHERE ch.course_id = c.id) AS exercise_count
FROM courses c;

-- View: view_student_course_progress
DROP VIEW IF EXISTS `view_student_course_progress`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `view_student_course_progress` AS
SELECT
  e.student_id,
  e.course_id,
  COUNT(l.id) AS total_lessons,
  COALESCE(SUM(CASE WHEN l.id IS NOT NULL AND lp.status = 'completed' THEN 1 ELSE 0 END), 0)
    AS completed_lessons,
  CASE
    WHEN COUNT(l.id) = 0 THEN 0.00
    ELSE ROUND(
      100.0 * COALESCE(SUM(CASE WHEN l.id IS NOT NULL AND lp.status = 'completed' THEN 1 ELSE 0 END), 0)
        / COUNT(l.id),
      2
    )
  END AS progress_percent
FROM enrollments e
LEFT JOIN course_chapters ch ON ch.course_id = e.course_id
LEFT JOIN lessons l ON l.chapter_id = ch.id AND l.status = 'published'
LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = e.student_id
GROUP BY e.student_id, e.course_id;

SET FOREIGN_KEY_CHECKS = 1;
