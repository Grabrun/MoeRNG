<?php include __DIR__ . '/helpers.php'; admin_header('操作日志'); ?>

<div class="page-header flex-between" style="display:flex;align-items:center;justify-content:space-between">
    <div>
        <h1>操作日志</h1>
        <p>管理员操作审计记录（设置变更 / 备份 / 缓存 / 登录）</p>
    </div>
    <a href="/admin/settings" class="btn btn-sm">返回系统设置</a>
</div>

<div class="card mb-3">
    <form method="GET" action="/admin/settings/logs" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <div class="form-group" style="margin:0;flex:1;min-width:220px">
            <input type="search" name="q" class="form-control" placeholder="搜索用户名 / 动作 / 详情…" value="<?= h($q ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0">
            <select name="action" class="form-control">
                <option value="">全部动作</option>
                <?php foreach (($actions ?? []) as $a): ?>
                <option value="<?= h($a) ?>" <?= ($action ?? '') === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <!-- v1.2.1 迭代: per-page selector + CSV export -->
        <select name="per_page" class="form-control" style="width:auto" onchange="this.form.submit()" aria-label="每页数量">
            <?php foreach ([50, 100, 200, 500] as $n): ?>
            <option value="<?= $n ?>" <?= ($per_page ?? 50) == $n ? 'selected' : '' ?>><?= $n ?> 条/页</option>
            <?php endforeach; ?>
        </select>
        <a href="/admin/settings/logs/export?q=<?= urlencode($q ?? '') ?>&action=<?= urlencode($action ?? '') ?>" class="btn btn-outline btn-sm" title="导出当前筛选结果为 CSV"><?= icon('download', 16) ?> 导出 CSV</a>
        <?php if (($q ?? '') !== '' || ($action ?? '') !== ''): ?>
        <a href="/admin/settings/logs" class="btn btn-sm">清除筛选</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if ($logs): ?>
    <table class="table" style="width:100%;border-collapse:collapse">
        <thead>
            <tr>
                <th style="text-align:left;padding:10px">时间</th>
                <th style="text-align:left;padding:10px">用户</th>
                <th style="text-align:left;padding:10px">动作</th>
                <th style="text-align:left;padding:10px">详情</th>
                <th style="text-align:left;padding:10px">IP</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td style="padding:10px;white-space:nowrap"><?= h($log['created_at']) ?></td>
                <td style="padding:10px"><?= h($log['username'] !== '' ? $log['username'] : '—') ?></td>
                <td style="padding:10px"><code><?= h($log['action']) ?></code></td>
                <td style="padding:10px;font-size:0.85rem;max-width:420px;word-break:break-all">
                    <?php
                    $detail = json_decode((string) $log['detail'], true);
                    if (is_array($detail)) {
                        $parts = [];
                        if (isset($detail['group_label'])) $parts[] = $detail['group_label'];
                        if (isset($detail['changed']) && is_array($detail['changed'])) $parts[] = '变更: ' . implode(', ', $detail['changed']);
                        if (isset($detail['message'])) $parts[] = (string) $detail['message'];
                        echo h($parts !== [] ? implode(' · ', $parts) : json_encode($detail, JSON_UNESCAPED_UNICODE));
                    } else {
                        echo h((string) ($log['detail'] ?? ''));
                    }
                    ?>
                </td>
                <td style="padding:10px;white-space:nowrap"><?= h($log['ip'] !== '' ? $log['ip'] : '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="text-muted" style="padding:16px;color:var(--text-secondary)">暂无操作日志记录。</p>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<div class="mt-3" style="display:flex;gap:8px;align-items:center">
    <?php if ($page > 1): ?>
    <a class="btn btn-sm" href="?page=<?= $page - 1 ?>&q=<?= urlencode($q ?? '') ?>&action=<?= urlencode($action ?? '') ?>">上一页</a>
    <?php endif; ?>
    <span class="text-muted" style="font-size:0.85rem">第 <?= $page ?> / <?= $pages ?> 页 · 共 <?= $total ?> 条</span>
    <?php if ($page < $pages): ?>
    <a class="btn btn-sm" href="?page=<?= $page + 1 ?>&q=<?= urlencode($q ?? '') ?>&action=<?= urlencode($action ?? '') ?>">下一页</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
