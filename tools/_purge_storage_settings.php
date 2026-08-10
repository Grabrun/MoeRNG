#!/usr/bin/env php
<?php
/**
 * 一键清理 settings 表里已被 storage_profiles 完整接管的孤儿键。
 *
 * v1.0.33 引入 storage_profiles 表时已迁移了所有 provider 凭据；v1.0.35
 * 起运行时完全不再读 settings 的 storage_* 键（profiles 是唯一事实来源），
 * 因此以下全部键都是孤儿（仍带敏感密钥）：
 *   - storage_providers          (JSON map，承载 cos/oss/aws/obs 全部凭据)
 *   - storage_s3_access_key      (legacy 单 provider 凭据)
 *   - storage_s3_secret_key
 *   - storage_s3_region
 *   - storage_s3_bucket
 *   - storage_s3_endpoint
 *   - storage_s3_provider
 *   - storage_driver             (master 开关 — v1.0.35 起不再读取)
 *   - storage_default_provider   (v1.0.35 起不再读取)
 *   - storage_local_path         (v1.0.35 起不再读取)
 *
 * 保留：cdn_url（站点级设置，非 storage 范畴）。
 *
 * 前置条件：storage_profiles 表必须非空且凭据完整（脚本会检查）。
 * 注意：老库升级路径依赖 settings 作为迁移源——若在升级前清理 settings，
 * 迁移会找不到源。仅在确认 profiles 已完整后执行。
 *
 * 用法：
 *   php tools/_purge_storage_settings.php              # dry-run 预览
 *   php tools/_purge_storage_settings.php --commit     # 真删
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/app/Autoloader.php';

$root = dirname(__DIR__);
require_once $root . '/src/app/Autoloader.php';

// ── args ────────────────────────────────────────────────────────────────
$commit = in_array('--commit', $argv ?? [], true);
$dryRun = !$commit;

// ── 安全检查：profiles 必须存在且非空（否则 settings 兜底路径仍生效）────
try {
    $count = \App\Models\StorageProfile::count();
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: cannot read storage_profiles: {$e->getMessage()}\n");
    exit(1);
}

if ($count === 0) {
    fwrite(STDERR, "FATAL: storage_profiles is empty — refusing to delete settings keys.\n");
    fwrite(STDERR, "       The settings keys are still the active configuration in that case.\n");
    exit(2);
}

echo "=== Storage settings cleanup ===\n";
echo "storage_profiles: {$count} profile(s) present\n";
echo "mode: " . ($commit ? 'COMMIT (will DELETE)' : 'DRY-RUN (preview only)') . "\n\n";

// ── 列出所有待清理的键（白名单，v1.0.35：含 master 开关）─────────────────
$ORPHAN_KEYS = [
    'storage_providers',          // {cos: {key, secret, region, bucket, endpoint, cdn}, ...}
    'storage_s3_access_key',
    'storage_s3_secret_key',
    'storage_s3_region',
    'storage_s3_bucket',
    'storage_s3_endpoint',
    'storage_s3_provider',
    'storage_driver',
    'storage_default_provider',
    'storage_local_path',
];

// ── 拉 settings 表里现有的值（一次性查询）──────────────────────────────
$existing = [];
try {
    $db = \App\Core\Database::getInstance();
    $place = implode(',', array_fill(0, count($ORPHAN_KEYS), '?'));
    $stmt = $db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($place)");
    $stmt->execute($ORPHAN_KEYS);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $existing[$row['key']] = $row['value'];
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: cannot read settings: {$e->getMessage()}\n");
    exit(1);
}

if (empty($existing)) {
    echo "[OK] Nothing to delete — all orphan keys already absent.\n";
    exit(0);
}

// ── 重复性 / 安全性二次检验：profiles 确实有对应凭据（不导致灭失）──────
$profiles = \App\Models\StorageProfile::all('sort_order ASC, id ASC');
$profileMap = [];
foreach ($profiles as $p) {
    $cfg = $p->config();
    $profileMap[$p->attributes['provider']] = [
        'has_key'    => !empty($cfg['key']),
        'has_secret' => !empty($cfg['secret']),
        'has_bucket' => !empty($cfg['bucket']),
    ];
}
$safetyOk = true;
foreach (['storage_s3_access_key', 'storage_s3_secret_key'] as $sensitive) {
    if (!empty($existing[$sensitive]) && empty($profileMap['cos']['has_key']) && empty($profileMap['oss']['has_key']) && empty($profileMap['aws']['has_key'])) {
        echo "[NO] REFUSE: {$sensitive} exists in settings but NO profile has a key — would lose configuration.\n";
        $safetyOk = false;
    }
}
if (!$safetyOk) {
    exit(3);
}

// ── 打印预览 ─────────────────────────────────────────────────────────────
echo "Following " . count($existing) . " orphan key(s) will be deleted:\n";
foreach ($existing as $k => $v) {
    $preview = strlen($v) > 80 ? substr($v, 0, 77) . '...' : $v;
    echo "  - {$k} = {$preview}\n";
}
echo "\n";
echo "Kept (site-level, outside storage scope):\n";
echo "  - cdn_url\n";

// ── 假脱敏：将密钥字段脱敏后显示（不泄露完整 secret）──────────────────
$SECRETISH = ['storage_providers', 'storage_s3_access_key', 'storage_s3_secret_key'];
echo "\nWith sensitive values masked:\n";
foreach ($existing as $k => $v) {
    $display = in_array($k, $SECRETISH, true) ? '[REDACTED · ' . strlen($v) . ' chars]' : $v;
    $display = strlen($display) > 80 ? substr($display, 0, 77) . '...' : $display;
    echo "  - {$k} = {$display}\n";
}

// ── dry-run 退出 ─────────────────────────────────────────────────────────
if ($dryRun) {
    echo "\nNothing was deleted. Re-run with --commit to apply.\n";
    exit(0);
}

// ── commit ───────────────────────────────────────────────────────────────
echo "\nCommitting deletion...\n";
try {
    $db = \App\Core\Database::getInstance();
    $stmt = $db->prepare("DELETE FROM settings WHERE `key` = ?");
    $deleted = 0;
    foreach ($existing as $k => $v) {
        $stmt->execute([$k]);
        $deleted += $stmt->rowCount();
    }
    echo "[OK] Deleted {$deleted} row(s) from settings.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: delete failed: {$e->getMessage()}\n");
    exit(4);
}
echo "Done. Clear opcache / restart PHP-FPM if any keys are still cached.\n";
exit(0);
