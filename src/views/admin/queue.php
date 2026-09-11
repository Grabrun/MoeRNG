<?php
include __DIR__ . '/helpers.php';
/** @var array $stats @var array $pendingRows @var array $failedRows @var string $incomingDir @var string $error */
admin_header('图片处理', 'page-queue');
?>

<div class="page-header flex-between">
    <div>
        <h1>图片处理</h1>
        <p>上传的图片先落临时目录（storage/incoming）即为成功，异步队列在此完成缩略图生成与最终存储，成功后退删临时文件</p>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary btn-sm" id="queue-start" <?= $stats['pending'] === 0 ? 'disabled' : '' ?>><?= icon('loader', 16) ?> 开始处理</button>
        <button class="btn btn-outline btn-sm" id="queue-requeue" <?= $stats['failed'] === 0 ? 'disabled' : '' ?>><?= icon('refresh', 16) ?> 重试失败项</button>
        <button class="btn btn-outline btn-sm" id="queue-backfill-thumbs" <?= $stats['no_thumb'] === 0 ? 'disabled' : '' ?>><?= icon('image', 16) ?> 补全历史缩略图</button>
    </div>
</div>

<?php if ($error !== ''): ?>
<div class="card mb-3">
    <p class="text-danger"><?= h($error) ?></p>
</div>
<?php endif; ?>

<div class="stats-grid grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-label">待处理</div>
        <div class="stat-value" id="stat-pending"><?= number_format($stats['pending']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label" title="正在处理的行（每批处理时短暂出现）；若长期大于 0 表示上次请求中断遗留，下次「开始处理」会自动复位重试">处理中</div>
        <div class="stat-value" id="stat-processing"><?= number_format($stats['processing']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">已完成</div>
        <div class="stat-value" id="stat-done"><?= number_format($stats['done']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">失败</div>
        <div class="stat-value" id="stat-failed"><?= number_format($stats['failed']) ?></div>
    </div>
</div>

<?php if ($stats['no_thumb'] > 0): ?>
<div class="card mb-3">
    <p class="text-sm mb-0">另有 <strong><?= number_format($stats['no_thumb']) ?></strong> 张历史图片缺少多尺寸缩略图（不影响前台展示）。点击右上角「补全历史缩略图」为它们生成 320/640/1280 三档 WebP 缩略图——处理过程中图片始终可见，仅回填缩略图，不改变处理状态。</p>
</div>
<?php endif; ?>

<!-- v1.3.3-beta.2 增强: 失败明细面板（JS 按队列统计动态填充；failed=0 时隐藏） -->
<div class="card mb-3 hidden" id="failed-panel">
    <div class="flex-between">
        <h3 class="mb-0">失败明细</h3>
        <button type="button" class="btn" id="failed-refresh">刷新</button>
    </div>
    <div id="failed-list" class="mt-2"></div>
</div>

<!-- 处理进度浮层（处理/重试时显示） -->
<div id="queue-progress" class="hidden backfill-float">
    <div class="health-progress-text" id="queue-text">准备处理…</div>
    <div class="health-progress-track"><div id="queue-fill" class="health-progress-fill"></div></div>
    <div class="health-progress-detail" id="queue-detail"></div>
</div>

<div class="card mb-3">
    <h3 class="mb-2 flex flex-between">
        <span>待处理队列</span>
        <small class="font-normal text-secondary">共 <?= number_format($stats['pending']) ?> 张，显示前 <?= count($pendingRows) ?> 张</small>
    </h3>
    <?php if ($pendingRows === []): ?>
    <p class="text-muted text-secondary text-sm">队列为空 —— 所有图片均已完成处理。</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>文件名</th><th>大小</th><th>入队时间</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pendingRows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td class="truncate max-w-320"><?= h((string) $r['original_name']) ?></td>
                    <td><?= number_format((int) $r['file_size'] / 1024, 1) ?> KB</td>
                    <td class="text-muted text-secondary"><?= h((string) ($r['created_at'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <h3 class="mb-2 flex flex-between">
        <span>处理失败</span>
        <small class="font-normal text-secondary">共 <?= number_format($stats['failed']) ?> 张，显示最近 <?= count($failedRows) ?> 张</small>
    </h3>
    <?php if ($failedRows === []): ?>
    <p class="text-muted text-secondary text-sm">没有失败项。</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>文件名</th><th>错误原因</th><th>临时文件</th><th>时间</th></tr>
            </thead>
            <tbody>
                <?php foreach ($failedRows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td class="truncate max-w-320"><?= h((string) $r['original_name']) ?></td>
                    <td class="text-danger"><?= h((string) ($r['process_error'] ?? '未知错误')) ?></td>
                    <td><?= !empty($r['temp_exists']) ? '<span class="badge badge-success">可重试</span>' : '<span class="badge badge-danger">已丢失</span>' ?></td>
                    <td class="text-muted text-secondary"><?= h((string) ($r['created_at'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted text-secondary text-sm mt-2">说明：标记「已丢失」的失败项其临时文件已被清理，无法重试；「可重试」项点击「重试失败项」重新入队。</p>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <h3 class="mb-2">处理流程</h3>
    <ol class="text-sm text-secondary queue-steps">
        <li>上传：文件写入临时目录 <code><?= h($incomingDir) ?></code>，记录状态为「待处理」，上传请求立即返回成功</li>
        <li>入队：本页「开始处理」（或图片管理页加载、上传完成后）自动驱动队列 Worker</li>
        <li>处理：读取临时文件 → 生成最大边 480px 的 WebP 缩略图 → 原图与缩略图上传到该图所属存储实例</li>
        <li>完成：记录置「已完成」并写入缩略图路径，随后删除临时文件</li>
        <li>失败：记录「失败」并保留临时文件，可在本页重试；处理失败的图片不会出现在前台 API</li>
    </ol>
</div>

<?php admin_footer(); ?>
