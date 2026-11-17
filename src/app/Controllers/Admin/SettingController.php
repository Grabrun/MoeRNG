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

class SettingController extends Controller
{
    /** CSV 导出行数上限，防止百万行导出打满内存。 */
    private const LOG_EXPORT_MAX = 100000;

    /** 分组与字段 schema：表单、校验、默认值的唯一来源。 */
    public const GROUPS = [
        'basic' => [
            'label' => '基础设置',
            'desc' => '站点名称、Logo、备案、版权等基础信息',
            'fields' => [
                'site_name' => ['type' => 'text', 'label' => '网站名称', 'default' => 'MoeRNG', 'maxlength' => 100, 'rules' => ['required' => true, 'max' => 100], 'help' => '显示在浏览器标题、首页与页脚'],
                'site_slogan' => ['type' => 'text', 'label' => '网站标语', 'default' => '随机二次元图片 API 服务', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '首页 hero 区域副标题'],
                'logo_url' => ['type' => 'logo', 'label' => 'Logo 图片', 'default' => '/assets/logo.png', 'maxlength' => 500, 'rules' => ['max' => 500], 'help' => '支持本地上传（PNG/JPG/GIF/WebP，≤2MB）；上传后点击「保存」生效，留空回退默认 Logo'],
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
            'desc' => '图片处理与缩略图设置；对象存储 / CDN 请到「存储管理」配置',
            'fields' => [
                'thumbs_enabled' => [
                    'type' => 'toggle', 'label' => '生成缩略图', 'default' => '1', 'rules' => ['in:0,1'],
                    'help' => '关闭后新上传图片只保留原图（不生成 320/640/1280 三档 WebP），后台与前台均直接展示原图；'
                        . '重新开启后可用「图片处理」页的「补全历史缩略图」为存量图补齐',
                ],
                'thumb_quality' => [
                    'type' => 'number', 'label' => '缩略图质量', 'default' => '82', 'rules' => ['numeric' => true, 'min' => 40, 'max' => 100],
                    'help' => 'WebP 编码质量 40-100；越高越清晰、体积越大（默认 82）',
                ],
                'thumb_max_pixels' => [
                    'type' => 'number', 'label' => '缩略图像素上限（万像素，0=自动）', 'default' => '0', 'rules' => ['numeric' => true, 'min' => 0, 'max' => 500],
                    'help' => '超过该像素数的原图跳过缩略图生成（仅存原图，避免服务器内存耗尽）；'
                        . '0 = 按当前可用内存自动判断。例：填 80 表示 8000 万像素以内才生成',
                ],
                'original_webp_enabled' => [
                    'type' => 'toggle', 'label' => '原图转 WebP', 'default' => '1', 'rules' => ['in:0,1'],
                    'help' => '开启后上传的原图统一转成 WebP 存储（体积通常只有 PNG 的 20~35%、JPEG 的 60~75%）；'
                        . '缩略图本来就是 WebP。以下情形保持原格式：GIF（转码会丢动画）、SVG（矢量）、'
                        . 'JPEG 读不到 EXIF 方向（转完会变歪）、编码结果不比原文件小、'
                        . '或转码峰值超出可用内存（超大图，含上面的像素上限）。'
                        . '已有原图可用下方「转换历史原图」批量处理',
                ],
                'original_webp_quality' => [
                    'type' => 'number', 'label' => '原图 WebP 质量', 'default' => '90', 'rules' => ['numeric' => true, 'min' => 40, 'max' => 100],
                    'help' => '原图是有损转码，质量建议高于缩略图（默认 90）。GD 不支持无损 WebP；'
                        . '追求极致可用 100（视觉近无损，体积仍通常小于 PNG）',
                ],
                'upload_max_mb' => [
                    'type' => 'number', 'label' => '单图大小上限（MB，0=不限制）', 'default' => '0', 'rules' => ['numeric' => true, 'min' => 0, 'max' => 200],
                    'help' => '应用层校验，超限直接拒绝并提示；0 = 只受 PHP 的 upload_max_filesize 限制。'
                        . '若填的值大于 PHP 配置则不会生效（上传会被 PHP 提前截断）',
                ],
            ],
        ],
        'maintenance' => [
            'label' => '系统维护',
            'desc' => '备份恢复、日志保留、邮件通知与缓存管理',
            'fields' => [
                'audit_log_retention_days' => ['type' => 'number', 'label' => '操作日志保留天数', 'default' => '90', 'rules' => ['numeric' => true, 'min' => 0, 'max' => 3650], 'help' => '超过该天数的操作日志自动清理；0 表示不清理'],
                'mail_enabled' => ['type' => 'toggle', 'label' => '邮件通知开关', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '总开关：关闭后所有邮件通知不发送'],
                'mail_host' => ['type' => 'text', 'label' => 'SMTP 服务器', 'default' => '', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '如 smtp.qq.com / smtp.exmail.qq.com'],
                'mail_port' => ['type' => 'number', 'label' => 'SMTP 端口', 'default' => '465', 'rules' => ['numeric' => true, 'min' => 1, 'max' => 65535], 'help' => 'SSL 加密通常 465，TLS 通常 587'],
                'mail_encryption' => ['type' => 'select', 'label' => '加密方式', 'default' => 'ssl', 'options' => ['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '无'], 'rules' => ['in:ssl,tls,none'], 'help' => 'SSL 使用 ssl:// 加密直连（推荐）；TLS/无 均为明文连接（当前未实现 STARTTLS 升级），敏感环境请选 SSL'],
                'mail_username' => ['type' => 'text', 'label' => 'SMTP 用户名', 'default' => '', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '通常是完整邮箱地址'],
                'mail_password' => ['type' => 'password', 'label' => 'SMTP 密码/授权码', 'default' => '', 'maxlength' => 200, 'rules' => ['max' => 200], 'help' => '第三方邮箱请使用授权码而非登录密码'],
                'mail_from' => ['type' => 'email', 'label' => '发件人地址', 'default' => '', 'rules' => ['email' => true, 'max' => 200], 'help' => '收件人看到的发件邮箱'],
                'mail_from_name' => ['type' => 'text', 'label' => '发件人名称', 'default' => 'MoeRNG', 'maxlength' => 100, 'rules' => ['max' => 100], 'help' => '如 MoeRNG / 图片服务'],
                'mail_test_to' => ['type' => 'email', 'label' => '测试收件邮箱', 'default' => '', 'rules' => ['email' => true, 'max' => 200], 'help' => '点「发送测试邮件」时的收件人，配置完成后建议先测试'],
                'mail_notify_backup' => ['type' => 'toggle', 'label' => '备份完成通知', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '自动备份完成后向「测试收件邮箱」发送通知邮件'],
                'backup_enabled' => ['type' => 'toggle', 'label' => '自动备份开关', 'default' => '0', 'rules' => ['in:0,1'], 'help' => '开启后按周期在访问时触发检查并自动备份'],
                'backup_period' => ['type' => 'select', 'label' => '备份周期', 'default' => 'weekly', 'options' => ['daily' => '每天', 'weekly' => '每周', 'monthly' => '每月'], 'rules' => ['in:daily,weekly,monthly'], 'help' => '按间隔触发：daily 每 24 小时；weekly 每 7 天；monthly 每 30 天'],
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

    public function save(Request $request): void
    {

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
            $this->redirect('/admin/settings?tab=' . $group . '#' . $group);
            return;
        }

        $old = Setting::allAsKeyValue();
        $diff = [];
        foreach ($fields as $key => $def) {
            $newValue = $data[$key];
            $oldValue = (string) ($old[$key] ?? $def['default'] ?? '');
            // 密码字段留空表示保留原值（表单出于安全不回显）。
            if (($def['type'] ?? '') === 'password' && $newValue === '') {
                $newValue = $oldValue;
            }
            Setting::set($key, $newValue);
            if ($newValue !== $oldValue) {
                // 密码与凭据不得以明文进审计日志，只记录「发生过变更」。
                $sensitive = ($def['type'] ?? '') === 'password'
                    || str_ends_with($key, '_password')
                    || str_contains(strtolower($key), 'secret');
                $diff[$key] = $sensitive
                    ? ['old' => '***', 'new' => '***']
                    : ['old' => $oldValue, 'new' => $newValue];
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
        $this->redirect('/admin/settings?tab=' . $group . '#' . $group);
    }

    public function logoUpload(Request $request): void
    {

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

        $old = (string) Setting::get('logo_url', '');
        if (preg_match('#/uploads/logo/([^/]+)$#', $old, $m) && is_file($dir . '/' . $m[1])) {
            @unlink($dir . '/' . $m[1]);
        }

        $url = '/public/uploads/logo/' . $filename;
        Setting::set('logo_url', $url);
        AuditLog::record('logo_upload', ['url' => $url, 'size' => (int) ($file['size'] ?? 0)]);

        $this->json(['success' => true, 'message' => 'Logo 上传成功', 'url' => $url]);
    }

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
        // MIME 白名单刻意排除 SVG（可携带脚本）。
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($info['mime'] ?? '', $allowed, true)) {
            return '仅支持 PNG / JPG / GIF / WebP 格式（不支持 SVG）';
        }
        // 扩展名白名单：保存名取自客户端文件名，否则真图片上传成 .php 会被当脚本执行。
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'ico'];
        if (!in_array($ext, $allowedExt, true)) {
            return 'Logo 扩展名仅支持 PNG / JPG / WebP / ICO';
        }
        return '';
    }

    public function cacheClear(Request $request): void
    {
        $cleared = [];
        if (function_exists('opcache_reset')) {
            $cleared[] = 'OPcache' . (opcache_reset() ? '' : '（无活动缓存）');
        }
        $files = glob(dirname(__DIR__, 3) . '/var/rate-limit/*.json') ?: [];
        $cleared[] = '限流计数 ' . count($files) . ' 项（如需重置计数）';
        AuditLog::record('cache_clear', ['opcache' => in_array('OPcache', $cleared, true)]);
        Session::flash('success', '缓存已清理：' . implode('、', $cleared));
        $this->redirect('/admin/settings?tab=maintenance#maintenance');
    }

    public function backupNow(Request $request): void
    {
        [$ok, $msg] = BackupService::create();
        AuditLog::record($ok ? 'backup_run' : 'backup_failed', ['message' => $msg]);
        Session::flash($ok ? 'success' : 'error', $msg);
        $this->redirect('/admin/settings?tab=maintenance#maintenance');
    }

    public function backupDelete(Request $request): void
    {
        $stamp = (string) $request->input('stamp', '');
        $ok = $stamp !== '' && BackupService::delete($stamp);
        AuditLog::record('backup_delete', ['stamp' => $stamp, 'deleted' => $ok]);
        Session::flash($ok ? 'success' : 'error', $ok ? "已删除备份 {$stamp}" : '备份删除失败或不存在');
        $this->redirect('/admin/settings?tab=maintenance#maintenance');
    }

    public function testMail(Request $request): void
    {
        [$ok, $msg] = Mailer::test();
        AuditLog::record($ok ? 'mail_test_ok' : 'mail_test_failed', ['message' => $msg]);
        Session::flash($ok ? 'success' : 'error', $msg);
        $this->redirect('/admin/settings?tab=maintenance#maintenance');
    }

    public function logs(Request $request): void
    {
        try {
            $days = (int) \App\Models\Setting::get('audit_log_retention_days', '90');
            if ($days > 0) {
                \App\Core\Database::getInstance()
                    ->prepare('DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')
                    ->execute([$days]);
            }
        } catch (\Throwable) {
        }

        $page = max(1, (int) $request->input('page', '1'));
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
        // audit_logs 会无限增长，全量 fetchAll 会打满内存；只导出最近 N 行。
        $stmt = \App\Core\Database::getInstance()->prepare(
            "SELECT * FROM audit_logs {$whereSql} ORDER BY id DESC LIMIT " . self::LOG_EXPORT_MAX
        );
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

    /** @return array<string,string> field => 错误信息 */
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
                continue;
            }
            if (empty($rules['numeric'])) {
                if (isset($rules['max']) && mb_strlen($value) > (int) $rules['max']) {
                    $errors[$key] = $def['label'] . ' 长度不能超过 ' . $rules['max'] . ' 字符';
                }
                if (isset($rules['min']) && mb_strlen($value) < (int) $rules['min']) {
                    $errors[$key] = $def['label'] . ' 长度不能少于 ' . $rules['min'] . ' 字符';
                }
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

    /** @return array<string,array{ok:bool,detail:string,fixable:bool,extra:array}> */
    private function runHealthChecks(): array
    {
        $checks = [];
        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            return ['database' => ['ok' => false, 'detail' => '数据库连接失败: ' . $e->getMessage(), 'fixable' => false, 'extra' => []]];
        }

        $expect = \App\Core\Application::schemaExpectations();
        $missing = [];
        $colsCache = [];
        $idxCache = [];
        $tableCols = function (string $tbl) use ($pdo, &$colsCache): array {
            return $colsCache[$tbl] ??= $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(\PDO::FETCH_COLUMN);
        };
        $tableIdx = function (string $tbl) use ($pdo, &$idxCache): array {
            if (!isset($idxCache[$tbl])) {
                $idxCache[$tbl] = [];
                foreach ($pdo->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $idxCache[$tbl][$r['Key_name']] = true;
                }
            }
            return $idxCache[$tbl];
        };
        // 先列后索引：索引依赖列已存在。
        foreach ($expect['columns'] as $tbl => $cols) {
            foreach ($cols as $col => $def) {
                if (!in_array($col, $tableCols($tbl), true)) {
                    $missing[] = [$tbl, $col, "ADD COLUMN `{$col}` {$def}"];
                }
            }
        }
        foreach ($expect['indexes'] as $tbl => $idxs) {
            foreach ($idxs as $name => $ddl) {
                if (!isset($tableIdx($tbl)[$name])) {
                    $missing[] = [$tbl, $name, $ddl];
                }
            }
        }
        $checks['schema'] = [
            'ok' => $missing === [],
            'detail' => $missing === []
                ? '所有应自迁移的字段与索引均已就位'
                : '缺失: ' . implode(', ', array_map(fn($m) => "{$m[0]}.{$m[1]}", $missing)),
            'fixable' => true,
            'extra' => ['missing' => $missing],
        ];

        $orphanKeys = ['direct_upload_enabled'];
        $orphanFound = [];
        try {
            foreach ($orphanKeys as $k) {
                $c = (int) $pdo->query("SELECT COUNT(*) FROM `settings` WHERE `key` = " . $pdo->quote($k))->fetchColumn();
                if ($c > 0) {
                    $orphanFound[$k] = $c;
                }
            }
            $checks['orphan_settings'] = [
                'ok' => $orphanFound === [],
                'detail' => $orphanFound === []
                    ? '无遗留设置行'
                    : '发现遗留设置行: ' . implode(', ', array_keys($orphanFound)),
                'fixable' => true,
                'extra' => ['found' => $orphanFound],
            ];
        } catch (\Throwable $e) {
            $checks['orphan_settings'] = ['ok' => true, 'detail' => 'settings 表不可探测（跳过）: ' . $e->getMessage(), 'fixable' => false, 'extra' => []];
        }

        try {
            $qPending = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE process_status = 'pending'")->fetchColumn();
            $qFailed = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE process_status = 'failed'"
            )->fetchColumn();
            $checks['image_queue'] = [
                'ok' => $qPending === 0 && $qFailed === 0,
                'detail' => $qPending === 0 && $qFailed === 0
                    ? '处理队列为空'
                    : "待处理 {$qPending} 张, 失败 {$qFailed} 张",
                'fixable' => true,
                'extra' => ['pending' => $qPending, 'failed' => $qFailed],
            ];
        } catch (\Throwable $e) {
            $checks['image_queue'] = ['ok' => true, 'detail' => '队列统计失败（跳过）: ' . $e->getMessage(), 'fixable' => false, 'extra' => []];
        }

        try {
            $noThumb = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND " . \App\Models\Image::thumbMetaPendingSql()
            )->fetchColumn();
            $checks['thumb_backfill'] = [
                'ok' => $noThumb === 0,
                'detail' => $noThumb === 0
                    ? '所有图片均已有缩略图'
                    : "{$noThumb} 张缺多尺寸缩略图 — 到「图片处理」页点击「补全历史缩略图」（不改状态，图片始终可见）",
                'fixable' => true,
                'extra' => ['missing' => $noThumb],
            ];
        } catch (\Throwable $e) {
            $checks['thumb_backfill'] = ['ok' => true, 'detail' => '统计失败（跳过）: ' . $e->getMessage(), 'fixable' => false, 'extra' => []];
        }

        try {
            $noProfile = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE `storage_profile_id` IS NULL"
            )->fetchColumn();
            $urlLeft = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE `url` IS NOT NULL AND `url` <> ''"
            )->fetchColumn();
            $checks['legacy_refs'] = [
                'ok' => $noProfile === 0 && $urlLeft === 0,
                'detail' => ($noProfile === 0 && $urlLeft === 0)
                    ? '无历史兼容数据残留'
                    : "{$noProfile} 张缺存储实例归属、{$urlLeft} 行残留 url 列 —— "
                        . '运行 php src/cli/migrate-legacy-refs.php --apply 完成一次性迁移'
                        . '（迁移前这些图片的读取会明确报错）',
                'fixable' => false,
                'extra' => ['no_profile' => $noProfile, 'url_left' => $urlLeft],
            ];
        } catch (\Throwable $e) {
            $checks['legacy_refs'] = ['ok' => true, 'detail' => '检测失败（跳过）: ' . $e->getMessage(), 'fixable' => false, 'extra' => []];
        }

        try {
            $noMd5 = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE file_hash IS NULL OR file_hash=''")->fetchColumn();
            $noSha = (int) $pdo->query("SELECT COUNT(*) FROM `images` WHERE file_sha256 IS NULL OR file_sha256=''")->fetchColumn();
            $checks['hash_backfill'] = [
                'ok' => $noMd5 === 0 && $noSha === 0,
                'detail' => $noMd5 === 0 && $noSha === 0
                    ? '所有图片均携带 MD5 + SHA-256'
                    : "{$noMd5} 张缺 file_hash, {$noSha} 张缺 file_sha256",
                'fixable' => true,
                'extra' => ['missing_md5' => $noMd5, 'missing_sha' => $noSha],
            ];
        } catch (\Throwable $e) {
            $checks['hash_backfill'] = ['ok' => true, 'detail' => '统计失败（跳过）: ' . $e->getMessage(), 'fixable' => false, 'extra' => []];
        }

        // 判据自检：索引表在不在、能不能回查到自己、库里有没有内容相同的记录。
        try {
            if (!\App\Models\ContentHash::tableExists()) {
                $checks['dedupe_key'] = [
                    'ok' => false,
                    'detail' => 'content_hashes 索引表不存在 —— 去重退化为 MD5 兜底，点「执行修复」重建',
                    'fixable' => true,
                    'extra' => ['missing_table' => true],
                ];
                return $checks;
            }

            $indexed = (int) $pdo->query(
                'SELECT COUNT(*) FROM `content_hashes` WHERE `image_id` IS NOT NULL'
            )->fetchColumn();
            $stale = (int) $pdo->query(
                'SELECT COUNT(*) FROM `content_hashes` WHERE `image_id` IS NULL'
                . ' AND `created_at` < (NOW() - INTERVAL 10 MINUTE)'
            )->fetchColumn();
            $sample = $pdo->query(
                'SELECT `sha256` FROM `content_hashes` WHERE `image_id` IS NOT NULL'
                . ' ORDER BY `created_at` DESC LIMIT 1'
            )->fetchColumn();
            // 自检必须走运行时同一条判据（ContentHash::find），不另写 SQL，避免再次漂移
            $selfHit = $sample === false ? null : \App\Models\ContentHash::find((string) $sample) !== null;
            $images = (int) $pdo->query('SELECT COUNT(*) FROM `images`')->fetchColumn();
            $dupGroups = (int) $pdo->query(
                "SELECT COUNT(*) FROM (SELECT `file_sha256` FROM `images`"
                . " WHERE TRIM(COALESCE(`file_sha256`, '')) <> ''"
                . " GROUP BY `file_sha256` HAVING COUNT(*) > 1) t"
            )->fetchColumn();

            $bad = [];
            if ($selfHit === false) {
                $bad[] = '索引回查不到自己';
            }

            if ($bad !== []) {
                $detail = '去重判据失效: ' . implode('；', $bad) . ' —— 上传的重复图片不会被拦截';
            } else {
                $detail = "去重判据正常：已索引 {$indexed} 条内容（库内 {$images} 张图）";
                if ($dupGroups > 0) {
                    $detail .= "；库内已有 {$dupGroups} 组内容完全相同的记录（历史漏判，需自行清理）";
                }
                if ($indexed < $images) {
                    $detail .= '；' . ($images - $indexed) . ' 张图尚未进索引（历史行，可跑 backfill-content-hashes 补齐）';
                }
                if ($stale > 0) {
                    $detail .= "；{$stale} 个超时占位待回收（队列每轮自动清理）";
                }
            }

            $checks['dedupe_key'] = [
                'ok' => $bad === [],
                'detail' => $detail,
                'fixable' => false,
                'extra' => [
                    'indexed'  => $indexed,
                    'images'   => $images,
                    'stale'    => $stale,
                    'self_hit' => $selfHit,
                    'dup_groups' => $dupGroups,
                ],
            ];
        } catch (\Throwable $e) {
            $checks['dedupe_key'] = ['ok' => true, 'detail' => '去重自检失败（跳过）: ' . $e->getMessage(), 'fixable' => false, 'extra' => []];
        }

        return $checks;
    }

