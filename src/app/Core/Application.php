<?php
declare(strict_types=1);

namespace App\Core;

class Application
{
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

            // Backfill per-image storage columns (see Image::driverFor).
            $this->runStorageMigration();

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
     *  2. Isolated per-provider credentials (v1.0.14): convert a pre-v1.0.14
     *     single shared S3 credential set into the `storage_providers` JSON
     *     map and set `storage_default_provider`, so every provider keeps its
     *     own Access Key / Secret Key instead of overwriting each other.
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
            $this->migrateProviderCredentials();
            // v1.0.33: storage profiles (multi-instance storage config).
            $this->ensureStorageProfileTable($db);
            $migrationError .= $this->ensureImageProfileColumn($db);
            $this->migrateLegacyToProfiles();
            // v1.1.0-beta.4: admin operation audit trail.
            $this->ensureAuditLogTable($db);
            // v1.2.0 迭代: daily counters for API calls & site visits.
            $this->ensureStatsTables($db);
        } catch (\Throwable $e) {
            $migrationError = $migrationError !== ''
                ? $migrationError . ' | ' . $e->getMessage()
                : $e->getMessage();
        }

        try {
            if ($migrationError === '') {
                \App\Models\Setting::set('migration_images_storage', '1');
                // Clear any stale error from a previous failed run so doctor.php
                // does not keep showing a TypeError that has since been fixed.
                \App\Models\Setting::set('migration_last_error', '');
            } else {
                // Expose the failure so doctor.php can tell the user exactly
                // what to fix (e.g. run the ALTER manually with a privileged
                // account). A missing flag would otherwise retry forever and a
                // stored flag would hide the breakage.
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
        foreach (['storage', 'storage_provider'] as $col) {
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

        if ($errors !== []) {
            return implode(' | ', $errors);
        }

        // Backfill existing rows with the current global driver/provider so
        // historically-stored files keep resolving after a driver switch.
        try {
            $driver = (string) \App\Core\Config::get('settings.storage_driver', 'local');
            $provider = (string) \App\Core\Config::get('settings.storage_s3_provider', 'cos');
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
     * v1.0.14: move from one shared S3 credential set (storage_s3_*) to an
     * isolated per-provider store (storage_providers JSON map). Only runs when
     * the new map is absent and legacy credentials exist, so already-migrated
     * or fresh installs are untouched.
     */
    private function migrateProviderCredentials(): void
    {
        if (\App\Models\Setting::get('migration_storage_providers', '0') === '1') {
            return;
        }

        $raw = \App\Models\Setting::get('storage_providers', '');
        $hasMap = is_string($raw) && $raw !== '';
        if ($hasMap) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $hasMap = is_array($decoded) && $decoded !== [];
            } catch (\Throwable) {
                $hasMap = false;
            }
        }

        if (!$hasMap) {
            $legacyProvider = \App\Models\Setting::get('storage_s3_provider', 'cos');
            $legacyKey = \App\Models\Setting::get('storage_s3_access_key', '');
            $legacySecret = \App\Models\Setting::get('storage_s3_secret_key', '');

            if ($legacyKey !== '' || $legacySecret !== '') {
                $map = [
                    $legacyProvider => [
                        'key'      => $legacyKey,
                        'secret'   => $legacySecret,
                        'region'   => \App\Models\Setting::get('storage_s3_region', ''),
                        'bucket'   => \App\Models\Setting::get('storage_s3_bucket', ''),
                        'endpoint' => \App\Models\Setting::get('storage_s3_endpoint', ''),
                        'cdn'      => \App\Models\Setting::get('cdn_url', ''),
                    ],
                ];
                \App\Models\Setting::set('storage_providers', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                \App\Models\Setting::set('storage_default_provider', $legacyProvider);
            }
        }

        \App\Models\Setting::set('migration_storage_providers', '1');
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
            `count` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);

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

    /**
     * v1.0.33: seed storage_profiles from the legacy settings-store (only
     * when the table is empty). Produces a local profile (always) plus one
     * profile per fully-credentialed object-storage provider, honouring the
     * old master storage_driver / storage_default_provider.
     */
    private function migrateLegacyToProfiles(): void
    {
        $db = \App\Core\Database::getInstance();
        $count = (int) $db->query('SELECT COUNT(*) FROM `storage_profiles`')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $driver = (string) \App\Models\Setting::get('storage_driver', 'local');
        $defaultProvider = (string) \App\Models\Setting::get('storage_default_provider', 'cos');

        $localPath = (string) \App\Models\Setting::get('storage_local_path', '');
        $this->insertProfile($db, [
            'name'       => '本地存储',
            'driver'     => 'local',
            'provider'   => '',
            'config'     => json_encode(['path' => $localPath], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'is_default' => $driver === 'local' ? 1 : 0,
            'enabled'    => 1,
            'sort_order' => 0,
        ]);

        $raw = \App\Models\Setting::get('storage_providers', '');
        $map = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $map = $decoded;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $label = ['cos' => '腾讯云 COS', 'oss' => '阿里云 OSS', 'aws' => 'AWS S3', 'obs' => '华为云 OBS'];
        $order = 1;
        $firstComplete = null;
        foreach (['cos', 'oss', 'aws', 'obs'] as $pid) {
            $cfg = $map[$pid] ?? null;
            if (!is_array($cfg)) {
                continue;
            }
            if (empty($cfg['key']) || empty($cfg['secret']) || empty($cfg['region']) || empty($cfg['bucket'])) {
                continue; // incomplete — not usable, skip
            }
            $this->insertProfile($db, [
                'name'       => $label[$pid] ?? strtoupper($pid),
                'driver'     => 's3',
                'provider'   => $pid,
                'config'     => json_encode([
                    'key'      => (string) ($cfg['key'] ?? ''),
                    'secret'   => (string) ($cfg['secret'] ?? ''),
                    'region'   => (string) ($cfg['region'] ?? ''),
                    'bucket'   => (string) ($cfg['bucket'] ?? ''),
                    'endpoint' => (string) ($cfg['endpoint'] ?? ''),
                    'cdn'      => (string) ($cfg['cdn'] ?? ''),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'is_default' => $driver === 's3' && $pid === $defaultProvider ? 1 : 0,
                'enabled'    => 1,
                'sort_order' => $order++,
            ]);
            $firstComplete ??= $pid;
        }

        // The legacy default provider had no usable creds — promote the first
        // complete one so uploads do not fail with "凭据未完整配置".
        if ($driver === 's3' && !$this->hasDefaultProfile($db)) {
            $db->exec(
                "UPDATE `storage_profiles` SET `is_default` = 1 "
                . "WHERE `driver` = 's3' ORDER BY `sort_order` ASC LIMIT 1"
            );
        }
    }

    private function insertProfile(\PDO $db, array $data): void
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $ph = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $db->prepare("INSERT INTO `storage_profiles` ({$cols}) VALUES ({$ph})");
        // Unique name collisions (e.g. a profile named 本地存储 already seeded
        // by a partial previous run) must not abort the whole migration.
        try {
            $stmt->execute(array_values($data));
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), '1062') === false) {
                throw $e;
            }
        }
    }

    private function hasDefaultProfile(\PDO $db): bool
    {
        return (bool) $db->query('SELECT COUNT(*) FROM `storage_profiles` WHERE `is_default` = 1')->fetchColumn();
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function basePath(): string
    {
        return $this->basePath;
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
