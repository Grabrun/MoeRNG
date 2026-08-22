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
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(128) NOT NULL,
    `key` VARCHAR(128) NOT NULL UNIQUE,
    `permissions` JSON,
    `rate_limit` INT NOT NULL DEFAULT 60,
    `rate_window` INT NOT NULL DEFAULT 60,
    `status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_key` (`key`),
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
    `storage_profile_id` INT UNSIGNED DEFAULT NULL,
    `url` VARCHAR(1024) DEFAULT '',
    `mime_type` VARCHAR(64) NOT NULL,
    `file_size` BIGINT NOT NULL DEFAULT 0,
    `width` INT NOT NULL DEFAULT 0,
    `height` INT NOT NULL DEFAULT 0,
    `category_id` INT DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `status` ENUM('active', 'hidden', 'deleted') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sort` (`sort_order`, `id`),
    INDEX `idx_rand` (`status`, `category_id`),
    INDEX `idx_storage_profile` (`storage_profile_id`),
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
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
