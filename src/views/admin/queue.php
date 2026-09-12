<?php
include __DIR__ . '/helpers.php';
/** @var array $stats @var array $pendingRows @var array $failedRows @var string $incomingDir @var string $error */
admin_header('图片处理', 'page-queue');
?>

<div class="page-header flex-between">
    <div>
        <h1>图片处理</h1>
        <p>上传即完成，队列负责生成缩略图与最终存储</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary btn-sm" id="queue-start" <?= $stats['pending'] === 0 ? 'disabled' : '' ?>><?= icon('loader', 16) ?> 开始处理</button>
        <button class="btn btn-outline btn-sm" id="queue-requeue" <?= $stats['failed'] === 0 ? 'disabled' : '' ?>><?= icon('refresh', 16) ?> 重试失败项</button>
        <button class="btn btn-outline btn-sm" id="queue-backfill-thumbs" <?= $stats['no_thumb'] === 0 ? 'disabled' : '' ?>><?= icon('image', 16) ?> 补全缩略图</button>
    </div>
</div>

<?php if ($error !== ''): ?>
<div class="card mb-3">
    <p class="text-danger mb-0"><?= h($error) ?></p>
</div>
<?php endif; ?>

<div class="stats-grid grid grid-4 mb-3">
    <div class="stat-card stat-card-sm">
        <div class="stat-label">待处理</div>
        <div class="stat-value" id="stat-pending"><?= number_format($stats['pending']) ?></div>
    </div>
    <div class="stat-card stat-card-sm">
        <div class="stat-label" title="正在处理的行（每批处理时短暂出现）；若长期大于 0 表示上次请求中断遗留，下次「开始处理」会自动复位重试">处理中</div>
        <div class="stat-value" id="stat-processing"><?= number_format($stats['processing']) ?></div>
    </div>
    <div class="stat-card stat-card-sm">
        <div class="stat-label">已完成</div>
        <div class="stat-value" id="stat-done"><?= number_format($stats['done']) ?></div>
    </div>
    <div class="stat-card stat-card-sm">
        <div class="stat-label">失败</div>
        <div class="stat-value" id="stat-failed"><?= number_format($stats['failed']) ?></div>
    </div>
</div>

<?php if ($stats['no_thumb'] > 0): ?>
<div class="card mb-3">
    <p class="queue-note mb-0">另有 <strong><?= number_format($stats['no_thumb']) ?></strong> 张已完成的图片没有缩略图，点击「补全缩略图」生成三档 WebP（不影响前台展示，处理期间图片始终可见）。</p>
</div>
<?php endif; ?>

<!-- v1.3.3-beta.2 增强: 失败明细面板（JS 按队列统计动态填充；failed=0 时隐藏） -->
<div class="card mb-3 hidden" id="failed-panel">
    <div class="flex-between">
        <h3 class="mb-0">失败明细</h3>
        <button type="button" class="btn btn-outline btn-sm" id="failed-refresh">刷新</button>
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
    <h3 class="queue-section-head">
        <span>待处理队列</span>
        <?php if ($stats['pending'] > 0): ?>
        <small class="queue-count"><?= number_format($stats['pending']) ?> 张待处理</small>
        <?php endif; ?>
    </h3>
    <?php if ($pendingRows === []): ?>
    <p class="queue-empty">队列为空，所有图片均已完成处理。</p>
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
    <h3 class="queue-section-head">
        <span>处理失败</span>
        <?php if ($stats['failed'] > 0): ?>
        <small class="queue-count"><?= number_format($stats['failed']) ?> 张失败</small>
        <?php endif; ?>
    </h3>
    <?php if ($failedRows === []): ?>
    <p class="queue-empty">没有失败项。</p>
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
    <?php endif; ?>
</div>

<!-- 处理流程说明（默认折叠：常驻会占用大量纵向空间且与操作无关） -->
<details class="card mb-3 queue-help">
    <summary>处理流程与队列机制</summary>
    <ol class="queue-steps">
        <li>上传：写入临时目录 <code><?= h($incomingDir) ?></code>，记为「待处理」，上传请求立即返回</li>
        <li>入队：本页「开始处理」（或图片管理页加载、上传完成后）自动驱动队列 Worker</li>
        <li>处理：读取临时文件 → 生成 320/640/1280 三档 WebP 缩略图 → 原图与缩略图上传到该图所属存储实例</li>
        <li>完成：记为「已完成」并写入缩略图路径，随后删除临时文件</li>
        <li>失败：记为「失败」并保留临时文件，可在本页重试；失败图片不会出现在前台 API</li>
        <li>失败项标记「已丢失」表示临时文件已被清理，无法重试，需在图片管理中删除该记录后重新上传</li>
    </ol>
</details>

<!-- 危险操作（默认折叠：破坏性且低频，避免常驻头部造成误触与视觉噪声） -->
<details class="card queue-danger">
    <summary>危险操作：清空队列</summary>
    <p class="queue-danger-warn mb-0">清空会<strong>删除数据库记录</strong>：待处理项的原图只在临时目录中会一并清理；失败项的原图可能已上传到存储，删除记录后该文件会成为孤立对象，需自行清理。</p>
    <div class="queue-danger-body">
        <select class="form-control queue-danger-scope" id="queue-clear-scope" aria-label="清空队列的范围">
            <option value="pending">待处理（<?= number_format($stats['pending']) ?>）</option>
            <option value="failed">失败项（<?= number_format($stats['failed']) ?>）</option>
            <option value="all">待处理 + 失败项</option>
        </select>
        <button class="btn btn-danger btn-sm" id="queue-clear" <?= ($stats['pending'] + $stats['failed']) === 0 ? 'disabled' : '' ?>><?= icon('trash', 16) ?> 清空队列</button>
    </div>
</details>

<?php admin_footer(); ?>