    public function health(Request $request): void
    {
        $checks = $this->runHealthChecks();
        $allOk = !in_array(false, array_column($checks, 'ok'), true);
        $this->json(['success' => true, 'all_ok' => $allOk, 'checks' => $checks]);
    }

    public function healthFix(Request $request): void
    {
        $checks = $this->runHealthChecks();
        $applied = 0;
        $already = 0;
        $errors = [];
        $deletedRows = 0;

        $missing = $checks['schema']['extra']['missing'] ?? [];
        try {
            $pdo = \App\Core\Database::getInstance();
            foreach ($missing as [$tbl, $col, $ddl]) {
                try {
                    $pdo->exec("ALTER TABLE `{$tbl}` {$ddl}");
                    $applied++;
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (stripos($msg, '1060') !== false || stripos($msg, '1061') !== false || stripos($msg, 'duplicate') !== false) {
                        $already++;
                    } else {
                        $errors[] = "{$tbl}.{$col}: {$msg}";
                    }
                }
            }
            $orphanFound = $checks['orphan_settings']['extra']['found'] ?? [];
            foreach ($orphanFound as $k => $c) {
                $deletedRows += $pdo->exec("DELETE FROM `settings` WHERE `key` = " . $pdo->quote($k));
            }
            if (($checks['dedupe_key']['extra']['missing_table'] ?? false) === true) {
                \App\Models\ContentHash::ensureTable($pdo);
                $applied++;
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $after = $this->runHealthChecks();
        $schemaOkAfter = $after['schema']['ok'] ?? false;
        $orphanOkAfter = $after['orphan_settings']['ok'] ?? true;

        $this->json([
            'success' => $schemaOkAfter && $orphanOkAfter && $errors === [],
            'applied' => $applied,
            'already' => $already,
            'deleted_rows' => $deletedRows,
            'errors' => $errors,
            'schema_ok_after' => $schemaOkAfter,
            'orphan_ok_after' => $orphanOkAfter,
            'checks_after' => $after,
        ]);
    }

}
