-- MoeRNG Database Schema
-- MySQL 8.0+ required

CREATE TABLE IF NOT EXISTS `settings` (
    `key` VARCHAR(128) NOT NULL PRIMARY KEY,
    `value` TEXT,
    INDEX `idx_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(64) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'editor') NOT NULL DEFAULT 'admin',
    `status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    `last_login` DATETIME NULL DEFAULT NULL,
    `remember_token` VARCHAR(255) NULL DEFAULT NULL,
    `remember_expires` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(128) NOT NULL,
    -- v2.0.0 MR-03: never store the plaintext key. Only its SHA-256 hash is
    -- kept for lookup (same pattern as users.remember_token); key_prefix is
    -- the first 16 chars, shown in admin UIs for identification only.
    `key_hash` CHAR(64) NOT NULL UNIQUE,
    `key_prefix` VARCHAR(24) NOT NULL DEFAULT '',
    `permissions` JSON,
    `rate_limit` INT NOT NULL DEFAULT 60,
    `rate_window` INT NOT NULL DEFAULT 60,
    `status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_key_hash` (`key_hash`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(128) NOT NULL,
    `slug` VARCHAR(128) NOT NULL UNIQUE,
    `description` TEXT,
    `parent_id` INT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `filename` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `path` VARCHAR(512) NOT NULL,
    `storage` VARCHAR(16) NOT NULL DEFAULT 'local',
    `storage_provider` VARCHAR(16) NOT NULL DEFAULT '',
    -- MR-17: SIGNED INT to match storage_profiles.id (INT AUTO_INCREMENT) so the
    -- two sides share the same integer sign (a future FK would otherwise be
    -- rejected with MySQL errno 1215).
    `storage_profile_id` INT DEFAULT NULL,
    `url` VARCHAR(1024) DEFAULT '',
    `mime_type` VARCHAR(64) NOT NULL,
    `file_size` BIGINT NOT NULL DEFAULT 0,
    `file_hash` CHAR(64) NULL DEFAULT NULL,
    `file_sha256` CHAR(64) NULL DEFAULT NULL,
    `width` INT NOT NULL DEFAULT 0,
    `height` INT NOT NULL DEFAULT 0,
    `category_id` INT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    -- 仅 'active'。历史 ENUM 里的 'hidden' / 'deleted' 从未被任何代码路径写入
    -- （删除一律走 Image::delete() 物理删除），故为死值，已移除。
    -- 特别注意：'deleted' 与「软删除」字面相近，极易让人误以为存在软删语义 ——
    -- 若将来要加隐藏/软删功能，必须同时补上写入路径与前台过滤，否则会出现
    -- 「置为 deleted 但前台 status='active' 过滤仍照常对外可见」的数据泄露。
    -- 存量库请用 Application 的运行时自迁移（_pruneDeadStatusValues）收敛。
    `status` ENUM('active') NOT NULL DEFAULT 'active',
    `process_status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'done',
    `thumb_path` VARCHAR(512) NULL DEFAULT NULL,
    `process_error` VARCHAR(500) NULL DEFAULT NULL,
    `thumbs` VARCHAR(1200) NULL DEFAULT NULL,
    `thumb_bytes` VARCHAR(255) NULL DEFAULT NULL,
    `processing_state` JSON NULL DEFAULT NULL,
    -- thumb_meta：processing_state.thumb_meta 的归一化投影（NULL/非法 JSON → 'pending'）。
    -- VIRTUAL（不落盘、不占存储），只为让 thumbMetaPendingSql()/
    -- thumbMetaRetryableSql() 变成可走索引的等值/IN 比较 —— 原表达式含
    -- JSON_VALID/JSON_EXTRACT，非 sargable，队列状态卡片每批都要全表回表。
    -- 表达式必须与 Image::thumbMetaExpr() 的降级分支逐字一致。
    `thumb_meta` VARCHAR(16) GENERATED ALWAYS AS (
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(
            IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '$.thumb_meta'
        )), 'pending')
    ) VIRTUAL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sort` (`sort_order`, `id`),
    INDEX `idx_rand` (`status`, `category_id`),
    INDEX `idx_storage_profile` (`storage_profile_id`),
    INDEX `idx_file_hash` (`file_hash`),
    INDEX `idx_file_sha256` (`file_sha256`),
    INDEX `idx_process_status` (`process_status`),
    -- 队列状态卡片的两个派生计数（no_thumb / meta_retryable）都形如
    -- 「process_status='done' AND thumb_meta IN (...)/= ...」，这条复合索引让它们
    -- 变成覆盖索引扫描，不必回表解析 JSON。
    INDEX `idx_status_thumb_meta` (`process_status`, `thumb_meta`),
    -- cleanupStagingFiles 按 path 反查是否已入库（每个暂存文件查一次），
    -- 无索引时该循环等于 N 次全表扫描。VARCHAR(512) 全长索引在 utf8mb4 下
    -- 会超 InnoDB 3072 字节上限，故取前缀 191（764 字节，足够区分路径）。
    INDEX `idx_path` (`path`(191)),
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Storage profiles: independent, multi-instance storage configuration.
-- Each row is one storage instance (local dir OR one object-storage bucket),
-- configurable by name. Uploads pick a profile (the default, or dynamically).
CREATE TABLE IF NOT EXISTS `storage_profiles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `driver` VARCHAR(16) NOT NULL DEFAULT 'local',
    `provider` VARCHAR(16) NOT NULL DEFAULT '',
    `config` JSON,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_name` (`name`),
    INDEX `idx_driver` (`driver`),
    INDEX `idx_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(64) NOT NULL,
    `window_key` VARCHAR(64) NOT NULL,
    `request_count` INT NOT NULL DEFAULT 0,
    `expires_at` INT NOT NULL,
    `created_at` INT NOT NULL,
    `updated_at` INT NOT NULL,
    INDEX `idx_identifier_window` (`identifier`, `window_key`),
    -- MR-17: one counter row per (identifier, window) — the runtime upsert relied
    -- on ON DUPLICATE KEY; without a true UNIQUE constraint concurrent requests
    -- could insert duplicate counter rows. Text must match the runtime DDL.
    UNIQUE KEY `uq_ident_window` (`identifier`, `window_key`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
