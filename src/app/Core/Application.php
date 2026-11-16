<?php
declare(strict_types=1);

namespace App\Core;

class Application
{
    /** 自迁移版本戳：与 settings.schema_version 一致则跳过迁移；新增迁移必须递增。 */
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
            Database::init([
                'host' => Config::get('database.host', '127.0.0.1'),
                'port' => Config::get('database.port', 3306),
                'database' => Config::get('database.database', ''),
                'username' => Config::get('database.username', ''),
                'password' => Config::get('database.password', ''),
            ]);

            $this->loadSettings();

            // 版本一致则整体跳过自迁移（否则每次请求几十条 SHOW COLUMNS + 写入）。
            if ((string) Config::get('settings.schema_version', '') !== self::SCHEMA_VERSION) {
                $this->runStorageMigration();
            }

            // 存储配置加载后重发 CSP，带上 CDN/桶域名，否则跨域图片被拦。
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
        }
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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}' https://miaoda.feishu.cn; font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; img-src {$imgSrc}; connect-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com; frame-ancestors 'self'");
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
                    'file_hash'          => "CHAR(64) NULL DEFAULT NULL AFTER `file_size`",
                    'file_sha256'        => "CHAR(64) NULL DEFAULT NULL AFTER `file_hash`",
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
                    'idx_file_hash'         => "ADD INDEX `idx_file_hash` (`file_hash`)",
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
            $db = \App\Core\Database::getInstance();
            $migrationError = $this->ensureImageColumns($db);
            $this->ensureStorageProfileTable($db);
            $this->ensureAuditLogTable($db);
            $this->ensureStatsTables($db);
            $exp = self::schemaExpectations();
            $userErr = $this->ensureColumns($db, 'users', $exp['columns']['users']);
            if ($userErr !== '') {
                $migrationError .= ($migrationError !== '' ? ' | ' : '') . $userErr;
            }
            $migrationError .= $this->pruneDeadStatusValues($db);
        } catch (\Throwable $e) {
            $migrationError = $migrationError !== ''
                ? $migrationError . ' | ' . $e->getMessage()
                : $e->getMessage();
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

    private function ensureImageColumns(\PDO $db): string
    {
        // 上传暂存目录：web 不可达，另加 .htaccess 拒绝直链。
        $incoming = dirname(__DIR__, 2) . '/storage/incoming';
        if (!is_dir($incoming)) {
            @mkdir($incoming, 0755, true);
        }
        if (!is_file($incoming . '/.htaccess')) {
            @file_put_contents($incoming . '/.htaccess', "Require all denied\n");
        }

        $expect = self::schemaExpectations();
        $errors = [];

        $added = 0;
        foreach ($expect['columns']['images'] as $col => $def) {
            if ($this->columnExists($db, 'images', $col)) {
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
    private function ensureColumns(\PDO $db, string $table, array $columns): string
    {
        $errors = [];
        foreach ($columns as $col => $def) {
            if ($this->columnExists($db, $table, $col)) {
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
    private function pruneDeadStatusValues(\PDO $db): string
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

    private function columnExists(\PDO $db, string $table, string $col): bool
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

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

    private function ensureStatsTables(\PDO $db): void
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

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
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
        // 抢锁：LOCK_EX 失败说明另一个进程正在备份。
        $fh = @fopen($lockFile . '.tmp', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            return;
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
