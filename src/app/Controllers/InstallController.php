<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Database;

class InstallController extends Controller
{
    /**
     * GET /install - Step 1: Environment check
     */
    public function index(Request $request): void
    {
        // If already installed, redirect
        if (Config::get('app.installed', false)) {
            $this->redirect('/');
        }

        $checks = $this->checkEnvironment();

        $this->render('install/step1', [
            'checks' => $checks,
            'allPassed' => $this->allChecksPassed($checks),
        ]);
    }

    /**
     * POST /install/step2 - Database config
     */
    public function step2(Request $request): void
    {
        // Security: block reinstall on an already-installed site. Without
        // this, anyone could POST through the wizard and overwrite
        // config/database.php + recreate the admin account (full takeover).
        if (\App\Core\Config::get('app.installed', false)) {
            $this->redirect('/');
        }
        $this->render('install/step2', [
            'db' => [
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'moerng',
                'username' => 'root',
                'password' => '',
            ],
        ]);
    }

    /**
     * POST /install/step3 - Admin account
     */
    public function step3(Request $request): void
    {
        // Security: block reinstall on an already-installed site. Without
        // this, anyone could POST through the wizard and overwrite
        // config/database.php + recreate the admin account (full takeover).
        if (\App\Core\Config::get('app.installed', false)) {
            $this->redirect('/');
        }
        // Save DB config to session
        \App\Core\Session::set('install_db', [
            'host' => $request->input('db_host', '127.0.0.1'),
            'port' => $request->input('db_port', '3306'),
            'database' => $request->input('db_database', ''),
            'username' => $request->input('db_username', ''),
            'password' => $request->input('db_password', ''),
        ]);

        // Test connection
        $dbConfig = \App\Core\Session::get('install_db');
        $connected = Database::test($dbConfig);

        if (!$connected) {
            $this->render('install/step2', [
                'db' => $dbConfig,
                'error' => 'Database connection failed. Please check your credentials.',
            ]);
        }

        $this->render('install/step3', []);
    }

    /**
     * POST /install/step4 - Storage config
     */
    public function step4(Request $request): void
    {
        // Security: block reinstall on an already-installed site. Without
        // this, anyone could POST through the wizard and overwrite
        // config/database.php + recreate the admin account (full takeover).
        if (\App\Core\Config::get('app.installed', false)) {
            $this->redirect('/');
        }
        \App\Core\Session::set('install_admin', [
            'email' => $request->input('email', ''),
            'username' => $request->input('username', ''),
            'password' => $request->input('password', ''),
        ]);

        // Validate
        $admin = \App\Core\Session::get('install_admin');
        if (empty($admin['email']) || empty($admin['username']) || empty($admin['password'])) {
            $this->render('install/step3', [
                'error' => 'All fields are required.',
            ]);
        }

        $this->render('install/step4', [
            'storage' => [
                'driver' => 'local',
                'local_path' => 'public/uploads',
            ],
        ]);
    }

