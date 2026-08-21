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
        $previewSrc = $value !== '' ? $value : '';
        $hasLogo = $previewSrc !== '';
        echo '<div class="logo-uploader" data-field="' . $name . '">';
        echo '<div class="logo-preview-wrap" style="display:flex;align-items:center;gap:12px;margin-bottom:10px">';
        echo '<img id="logo-preview-' . $name . '" src="' . h($previewSrc) . '" alt="当前 Logo" '
            . 'style="max-height:48px;max-width:160px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:4px;background:var(--bg-input);object-fit:contain;display:' . ($hasLogo ? 'block' : 'none') . '">';
        if (!$hasLogo) {
            echo '<span class="text-muted" id="logo-empty-' . $name . '" style="font-size:0.85rem;color:var(--text-secondary)">未设置 Logo，站点将使用文字标题</span>';
        }
        echo '</div>';
        echo '<div class="logo-upload-row" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<input type="file" id="logo-file-' . $name . '" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none">';
        echo '<button type="button" class="btn btn-sm btn-outline" data-logo-choose="' . $name . '">选择图片</button>';
        echo '<button type="button" class="btn btn-sm btn-primary" data-logo-upload="' . $name . '" disabled>上传</button>';
        if ($hasLogo) {
            echo '<button type="button" class="btn btn-sm" data-logo-remove="' . $name . '">移除 Logo</button>';
        }
        echo '<input type="text" name="' . $name . '" class="form-control" style="max-width:280px" value="' . h($value) . '" placeholder="或粘贴外部 URL">';
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
        echo '<small class="text-muted setting-help" style="display:block;margin-top:6px;color:var(--text-secondary);font-size:0.8rem">' . h($help) . '</small>';
    }
    echo '</div>';
}

admin_header('系统设置');
?>

<div class="page-header">
    <h1>系统设置</h1>
    <p>站点、安全、性能、邮件与备份配置（仅管理员可修改，所有变更记录到操作日志）</p>
</div>

