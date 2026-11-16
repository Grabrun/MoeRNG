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
    public function index(Request $request): void
    {
        $this->guardNotInstalled();

        $checks = $this->checkEnvironment();

        $this->render('install/step1', [
            'checks' => $checks,
            'allPassed' => $this->allChecksPassed($checks),
        ]);
    }

    /** 已安装站点禁止重装，否则可覆盖配置并重建管理员（完整接管）。 */
    private function guardNotInstalled(): void
    {
        if (\App\Core\Config::get('app.installed', false)) {
            $this->redirect('/');
        }
    }

    public function showStep2(Request $request): void
    {
        $this->guardNotInstalled();
        $this->render('install/step2', [
            'db' => \App\Core\Session::get('install_db') ?: [
                'host' => '127.0.0.1', 'port' => '3306',
                'database' => '', 'username' => '', 'password' => '',
            ],
            'error' => '',
        ]);
    }

    public function showStep3(Request $request): void
    {
        $this->guardNotInstalled();
        $this->render('install/step3', ['error' => '']);
    }

    public function showStep4(Request $request): void
    {
        $this->guardNotInstalled();
        $this->render('install/step4', [
            'storage' => ['driver' => 'local', 'local_path' => \App\Storage\LocalDriver::defaultRelDir()],
        ]);
    }

    public function step2(Request $request): void
    {
        $this->guardNotInstalled();
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

    public function step3(Request $request): void
    {
        $this->guardNotInstalled();
        \App\Core\Session::set('install_db', [
            'host' => $request->input('db_host', '127.0.0.1'),
            'port' => $request->input('db_port', '3306'),
            'database' => $request->input('db_database', ''),
            'username' => $request->input('db_username', ''),
            'password' => $request->input('db_password', ''),
        ]);

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

    public function step4(Request $request): void
    {
        $this->guardNotInstalled();
        \App\Core\Session::set('install_admin', [
            'email' => $request->input('email', ''),
            'username' => $request->input('username', ''),
            'password' => $request->input('password', ''),
        ]);

        $admin = \App\Core\Session::get('install_admin');
        if (empty($admin['email']) || empty($admin['username']) || empty($admin['password'])) {
            $this->render('install/step3', [
                'error' => 'All fields are required.',
            ]);
        }

        $this->render('install/step4', [
            'storage' => [
                'driver' => 'local',
                'local_path' => \App\Storage\LocalDriver::defaultRelDir(),
            ],
        ]);
    }

    public function complete(Request $request): void
    {
        $this->guardNotInstalled();
        $dbConfig = \App\Core\Session::get('install_db');
        $adminConfig = \App\Core\Session::get('install_admin');
        $storageDriver = $request->input('storage_driver', 'local');

        try {
            Database::init($dbConfig);
            $db = Database::connect();

            $this->runMigrations($db);

            // 已存在库上重跑安装不因 UNIQUE(email) 失败。
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
                $insertProfile->execute([
                    '本地存储',
                    'local',
                    '',
                    json_encode(
                        ['path' => $request->input('storage_local_path', \App\Storage\LocalDriver::defaultRelDir())],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    1,
                    $sort++,
                ]);
            }

            $configPath = dirname(__DIR__, 2) . '/config/app.php';
            $configData = [
                'installed' => true,
                'base_url' => $this->getBaseUrl(),
            ];
            file_put_contents($configPath, "<?php\n\nreturn " . var_export($configData, true) . ";\n", LOCK_EX);

            $dbConfigPath = dirname(__DIR__, 2) . '/config/database.php';
            file_put_contents($dbConfigPath, "<?php\n\nreturn " . var_export($dbConfig, true) . ";\n", LOCK_EX);

            \App\Core\Session::remove('install_db');
            \App\Core\Session::remove('install_admin');

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

        $checks[] = [
            'name' => 'PHP Version >= 8.4',
            'status' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'current' => PHP_VERSION,
            'required' => '8.4.0',
        ];

        $requiredExtensions = ['pdo_mysql', 'curl', 'json', 'fileinfo', 'mbstring', 'gd'];
        foreach ($requiredExtensions as $ext) {
            $checks[] = [
                'name' => "Extension: {$ext}",
                'status' => extension_loaded($ext),
                'current' => extension_loaded($ext) ? 'Enabled' : 'Not enabled',
                'required' => 'Enabled',
            ];
        }

        $dirs = [
            'config/' => dirname(__DIR__, 2) . '/config',
            \App\Storage\LocalDriver::defaultRelDir() . '/' => \App\Storage\LocalDriver::defaultUploadDir(),
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
            // 必须先剥离整行注释再切分：schema.sql 注释里含分号，直接 explode 会截断语句。
            $stripped = preg_replace(
                '/^[ \t]*(?:--|#).*$/m',
                '',
                $sql
            ) ?? $sql;

            $statements = array_filter(
                array_map('trim', explode(';', $stripped)),
                fn($s) => !empty($s)
            );
            foreach ($statements as $statement) {
                $db->exec($statement);
            }
        }
    }
}
