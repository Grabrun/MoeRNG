<?php include __DIR__ . '/helpers.php'; admin_header('操作日志'); ?>

<div class="page-header flex-between flex">
    <div>
        <h1>操作日志</h1>
        <p>管理员操作审计记录（设置变更 / 备份 / 缓存 / 登录）</p>
    </div>
    <a href="/admin/settings" class="btn btn-sm">返回系统设置</a>
</div>

<div class="card mb-3">
    <form method="GET" action="/admin/settings/logs" class="flex gap-12 align-center flex-wrap">
        <div class="form-group m-0 flex-1 min-w-220">
            <input type="search" name="q" class="form-control" placeholder="搜索用户名 / 动作 / 详情…" value="<?= h($q ?? '') ?>">
        </div>
        <div class="form-group m-0">
            <select name="action" class="form-control">
                <option value="">全部动作</option>
                <?php foreach (($actions ?? []) as $a): ?>
                <option value="<?= h($a) ?>" <?= ($action ?? '') === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <!-- v1.2.1 迭代: per-page selector + CSV export -->
        <select name="per_page" class="form-control w-auto" data-auto-submit aria-label="每页数量">
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
    <table class="table table-full">
        <thead>
            <tr>
                <th class="text-left p-10">时间</th>
                <th class="text-left p-10">用户</th>
                <th class="text-left p-10">动作</th>
                <th class="text-left p-10">详情</th>
                <th class="text-left p-10">IP</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td class="p-10 nowrap"><?= h($log['created_at']) ?></td>
                <td class="p-10"><?= h($log['username'] !== '' ? $log['username'] : '—') ?></td>
                <td class="p-10"><code><?= h($log['action']) ?></code></td>
                <td class="p-10 text-small max-w-420 wrap-all">
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
                <td class="p-10 nowrap"><?= h($log['ip'] !== '' ? $log['ip'] : '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="text-muted p-16 text-secondary">暂无操作日志记录。</p>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<div class="mt-3 flex gap-8">
    <?php if ($page > 1): ?>
    <a class="btn btn-sm" href="?page=<?= $page - 1 ?>&q=<?= urlencode($q ?? '') ?>&action=<?= urlencode($action ?? '') ?>">上一页</a>
    <?php endif; ?>
    <span class="text-muted text-small">第 <?= $page ?> / <?= $pages ?> 页 · 共 <?= $total ?> 条</span>
    <?php if ($page < $pages): ?>
    <a class="btn btn-sm" href="?page=<?= $page + 1 ?>&q=<?= urlencode($q ?? '') ?>&action=<?= urlencode($action ?? '') ?>">下一页</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
