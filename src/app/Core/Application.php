<?php
declare(strict_types=1);

namespace App\Core;

class Application
{
    /**
     * v1.4.0-beta.2: 自迁移版本戳 —— settings 表里的 schema_version 与它一致时
     * 完全跳过 runStorageMigration()（每请求几十条 SHOW COLUMNS + 2 次写入）。
     *
     * **新增迁移时务必递增此值**，否则老站点不会执行新迁移。
     */
    private const SCHEMA_VERSION = '2026-09-28-2';

    private static ?self $instance = null;
    private Router $router;
    private bool $installed = false;
    private string $basePath;
    private array $config = [];

    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->router = new Router();
        $this->bootstrap();
    }

    public static function create(string $basePath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath);
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    private function bootstrap(): void
    {
        // Error reporting
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        // v1.2.1 security: baseline security headers on every response.
        // CSP uses nonce for script-src and style-src (see CspNonce).
        // Inline theme JS and <style> blocks carry the nonce so they are
        // allowed while the CSP otherwise blocks all untrusted scripts/styles.
        // Re-sent after DB load with storage CDN domains (see below).
        $this->sendSecurityHeaders(false);
        header('X-Robots-Tag: noindex, nofollow');

        // Timezone
        date_default_timezone_set('Asia/Shanghai');

        // Load config
        Config::init($this->basePath . '/config');
        Config::load();

        // Check if installed
        $this->installed = Config::get('app.installed', false);

        if ($this->installed) {
            // Connect database
            Database::init([
                'host' => Config::get('database.host', '127.0.0.1'),
                'port' => Config::get('database.port', 3306),
                'database' => Config::get('database.database', ''),
                'username' => Config::get('database.username', ''),
                'password' => Config::get('database.password', ''),
            ]);

            // Load settings into config
            $this->loadSettings();

            // v1.4.0-beta.2 性能修复: 自迁移只在 schema 版本落后时执行。
            //
            // 此前 runStorageMigration() 每请求无条件执行 —— 9 个 ensure* 里各自
            // 做 SHOW COLUMNS / SHOW TABLES 探测（合计几十条查询），成功路径还会
            // 无条件写 2 条 settings（每次请求 2 次写入！）。这是全站服务端延迟的
            // 主要来源，也白白消耗写入配额。
            //
            // 门禁语义：版本一致 → 完全跳过（零查询零写入）；不一致/缺失（老站点、
            // 升级后首次请求、settings 表刚建）→ 照常执行迁移并补写新版本号。
            if ((string) Config::get('settings.schema_version', '') !== self::SCHEMA_VERSION) {
                $this->runStorageMigration();
            }

            // v1.2.1: storage profiles are loaded — re-send CSP with the
            // CDN/bucket hosts so cross-origin object-stored images load.
            $this->sendSecurityHeaders(true);
        }
    }

    private function loadSettings(): void
    {
        try {
            $settings = \App\Models\Setting::allAsKeyValue();
            foreach ($settings as $key => $value) {
                Config::set('settings.' . $key, $value);
            }
        } catch (\Throwable) {
            // Settings table may not exist yet
        }
    }

    /**
     * v1.2.1: send baseline security headers. When $withStorageDomains is
     * true (DB already connected) the CSP img-src is extended with every
     * storage profile's CDN / bucket host, otherwise cross-origin images
     * (COS/OSS/AWS …) are blocked by 'self' — the site then fails to load
     * object-stored images even though the URLs themselves work in a browser
     * (the browser bypasses CSP when opening the link directly).
     */
    private function sendSecurityHeaders(bool $withStorageDomains): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        $imgSrc = "'self' data: blob:";
        if ($withStorageDomains) {
            $hosts = $this->storageImageHosts();
            foreach ($hosts as $h) {
                $imgSrc .= ' https://' . $h;
            }
        }
        $nonce = \App\Core\CspNonce::token();
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src {$imgSrc}; connect-src 'self'; frame-ancestors 'self'");
    }

    /**
     * Collect every host the storage layer may actually serve images from.
     *
     * Instead of guessing provider-specific default domains, each enabled
     * storage profile is resolved to its real driver and asked for a probe
     * URL (driver->url() is pure local computation — signing or CDN prefix,
     * no network I/O). The host of that real URL is what gets whitelisted:
     *   - profile with a CDN  → CDN host (that is where images will load from)
     *   - object storage      → the bucket host the SDK actually signs
     *   - local without CDN   → relative /files… URL → no host → same-origin
     * Best-effort: any DB/driver error just yields an empty list (base CSP
     * stays), so a broken profile can never take the site down.
     */
    private function storageImageHosts(): array
    {
        $hosts = [];
        try {
            $profiles = \App\Models\StorageProfile::all('sort_order ASC, id ASC');
        } catch (\Throwable) {
            return [];
        }
        foreach ($profiles as $profile) {
            if (!$profile->isEnabled()) {
                continue;
            }
            try {
                $url = $profile->driver()->url('__csp_probe__.png');
            } catch (\Throwable) {
                continue; // SDK missing / profile unusable — skip, never fatal
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }

    /**
     * One-time backfills that must run after a code upgrade:
     *
     *  1. Per-image storage columns (v1.0.13) so switching the global driver
     *     never orphans previously uploaded images.
     *  2. Storage profiles table + images.storage_profile_id (v1.0.33).
     *     v2.0.0-beta.1: 旧凭据迁移（storage_s3_* → profiles）已随「取消兼容与迁移」
     *     整体移除 —— `storage_profiles` 是唯一配置来源。
     *
     * On a typical hosted MySQL the application DB user may LACK the ALTER
     * privilege, so the column backfill can legitimately fail. When it does we
     * record the exact error in `migration_last_error` (surfaced by doctor.php)
     * instead of swallowing it, so the operator gets the manual SQL they need
     * rather than a silent "every upload fails".
     */
    private function runStorageMigration(): void
    {
        $migrationError = '';
        try {
            $db = \App\Core\Database::getInstance();
            $migrationError = $this->ensureImageColumns($db);
            // v1.0.33: storage profiles (multi-instance storage config).
            $this->ensureStorageProfileTable($db);
            $migrationError .= $this->ensureImageProfileColumn($db);
            // v1.1.0-beta.4: admin operation audit trail.
            $this->ensureAuditLogTable($db);
            // v1.2.0 迭代: daily counters for API calls & site visits.
            $this->ensureStatsTables($db);
            // v1.2.1 UI 深度分析 (UI-10): last_login column on users.
            $migrationError .= $this->ensureUserLastLogin($db);
            // v1.2.1 登录增强: remember-me (auto-login) token columns on users.
            $migrationError .= $this->ensureUserRemember($db);
        } catch (\Throwable $e) {
            $migrationError = $migrationError !== ''
                ? $migrationError . ' | ' . $e->getMessage()
                : $e->getMessage();
        }

        try {
            if ($migrationError === '') {
                // v1.4.0-beta.2 性能: 只写"版本戳"（下一次请求即整体跳过迁移）。
                // 原先成功路径要无条件写 2 条 settings —— 那是每请求 2 次写入。
                \App\Models\Setting::set('schema_version', self::SCHEMA_VERSION);
                \App\Models\Setting::set('migration_images_storage', '1');
                // 仅在值真的变化时清一次历史错误（避免每次迁移都白写一条）
                if (Config::get('settings.migration_last_error', '') !== '') {
                    \App\Models\Setting::set('migration_last_error', '');
                }
            } elseif (Config::get('settings.migration_last_error', '') !== $migrationError) {
                // Expose the failure so doctor.php can tell the user exactly
                // what to fix (e.g. run the ALTER manually with a privileged
                // account). 只在错误内容变化时写入，避免失败状态下每请求都写库。
                // 注意：失败时不写 schema_version → 下次请求仍会重试迁移。
                \App\Models\Setting::set('migration_last_error', $migrationError);
            }
        } catch (\Throwable) {
            // settings table unavailable — nothing more we can record.
        }
    }

    /**
     * Ensure the per-image storage columns exist on the `images` table.
     *
     * Returns '' on success, or a human-readable error string if the columns
     * could not be created (most often an ALTER-permission denial on a hosted
     * MySQL account).
     *
     * Design notes:
     *  - We probe with SHOW COLUMNS (widely permitted, unlike information_schema
     *    which some accounts cannot query) and only ALTER what is missing.
     *  - The ALTER is idempotent: a "duplicate column" (MySQL 1060) is treated
     *    as success.
     *  - We deliberately do NOT gate on a stored completion flag, because a flag
     *    left set by a partial/foreign run would otherwise skip this step
     *    forever while the columns stay missing.
     */
    private function ensureImageColumns(\PDO $db): string
    {
        // Database::getInstance() returns a PDO instance (Database is a static
        // wrapper, never instantiated). An earlier version wrongly type-hinted
        // this parameter as App\Core\Database, which raised a TypeError on every
        // call — caught and swallowed by runStorageMigration, so the columns
        // never got added and every upload silently failed. PDO is the truth.
        $needed = [];
        // v1.3.2-beta.2: 临时存放目录（storage/incoming）—— 上传先落这里，
        // web 不可达（站点根下的 storage/ 不经 public/）；.htaccess 双保险拒绝直链。
        $incoming = dirname(__DIR__, 2) . '/storage/incoming';
        if (!is_dir($incoming)) {
            @mkdir($incoming, 0755, true);
        }
        if (!is_file($incoming . '/.htaccess')) {
            @file_put_contents($incoming . '/.htaccess', "Require all denied\n");
        }

        foreach (['storage', 'storage_provider', 'file_hash', 'file_sha256',
                  'process_status', 'thumb_path', 'process_error', 'thumbs',
                  'thumb_bytes', 'processing_state'] as $col) {
            if (!$this->columnExists($db, 'images', $col)) {
                $needed[] = $col;
            }
        }
        if ($needed === []) {
            return '';
        }

        $defs = [
            'storage'          => "VARCHAR(16) NOT NULL DEFAULT 'local' AFTER `path`",
            'storage_provider' => "VARCHAR(16) NOT NULL DEFAULT '' AFTER `storage`",
            // v1.3.1 迭代: 上传去重 —— MD5 内容哈希（服务端计算，权威）；
            // 旧数据为 NULL，不回填
            'file_hash'        => "CHAR(64) NULL DEFAULT NULL AFTER `file_size`",
            // v1.3.2 迭代: 分层校验 —— MD5 快速初筛 + SHA-256 二次验证。
            // 旧数据为 NULL，由 tools/backfill_file_sha256.php 一次性回填。
            'file_sha256'      => "CHAR(64) NULL DEFAULT NULL AFTER `file_hash`",
            // v1.3.2-beta.2 迭代: 异步图片处理管线 —— 上传落临时目录即成功（pending），
            // 队列Worker 生成缩略图并上传最终存储后置 done；存量图默认 done。
            'process_status'   => "ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'done' AFTER `status`",
            'thumb_path'       => "VARCHAR(512) NULL DEFAULT NULL AFTER `process_status`",
            'process_error'    => "VARCHAR(500) NULL DEFAULT NULL AFTER `thumb_path`",
            // v1.3.2-beta.2 迭代: 多尺寸缩略图映射（JSON: 尺寸 => 存储 key）。
            // md 仍写 thumb_path（兼容），本列存 sm/lg 等附加尺寸。
            'thumbs'           => "VARCHAR(1200) NULL DEFAULT NULL AFTER `process_error`",
            // v1.5.0-beta.3 迭代: 各档缩略图实测字节数（JSON: 尺寸 => bytes）+ 按处理项
            // 的补全状态（JSON: 处理项 => pending/partial/failed/ok/skipped）。
            // 2026-09-23 补入自迁移清单（此前只在 schema.sql / 健康检查修复清单里，
            // 老站点升级后这两列缺失 → 队列统计直接 500，见 docs/audit/2026-09-23）。
            'thumb_bytes'      => "VARCHAR(255) NULL DEFAULT NULL AFTER `thumbs`",
            'processing_state' => "JSON NULL DEFAULT NULL AFTER `thumb_bytes`",
        ];

        $errors = [];
        foreach ($needed as $col) {
            try {
                $db->exec("ALTER TABLE `images` ADD COLUMN `{$col}` {$defs[$col]}");
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // Duplicate column means it already exists — treat as success.
                if (stripos($msg, '1060') !== false || stripos($msg, 'duplicate column') !== false) {
                    continue;
                }
                $errors[] = "ADD COLUMN `{$col}` failed: {$msg}";
            }
        }

        // v1.3.1: 去重查询索引（幂等，1061 duplicate key name 视为已存在）。
        try {
            $db->exec("ALTER TABLE `images` ADD INDEX `idx_file_hash` (`file_hash`)");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1061') === false && stripos($msg, 'duplicate key name') === false) {
                $errors[] = "ADD INDEX idx_file_hash failed: {$msg}";
            }
        }

        // v1.3.2-beta.2: 处理队列状态索引（幂等）。
        try {
            $db->exec("ALTER TABLE `images` ADD INDEX `idx_process_status` (`process_status`)");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1061') === false && stripos($msg, 'duplicate key name') === false) {
                $errors[] = "ADD INDEX idx_process_status failed: {$msg}";
            }
        }

        // v1.3.2: SHA-256 二次验证查询索引（幂等）。
        try {
            $db->exec("ALTER TABLE `images` ADD INDEX `idx_file_sha256` (`file_sha256`)");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1061') === false && stripos($msg, 'duplicate key name') === false) {
                $errors[] = "ADD INDEX idx_file_sha256 failed: {$msg}";
            }
        }

        if ($errors !== []) {
            return implode(' | ', $errors);
        }

        // Backfill existing rows with the default profile's driver/provider so
        // historically-stored files keep resolving after a driver switch.
        // v2.0.0-beta.1: storage_profiles is the single source of truth — the
        // legacy settings keys (settings.storage_driver / storage_s3_provider)
        // are no longer read or written anywhere.
        try {
            $profile = \App\Models\StorageProfile::defaultProfile();
            $driver = $profile !== null ? $profile->driver : 'local';
            $provider = $profile !== null ? (string) $profile->provider : '';
            $db->exec(
                "UPDATE `images` SET `storage` = " . $db->quote($driver)
                . " WHERE `storage` = '' OR `storage` IS NULL"
            );
            if ($provider !== '') {
                $db->exec(
                    "UPDATE `images` SET `storage_provider` = " . $db->quote($provider)
                    . " WHERE `storage` = 's3' AND (`storage_provider` = '' OR `storage_provider` IS NULL)"
                );
            }
        } catch (\Throwable $e) {
            return 'backfill existing rows failed: ' . $e->getMessage();
        }

        return '';
    }

    /**
     * v1.2.1 UI 深度分析 (UI-10): ensure the users.last_login column exists.
     * Idempotent: SHOW COLUMNS probe → ALTER with 1060 swallowed.
     */
    private function ensureUserLastLogin(\PDO $db): string
    {
        try {
            if ($this->columnExists($db, 'users', 'last_login')) {
                return '';
            }
            $db->exec("ALTER TABLE `users` ADD COLUMN `last_login` DATETIME NULL DEFAULT NULL AFTER `status`");
            return '';
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1060') !== false || stripos($msg, 'duplicate column') !== false) {
                return '';
            }
            return 'ADD COLUMN users.last_login failed: ' . $msg;
        }
    }

    /**
     * v1.2.1 登录增强: ensure the users.remember_token + remember_expires columns
     * exist (remember-me / auto-login). Idempotent via columnExists probe.
     * remember_token holds a HASH (not the raw cookie value); remember_expires
     * bounds how long a "记住我" session may auto-login.
     */
    private function ensureUserRemember(\PDO $db): string
    {
        $err = '';
        if (!$this->columnExists($db, 'users', 'remember_token')) {
            try {
                $db->exec("ALTER TABLE `users` ADD COLUMN `remember_token` VARCHAR(255) NULL DEFAULT NULL AFTER `last_login`");
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1060') === false && stripos($msg, 'duplicate column') === false) {
                    $err .= 'ADD COLUMN users.remember_token failed: ' . $msg . ' | ';
                }
            }
        }
        if (!$this->columnExists($db, 'users', 'remember_expires')) {
            try {
                $db->exec("ALTER TABLE `users` ADD COLUMN `remember_expires` DATETIME NULL DEFAULT NULL AFTER `remember_token`");
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1060') === false && stripos($msg, 'duplicate column') === false) {
                    $err .= 'ADD COLUMN users.remember_expires failed: ' . $msg;
                }
            }
        }
        return $err;
    }

    private function columnExists(\PDO $db, string $table, string $col): bool
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            // If we cannot probe (some hosts restrict SHOW), assume missing and
            // let the idempotent ALTER decide; a 1060 (exists) is swallowed.
            return false;
        }
    }

    /**
     * v1.0.33: create the storage_profiles table (multi-instance storage
     * configuration). CREATE TABLE IF NOT EXISTS is idempotent; the probe is
     * only for the log-free fast path on already-migrated installs.
     */
    private function ensureStorageProfileTable(\PDO $db): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `storage_profiles` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
    }

    /**
     * v1.1.0-beta.4: create the audit_logs table (admin operation audit
     * trail). CREATE TABLE IF NOT EXISTS is idempotent — safe on overwrite
     * deploys against an existing database.
     */
    private function ensureAuditLogTable(\PDO $db): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL,
            `username` VARCHAR(64) NOT NULL DEFAULT '',
            `action` VARCHAR(48) NOT NULL,
            `detail` TEXT,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_action` (`action`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
    }

    /**
     * v1.2.0 迭代: daily counters for API call volume & site visits.
     * Lightweight: one row per day per metric, upserted by INSERT..ON
     * DUPLICATE KEY UPDATE. CREATE TABLE IF NOT EXISTS is idempotent.
     */
    private function ensureStatsTables(\PDO $db): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `api_stats` (
            `day` DATE PRIMARY KEY,
            `count` INT UNSIGNED NOT NULL DEFAULT 0,
            `fail` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        
        // v2.0.0-beta.2: 可用性统计需要失败计数列。老库由运行时迁移补齐
        // （SHOW COLUMNS probe → ALTER，吞 duplicate-column）；新库建表已带。
        // ALTER 权限被拒时降级为「只有成功计数」（fail 恒 0），站点不受影响。
        try {
            $colProbe = $db->query("SHOW COLUMNS FROM `api_stats` LIKE 'fail'");
            $hasFail = $colProbe !== false && $colProbe->fetch() !== false;
            if (!$hasFail) {
                $db->exec("ALTER TABLE `api_stats` ADD COLUMN `fail` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `count`");
            }
        } catch (\Throwable $e) {
            // ALTER-permission denial on a hosted account — availability degrades
            // to success-only (fail stays 0); site still works.
        }

        $sql = "CREATE TABLE IF NOT EXISTS `visit_stats` (
            `day` DATE PRIMARY KEY,
            `count` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
    }

    /**
     * v1.0.33: add images.storage_profile_id so every row remembers WHICH
     * storage instance it was uploaded to (multiple COS/OSS/S3 instances are
     * now allowed). Idempotent — a 1060 duplicate column is success.
     */
    private function ensureImageProfileColumn(\PDO $db): string
    {
        if ($this->columnExists($db, 'images', 'storage_profile_id')) {
            return '';
        }
        try {
            $db->exec(
                "ALTER TABLE `images` ADD COLUMN `storage_profile_id` INT UNSIGNED NULL AFTER `storage_provider`, "
                . "ADD INDEX `idx_storage_profile` (`storage_profile_id`)"
            );
            return '';
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1060') !== false || stripos($msg, 'duplicate column') !== false) {
                return '';
            }
            return 'ADD COLUMN storage_profile_id failed: ' . $msg;
        }
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}";
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Handle installation redirect FIRST — the settings table may not
        // exist yet on a fresh install, so nothing below may touch it.
        if (!$this->installed && !str_starts_with($path, '/install')) {
            $response = new Response();
            $response->redirect($this->baseUrl() . '/install');
        }

        // v1.1.0-beta.4: output gzip (settings.gzip_level, 0 = off).
        try {
            $gzipLevel = (int) (\App\Models\Setting::get('gzip_level', '6') ?: '0');
        } catch (\Throwable) {
            $gzipLevel = 0;
        }
        $acceptsGzip = stripos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false;
        if ($gzipLevel > 0 && $acceptsGzip && !headers_sent() && ob_get_level() === 0
            && extension_loaded('zlib') && function_exists('ob_gzhandler')) {
            ob_start('ob_gzhandler');
        }

        // v1.1.0-beta.4: opportunistic auto-backup (settings.backup_enabled +
        // backup_period). Cheap file-mtime check per request; guarded by a lock
        // file so concurrent requests do not double-run a backup.
        $this->maybeAutoBackup();

        // Dispatch
        $this->router->dispatch($method, $path);
    }

    /**
     * v1.1.0-beta.4: run the auto-backup when its period has elapsed.
     * Intervals: daily 24h / weekly 168h / monthly 720h. Uses a lock file to
     * avoid duplicate runs under concurrent traffic.
     */
    private function maybeAutoBackup(): void
    {
        try {
            if (\App\Models\Setting::get('backup_enabled', '0') !== '1') {
                return;
            }
        } catch (\Throwable) {
            return; // settings unavailable (fresh install) — skip silently
        }
        $period = \App\Models\Setting::get('backup_period', 'daily');
        $hours = match ($period) {
            'weekly' => 168,
            'monthly' => 720,
            default => 24,
        };
        $lockDir = dirname(__DIR__, 2) . '/var';
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return;
        }
        $lockFile = $lockDir . '/backup-lock';
        $state = is_file($lockFile)
            ? (array) json_decode((string) file_get_contents($lockFile), true)
            : [];
        $last = (int) ($state['last'] ?? 0);
        if (time() - $last < $hours * 3600) {
            return;
        }
        // Try to claim the lock (atomic-ish via LOCK_EX on a separate handle).
        $fh = @fopen($lockFile . '.tmp', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            return; // another worker is backing up right now
        }
        [$ok, $msg] = \App\Core\BackupService::create();
        @file_put_contents($lockFile, json_encode(['last' => time(), 'ok' => $ok, 'msg' => $msg]));
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($ok && \App\Core\Mailer::enabled()
            && \App\Models\Setting::get('mail_notify_backup', '0') === '1') {
            \App\Core\Mailer::send(
                \App\Models\Setting::get('mail_test_to', ''),
                'MoeRNG 自动备份完成',
                "<p>自动备份完成：{$msg}</p>"
            );
        }
    }
}
