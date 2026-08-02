-- Storage backends: local disk, remote FTP/FTPS/SFTP servers and S3 buckets,
-- all managed from the admin panel instead of .env alone.

CREATE TABLE IF NOT EXISTS `storage_backends` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36) NOT NULL,
    `name`            VARCHAR(100) NOT NULL,
    -- The slug is what lands in `files`.`disk`; renaming one would orphan files.
    `slug`            VARCHAR(64) NOT NULL,
    `driver`          VARCHAR(16) NOT NULL DEFAULT 'local',
    `host`            VARCHAR(190) NULL,
    `port`            SMALLINT UNSIGNED NULL,
    `username`        VARCHAR(190) NULL,
    -- AES-256-GCM blob (App\Support\Crypto) holding password / private key / secret key.
    `secret`          TEXT NULL,
    `root_path`       VARCHAR(255) NOT NULL DEFAULT '',
    `options`         TEXT NULL,
    `is_default`      TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `status`          VARCHAR(16) NOT NULL DEFAULT 'unknown',
    `last_error`      VARCHAR(500) NULL,
    `last_checked_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `storage_backends_slug_unique` (`slug`),
    UNIQUE KEY `storage_backends_uuid_unique` (`uuid`),
    KEY `storage_backends_default_idx` (`is_default`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slugs are longer than the old driver names.
ALTER TABLE `files` MODIFY `disk` VARCHAR(64) NOT NULL DEFAULT 'local';

-- Versions used to assume the default disk; they now carry their own.
ALTER TABLE `file_versions` ADD `disk` VARCHAR(64) NOT NULL DEFAULT 'local' AFTER `checksum`;

UPDATE `file_versions` v JOIN `files` f ON f.`id` = v.`file_id` SET v.`disk` = f.`disk`;

-- Multipart sessions remember where the assembled file should land.
ALTER TABLE `multipart_uploads` ADD `disk` VARCHAR(64) NULL AFTER `folder_id`;

-- The local disk is always present, and is the default until an admin says otherwise.
INSERT INTO `storage_backends` (`uuid`, `name`, `slug`, `driver`, `root_path`, `is_default`, `is_active`, `status`)
VALUES (UUID(), 'Local disk', 'local', 'local', '', 1, 1, 'unknown');
