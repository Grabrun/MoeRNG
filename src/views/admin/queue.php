<?php
include __DIR__ . '/helpers.php';
/**
 * @var array  $stats
 * @var array  $rows        合并后的队列行（pending + failed）
 * @var int    $total       当前筛选下的总条数
 * @var int    $page @var int $pageCount @var int $perPage
 * @var string $status      筛选状态：all | pending | failed
 * @var string $search      搜索词（文件名或 ID）
 * @var int    $queueTotal  待处理 + 失败总数
 * @var string $incomingDir @var string $error
 */
admin_header('图片处理', 'page-queue');

$queryBase = 'status=' . urlencode($status) . '&q=' . urlencode($search);
?>
<div class="page-header flex-between">
    <div>
        <h1>图片处理</h1>
        <p>上传即完成，队列负责生成缩略图与最终存储</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary btn-sm" id="queue-start" <?= $stats['pending'] === 0 ? 'disabled' : '' ?>><?= icon('loader', 16) ?> 开始处理</button>
        <button class="btn btn-outline btn-sm" id="queue-requeue" <?= $stats['failed'] === 0 ? 'disabled' : '' ?>><?= icon('refresh', 16) ?> 重试全部失败</button>
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

<!-- 处理进度浮层（处理/重试时显示） -->
<div id="queue-progress" class="hidden backfill-float">
    <div class="health-progress-text" id="queue-text">准备处理…</div>
    <div class="health-progress-track"><div id="queue-fill" class="health-progress-fill"></div></div>
    <div class="health-progress-detail" id="queue-detail"></div>
</div>

<!-- v1.4.0-beta.2: 待处理与失败**合并为单一队列**（按状态筛选 + 按文件名/ID 搜索） -->
<div class="card mb-3">
    <h3 class="queue-section-head">
        <span>队列</span>
        <small class="queue-count"><?= number_format($total) ?> 条<?= $total > $perPage ? '（第 ' . $page . '/' . $pageCount . ' 页）' : '' ?></small>
    </h3>

    <form method="GET" action="/admin/images/queue" class="queue-filter" id="queue-filter">
        <select name="status" class="form-control queue-filter-status" id="queue-status" aria-label="按处理状态筛选">
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>全部状态（<?= number_format($queueTotal) ?>）</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>待处理（<?= number_format($stats['pending']) ?>）</option>
            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>失败（<?= number_format($stats['failed']) ?>）</option>
        </select>
        <input type="text" name="q" id="queue-search" class="form-control queue-filter-search"
               value="<?= h($search) ?>" placeholder="搜索文件名或 ID…" aria-label="搜索文件名或 ID">
        <button type="submit" class="btn btn-outline btn-sm">筛选</button>
        <a href="/admin/images/queue" class="btn btn-outline btn-sm no-underline" id="queue-reset"><?= ($status !== 'all' || $search !== '') ? '重置' : '刷新' ?></a>
    </form>

    <?php if ($rows === []): ?>
    <p class="queue-empty">
        <?php if ($status !== 'all' || $search !== ''): ?>
        没有符合筛选条件的记录。
        <?php else: ?>
        队列为空，所有图片均已完成处理。
        <?php endif; ?>
    </p>
    <?php else: ?>
    <div class="table-wrap" id="queue-list">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>文件名 / 错误原因</th>
                    <th>大小</th>
                    <th>状态</th>
                    <th title="处理所需的临时原图是否仍在服务器上（已丢失则无法重试）">临时文件</th>
                    <th>时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <?php
                    $isFailed = (string) $r['process_status'] === 'failed';
                    $err = (string) ($r['process_error'] ?? '');
                ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td>
                        <div class="truncate max-w-320"><?= h((string) $r['original_name']) ?></div>
                        <?php if ($isFailed && $err !== ''): ?>
                        <div class="queue-row-err" title="<?= h($err) ?>"><?= h($err) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((int) $r['file_size'] / 1024, 1) ?> KB</td>
                    <td>
                        <?php if ($isFailed): ?>
                        <span class="badge badge-danger">失败</span>
                        <?php else: ?>
                        <span class="badge badge-info">待处理</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['temp_exists'])): ?>
                        <span class="badge badge-success">在</span>
                        <?php else: ?>
                        <span class="badge badge-danger">已丢失</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted text-secondary"><?= h((string) ($r['created_at'] ?? '')) ?></td>
                    <td>
                        <?php if ($isFailed): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-retry-id="<?= (int) $r['id'] ?>">重试</button>
                        <?php else: ?>
                        <span class="text-muted text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pageCount > 1): ?>
    <div class="pagination-wrap">
        <div class="pagination">
            <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                <?php if ($i === $page): ?>
                <span class="active"><?= $i ?></span>
                <?php else: ?>
                <a href="?<?= h($queryBase) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

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
        <button class="btn btn-danger btn-sm" id="queue-clear" <?= $queueTotal === 0 ? 'disabled' : '' ?>><?= icon('trash', 16) ?> 清空队列</button>
    </div>
</details>

<?php admin_footer(); ?>
