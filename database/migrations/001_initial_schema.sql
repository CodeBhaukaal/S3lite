-- ---------------------------------------------------------------------------
-- Core schema
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(64) NOT NULL,
    `label`       VARCHAR(128) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_system`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(96) NOT NULL,
    `label`      VARCHAR(128) NOT NULL,
    `group_name` VARCHAR(64) NOT NULL DEFAULT 'general',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36) NOT NULL,
    `name`               VARCHAR(120) NOT NULL,
    `email`              VARCHAR(190) NOT NULL,
    `password_hash`      VARCHAR(255) NOT NULL,
    `role_id`            INT UNSIGNED NOT NULL,
    `status`             ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    `quota_bytes`        BIGINT UNSIGNED NOT NULL DEFAULT 10737418240,
    `used_bytes`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `avatar_color`       VARCHAR(16) NOT NULL DEFAULT '#4f7cff',
    `two_factor_secret`  VARCHAR(255) NULL,
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `recovery_codes`     TEXT NULL,
    `email_verified_at`  DATETIME NULL,
    `last_login_at`      DATETIME NULL,
    `last_login_ip`      VARCHAR(45) NULL,
    `failed_attempts`    INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`       DATETIME NULL,
    `settings`           TEXT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`         DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_uuid_unique` (`uuid`),
    UNIQUE KEY `users_email_unique` (`email`),
    KEY `users_role_idx` (`role_id`),
    KEY `users_status_idx` (`status`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `name`          VARCHAR(120) NOT NULL,
    `prefix`        VARCHAR(16) NOT NULL,
    `key_hash`      CHAR(64) NOT NULL,
    `scopes`        TEXT NULL,
    `ip_allowlist`  TEXT NULL,
    `last_used_at`  DATETIME NULL,
    `last_used_ip`  VARCHAR(45) NULL,
    `usage_count`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`    DATETIME NULL,
    `revoked_at`    DATETIME NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `api_keys_uuid_unique` (`uuid`),
    UNIQUE KEY `api_keys_hash_unique` (`key_hash`),
    KEY `api_keys_user_idx` (`user_id`),
    KEY `api_keys_prefix_idx` (`prefix`),
    CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `token_hash`  CHAR(64) NOT NULL,
    `user_agent`  VARCHAR(255) NULL,
    `ip`          VARCHAR(45) NULL,
    `expires_at`  DATETIME NOT NULL,
    `revoked_at`  DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `refresh_tokens_hash_unique` (`token_hash`),
    KEY `refresh_tokens_user_idx` (`user_id`),
    CONSTRAINT `fk_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `folders` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`       CHAR(36) NOT NULL,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `parent_id`  BIGINT UNSIGNED NULL,
    `name`       VARCHAR(255) NOT NULL,
    `path`       VARCHAR(1000) NOT NULL DEFAULT '/',
    `color`      VARCHAR(16) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `folders_uuid_unique` (`uuid`),
    KEY `folders_user_idx` (`user_id`),
    KEY `folders_parent_idx` (`parent_id`),
    KEY `folders_path_idx` (`path`(191)),
    CONSTRAINT `fk_folders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_folders_parent` FOREIGN KEY (`parent_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `files` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36) NOT NULL,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `folder_id`      BIGINT UNSIGNED NULL,
    `name`           VARCHAR(255) NOT NULL,
    `original_name`  VARCHAR(255) NOT NULL,
    `extension`      VARCHAR(32) NOT NULL DEFAULT '',
    `mime`           VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
    `size`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum`       CHAR(64) NOT NULL,
    `disk`           VARCHAR(32) NOT NULL DEFAULT 'local',
    `storage_path`   VARCHAR(500) NOT NULL,
    `version`        INT UNSIGNED NOT NULL DEFAULT 1,
    `is_public`      TINYINT(1) NOT NULL DEFAULT 0,
    `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `tags`           TEXT NULL,
    `meta`           TEXT NULL,
    `source`         VARCHAR(16) NOT NULL DEFAULT 'web',
    `scanned_at`     DATETIME NULL,
    `scan_result`    VARCHAR(32) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `files_uuid_unique` (`uuid`),
    KEY `files_user_idx` (`user_id`),
    KEY `files_folder_idx` (`folder_id`),
    KEY `files_checksum_idx` (`checksum`),
    KEY `files_deleted_idx` (`deleted_at`),
    KEY `files_name_idx` (`name`(100)),
    KEY `files_mime_idx` (`mime`),
    CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_files_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `file_versions` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id`      BIGINT UNSIGNED NOT NULL,
    `version`      INT UNSIGNED NOT NULL,
    `size`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum`     CHAR(64) NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `mime`         VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
    `created_by`   BIGINT UNSIGNED NULL,
    `note`         VARCHAR(255) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `file_versions_unique` (`file_id`, `version`),
    CONSTRAINT `fk_versions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shares` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36) NOT NULL,
    `file_id`         BIGINT UNSIGNED NULL,
    `folder_id`       BIGINT UNSIGNED NULL,
    `user_id`         BIGINT UNSIGNED NOT NULL,
    `token`           VARCHAR(64) NOT NULL,
    `type`            ENUM('permanent','temporary') NOT NULL DEFAULT 'permanent',
    `password_hash`   VARCHAR(255) NULL,
    `expires_at`      DATETIME NULL,
    `max_downloads`   INT UNSIGNED NULL,
    `download_count`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `allow_preview`   TINYINT(1) NOT NULL DEFAULT 1,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `last_accessed_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `shares_uuid_unique` (`uuid`),
    UNIQUE KEY `shares_token_unique` (`token`),
    KEY `shares_file_idx` (`file_id`),
    KEY `shares_folder_idx` (`folder_id`),
    KEY `shares_user_idx` (`user_id`),
    CONSTRAINT `fk_shares_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shares_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shares_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uploads` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NULL,
    `file_id`     BIGINT UNSIGNED NULL,
    `filename`    VARCHAR(255) NOT NULL,
    `size`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `source`      VARCHAR(16) NOT NULL DEFAULT 'web',
    `ip`          VARCHAR(45) NULL,
    `user_agent`  VARCHAR(255) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `uploads_user_idx` (`user_id`),
    KEY `uploads_file_idx` (`file_id`),
    KEY `uploads_created_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `downloads` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id`     BIGINT UNSIGNED NULL,
    `share_id`    BIGINT UNSIGNED NULL,
    `user_id`     BIGINT UNSIGNED NULL,
    `bytes`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `source`      VARCHAR(16) NOT NULL DEFAULT 'web',
    `ip`          VARCHAR(45) NULL,
    `user_agent`  VARCHAR(255) NULL,
    `referer`     VARCHAR(255) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `downloads_file_idx` (`file_id`),
    KEY `downloads_share_idx` (`share_id`),
    KEY `downloads_user_idx` (`user_id`),
    KEY `downloads_created_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `multipart_uploads` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`           CHAR(36) NOT NULL,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `folder_id`      BIGINT UNSIGNED NULL,
    `filename`       VARCHAR(255) NOT NULL,
    `mime`           VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
    `total_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `total_parts`    INT UNSIGNED NOT NULL DEFAULT 0,
    `received_parts` INT UNSIGNED NOT NULL DEFAULT 0,
    `received_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum`       CHAR(64) NULL,
    `status`         ENUM('pending','completed','aborted','failed') NOT NULL DEFAULT 'pending',
    `file_id`        BIGINT UNSIGNED NULL,
    `expires_at`     DATETIME NOT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `multipart_uuid_unique` (`uuid`),
    KEY `multipart_user_idx` (`user_id`),
    KEY `multipart_status_idx` (`status`),
    CONSTRAINT `fk_multipart_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `multipart_parts` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`   BIGINT UNSIGNED NOT NULL,
    `part_number` INT UNSIGNED NOT NULL,
    `size`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum`    CHAR(64) NULL,
    `path`        VARCHAR(500) NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `multipart_parts_unique` (`upload_id`, `part_number`),
    CONSTRAINT `fk_parts_upload` FOREIGN KEY (`upload_id`) REFERENCES `multipart_uploads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NULL,
    `actor_type`  VARCHAR(16) NOT NULL DEFAULT 'user',
    `actor_label` VARCHAR(120) NULL,
    `action`      VARCHAR(64) NOT NULL,
    `entity_type` VARCHAR(48) NULL,
    `entity_id`   VARCHAR(64) NULL,
    `description` VARCHAR(500) NULL,
    `ip`          VARCHAR(45) NULL,
    `user_agent`  VARCHAR(255) NULL,
    `method`      VARCHAR(10) NULL,
    `path`        VARCHAR(255) NULL,
    `status`      VARCHAR(16) NOT NULL DEFAULT 'success',
    `meta`        TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `audit_user_idx` (`user_id`),
    KEY `audit_action_idx` (`action`),
    KEY `audit_created_idx` (`created_at`),
    KEY `audit_entity_idx` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `key_name`   VARCHAR(96) NOT NULL,
    `value`      TEXT NULL,
    `type`       VARCHAR(16) NOT NULL DEFAULT 'string',
    `group_name` VARCHAR(48) NOT NULL DEFAULT 'general',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sftp_accounts` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `username`      VARCHAR(64) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `home_dir`      VARCHAR(500) NOT NULL,
    `protocol`      ENUM('sftp','ftp','ftps') NOT NULL DEFAULT 'sftp',
    `permission`    ENUM('ro','rw') NOT NULL DEFAULT 'rw',
    `quota_bytes`   BIGINT UNSIGNED NOT NULL DEFAULT 1073741824,
    `used_bytes`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `ip_allowlist`  TEXT NULL,
    `last_login_at` DATETIME NULL,
    `last_login_ip` VARCHAR(45) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sftp_uuid_unique` (`uuid`),
    UNIQUE KEY `sftp_username_unique` (`username`),
    KEY `sftp_user_idx` (`user_id`),
    CONSTRAINT `fk_sftp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sftp_keys` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id`  BIGINT UNSIGNED NOT NULL,
    `name`        VARCHAR(120) NOT NULL,
    `public_key`  TEXT NOT NULL,
    `fingerprint` VARCHAR(128) NOT NULL,
    `key_type`    VARCHAR(32) NOT NULL DEFAULT 'ssh-rsa',
    `last_used_at` DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sftp_keys_fp_unique` (`account_id`, `fingerprint`),
    CONSTRAINT `fk_keys_account` FOREIGN KEY (`account_id`) REFERENCES `sftp_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sftp_sessions` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id`       BIGINT UNSIGNED NOT NULL,
    `session_key`      VARCHAR(64) NOT NULL,
    `protocol`         VARCHAR(8) NOT NULL DEFAULT 'sftp',
    `ip`               VARCHAR(45) NULL,
    `client`           VARCHAR(190) NULL,
    `bytes_in`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `bytes_out`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `status`           ENUM('active','closed') NOT NULL DEFAULT 'active',
    `started_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at`         DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sftp_sessions_key_unique` (`session_key`),
    KEY `sftp_sessions_account_idx` (`account_id`),
    KEY `sftp_sessions_status_idx` (`status`),
    CONSTRAINT `fk_sessions_account` FOREIGN KEY (`account_id`) REFERENCES `sftp_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sftp_activity` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `session_id` BIGINT UNSIGNED NULL,
    `action`     VARCHAR(32) NOT NULL,
    `path`       VARCHAR(500) NULL,
    `size`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `status`     VARCHAR(16) NOT NULL DEFAULT 'ok',
    `ip`         VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `sftp_activity_account_idx` (`account_id`),
    KEY `sftp_activity_created_idx` (`created_at`),
    CONSTRAINT `fk_activity_account` FOREIGN KEY (`account_id`) REFERENCES `sftp_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(190) NULL,
    `user_id`    BIGINT UNSIGNED NULL,
    `ip`         VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `success`    TINYINT(1) NOT NULL DEFAULT 0,
    `reason`     VARCHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `login_attempts_email_idx` (`email`),
    KEY `login_attempts_ip_idx` (`ip`),
    KEY `login_attempts_created_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue`        VARCHAR(48) NOT NULL DEFAULT 'default',
    `type`         VARCHAR(64) NOT NULL,
    `payload`      TEXT NULL,
    `status`       ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reserved_at`  DATETIME NULL,
    `completed_at` DATETIME NULL,
    `output`       TEXT NULL,
    `error`        TEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `jobs_status_idx` (`status`),
    KEY `jobs_queue_idx` (`queue`),
    KEY `jobs_available_idx` (`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhooks` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`       CHAR(36) NOT NULL,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `name`       VARCHAR(120) NOT NULL,
    `url`        VARCHAR(500) NOT NULL,
    `events`     TEXT NULL,
    `secret`     VARCHAR(128) NOT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `failures`   INT UNSIGNED NOT NULL DEFAULT 0,
    `last_fired_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `webhooks_uuid_unique` (`uuid`),
    KEY `webhooks_user_idx` (`user_id`),
    CONSTRAINT `fk_webhooks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webhook_id`  BIGINT UNSIGNED NOT NULL,
    `event`       VARCHAR(64) NOT NULL,
    `payload`     TEXT NULL,
    `status_code` INT NULL,
    `response`    TEXT NULL,
    `attempts`    INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `deliveries_webhook_idx` (`webhook_id`),
    CONSTRAINT `fk_deliveries_webhook` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_rules` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`       ENUM('allow','block') NOT NULL DEFAULT 'block',
    `cidr`       VARCHAR(64) NOT NULL,
    `scope`      VARCHAR(32) NOT NULL DEFAULT 'global',
    `note`       VARCHAR(255) NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ip_rules_unique` (`type`, `cidr`, `scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `idempotency_keys` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key_value`   VARCHAR(128) NOT NULL,
    `user_id`     BIGINT UNSIGNED NULL,
    `method`      VARCHAR(10) NOT NULL,
    `path`        VARCHAR(255) NOT NULL,
    `status_code` INT NOT NULL DEFAULT 200,
    `response`    MEDIUMTEXT NULL,
    `expires_at`  DATETIME NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idempotency_unique` (`key_value`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `metrics_samples` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `metric`     VARCHAR(48) NOT NULL,
    `value`      DOUBLE NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `metrics_metric_idx` (`metric`),
    KEY `metrics_created_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `type`       VARCHAR(48) NOT NULL DEFAULT 'info',
    `title`      VARCHAR(190) NOT NULL,
    `body`       VARCHAR(500) NULL,
    `link`       VARCHAR(255) NULL,
    `read_at`    DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `notifications_user_idx` (`user_id`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
