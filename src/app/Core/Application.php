<?php
declare(strict_types=1);

namespace App\Core;

class Application
{
    /** 自迁移版本戳：与 settings.schema_version 一致则跳过迁移；新增迁移必须递增。 */
    private const SCHEMA_VERSION = '2026-11-28-1';

    private static ?self $instance = null;
    private static bool $cliBooted = false;
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
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        // 必须早于任何 session_start()，否则 HttpOnly/SameSite/Secure 不生效。Session::start() 幂等。
        \App\Core\Session::start();

        // 基础安全头；存储实例加载完成后需带 CDN 域名再发一次（见下）。
        $this->sendSecurityHeaders(false);
        header('X-Robots-Tag: noindex, nofollow');

        date_default_timezone_set('Asia/Shanghai');

        Config::init($this->basePath . '/config');
        Config::load();

        $this->installed = Config::get('app.installed', false);

        if ($this->installed) {
            Database::init(self::databaseConfig());

            self::loadSettings();

            // 版本一致则整体跳过自迁移（否则每次请求几十条 SHOW COLUMNS + 写入）。
            if ((string) Config::get('settings.schema_version', '') !== self::SCHEMA_VERSION) {
                $this->runStorageMigration();
            }

            // 存储配置加载后重发 CSP，带上 CDN/桶域名，否则跨域图片被拦。
            $this->sendSecurityHeaders(true);
        }
    }

    private static function loadSettings(): void
    {
        try {
            $settings = \App\Models\Setting::allAsKeyValue();
            foreach ($settings as $key => $value) {
                Config::set('settings.' . $key, $value);
            }
        } catch (\Throwable) {
        }
    }

    private static function databaseConfig(): array
    {
        return [
            'host' => Config::get('database.host', '127.0.0.1'),
            'port' => Config::get('database.port', 3306),
            'database' => Config::get('database.database', ''),
            'username' => Config::get('database.username', ''),
            'password' => Config::get('database.password', ''),
        ];
    }

    /**
     * CLI 引导：只加载配置、连接参数与站点设置。
     *
     * CLI 脚本不经过 Application::create()，缺了这一步 Database 会拿空配置去连
     * 127.0.0.1:3306（空库名空账号），在 socket-only 或非默认端口的 MySQL 上直接 2002。
     * 不启 session、不发响应头、不跑自迁移 —— 那些属于请求生命周期，脚本要控制自己的副作用。
     */
    public static function bootstrapCli(string $basePath): void
    {
        if (self::$cliBooted) {
            return;
        }
        self::$cliBooted = true;

        date_default_timezone_set('Asia/Shanghai');

        Config::init(rtrim($basePath, '/\\') . '/config');
        Config::load();

        if (!Config::get('app.installed', false)) {
            return;
        }

        Database::init(self::databaseConfig());

        // 队列 worker 会用到 thumb_quality / thumbs_enabled 等设置，不加载就会静默用默认值。
        self::loadSettings();
    }

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
        // 字体样式表与 woff2 分片在飞书 CDN（前台唯一的外部资源）；其余连接一律限本站。
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}' https://miaoda.feishu.cn; font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; img-src {$imgSrc}; connect-src 'self'; frame-ancestors 'self'");
    }

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
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }

    /**
     * 迁移 / 健康检查 / doctor 的唯一来源；columns 值可直接拼在列名后，indexes 是完整的 ADD INDEX 片段。
     *
     * @return array{columns: array<string, array<string,string>>, indexes: array<string, array<string,string>>}
     */
    public static function schemaExpectations(): array
    {
        return [
            'columns' => [
                'images' => [
                    'storage'            => "VARCHAR(16) NOT NULL DEFAULT 'local' AFTER `path`",
                    'storage_provider'   => "VARCHAR(16) NOT NULL DEFAULT '' AFTER `storage`",
                    // 签名 INT（非 UNSIGNED）：须与 storage_profiles.id 一致，否则将来加 FK 报 errno 1215。
                    'storage_profile_id' => "INT NULL AFTER `storage_provider`",
                    'file_sha256'        => "CHAR(64) NULL DEFAULT NULL AFTER `file_size`",
                    'process_status'     => "ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'done' AFTER `status`",
                    'thumb_path'         => "VARCHAR(512) NULL DEFAULT NULL AFTER `process_status`",
                    'process_error'      => "VARCHAR(500) NULL DEFAULT NULL AFTER `thumb_path`",
                    'thumbs'             => "VARCHAR(1200) NULL DEFAULT NULL AFTER `process_error`",
                    'thumb_bytes'        => "VARCHAR(255) NULL DEFAULT NULL AFTER `thumbs`",
                    'processing_state'   => "JSON NULL DEFAULT NULL AFTER `thumb_bytes`",
                    // VIRTUAL 而非 STORED：追加是 INSTANT 操作，不重建表、不锁写。
                    'thumb_meta'         => "VARCHAR(16) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT("
                        . "IF(JSON_VALID(`processing_state`), `processing_state`, NULL), '$.thumb_meta')), 'pending')) VIRTUAL",
                ],
                'users' => [
                    'last_login'       => "DATETIME NULL DEFAULT NULL AFTER `status`",
                    'remember_token'   => "VARCHAR(255) NULL DEFAULT NULL AFTER `last_login`",
                    'remember_expires' => "DATETIME NULL DEFAULT NULL AFTER `remember_token`",
                ],
            ],
            'indexes' => [
                'images' => [
                    'idx_file_sha256'       => "ADD INDEX `idx_file_sha256` (`file_sha256`)",
                    'idx_storage_profile'   => "ADD INDEX `idx_storage_profile` (`storage_profile_id`)",
                    'idx_process_status'    => "ADD INDEX `idx_process_status` (`process_status`)",
                    // VARCHAR(512) 在 utf8mb4 下全长索引会超 InnoDB 3072 字节上限。
                    'idx_path'              => "ADD INDEX `idx_path` (`path`(191))",
                    'idx_status_thumb_meta' => "ADD INDEX `idx_status_thumb_meta` (`process_status`, `thumb_meta`)",
                ],
            ],
        ];
    }

    private function runStorageMigration(): void
    {
        $migrationError = '';
        try {
            $migrationError = self::migrateSchema(\App\Core\Database::getInstance());
        } catch (\Throwable $e) {
            $migrationError = $e->getMessage();
        }

        try {
            if ($migrationError === '') {
                \App\Models\Setting::set('schema_version', self::SCHEMA_VERSION);
                if (Config::get('settings.migration_last_error', '') !== '') {
                    \App\Models\Setting::set('migration_last_error', '');
                }
            } elseif (Config::get('settings.migration_last_error', '') !== $migrationError) {
                // 只在错误变化时写入；失败时不写 schema_version，下次请求会重试迁移。
                \App\Models\Setting::set('migration_last_error', $migrationError);
            }
        } catch (\Throwable) {
        }
    }

    /** 当前架构版本戳（CLI 迁移脚本写入 settings 时用同一份值）。 */
    public static function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * 补齐当前架构所需的全部表 / 列 / 索引，并收敛历史脏值；返回空串表示全部成功。
     * 运行时迁移与 CLI 迁移脚本共用这一处 —— 将来新增迁移只需改这里，脚本自动覆盖。
     */
    public static function migrateSchema(\PDO $db): string
    {
        $errors = [];
        $step = static function (callable $fn) use (&$errors): void {
            try {
                $result = $fn();
                if (is_string($result) && $result !== '') {
                    $errors[] = $result;
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        };

        // 显式 use ($db)：静态闭包不绑定 $this，读代码的人也不必猜捕获规则
        $step(static function () use ($db) { return self::ensureImageColumns($db); });
        $step(static function () use ($db) { return self::ensureStorageProfileTable($db); });
        $step(static function () use ($db) { return self::ensureAuditLogTable($db); });
        $step(static function () use ($db) { return self::ensureContentHashTable($db); });
        $step(static function () use ($db) { return self::ensureStatsTables($db); });
        $step(static function () use ($db) { return self::ensureRateLimitTable($db); });
        $step(static function () use ($db) {
            return self::ensureColumns($db, 'users', self::schemaExpectations()['columns']['users']);
        });
        $step(static function () use ($db) { return self::pruneDeadStatusValues($db); });

        return implode(' | ', $errors);
    }

    private static function ensureImageColumns(\PDO $db): string
    {
        // 上传暂存目录：web 不可达，另加 .htaccess 拒绝直链。
        $incoming = dirname(__DIR__, 2) . '/storage/incoming';
        if (!is_dir($incoming)) {
            @mkdir($incoming, 0755, true);
        }
        // Apache 2.2/2.4 双写：只写 Require all denied 在未加载 mod_authz_core 的 2.2 上不生效。
        $guardFile = $incoming . '/.htaccess';
        $guardBody = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
        if (!is_file($guardFile) || !str_contains((string) @file_get_contents($guardFile), 'mod_authz_core')) {
            @file_put_contents($guardFile, $guardBody);
        }

        $expect = self::schemaExpectations();
        $errors = [];

        $added = 0;
        foreach ($expect['columns']['images'] as $col => $def) {
            if (self::columnExists($db, 'images', $col)) {
                continue;
            }
            try {
                $db->exec("ALTER TABLE `images` ADD COLUMN `{$col}` {$def}");
                $added++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1060') !== false || stripos($msg, 'duplicate column') !== false) {
                    continue;
                }
                $errors[] = "ADD COLUMN `{$col}` failed: {$msg}";
            }
        }

        // 重索引失败只记日志：进 migration_error 会让版本戳永不写入、每次请求重试。
        $logOnly = ['idx_path', 'idx_status_thumb_meta'];
        $existing = [];
        try {
            foreach ($db->query('SHOW INDEX FROM `images`')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $existing[(string) $r['Key_name']] = true;
            }
        } catch (\Throwable) {
            $existing = [];
        }
        foreach ($expect['indexes']['images'] as $name => $ddl) {
            if (isset($existing[$name])) {
                continue;
            }
            try {
                $db->exec("ALTER TABLE `images` " . $ddl);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1061') !== false || stripos($msg, 'duplicate key name') !== false) {
                    continue;
                }
                if (in_array($name, $logOnly, true)) {
                    error_log('[MoeRNG] ADD INDEX ' . $name . ' 失败（不影响功能，仅查询变慢）: ' . $msg);
                    continue;
                }
                $errors[] = "ADD INDEX {$name} failed: {$msg}";
            }
        }

        if ($errors !== []) {
            return implode(' | ', $errors);
        }

        if ($added === 0) {
            return '';
        }

        // 回填存量行，保证换驱动后旧图仍能解析。
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

    /** @param array<string,string> $columns 列名 => 列定义（不含 ADD COLUMN 前缀）。 */
    private static function ensureColumns(\PDO $db, string $table, array $columns): string
    {
        $errors = [];
        foreach ($columns as $col => $def) {
            if (self::columnExists($db, $table, $col)) {
                continue;
            }
            try {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, '1060') !== false || stripos($msg, 'duplicate column') !== false) {
                    continue;
                }
                $errors[] = "ADD COLUMN {$table}.{$col} failed: {$msg}";
            }
        }
        return implode(' | ', $errors);
    }

    /** 收敛 images.status 的死 ENUM 值：'hidden' / 'deleted' → 只留 'active'。 */
    private static function pruneDeadStatusValues(\PDO $db): string
    {
        try {
            $col = $db->query("SHOW COLUMNS FROM `images` LIKE 'status'")->fetch(\PDO::FETCH_ASSOC);
            $type = (string) ($col['Type'] ?? '');
            if ($type === '' || stripos($type, 'hidden') === false) {
                return '';
            }

            // 先把落在死值上的行归位 active，否则收窄 ENUM 会把它们截断/置空。
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
            error_log('[MoeRNG] images.status 死值收敛失败（可忽略）: ' . $e->getMessage());
            return '';
        }
    }

    private static function columnExists(\PDO $db, string $table, string $col): bool
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ensureStorageProfileTable(\PDO $db): void
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

    private static function ensureAuditLogTable(\PDO $db): void
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

    private static function ensureContentHashTable(\PDO $db): void
    {
        \App\Models\ContentHash::ensureTable($db);
    }

    private static function ensureStatsTables(\PDO $db): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `api_stats` (
            `day` DATE PRIMARY KEY,
            `count` INT UNSIGNED NOT NULL DEFAULT 0,
            `fail` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);

        // 老库补 fail 列；ALTER 被拒时降级为只有成功计数，站点不受影响。
        try {
            $hasFail = $db->query("SHOW COLUMNS FROM `api_stats` LIKE 'fail'")->fetch() !== false;
            if (!$hasFail) {
                $db->exec("ALTER TABLE `api_stats` ADD COLUMN `fail` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `count`");
            }
        } catch (\Throwable $e) {
        }

        $sql = "CREATE TABLE IF NOT EXISTS `visit_stats` (
            `day` DATE PRIMARY KEY,
            `count` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
    }

    /** 限流计数表与唯一键：中间件懒建与 CLI 迁移共用，避免两处 DDL 漂移。 */
    public static function ensureRateLimitTable(\PDO $db): string
    {
        try {
            $db->query('SELECT 1 FROM `rate_limits` LIMIT 1');
        } catch (\Throwable) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `rate_limits` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `identifier` VARCHAR(64) NOT NULL,
                    `window_key` VARCHAR(64) NOT NULL,
                    `request_count` INT NOT NULL DEFAULT 0,
                    `expires_at` INT NOT NULL,
                    `created_at` INT NOT NULL,
                    `updated_at` INT NOT NULL,
                    UNIQUE KEY `uq_ident_window` (`identifier`, `window_key`),
                    INDEX `idx_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // 存量表可能只有普通索引：缺唯一键会让 upsert 退化为纯 INSERT（限流失效 + 表膨胀）。
        try {
            foreach ($db->query('SHOW INDEX FROM `rate_limits`')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (($row['Key_name'] ?? '') === 'uq_ident_window' && (int) ($row['Non_unique'] ?? 1) === 0) {
                    return '';
                }
            }
            // 先按 (identifier, window_key) 去重再加键，否则 ADD UNIQUE 必然因重复值失败。
            $db->exec(
                'DELETE t1 FROM `rate_limits` t1 INNER JOIN `rate_limits` t2'
                . ' ON t1.`identifier` = t2.`identifier` AND t1.`window_key` = t2.`window_key`'
                . ' AND t1.`expires_at` < t2.`expires_at`'
            );
            $db->exec('ALTER TABLE `rate_limits` ADD UNIQUE KEY `uq_ident_window` (`identifier`, `window_key`)');
            return '';
        } catch (\Throwable $e) {
            // 1061 = 键已存在（并发下的幂等结果）
            if ((int) ($e->errorInfo[1] ?? 0) === 1061) {
                return '';
            }
            return 'rate_limits 唯一键补齐失败（限流可能退化为弱语义）: ' . $e->getMessage();
        }
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function baseUrl(): string
    {
        // 优先用配置的 base_url；未配置时才从 Host 推导并消毒，防 Host 注入。
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

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = $this->sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return "{$scheme}://{$host}";
    }

    /** 消毒 Host：去 CRLF/NUL、拆端口、校验 hostname；可疑一律回 localhost。 */
    private function sanitizeHost(string $host): string
    {
        $host = str_replace(["\r", "\n", "\0"], '', $host);
        $host = trim($host);
        if ($host === '') {
            return 'localhost';
        }
        $port = '';
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

        // 未安装先跳转（settings 表可能还不存在）；用相对路径防 Host 劫持。
        if (!$this->installed && !str_starts_with($path, '/install')) {
            $response = new Response();
            $response->redirect('/install');
        }

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

        // 按需自动备份：每请求只做一次文件 mtime 检查，锁文件防并发重复执行。
        $this->maybeAutoBackup();

        $this->router->dispatch($method, $path);
    }

    private function maybeAutoBackup(): void
    {
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
        // 上一次尝试没写完状态（备份被 max_execution_time / 网关超时杀掉）：
        // 1 小时内不再重试，否则每个请求都会重新跑一次全库 dump。
        $started = (int) ($state['started'] ?? 0);
        if ($started > 0 && time() - $started < 3600) {
            return;
        }
        // 抢锁：LOCK_EX 失败说明另一个进程正在备份。
        $fh = @fopen($lockFile . '.tmp', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            return;
        }
        // 先落「已开始」再干活：进程被杀时留下时间戳，下一请求不会立刻重试。
        @file_put_contents($lockFile, json_encode(['started' => time(), 'last' => $last]), LOCK_EX);
        [$ok, $msg] = \App\Core\BackupService::create();
        @file_put_contents($lockFile, json_encode(['last' => time(), 'ok' => $ok, 'msg' => $msg]), LOCK_EX);
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
