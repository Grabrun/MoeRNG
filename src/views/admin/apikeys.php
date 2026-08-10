<?php include __DIR__ . '/helpers.php'; admin_header('API Key 管理'); ?>

<div class="page-header flex-between">
    <div>
        <h1>API Key 管理</h1>
        <p>管理 API 访问密钥与速率限制</p>
    </div>
    <button class="btn btn-primary btn-sm" id="apikey-new">生成 Key</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table id="apikey-table">
            <thead>
                <tr>
                    <th>名称</th>
                    <th>Key</th>
                    <th>权限</th>
                    <th>速率限制</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($apiKeys as $key): ?>
                <?php
                    $perms = json_decode($key->permissions ?: '[]', true) ?: [];
                    $active = $key->status === 'active';
                ?>
                <tr data-id="<?= $key->id ?>"
                    data-key="<?= h($key->key) ?>"
                    data-name="<?= h($key->name) ?>"
                    data-rate-limit="<?= h($key->rate_limit) ?>"
                    data-rate-window="<?= h($key->rate_window) ?>"
                    data-permissions="<?= h(json_encode($perms, JSON_UNESCAPED_UNICODE)) ?>">
                    <td><?= h($key->name) ?></td>
                    <td>
                        <div class="flex gap-1" style="align-items:center">
                            <code style="font-size:0.8rem"><?= h(substr($key->key, 0, 16)) ?>...</code>
                            <button type="button" class="btn btn-outline btn-sm" data-key-action="copy" title="复制完整 Key" aria-label="复制完整 Key"><?= icon('copy', 16) ?></button>
                        </div>
                    </td>
                    <td>
                        <?php foreach ($perms as $p): ?>
                        <span class="badge badge-info"><?= h($p) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= h($key->rate_limit) ?> / <?= h($key->rate_window) ?>s</td>
                    <td><span class="badge badge-<?= $active ? 'success' : 'danger' ?>"><?= h($key->status) ?></span></td>
                    <td>
                        <div class="flex gap-1">
                            <button type="button" class="btn btn-outline btn-sm" data-key-action="edit">编辑</button>
                            <button type="button" class="btn btn-sm <?= $active ? 'btn-danger' : 'btn-outline' ?>" data-key-action="toggle"><?= $active ? '禁用' : '启用' ?></button>
                            <button type="button" class="btn btn-danger btn-sm" data-key-action="delete">删除</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="apikey-empty" class="<?= empty($apiKeys) ? '' : 'hidden' ?>">
                    <td colspan="6" class="text-center text-muted">暂无 API Key，点击「生成 Key」创建</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- API Key Modal (create / edit) -->
<div class="modal-overlay" id="apikey-modal">
    <div class="modal">
        <h2 id="apikey-modal-title">生成 API Key</h2>
        <form method="POST" action="/admin/apikeys/create" id="apikey-form">
            <?= $csrf_field ?>
            <input type="hidden" name="id" id="key-id">
            <div class="form-group"><label>名称</label><input type="text" name="name" id="key-name" class="form-control" required placeholder="My App"></div>
            <div class="form-group">
                <label>权限范围</label>
                <div class="flex gap-2 flex-wrap">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" name="permissions[]" value="read" checked> read</label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" name="permissions[]" value="write"> write</label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" name="permissions[]" value="admin"> admin</label>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="form-group">
                    <label>速率限制（请求数）</label>
                    <input type="number" name="rate_limit" id="key-rate-limit" class="form-control" value="60" min="1">
                </div>
                <div class="form-group">
                    <label>时间窗口（秒）</label>
                    <input type="number" name="rate_window" id="key-rate-window" class="form-control" value="60" min="1">
                </div>
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-outline" onclick="closeModal('apikey-modal')">取消</button>
                <button type="submit" class="btn btn-primary" id="apikey-submit">生成</button>
            </div>
        </form>
    </div>
</div>

<!-- Generated key reveal modal -->
<div class="modal-overlay" id="newkey-modal">
    <div class="modal" style="max-width:600px">
        <h2>API Key 已生成</h2>
        <p class="text-muted" style="font-size:0.85rem;margin-bottom:12px">
            请立即复制保存，关闭后将无法再次查看完整 Key。
        </p>
        <div class="form-group">
            <label>名称：<span id="newkey-name"></span></label>
            <textarea id="newkey-value" class="form-control" rows="3" readonly style="font-family:monospace;font-size:0.85rem"></textarea>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-outline" onclick="closeModal('newkey-modal')">关闭</button>
            <button type="button" class="btn btn-primary" id="newkey-copy">复制 Key</button>
        </div>
    </div>
</div>

<?php admin_footer(); ?>
