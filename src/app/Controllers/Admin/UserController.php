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
        $this->validateCsrf();

        $email = $request->input('email', '');
        $username = $request->input('username', '');
        $password = $request->input('password', '');
        $role = $request->input('role', 'admin');

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

        Session::flash('success', 'User created successfully.');
        $this->redirect('/admin/users');
    }

    public function update(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $user = User::find($id);

        if (!$user) {
            $this->json(['error' => 'User not found.'], 404);
        }

        $user->username = $request->input('username', $user->username);
        $user->email = $request->input('email', $user->email);
        $user->role = $request->input('role', $user->role);

        $password = $request->input('password', '');
        if (!empty($password)) {
            $user->setPassword($password);
        }

        $user->save();
        Session::flash('success', 'User updated successfully.');
        $this->redirect('/admin/users');
    }

    public function toggleStatus(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $user = User::find($id);

        if (!$user) {
            $this->json(['error' => 'User not found.'], 404);
        }

        $user->status = $user->status === 'active' ? 'disabled' : 'active';
        $user->save();

        $this->json(['success' => true, 'message' => 'Status updated.']);
    }

    public function delete(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $user = User::find($id);

        if (!$user) {
            $this->json(['error' => 'User not found.'], 404);
        }

        // Prevent deleting self
        if ((int) $user->id === (int) Session::get('user_id')) {
            $this->json(['error' => 'Cannot delete your own account.'], 400);
        }

        $user->delete();
        $this->json(['success' => true, 'message' => 'User deleted.']);
    }
}
