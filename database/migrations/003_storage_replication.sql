-- Backup layers: every upload lands on its primary backend, then a background
-- job copies it to each backend marked as a replica, in priority order. When
-- the primary cannot serve an object, downloads fall back to those copies.

-- NULL = not a backup layer. 1 is tried first, then 2, then 3...
ALTER TABLE `storage_backends` ADD `replica_priority` SMALLINT UNSIGNED NULL AFTER `is_active`;

-- One row per (object, layer). Keyed by path rather than file id: paths are
-- unique per blob and cover both current files and archived versions.
CREATE TABLE IF NOT EXISTS `file_replicas` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `storage_path` VARCHAR(500) NOT NULL,
    `disk`         VARCHAR(64) NOT NULL,
    -- pending: waiting to copy; synced: copy verified; failed: gave up after retries.
    `status`       VARCHAR(16) NOT NULL DEFAULT 'pending',
    `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error`   VARCHAR(500) NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `synced_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `file_replicas_path_disk_unique` (`storage_path`, `disk`),
    KEY `file_replicas_status_idx` (`status`, `attempts`),
    KEY `file_replicas_disk_idx` (`disk`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
