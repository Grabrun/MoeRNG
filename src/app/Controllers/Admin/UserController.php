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

        // role 仅接受 admin/editor；非 admin 会话强制降级为 editor，防提权。
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

        // 审计只记录是否改了密码，不记录密码内容。
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
            $this->fail('用户不存在。', 404, '/admin/users');
        }

        if ((int) $user->id === (int) Session::get('user_id')) {
            $this->fail('不能删除当前登录的账号。', 400, '/admin/users');
        }

        $username = (string) $user->username;
        $user->delete();
        \App\Models\AuditLog::record('user_delete', ['id' => $id, 'username' => $username]);
        $this->ok('已删除用户「' . $username . '」。', [], '/admin/users');
    }
}
