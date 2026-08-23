<?php
include __DIR__ . '/helpers.php';
/** @var array $settings @var array $groups @var array $backups */
$groupNames = array_keys($groups);
$activeGroup = $_GET['tab'] ?? ($groupNames[0] ?? 'site');
if (!in_array($activeGroup, $groupNames, true)) $activeGroup = $groupNames[0] ?? 'site';

function renderField(string $key, array $def, array $settings): void
{
    $value = (string) ($settings[$key] ?? $def['default'] ?? '');
    $type = $def['type'] ?? 'text';
    $label = h($def['label'] ?? $key);
    $help = $def['help'] ?? '';
    $name = h($key);
    $maxlength = isset($def['maxlength']) ? ' maxlength="' . (int)$def['maxlength'] . '"' : '';
    // v1.2.1 迭代: wide fields (logo / textarea) span the full row.
    $wide = in_array($type, ['logo', 'textarea'], true) ? ' setting-item-wide' : '';

    echo '<div class="form-group setting-item' . $wide . '" data-search="' . h(strtolower($label . ' ' . ($help ?? ''))) . '">';
    echo '<label class="setting-label">' . $label . '</label>';

    if ($type === 'toggle') {
        $checked = $value === '1' ? ' checked' : '';
        echo '<div class="toggle-wrap">'
            . '<input type="hidden" name="' . $name . '" value="0">'
            . '<label class="toggle"><input type="checkbox" name="' . $name . '" value="1"' . $checked . '><span class="toggle-slider"></span></label>'
            . '<span class="toggle-text">' . ($checked ? '已开启' : '已关闭') . '</span>'
            . '</div>';
    } elseif ($type === 'logo') {
        // v1.2.1 迭代: always preview — fall back to the packaged default logo
        // when no custom logo has been set (site renders /assets/logo.png).
        $previewSrc = $value !== '' ? $value : '/assets/logo.png';
        $hasLogo = $value !== '';
        echo '<div class="logo-uploader" data-field="' . $name . '">';
        echo '<div class="logo-preview-wrap">';
        echo '<img id="logo-preview-' . $name . '" src="' . h($previewSrc) . '" alt="当前 Logo" '
            . 'class="logo-preview-img">';
        if (!$hasLogo) {
            echo '<span class="text-muted text-small text-secondary" id="logo-default-hint-' . $name . '">当前使用默认 Logo，上传后可替换</span>';
        }
        echo '</div>';
        echo '<div class="logo-upload-row" class="flex gap-8 flex-wrap">';
        echo '<input type="file" id="logo-file-' . $name . '" accept="image/png,image/jpeg,image/gif,image/webp" class="hidden">';
        // v1.2.1 迭代: single "上传" button — pick file then upload in one flow.
        echo '<button type="button" class="btn btn-sm btn-primary" data-logo-upload="' . $name . '">上传</button>';
        if ($hasLogo) {
            echo '<button type="button" class="btn btn-sm" data-logo-remove="' . $name . '">移除 Logo</button>';
        }
        // v1.2.1 迭代: no manual URL input — value travels as a hidden field so
        // saving the form never wipes an uploaded/custom logo.
        echo '<input type="hidden" name="' . $name . '" value="' . h($value) . '">';
        echo '</div>';
        echo '</div>';
    } elseif ($type === 'select') {
        echo '<select name="' . $name . '" class="form-control">';
        foreach (($def['options'] ?? []) as $optValue => $optLabel) {
            $sel = (string)$optValue === $value ? ' selected' : '';
            echo '<option value="' . h((string)$optValue) . '"' . $sel . '>' . h($optLabel) . '</option>';
        }
        echo '</select>';
    } elseif ($type === 'textarea') {
        echo '<textarea name="' . $name . '" class="form-control" rows="3"' . $maxlength . '>' . h($value) . '</textarea>';
    } elseif ($type === 'password') {
        echo '<input type="password" name="' . $name . '" class="form-control" value="" autocomplete="new-password" placeholder="留空保持不变"' . $maxlength . '>';
    } else {
        $inputType = $type === 'email' ? 'email' : ($type === 'number' ? 'number' : 'text');
        echo '<input type="' . $inputType . '" name="' . $name . '" class="form-control" value="' . h($value) . '"' . $maxlength . '>';
    }

    if ($help !== '') {
        echo '<small class="text-muted setting-help block mt-1 text-secondary text-xs">' . h($help) . '</small>';
    }
    echo '</div>';
}

admin_header('系统设置');
?>

<div class="page-header">
    <h1>系统设置</h1>
    <p>站点、安全、性能、邮件与备份配置（仅管理员可修改，所有变更记录到操作日志）</p>
</div>

