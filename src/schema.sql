-- MoeRNG Database Schema (MySQL 8.0+)

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
    -- 只存 key 的 SHA-256；key_prefix 仅用于后台识别
    `key_hash` CHAR(64) NOT NULL UNIQUE,
    `key_prefix` VARCHAR(24) NOT NULL DEFAULT '',
    -- 预留列：/api/v1 全部只读，没有鉴权执行点；后台已移除权限勾选，保留列以免破坏存量库
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
    -- signed INT，须与 storage_profiles.id 同符号（否则将来加 FK 报 errno 1215）
    `storage_profile_id` INT DEFAULT NULL,
    `url` VARCHAR(1024) DEFAULT '',
    `mime_type` VARCHAR(64) NOT NULL,
    `file_size` BIGINT NOT NULL DEFAULT 0,
    `file_sha256` CHAR(64) NULL DEFAULT NULL,
    `width` INT NOT NULL DEFAULT 0,
    `height` INT NOT NULL DEFAULT 0,
    `category_id` INT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    -- 仅 'active'：删除一律物理删除，不存在软删语义
    `status` ENUM('active') NOT NULL DEFAULT 'active',
    `process_status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'done',
    `thumb_path` VARCHAR(512) NULL DEFAULT NULL,
    `process_error` VARCHAR(500) NULL DEFAULT NULL,
    `thumbs` VARCHAR(1200) NULL DEFAULT NULL,
    `thumb_bytes` VARCHAR(255) NULL DEFAULT NULL,
    `processing_state` JSON NULL DEFAULT NULL,
    -- processing_state.thumb_meta 的投影；VIRTUAL 只为让队列判据走索引，表达式须与
    -- Image::thumbMetaExpr() 的降级分支逐字一致
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
    INDEX `idx_file_sha256` (`file_sha256`),
    INDEX `idx_process_status` (`process_status`),
    -- 队列派生计数（no_thumb / meta_retryable）走覆盖索引，避免回表解析 JSON
    INDEX `idx_status_thumb_meta` (`process_status`, `thumb_meta`),
    -- 按 path 反查入库状态用；utf8mb4 下全长索引超 3072 字节，取前缀 191
    INDEX `idx_path` (`path`(191)),
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 一个实例 = 一个本地目录或一个对象存储桶，上传时按默认/选择命中
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
    -- 运行时 upsert 依赖真实 UNIQUE，缺了会插入重复计数行
    UNIQUE KEY `uq_ident_window` (`identifier`, `window_key`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
