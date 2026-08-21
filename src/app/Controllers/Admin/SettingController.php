<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Core\Config;
use App\Core\Mailer;
use App\Core\BackupService;
use App\Models\Setting;
use App\Models\AuditLog;

/**
 * System settings board — v1.1.0-beta.4 multi-group rewrite.
 *
 * Five groups (site / security / performance / mail / backup), each with a
 * typed field schema (defaults, validation rules, help text). Saves validate
 * the submitted group, persist only changed values, and append a field-level
 * diff to the audit log. Action endpoints (cache clear / backup / mail test)
 * live alongside the passive settings.
 *
 * Storage configuration deliberately stays on its own board (/admin/storage).
 */
class SettingController extends Controller
{
    /**
     * Group + field schema. This is the single source of truth for the form
     * (view), validation (save) and defaults (view + code paths).
     *
     * Field types: text | password | textarea | number | email | select | toggle
     * Rules: required, max, min, numeric, integer, email, url, in:[a,b]
     */
    public const GROUPS = [
        // v1.2.1 迭代: 5 groups per settings-optimization doc §4 —
        // 基础设置 / 安全设置 / 图片与存储 / 系统维护 / 高级设置(不折叠).
        'basic' => [
            'label' => '基础设置',
            'desc' => '站点名称、Logo、备案、版权等基础信息',
            'fields' => [
                'site_name' => ['type' => 'text', 'label' => '网站名称', 'default' => 'MoeRNG', 'maxlength' => 100, 'rules' => ['required' => true, 'max' => 100], 'help' => '显示在浏览器标题、首页与页脚'],
                'site_slogan' => ['type' => 'text', 'label' => '网站标语', 'default' => '随机二次元图片 API 服务', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '首页 hero 区域副标题'],
                'logo_url' => ['type' => 'logo', 'label' => 'Logo 图片', 'default' => '/assets/logo.png', 'maxlength' => 500, 'rules' => ['url' => true, 'max' => 500], 'help' => '支持本地上传（PNG/JPG/GIF/WebP，≤2MB）或填写外部 URL；留空使用文字标题'],
                'site_description' => ['type' => 'textarea', 'label' => '网站描述', 'default' => '', 'maxlength' => 500, 'rules' => ['max' => 500], 'help' => 'SEO meta description，建议 50-160 字'],
                'icp_number' => ['type' => 'text', 'label' => 'ICP 备案号', 'default' => '', 'maxlength' => 64, 'rules' => ['max' => 64], 'help' => '仅中国大陆部署需要；如 粤ICP备xxxxxxxx号，留空不显示'],
                'copyright' => ['type' => 'text', 'label' => '版权信息', 'default' => '© {year} MoeRNG. All rights reserved.', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '页脚版权文字；{year} 会自动替换为当前年份'],
                'footer_html' => ['type' => 'textarea', 'label' => '页脚自定义信息', 'default' => '', 'maxlength' => 1000, 'rules' => ['max' => 1000], 'help' => '页脚底部追加的纯文本（自动转义），支持多行'],
                'github_url' => ['type' => 'text', 'label' => 'GitHub 仓库地址', 'default' => '', 'maxlength' => 200, 'rules' => ['url' => true, 'max' => 200], 'help' => '填后顶部导航显示 GitHub 入口与「关于」区的开源链接；留空不显示'],
                'per_page' => ['type' => 'number', 'label' => '每页图片数', 'default' => '20', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 100], 'help' => '后台图片列表与分类每页显示数量'],
            ],
        ],
        'security' => [
            'label' => '安全设置',
            'desc' => '登录安全、API 认证与访问控制',
            'fields' => [
                'login_captcha' => ['type' => 'toggle', 'label' => '登录验证码', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '开启后登录页显示图形验证码（需 GD 扩展）'],
                'login_max_attempts' => ['type' => 'number', 'label' => '登录失败最大次数', 'default' => '5', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 20], 'help' => '同一 IP 在窗口期内连续失败达到次数后锁定登录'],
                'login_lockout_minutes' => ['type' => 'number', 'label' => '锁定时间（分钟）', 'default' => '15', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 1440], 'help' => '锁定期内该 IP 无法登录（成功登录自动重置计数）'],
                'api_rate_limit_enabled' => ['type' => 'toggle', 'label' => '接口频率限制', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '对公开 API（/api/v1）按 IP 限流，防止滥用'],
                'api_rate_limit_per_minute' => ['type' => 'number', 'label' => '每分钟最大请求数', 'default' => '100', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 1000], 'help' => '单个 IP 每分钟允许的请求数（仅限流开启时生效）'],
                'api_key_auth_required' => ['type' => 'toggle', 'label' => 'API Key 认证（实验性）', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '开启后公开 API 必须携带有效 X-API-Key 头；默认关闭（公开 API 保持开放）'],
            ],
        ],
        'media' => [
            'label' => '图片与存储',
            'desc' => '图片 CDN 域名（图片处理与存储优化设置后续版本提供）',
            'fields' => [
                'cdn_url' => ['type' => 'text', 'label' => 'CDN 加速域名', 'default' => '', 'maxlength' => 200, 'rules' => ['url' => true, 'max' => 200], 'help' => '配置后图片 URL 将通过 CDN 域名提供（本地与对象存储均支持）'],
            ],
        ],
        'maintenance' => [
            'label' => '系统维护',
            'desc' => '备份恢复、日志保留、邮件通知与缓存管理',
            'fields' => [
                'audit_log_retention_days' => ['type' => 'number', 'label' => '操作日志保留天数', 'default' => '90', 'rules' => ['numeric' => true, 'min' => 7, 'max' => 3650], 'help' => '超过该天数的操作日志自动清理；0 表示不清理'],
                'mail_enabled' => ['type' => 'toggle', 'label' => '邮件通知开关', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '总开关：关闭后所有邮件通知不发送'],
                'mail_host' => ['type' => 'text', 'label' => 'SMTP 服务器', 'default' => '', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '如 smtp.qq.com / smtp.exmail.qq.com'],
                'mail_port' => ['type' => 'number', 'label' => 'SMTP 端口', 'default' => '465', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 65535], 'help' => 'SSL 加密通常 465，TLS 通常 587'],
                'mail_encryption' => ['type' => 'select', 'label' => '加密方式', 'default' => 'ssl', 'options' => ['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '无'], 'rules' => ['in:ssl,tls,none'], 'help' => 'SSL 使用 ssl:// 直连；TLS 走 STARTTLS 前的明文握手（简化实现：tls 按 ssl 连接处理，请优先选 SSL）'],
                'mail_username' => ['type' => 'text', 'label' => 'SMTP 用户名', 'default' => '', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '通常是完整邮箱地址'],
                'mail_password' => ['type' => 'password', 'label' => 'SMTP 密码/授权码', 'default' => '', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '第三方邮箱请使用授权码而非登录密码'],
                'mail_from' => ['type' => 'email', 'label' => '发件人地址', 'default' => '', 'rules' => ['email' => true, 'max' => 200], 'help' => '收件人看到的发件邮箱'],
                'mail_from_name' => ['type' => 'text', 'label' => '发件人名称', 'default' => 'MoeRNG', 'maxlength' => 100, 'rules' => ['max' => 100], 'help' => '如 MoeRNG / 图片服务'],
                'mail_test_to' => ['type' => 'email', 'label' => '测试收件邮箱', 'default' => '', 'rules' => ['email' => true, 'max' => 200], 'help' => '点「发送测试邮件」时的收件人，配置完成后建议先测试'],
                'mail_notify_backup' => ['type' => 'toggle', 'label' => '备份完成通知', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '自动备份完成后向「测试收件邮箱」发送通知邮件'],
                'backup_enabled' => ['type' => 'toggle', 'label' => '自动备份开关', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '开启后按周期在访问时触发检查并自动备份'],
                'backup_period' => ['type' => 'select', 'label' => '备份周期', 'default' => 'weekly', 'options' => ['daily' => '每天', 'weekly' => '每周', 'monthly' => '每月'], 'rules' => ['in:daily,weekly,monthly'], 'help' => 'daily 每天一次；weekly 每周一；monthly 每月 1 号'],
                'backup_path' => ['type' => 'text', 'label' => '备份存储路径', 'default' => 'backups', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '相对项目根目录，或绝对路径；请勿放在 public 下（会被下载）'],
                'backup_keep' => ['type' => 'number', 'label' => '保留份数', 'default' => '14', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 60], 'help' => '超出份数的旧备份自动删除；建议 3-14 份'],
            ],
        ],
        'advanced' => [
            'label' => '高级设置',
            'desc' => '性能调优与开发者选项',
            'fields' => [
                'gzip_level' => ['type' => 'select', 'label' => 'Gzip 压缩级别', 'default' => '6', 'options' => ['0' => '0 - 不压缩', '1' => '1 - 最快', '4' => '4 - 平衡', '6' => '6 - 推荐', '9' => '9 - 最小体积'], 'rules' => ['in:0,1,4,6,9'], 'help' => '页面/API 输出 Gzip 压缩级别；0 关闭'],
                'cors_origins' => ['type' => 'text', 'label' => 'CORS 允许来源', 'default' => '', 'maxlength' => 500, 'rules' => ['max' => 500], 'help' => '逗号分隔的域名白名单，如 https://a.com,https://b.com；留空=不发送 CORS 头（浏览器默认同源限制）'],
            ],
        ],
    ];

    public function index(Request $request): void
    {
        $settings = Setting::allAsKeyValue();
        $this->render('admin/settings', [
            'title' => '系统设置',
            'settings' => $settings,
            'groups' => self::GROUPS,
            'backups' => BackupService::list(),
        ]);
    }

    /**
     * POST /admin/settings/save — persist one group (with validation + audit).
     */
    public function save(Request $request): void
    {
        $this->validateCsrf();

        $group = (string) $request->input('group', '');
        if (!isset(self::GROUPS[$group])) {
            Session::flash('error', '无效的设置分组。');
            $this->redirect('/admin/settings');
            return;
        }

        $fields = self::GROUPS[$group]['fields'];
        $data = [];
        foreach ($fields as $key => $def) {
            $data[$key] = (string) $request->input($key, '');
        }
        $errors = self::validateFields($fields, $data);

        if ($errors !== []) {
            Session::flash('error', '表单校验未通过：' . implode('；', array_values($errors)));
            $this->redirect('/admin/settings#' . $group);
            return;
        }

        // Persist + diff for the audit log.
        $old = Setting::allAsKeyValue();
        $diff = [];
        foreach ($fields as $key => $def) {
            $newValue = $data[$key];
            $oldValue = (string) ($old[$key] ?? $def['default'] ?? '');
            // Password fields: keep the stored value when the input is blank
            // (the form renders password fields empty for security).
            if (($def['type'] ?? '') === 'password' && $newValue === '') {
                $newValue = $oldValue;
            }
            Setting::set($key, $newValue);
            if ($newValue !== $oldValue) {
                $diff[$key] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        AuditLog::record('settings_update', [
            'group' => $group,
            'group_label' => self::GROUPS[$group]['label'],
            'changed' => array_keys($diff),
            'diff' => $diff,
        ]);

        Config::reload();

        Session::flash('success', '「' . self::GROUPS[$group]['label'] . '」已保存' . ($diff === [] ? '（无变更）' : '，变更 ' . count($diff) . ' 项'));
        $this->redirect('/admin/settings#' . $group);
    }

    /**
     * POST /admin/settings/logo-upload — upload a site logo (AJAX, JSON out).
     *
     * Stores the file under public/uploads/logo/ (same publicly-accessible
     * tree as images) and persists settings.logo_url. Rejects SVG on purpose
     * (script-injection risk). Replaces the previous uploaded logo file.
     */
    public function logoUpload(Request $request): void
    {
        $this->validateCsrf();

        $file = $_FILES['logo_file'] ?? null;
        $error = self::validateLogoUpload($file);
        if ($error !== '') {
            $this->json(['success' => false, 'message' => $error], 422);
            return;
        }

        $root = dirname(__DIR__, 3);
        $dir = $root . '/public/uploads/logo';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->json(['success' => false, 'message' => '无法创建 logo 上传目录'], 500);
            return;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $filename = 'logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.' . $ext;
        $target = $dir . '/' . $filename;
        if (!@move_uploaded_file($tmp, $target)) {
            $this->json(['success' => false, 'message' => '文件保存失败（目录可能不可写）'], 500);
            return;
        }
        @chmod($target, 0644);

        // Delete the previous logo when it was an uploaded one (uploads/logo/).
        $old = (string) Setting::get('logo_url', '');
        if (preg_match('#/uploads/logo/([^/]+)$#', $old, $m) && is_file($dir . '/' . $m[1])) {
            @unlink($dir . '/' . $m[1]);
        }

        $url = '/public/uploads/logo/' . $filename;
        Setting::set('logo_url', $url);
        AuditLog::record('logo_upload', ['url' => $url, 'size' => (int) ($file['size'] ?? 0)]);

        $this->json(['success' => true, 'message' => 'Logo 上传成功', 'url' => $url]);
    }

    /** Validate an uploaded logo file; returns '' when acceptable. */
    private static function validateLogoUpload(mixed $file): string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '请选择要上传的图片文件';
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1048576) {
            return 'Logo 文件大小不能超过 2MB';
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $info = @getimagesize($tmp);
        if ($info === false) {
            return '文件不是有效的图片';
        }
        // MIME whitelist — SVG is excluded deliberately (XSS / script payloads).
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($info['mime'] ?? '', $allowed, true)) {
            return '仅支持 PNG / JPG / GIF / WebP 格式（不支持 SVG）';
        }
        return '';
    }

    /**
     * POST /admin/settings/cache-clear — flush output caches.
     */
    public function cacheClear(Request $request): void
    {
        $this->validateCsrf();
        $cleared = [];
        if (function_exists('opcache_reset')) {
            $cleared[] = 'OPcache' . (opcache_reset() ? '' : '（无活动缓存）');
        }
        // Rate-limit counters are safe to drop; keep them (they self-expire).
        $files = glob(dirname(__DIR__, 3) . '/var/rate-limit/*.json') ?: [];
        $cleared[] = '限流计数 ' . count($files) . ' 项（如需重置计数）';
        AuditLog::record('cache_clear', ['opcache' => in_array('OPcache', $cleared, true)]);
        Session::flash('success', '缓存已清理：' . implode('、', $cleared));
        $this->redirect('/admin/settings#performance');
    }

    /**
     * POST /admin/settings/backup — run a backup now.
     */
    public function backupNow(Request $request): void
    {
        $this->validateCsrf();
        [$ok, $msg] = BackupService::create();
        AuditLog::record($ok ? 'backup_run' : 'backup_failed', ['message' => $msg]);
        Session::flash($ok ? 'success' : 'error', $msg);
        $this->redirect('/admin/settings#backup');
    }

    /**
     * POST /admin/settings/backup-delete — remove one backup group.
     */
    public function backupDelete(Request $request): void
    {
        $this->validateCsrf();
        $stamp = (string) $request->input('stamp', '');
        $ok = $stamp !== '' && BackupService::delete($stamp);
        AuditLog::record('backup_delete', ['stamp' => $stamp, 'deleted' => $ok]);
        Session::flash($ok ? 'success' : 'error', $ok ? "已删除备份 {$stamp}" : '备份删除失败或不存在');
        $this->redirect('/admin/settings#backup');
    }

    /**
     * POST /admin/settings/test-mail — send a probe email.
     */
    public function testMail(Request $request): void
    {
        $this->validateCsrf();
        [$ok, $msg] = Mailer::test();
        AuditLog::record($ok ? 'mail_test_ok' : 'mail_test_failed', ['message' => $msg]);
        Session::flash($ok ? 'success' : 'error', $msg);
        $this->redirect('/admin/settings#mail');
    }

    /**
     * GET /admin/settings/logs — operation audit trail (paginated + search).
     */
    public function logs(Request $request): void
    {
        // v1.2.1 迭代: opportunistic audit-log retention cleanup (settings doc).
        try {
            $days = (int) \App\Models\Setting::get('audit_log_retention_days', '90');
            if ($days > 0) {
                \App\Core\Database::getInstance()
                    ->prepare('DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')
                    ->execute([$days]);
            }
        } catch (\Throwable) {
            // best effort — cleanup must never break the page
        }

        $page = max(1, (int) $request->input('page', '1'));
        // v1.2.1 迭代: per-page selector (50/100/200/500) for long audit trails.
        $perPage = in_array((int) $request->input('per_page', '50'), [50, 100, 200, 500], true)
            ? (int) $request->input('per_page', '50') : 50;
        $search = trim((string) $request->input('q', ''));
        $action = trim((string) $request->input('action', ''));

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(username LIKE ? OR action LIKE ? OR detail LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($action !== '') {
            $where[] = 'action = ?';
            $params[] = $action;
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $countStmt = \App\Core\Database::getInstance()->prepare("SELECT COUNT(*) FROM audit_logs {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $sql = "SELECT * FROM audit_logs {$whereSql} ORDER BY id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) (($page - 1) * $perPage);
        $stmt = \App\Core\Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $actions = [];
        try {
            $rows = \App\Core\Database::getInstance()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action');
            $actions = $rows->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            // table may not exist yet
        }

        $this->render('admin/logs', [
            'title' => '操作日志',
            'logs' => $logs,
            'actions' => $actions,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'q' => $search,
            'action' => $action,
            'per_page' => $perPage,
        ]);
    }

    /**
     * v1.2.1 迭代: export the filtered audit trail as CSV.
     * GET /admin/settings/logs/export?q=&action=  (admin-only, CSRF not needed for GET).
     */
    public function logsExport(Request $request): void
    {
        $search = trim((string) $request->input('q', ''));
        $action = trim((string) $request->input('action', ''));

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(username LIKE ? OR action LIKE ? OR detail LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($action !== '') {
            $where[] = 'action = ?';
            $params[] = $action;
        }
        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = \App\Core\Database::getInstance()->prepare("SELECT * FROM audit_logs {$whereSql} ORDER BY id DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="moerng-audit-log-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['time', 'username', 'action', 'detail', 'ip']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'] ?? '',
                $row['username'] ?? '',
                $row['action'] ?? '',
                $row['detail'] ?? '',
                $row['ip'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * Validate submitted values against the field schema.
     *
     * @return array<string,string> field => error message (empty = valid)
     */
    public static function validateFields(array $fields, array $input): array
    {
        $errors = [];
        foreach ($fields as $key => $def) {
            $rules = $def['rules'] ?? [];
            $value = (string) ($input[$key] ?? '');

            if (!empty($rules['required']) && trim($value) === '') {
                $errors[$key] = $def['label'] . ' 为必填项';
                continue;
            }
            if ($value === '') {
                continue; // optional & empty → OK
            }
            if (isset($rules['max']) && mb_strlen($value) > (int) $rules['max']) {
                $errors[$key] = $def['label'] . ' 长度不能超过 ' . $rules['max'] . ' 字符';
            }
            if (isset($rules['min']) && mb_strlen($value) < (int) $rules['min']) {
                $errors[$key] = $def['label'] . ' 长度不能少于 ' . $rules['min'] . ' 字符';
            }
            if (!empty($rules['numeric']) && !is_numeric($value)) {
                $errors[$key] = $def['label'] . ' 必须是数字';
            }
            if (!empty($rules['numeric']) && is_numeric($value)) {
                if (isset($rules['min']) && (float) $value < (float) $rules['min']) {
                    $errors[$key] = $def['label'] . ' 不能小于 ' . $rules['min'];
                }
                if (isset($rules['max']) && (float) $value > (float) $rules['max']) {
                    $errors[$key] = $def['label'] . ' 不能大于 ' . $rules['max'];
                }
            }
            if (!empty($rules['email']) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = $def['label'] . ' 不是有效的邮箱地址';
            }
            if (!empty($rules['url']) && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[$key] = $def['label'] . ' 不是有效的 URL';
            }
            if (!empty($rules['in']) && !in_array($value, explode(',', (string) $rules['in']), true)) {
                $errors[$key] = $def['label'] . ' 取值不合法';
            }
        }
        return $errors;
    }

    /** Render helpers for the view. */
    public static function fieldValue(array $settings, string $key, array $def): string
    {
        return (string) ($settings[$key] ?? $def['default'] ?? '');
    }
}
