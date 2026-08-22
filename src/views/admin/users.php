<?php include __DIR__ . '/helpers.php'; admin_header('用户管理'); ?>

<?php
// v1.2.1 迭代: render flash messages (ok/fail) so deletes/toggles show feedback.
$success = \App\Core\Session::flash('success');
$error   = \App\Core\Session::flash('error');
if ($success): ?>
<div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;border-radius:var(--radius-sm);background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--text)">
    <?= h($success) ?>
</div>
<?php endif; if ($error): ?>
<div class="alert alert-error" style="margin-bottom:16px;padding:12px 16px;border-radius:var(--radius-sm);background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:var(--text)">
    <?= h($error) ?>
</div>
<?php endif; ?>

<div class="page-header flex-between">
    <div>
        <h1>用户管理</h1>
        <p>管理后台管理员账号</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openModal('user-modal')">新建用户</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table-card">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>用户名</th>
                    <th>邮箱</th>
                    <th>角色</th>
                    <th>状态</th>
                    <th>最后登录</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td data-label="ID"><?= $user->id ?></td>
                    <td data-label="用户名"><?= h($user->username) ?></td>
                    <td data-label="邮箱"><?= h($user->email) ?></td>
                    <td data-label="角色"><span class="badge badge-<?= $user->role === 'admin' ? 'primary' : 'info' ?>"><?= h($user->role) ?></span></td>
                    <td data-label="状态"><span class="badge badge-<?= $user->status === 'active' ? 'success' : 'danger' ?>"><?= h($user->status) ?></span></td>
                    <td data-label="最后登录"><?= !empty($user->last_login) ? h(date('Y-m-d H:i', strtotime((string)$user->last_login))) : '<span class="text-muted">从未登录</span>' ?></td>
                    <td data-label="操作">
                        <div class="flex gap-1">
                            <button class="btn btn-outline btn-sm" onclick="editUser(<?= $user->id ?>, '<?= h($user->username) ?>', '<?= h($user->email) ?>', '<?= h($user->role) ?>')">编辑</button>
                            <form method="POST" action="/admin/users/toggle-status" style="display:inline">
                                <?= $csrf_field ?>
                                <input type="hidden" name="id" value="<?= $user->id ?>">
                                <button type="submit" class="btn btn-sm <?= $user->status === 'active' ? 'btn-danger' : 'btn-outline' ?>">
                                    <?= $user->status === 'active' ? '禁用' : '启用' ?>
                                </button>
                            </form>
                            <form method="POST" action="/admin/users/delete" style="display:inline" onsubmit="return confirm('确定删除此用户？')">
                                <?= $csrf_field ?>
                                <input type="hidden" name="id" value="<?= $user->id ?>">
                                <button type="submit" class="btn btn-danger btn-sm">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="6" class="text-center text-muted">暂无用户</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- User Modal -->
<div class="modal-overlay" id="user-modal">
    <div class="modal">
        <h2 id="user-modal-title">新建用户</h2>
        <form method="POST" action="/admin/users/create" id="user-form">
            <?= $csrf_field ?>
            <input type="hidden" name="id" id="user-id">
            <div class="form-group"><label>用户名 *</label><input type="text" name="username" id="user-username" class="form-control" required></div>
            <div class="form-group"><label>邮箱 *</label><input type="email" name="email" id="user-email" class="form-control" required></div>
            <div class="form-group"><label>密码 <small id="pwd-hint">(留空不修改)</small></label><input type="password" name="password" id="user-password" class="form-control"></div>
            <div class="form-group"><label>角色</label>
                <select name="role" id="user-role" class="form-control">
                    <option value="admin">Admin</option>
                    <option value="editor">Editor</option>
                </select>
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-outline" onclick="closeModal('user-modal')">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div><?php admin_footer(); ?>