<div class="settings-toolbar" class="flex gap-12 flex-wrap mb-3">
    <div class="settings-tabs" class="flex gap-4 flex-wrap">
        <?php foreach ($groups as $gid => $gdef): ?>
        <a href="?tab=<?= h($gid) ?>#<?= h($gid) ?>"
           class="settings-tab btn btn-sm <?= $gid === $activeGroup ? 'btn-primary' : '' ?>"
           class="no-underline"><?= h($gdef['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="flex-1"></div>
    <input type="search" id="settings-search" class="form-control" class="max-w-260" placeholder="搜索设置项…">
</div>

<?php foreach ($groups as $gid => $gdef): ?>
<section class="settings-group" id="<?= h($gid) ?>" data-group="<?= h($gid) ?>" <?= $gid !== $activeGroup ? 'class="hidden"' : '' ?>>
    <form method="POST" action="/admin/settings/save">
        <?= $csrf_field ?>
        <input type="hidden" name="group" value="<?= h($gid) ?>">

        <div class="card mb-3">
            <h3 class="mb-2" class="flex flex-between">
                <span><?= h($gdef['label']) ?></span>
                <small class="font-normal text-secondary"><?= h($gdef['desc']) ?></small>
            </h3>
            <!-- v1.2.1 迭代: settings layout — adaptive columns + full-width wide fields -->
            <div class="settings-group-body">
                <?php if ($gdef['fields'] === []): ?>
                <p class="text-muted text-secondary text-sm py-2">
                    该分组暂无可用设置项，敬请期待后续版本。
                </p>
                <?php else: ?>
                <div class="settings-grid">
                    <?php foreach ($gdef['fields'] as $fkey => $fdef) renderField($fkey, $fdef, $settings); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($gdef['fields'] !== []): ?>
        <div class="settings-save-bar mb-3">
            <button type="submit" class="btn btn-primary">保存「<?= h($gdef['label']) ?>」</button>
            <!-- v1.2.1 UI 深度分析 (UI-04): 未保存修改提示 -->
            <span class="save-hint text-small" id="save-hint"></span>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($gid === 'maintenance'): ?>
    <div class="card mb-3">
        <h3 class="mb-2">缓存清理</h3>
        <p class="text-muted text-small text-secondary">清理 OPcache 与限流计数等运行时缓存。</p>
        <form method="POST" action="/admin/settings/cache-clear">
            <?= $csrf_field ?>
            <button type="submit" class="btn btn-warning" data-confirm="确认清理缓存？">立即清理缓存</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($gid === 'maintenance'): ?>
    <div class="card mb-3">
        <h3 class="mb-2">测试邮件</h3>
        <p class="text-muted text-small text-secondary">使用上方 SMTP 参数向「测试收件邮箱」发送一封测试邮件，验证配置是否正确。</p>
        <form method="POST" action="/admin/settings/test-mail">
            <?= $csrf_field ?>
            <button type="submit" class="btn btn-warning">发送测试邮件</button>
        </form>
    </div>

    <div class="card mb-3">
        <h3 class="mb-2">备份管理</h3>
        <div class="d-flex" class="flex gap-12 mb-3">
            <form method="POST" action="/admin/settings/backup">
                <?= $csrf_field ?>
                <button type="submit" class="btn btn-warning" data-confirm="立即执行一次完整备份（数据库 + 上传文件）？">立即备份</button>
            </form>
            <small class="text-muted" class="text-secondary">自动备份按周期在上方「备份与恢复」表单中配置，访问时自动触发检查。</small>
        </div>

        <?php if ($backups): ?>
        <table class="table" class="table-full">
            <thead>
                <tr><th class="text-left p-8">备份文件</th><th class="text-left p-8">大小</th><th class="text-left p-8">时间</th><th class="text-left p-8">操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td class="p-8"><?= h($b['name']) ?></td>
                    <td class="p-8"><?= $b['size'] >= 1048576 ? round($b['size'] / 1048576, 2) . ' MB' : round($b['size'] / 1024, 1) . ' KB' ?></td>
                    <td class="p-8"><?= date('Y-m-d H:i', $b['mtime']) ?></td>
                    <td class="p-8">
                        <form method="POST" action="/admin/settings/backup-delete" class="inline">
                            <?= $csrf_field ?>
                            <input type="hidden" name="stamp" value="<?= h($b['stamp']) ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="确认删除该备份？此操作不可恢复">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted" class="text-secondary">暂无备份。点击「立即备份」生成第一份。</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<div class="mt-3" class="border-top pt-3">
    <a href="/admin/settings/logs" class="btn btn-sm">查看操作日志 →</a>
</div>

<?php admin_footer(); ?>