<div class="settings-toolbar" style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
    <div class="settings-tabs" style="display:flex;gap:4px;flex-wrap:wrap">
        <?php foreach ($groups as $gid => $gdef): ?>
        <a href="?tab=<?= h($gid) ?>#<?= h($gid) ?>"
           class="settings-tab btn btn-sm <?= $gid === $activeGroup ? 'btn-primary' : '' ?>"
           style="text-decoration:none"><?= h($gdef['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <div style="flex:1"></div>
    <input type="search" id="settings-search" class="form-control" style="max-width:260px" placeholder="搜索设置项…">
</div>

<?php foreach ($groups as $gid => $gdef): ?>
<section class="settings-group" id="<?= h($gid) ?>" data-group="<?= h($gid) ?>" <?= $gid !== $activeGroup ? 'style="display:none"' : '' ?>>
    <form method="POST" action="/admin/settings/save">
        <?= $csrf_field ?>
        <input type="hidden" name="group" value="<?= h($gid) ?>">

        <div class="card mb-3">
            <h3 class="mb-2" style="display:flex;align-items:center;justify-content:space-between">
                <span><?= h($gdef['label']) ?></span>
                <small style="font-weight:400;color:var(--text-secondary)"><?= h($gdef['desc']) ?></small>
            </h3>
            <!-- v1.2.1 迭代: settings layout — adaptive columns + full-width wide fields -->
            <div class="settings-group-body">
                <div class="settings-grid">
                    <?php foreach ($gdef['fields'] as $fkey => $fdef) renderField($fkey, $fdef, $settings); ?>
                </div>
            </div>
        </div>

        <div class="settings-save-bar mb-3">
            <button type="submit" class="btn btn-primary">保存「<?= h($gdef['label']) ?>」</button>
        </div>
    </form>

    <?php if ($gid === 'maintenance'): ?>
    <div class="card mb-3">
        <h3 class="mb-2">缓存清理</h3>
        <p class="text-muted" style="font-size:0.85rem;color:var(--text-secondary)">清理 OPcache 与限流计数等运行时缓存。</p>
        <form method="POST" action="/admin/settings/cache-clear">
            <?= $csrf_field ?>
            <button type="submit" class="btn btn-warning" onclick="return confirm('确认清理缓存？')">立即清理缓存</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($gid === 'maintenance'): ?>
    <div class="card mb-3">
        <h3 class="mb-2">测试邮件</h3>
        <p class="text-muted" style="font-size:0.85rem;color:var(--text-secondary)">使用上方 SMTP 参数向「测试收件邮箱」发送一封测试邮件，验证配置是否正确。</p>
        <form method="POST" action="/admin/settings/test-mail">
            <?= $csrf_field ?>
            <button type="submit" class="btn btn-warning">发送测试邮件</button>
        </form>
    </div>

    <div class="card mb-3">
        <h3 class="mb-2">备份管理</h3>
        <div class="d-flex" style="display:flex;gap:12px;align-items:center;margin-bottom:16px">
            <form method="POST" action="/admin/settings/backup">
                <?= $csrf_field ?>
                <button type="submit" class="btn btn-warning" onclick="return confirm('立即执行一次完整备份（数据库 + 上传文件）？')">立即备份</button>
            </form>
            <small class="text-muted" style="color:var(--text-secondary)">自动备份按周期在上方「备份与恢复」表单中配置，访问时自动触发检查。</small>
        </div>

        <?php if ($backups): ?>
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr><th style="text-align:left;padding:8px">备份文件</th><th style="text-align:left;padding:8px">大小</th><th style="text-align:left;padding:8px">时间</th><th style="text-align:left;padding:8px">操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td style="padding:8px"><?= h($b['name']) ?></td>
                    <td style="padding:8px"><?= $b['size'] >= 1048576 ? round($b['size'] / 1048576, 2) . ' MB' : round($b['size'] / 1024, 1) . ' KB' ?></td>
                    <td style="padding:8px"><?= date('Y-m-d H:i', $b['mtime']) ?></td>
                    <td style="padding:8px">
                        <form method="POST" action="/admin/settings/backup-delete" style="display:inline">
                            <?= $csrf_field ?>
                            <input type="hidden" name="stamp" value="<?= h($b['stamp']) ?>">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('确认删除该备份？此操作不可恢复')">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted" style="color:var(--text-secondary)">暂无备份。点击「立即备份」生成第一份。</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<div class="mt-3" style="border-top:1px solid var(--border);padding-top:12px">
    <a href="/admin/settings/logs" class="btn btn-sm">查看操作日志 →</a>
</div>

<script>
(function () {
    // Group tabs
    var tabs = document.querySelectorAll('.settings-tab');
    var groups = document.querySelectorAll('.settings-group');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            var gid = tab.getAttribute('href').split('#')[1];
            groups.forEach(function (g) { g.style.display = g.dataset.group === gid ? '' : 'none'; });
        });
    });
    // Live field search (filters within the active group)
    var box = document.getElementById('settings-search');
    if (box) {
        box.addEventListener('input', function () {
            var q = box.value.trim().toLowerCase();
            groups.forEach(function (g) {
                if (g.style.display === 'none') return;
                g.querySelectorAll('.setting-item').forEach(function (item) {
                    item.style.display = (q === '' || item.dataset.search.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        });
    }
    // Toggle visual state
    document.querySelectorAll('.toggle input[type=checkbox]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var text = cb.closest('.toggle-wrap').querySelector('.toggle-text');
            if (text) text.textContent = cb.checked ? '已开启' : '已关闭';
        });
    });

    // Logo uploader (AJAX)
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    document.querySelectorAll('[data-logo-choose]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('logo-file-' + btn.dataset.logoChoose).click();
        });
    });
    document.querySelectorAll('[data-logo-upload]').forEach(function (btn) {
        var field = btn.dataset.logoUpload;
        var fileInput = document.getElementById('logo-file-' + field);
        var urlInput = document.querySelector('.logo-uploader[data-field="' + field + '"] input[name="' + field + '"]');
        fileInput.addEventListener('change', function () {
            var f = fileInput.files[0];
            btn.disabled = !f;
            if (f) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    var img = document.getElementById('logo-preview-' + field);
                    img.src = e.target.result;
                    img.style.display = 'block';
                    var empty = document.getElementById('logo-empty-' + field);
                    if (empty) empty.style.display = 'none';
                };
                reader.readAsDataURL(f);
            }
        });
        btn.addEventListener('click', function () {
            var f = fileInput.files[0];
            if (!f) return;
            var fd = new FormData();
            fd.append('logo_file', f);
            fd.append('_csrf_token', csrf);
            btn.disabled = true;
            btn.textContent = '上传中…';
            fetch('/admin/settings/logo-upload', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.textContent = '上传';
                    if (res.success) {
                        urlInput.value = res.url;
                        var img = document.getElementById('logo-preview-' + field);
                        img.src = res.url;
                        img.style.display = 'block';
                        if (window.toast) toast('Logo 上传成功', 'success');
                        else alert('Logo 上传成功');
                    } else {
                        if (window.toast) toast(res.message || '上传失败', 'error');
                        else alert(res.message || '上传失败');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = '上传';
                    if (window.toast) toast('网络错误，请重试', 'error');
                    else alert('网络错误，请重试');
                });
        });
    });
    document.querySelectorAll('[data-logo-remove]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = btn.dataset.logoRemove;
            var urlInput = document.querySelector('.logo-uploader[data-field="' + field + '"] input[name="' + field + '"]');
            urlInput.value = '';
            var img = document.getElementById('logo-preview-' + field);
            img.style.display = 'none';
            img.removeAttribute('src');
            var empty = document.getElementById('logo-empty-' + field);
            if (empty) empty.style.display = '';
            btn.style.display = 'none';
        });
    });
})();
</script>

<style>
.toggle-wrap { display: flex; align-items: center; gap: 10px; padding-top: 4px; }
.toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer; inset: 0; border-radius: 24px;
    background: var(--bg-input); border: 1px solid var(--border); transition: all var(--transition);
}
.toggle-slider::before {
    content: ""; position: absolute; width: 18px; height: 18px; left: 2px; top: 2px;
    border-radius: 50%; background: var(--text-secondary); transition: all var(--transition);
}
.toggle input:checked + .toggle-slider { background: var(--primary); border-color: var(--primary); }
.toggle input:checked + .toggle-slider::before { transform: translateX(20px); background: #fff; }
.toggle-text { font-size: 0.85rem; color: var(--text-secondary); }
.settings-tab { cursor: pointer; }
</style>

<?php admin_footer(); ?>
