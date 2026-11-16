<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\User;

class UserController extends Controller
{
    public function index(Request $request): void
    {
        $users = User::all('id ASC');

        $this->render('admin/users', [
            'title' => '用户管理',
            'users' => $users,
        ]);
    }

    public function create(Request $request): void
    {

        $email = $request->input('email', '');
        $username = $request->input('username', '');
        $password = $request->input('password', '');

        // MR-02 纵深防御：role 白名单 + 越权降级。
        // 1) 仅接受 'admin'/'editor'，非法值回落 'editor'；
        // 2) 只有当前会话本身是 admin 时才允许建 admin 账号；
        //    非 admin 会话（正常已被 RequireAdminMiddleware 挡在门外）一律强制 editor。
        $requestedRole = $request->input('role', 'admin');
        $role = ($requestedRole === 'admin' || $requestedRole === 'editor') ? $requestedRole : 'editor';
        if (Session::get('user_role') !== 'admin' && $role === 'admin') {
            $role = 'editor';
        }

        if (empty($email) || empty($username) || empty($password)) {
            Session::flash('error', 'All fields are required.');
            $this->redirect('/admin/users');
        }

        if (User::findByEmail($email)) {
            Session::flash('error', 'Email already exists.');
            $this->redirect('/admin/users');
        }

        $user = new User([
            'email' => $email,
            'username' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
        $user->setPassword($password);
        $user->save();

        // MR-38: account creation is a sensitive admin action — audit it.
        \App\Models\AuditLog::record('user_create', [
            'id'       => (int) $user->id,
            'username' => (string) $user->username,
            'email'    => (string) $user->email,
            'role'     => (string) $user->role,
        ]);

        Session::flash('success', 'User created successfully.');
        $this->redirect('/admin/users');
    }

    public function update(Request $request): void
    {
        $id = (int) $request->input('id');
        $user = User::find($id);

        if (!$user) {
            $this->json(['error' => 'User not found.'], 404);
        }

        $user->username = $request->input('username', $user->username);
        $user->email = $request->input('email', $user->email);
        $oldRole = (string) $user->role;

        // MR-02 纵深防御：与 create 相同的 role 白名单 + 非 admin 会话强制 editor，
        // 防止任何非 admin 上下文把账号提权为 admin。
        $requestedRole = $request->input('role', $user->role);
        $newRole = ($requestedRole === 'admin' || $requestedRole === 'editor') ? $requestedRole : 'editor';
        if (Session::get('user_role') !== 'admin' && $newRole === 'admin') {
            $newRole = 'editor';
        }
        $user->role = $newRole;

        $password = $request->input('password', '');
        if (!empty($password)) {
            $user->setPassword($password);
        }

        $user->save();

        // MR-38: account edit (esp. role change) must be audited. Never log the
        // password; only that one was set.
        \App\Models\AuditLog::record('user_update', [
            'id'       => (int) $user->id,
            'username' => (string) $user->username,
            'role'     => $oldRole . ' -> ' . (string) $user->role,
            'password_changed' => !empty($password),
        ]);

        Session::flash('success', 'User updated successfully.');
        $this->redirect('/admin/users');
    }

    public function toggleStatus(Request $request): void
    {
        $id = (int) $request->input('id');
        $user = User::find($id);

        if (!$user) {
            $this->json(['error' => 'User not found.'], 404);
        }

        $user->status = $user->status === 'active' ? 'disabled' : 'active';
        $user->save();

        // MR-38: enable/disable an account is sensitive (locks someone out).
        \App\Models\AuditLog::record('user_status', [
            'id'       => (int) $user->id,
            'username' => (string) $user->username,
            'status'   => (string) $user->status,
        ]);

        $this->json(['success' => true, 'message' => 'Status updated.']);
    }

    public function delete(Request $request): void
    {
        $id = (int) $request->input('id');
        $user = User::find($id);

        if (!$user) {
            // v1.2.1 fix: JSON was shown as raw page when posted via plain <form>;
            // use redirect+flash so it always lands back on /admin/users.
            $this->fail('用户不存在。', 404, '/admin/users');
        }

        // Prevent deleting self
        if ((int) $user->id === (int) Session::get('user_id')) {
            $this->fail('不能删除当前登录的账号。', 400, '/admin/users');
        }

        $username = (string) $user->username;
        $user->delete();
        \App\Models\AuditLog::record('user_delete', ['id' => $id, 'username' => $username]);
        $this->ok('已删除用户「' . $username . '」。', [], '/admin/users');
    }
}