    /**
     * POST /install/complete - Execute installation
     */
    public function complete(Request $request): void
    {
        // Security: block reinstall on an already-installed site. Without
        // this, anyone could POST through the wizard and overwrite
        // config/database.php + recreate the admin account (full takeover).
        if (\App\Core\Config::get('app.installed', false)) {
            $this->redirect('/');
        }
        $dbConfig = \App\Core\Session::get('install_db');
        $adminConfig = \App\Core\Session::get('install_admin');
        $storageDriver = $request->input('storage_driver', 'local');

        try {
            // Connect to database
            Database::init($dbConfig);
            $db = Database::connect();

            // Run migrations
            $this->runMigrations($db);

            // Create admin user (idempotent: re-running the installer on an
            // existing database must not fail on the UNIQUE(email) constraint).
            $stmt = $db->prepare(
                "INSERT INTO users (username, email, password, role, status, created_at) "
                . "VALUES (?, ?, ?, 'admin', 'active', NOW()) "
                . "ON DUPLICATE KEY UPDATE password = VALUES(password), role = 'admin', status = 'active'"
            );
            $stmt->execute([
                $adminConfig['username'],
                $adminConfig['email'],
                password_hash($adminConfig['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            ]);

            // v1.0.35: storage configuration goes STRAIGHT into the
            // storage_profiles table — settings holds site-level keys only.
            // No settings storage_* keys are written during install anymore.
            $storageDefaultProvider = $request->input('storage_default_provider', 'cos');
            $providersPost = $request->input('storage_provider', []);
            $known = ['cos', 'oss', 'aws', 'obs'];
            $providerMap = [];
            if (is_array($providersPost)) {
                foreach ($known as $p) {
                    if (!empty($providersPost[$p]) && is_array($providersPost[$p])) {
                        $providerMap[$p] = [
                            'key'      => trim((string) ($providersPost[$p]['key'] ?? '')),
                            'secret'   => trim((string) ($providersPost[$p]['secret'] ?? '')),
                            'region'   => trim((string) ($providersPost[$p]['region'] ?? '')),
                            'bucket'   => trim((string) ($providersPost[$p]['bucket'] ?? '')),
                            'endpoint' => trim((string) ($providersPost[$p]['endpoint'] ?? '')),
                            'cdn'      => trim((string) ($providersPost[$p]['cdn'] ?? '')),
                        ];
                    }
                }
            }

            $settings = [
                'site_name' => 'MoeRNG',
                'site_slogan' => '随机二次元图片 API 服务',
                'logo_url' => '/assets/logo.png',
                'per_page' => '20',
            ];

            $insertStmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            foreach ($settings as $key => $value) {
                $insertStmt->execute([$key, $value]);
            }

            // Storage profiles (single source of truth) — one row per usable
            // provider; default gets is_default=1; local fallback otherwise.
            $insertProfile = $db->prepare(
                "INSERT INTO storage_profiles (name, driver, provider, config, is_default, enabled, sort_order, created_at) "
                . "VALUES (?, ?, ?, ?, ?, 1, ?, NOW())"
            );
            $labels = \App\Storage\S3Driver::providerList();
            $sort = 1;
            $hasDefault = false;

            if ($storageDriver === 's3') {
                foreach ($known as $p) {
                    $cfg = $providerMap[$p] ?? null;
                    if (!is_array($cfg)) {
                        continue;
                    }
                    $usable = !empty($cfg['key']) && !empty($cfg['secret']) && !empty($cfg['bucket']);
                    if ($p === 'obs') {
                        $usable = $usable && !empty($cfg['endpoint']);
                    } else {
                        $usable = $usable && !empty($cfg['region']);
                    }
                    if (!$usable) {
                        continue;
                    }
                    $isDefault = !$hasDefault && ($p === $storageDefaultProvider || $sort === 1);
                    $insertProfile->execute([
                        '对象存储 · ' . ($labels[$p] ?? strtoupper($p)),
                        's3',
                        $p,
                        json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $isDefault ? 1 : 0,
                        $sort++,
                    ]);
                    if ($isDefault) {
                        $hasDefault = true;
                    }
                }
            }
            if (!$hasDefault) {
                // Local fallback so a fresh install always has a usable profile.
                $insertProfile->execute([
                    '本地存储',
                    'local',
                    '',
                    json_encode(
                        ['path' => $request->input('storage_local_path', 'public/uploads')],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    1,
                    $sort++,
                ]);
            }

            // Write config file
            $configPath = dirname(__DIR__, 2) . '/config/app.php';
            $configData = [
                'installed' => true,
                'base_url' => $this->getBaseUrl(),
            ];
            file_put_contents($configPath, "<?php\n\nreturn " . var_export($configData, true) . ";\n", LOCK_EX);

            // Write database config
            $dbConfigPath = dirname(__DIR__, 2) . '/config/database.php';
            file_put_contents($dbConfigPath, "<?php\n\nreturn " . var_export($dbConfig, true) . ";\n", LOCK_EX);

            // Clear session
            \App\Core\Session::remove('install_db');
            \App\Core\Session::remove('install_admin');

            // Reload config
            Config::reload();

            $this->render('install/complete', [
                'adminUrl' => $this->getBaseUrl() . '/admin',
                'homeUrl' => $this->getBaseUrl() . '/',
            ]);

        } catch (\Throwable $e) {
            $this->render('install/step1', [
                'checks' => $this->checkEnvironment(),
                'allPassed' => true,
                'error' => 'Installation failed: ' . $e->getMessage(),
            ]);
        }
    }

    private function checkEnvironment(): array
    {
        $checks = [];

        // PHP Version
        $checks[] = [
            'name' => 'PHP Version >= 8.4',
            'status' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'current' => PHP_VERSION,
            'required' => '8.4.0',
        ];

        // Extensions
        $requiredExtensions = ['pdo_mysql', 'curl', 'json', 'fileinfo', 'mbstring', 'gd'];
        foreach ($requiredExtensions as $ext) {
            $checks[] = [
                'name' => "Extension: {$ext}",
                'status' => extension_loaded($ext),
                'current' => extension_loaded($ext) ? 'Enabled' : 'Not enabled',
                'required' => 'Enabled',
            ];
        }

        // Directories writable
        $dirs = [
            'config/' => dirname(__DIR__, 2) . '/config',
            'public/uploads/' => dirname(__DIR__, 2) . '/public/uploads',
        ];
        foreach ($dirs as $label => $dir) {
            $writable = is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
            $checks[] = [
                'name' => "Directory writable: {$label}",
                'status' => $writable,
                'current' => $writable ? 'Writable' : 'Not writable',
                'required' => 'Writable',
            ];
        }

        return $checks;
    }

    private function allChecksPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['status']) return false;
        }
        return true;
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        return "{$scheme}://{$host}{$scriptDir}";
    }

    private function runMigrations(\PDO $db): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/schema.sql');
        if ($sql) {
            // Split by semicolons for individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => !empty($s)
            );
            foreach ($statements as $statement) {
                $db->exec($statement);
            }
        }
    }
}
