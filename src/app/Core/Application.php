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
    private const SCHEMA_VERSION = '2026-11-04-1';

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

        // MR-01: start the session through the hardened entry point FIRST, so
        // the very first session_start() of any request carries the hardened
        // cookie params (HttpOnly / SameSite=Lax / Secure). Previously the CSP
        // path (sendSecurityHeaders -> CspNonce::token -> generate) triggered a
        // bare session_start(), which bypassed the hardening because
        // session_set_cookie_params must run before session_start() on the
        // first call. Session::start() is idempotent (static $started gate).
        \App\Core\Session::start();

        // v1.2.1 security: baseline security headers on every response.
        // CSP uses nonce for script-src and style-src (see CspNonce).
        // v2.0.0-beta.8: font-src 放行 sf3-scmcdn-cn.feishucdn.com —— miaoda.feishu.cn 的
        // css2 入口返回的 @font-face 实际指向 feishucdn.com 的 woff2 分片（两跳分离），
        // 此前仅放行入口域名导致所有字体被 CSP 拦截（控制台批量报错、回退系统字体）。
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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}' https://miaoda.feishu.cn; font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; img-src {$imgSrc}; connect-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; frame-ancestors 'self'");
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
            // 收敛 images.status 的死 ENUM 值（'hidden' / 'deleted'）+ 补 path 索引。
            $migrationError .= $this->pruneDeadStatusValues($db);
            $migrationError .= $this->ensureImagePathIndex($db);
            // thumb_meta 生成列 + (process_status, thumb_meta) 复合索引。
            $migrationError .= $this->ensureThumbMetaColumn($db);
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

    /**
     * 收敛 images.status 的死 ENUM 值：'hidden' / 'deleted' → 只留 'active'。
     *
     * 这两个值从 beta.7 起到本轮为止从未被任何代码路径写入（删除一律走
     * Image::delete() 物理删除）。保留它们有两个害处：一是让人误以为存在
     * 「隐藏 / 软删」语义，二是 'deleted' 与物理删除重名极易引发误用
     * （置为 deleted 后前台 status='active' 过滤仍会对外可见）。
     *
     * 幂等：已是单值 ENUM 时 SHOW COLUMNS 探测到无 hidden/deleted，直接返回。
     * 安全：若库里真有落在这两个值的行（理论上不可能，但外部直改过数据库则可能），
     * 先把它们改回 'active' 再收敛 ENUM，绝不丢数据、也绝不留下非法值。
     */
    private function pruneDeadStatusValues(\PDO $db): string
    {
        try {
            $col = $db->query("SHOW COLUMNS FROM `images` LIKE 'status'")->fetch(\PDO::FETCH_ASSOC);
            $type = (string) ($col['Type'] ?? '');
            if ($type === '' || stripos($type, 'hidden') === false) {
                return ''; // 已收敛（或列不存在），无需处理。
            }

            // 极端情况：外部直接改过库，确有行落在死值上。先归位为 active，
            // 否则 ALTER 收窄 ENUM 时 MySQL 会把这些行截断/置空。
            $stuck = $db->query(
                "SELECT COUNT(*) FROM `images` WHERE `status` IN ('hidden','deleted')"
            )->fetchColumn();
            if ((int) $stuck > 0) {
                $db->exec("UPDATE `images` SET `status` = 'active' WHERE `status` IN ('hidden','deleted')");
                error_log("[MoeRNG] images.status 发现 {$stuck} 行处于死值，已归位为 active 再收敛 ENUM");
            }

            $db->exec("ALTER TABLE `images` MODIFY COLUMN `status` ENUM('active') NOT NULL DEFAULT 'active'");
            return '';
        } catch (\Throwable $e) {
            // 收窄失败不阻断站点：ENUM 多几个死值只是冗余，不影响任何现有逻辑。
            error_log('[MoeRNG] images.status 死值收敛失败（可忽略）: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 为 images.path 补前缀索引（cleanupStagingFiles 逐文件按 path 反查用）。
     *
     * VARCHAR(512) 在 utf8mb4 下全长索引会超 InnoDB 3072 字节上限，故取前缀 191。
     * 幂等：1061（duplicate key name）视为已存在。
     */
    private function ensureImagePathIndex(\PDO $db): string
    {
        try {
            $db->exec("ALTER TABLE `images` ADD INDEX `idx_path` (`path`(191))");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1061') !== false || stripos($msg, 'duplicate key name') !== false) {
                return '';
            }
            // 大表加索引可能超时/锁表；失败只记录，不阻断站点（查询仍会走全表扫描，
            // 只是慢，功能不受影响）。
            error_log('[MoeRNG] ADD INDEX idx_path 失败（不影响功能）: ' . $msg);
        }
        return '';
    }

    /**
     * 给 images 补 thumb_meta 生成列与 (process_status, thumb_meta) 复合索引。
     *
     * 目的：队列状态卡片的两个派生计数原先是
     *   `process_status='done' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(...)),'pending') = 'pending'`
     * —— 谓词含 JSON_VALID/JSON_EXTRACT，**非 sargable**，且 processing_state 不在
     * 任何索引里，于是每批轮询都要对全部 done 行回表解析 JSON。
     *
     * 用 **VIRTUAL** 而非 STORED：MySQL 8.0 追加 VIRTUAL 生成列是 INSTANT 操作
     * （不重建表、不锁写），而 STORED 会整表重建 —— 本项目是覆盖部署到存量库，
     * 不能假设有停机窗口。VIRTUAL 列的值会物化进索引，覆盖索引扫描照样生效。
     *
     * 全程 best-effort：两步各自 try/catch + 1060/1061 幂等，失败只 error_log。
     * Image::thumbMetaExpr() 探测不到列时会退回原 JSON 表达式，查询结果不变（只是慢），
     * 所以这里的失败绝不会演变成功能不可用。
     */
    private function ensureThumbMetaColumn(\PDO $db): string
    {
        if (!$this->columnExists($db, 'images', 'thumb_meta')) {
            try {
                $db->exec(
                    "ALTER TABLE `images` ADD COLUMN `thumb_meta` VARCHAR(16) "
                    . "GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT("
                    . "IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '$.thumb_meta'"
                    . ")), 'pending')) VIRTUAL"
                );
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1060') === false && stripos($msg, 'duplicate column') === false) {
                    error_log('[MoeRNG] ADD COLUMN images.thumb_meta 失败（退回 JSON 表达式，功能不受影响）: ' . $msg);
                    return '';
                }
            }
        }

        try {
            $db->exec("ALTER TABLE `images` ADD INDEX `idx_status_thumb_meta` (`process_status`, `thumb_meta`)");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, '1061') === false && stripos($msg, 'duplicate key name') === false) {
                // 索引缺失只是慢（列已在，谓词仍可走 process_status 前缀），不阻断站点。
                error_log('[MoeRNG] ADD INDEX idx_status_thumb_meta 失败（不影响功能）: ' . $msg);
            }
        }
        return '';
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
                "ALTER TABLE `images` ADD COLUMN `storage_profile_id` INT NULL AFTER `storage_provider`, "
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
        // MR-31: never blindly trust the Host header. Prefer the operator's
        // explicit `app.base_url` from config (authoritative). Only derive from
        // HTTP_HOST when it is not configured, and then sanitize the host so an
        // injected `Host: evil.com` / CRLF cannot poison absolute links or the
        // install redirect.
        $configured = trim((string) (Config::get('app.base_url', '') ?? ''));
        if ($configured !== '') {
            $p = parse_url($configured);
            if ($p && !empty($p['host'])) {
                $scheme = !empty($p['scheme']) ? strtolower($p['scheme']) : 'http';
                $host = $p['host'];
                if (!empty($p['port'])) {
                    $host .= ':' . (int) $p['port'];
                }
                return "{$scheme}://{$host}";
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $this->sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return "{$scheme}://{$host}";
    }

    /**
     * MR-31: validate/sanitize a request-supplied Host value. Strips CRLF/NUL,
     * splits an optional port, and rejects anything that is not a sane hostname.
     * Returns 'localhost' for anything suspicious so a bad Host can never be
     * reflected into an absolute URL.
     */
    private function sanitizeHost(string $host): string
    {
        $host = str_replace(["\r", "\n", "\0"], '', $host);
        $host = trim($host);
        if ($host === '') {
            return 'localhost';
        }
        $port = '';
        // IPv6 literal, e.g. [::1]:8080
        if (preg_match('/^(\[[0-9a-fA-F:]+\])(:(\d+))?$/', $host, $m)) {
            $host = $m[1];
            if (!empty($m[3]) && (int) $m[3] > 0 && (int) $m[3] <= 65535) {
                $port = ':' . (int) $m[3];
            }
        } elseif (str_contains($host, ':')) {
            [$h, $p] = explode(':', $host, 2);
            $host = $h;
            if (ctype_digit($p) && (int) $p > 0 && (int) $p <= 65535) {
                $port = ':' . (int) $p;
            }
        }
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $host)) {
            return 'localhost';
        }
        return $host . $port;
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Handle installation redirect FIRST — the settings table may not
        // exist yet on a fresh install, so nothing below may touch it.
        // MR-31: redirect to a SITE-RELATIVE path, never an absolute URL built
        // from the (untrusted) Host header. The browser resolves /install against
        // whatever host it actually contacted, so a spoofed `Host: evil.com` can
        // no longer 302 the operator to an attacker domain.
        if (!$this->installed && !str_starts_with($path, '/install')) {
            $response = new Response();
            $response->redirect('/install');
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
        // MR-32: read the gate from the in-memory settings cache (loaded into
        // Config as `settings.*` by bootstrap->loadSettings) instead of issuing a
        // fresh `SELECT * FROM settings` on EVERY web request. Values are strings,
        // same as Setting::get; default '0' keeps the "disabled" behavior.
        if ((string) Config::get('settings.backup_enabled', '0') !== '1') {
            return;
        }
        $period = (string) Config::get('settings.backup_period', 'daily');
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
