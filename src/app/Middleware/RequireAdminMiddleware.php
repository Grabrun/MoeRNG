<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * MR-02 修复 —— 服务端角色守卫（admin-only 路由的强制校验）。
 *
 * 背景：AuthMiddleware 只校验「已登录且账号 active」，不读 role；此前所有
 * /admin 路由仅挂 [AuthMiddleware, CsrfMiddleware]，editor 与 admin 等价，
 * 构成垂直越权（editor 可建号/删号、管理 API Key、改存储配置、改站点设置、
 * 批量删图）。本中间件按路由挂载在组中间件之后，对高危动作做强制 role 校验。
 *
 * 执行顺序（Router::dispatch 反向包栈，组中间件先于路由中间件）：
 *   AuthMiddleware → CsrfMiddleware → RequireAdminMiddleware → Controller
 * 因此进入 handle() 时 $request->user 已是 App\Models\User 实例。
 *
 * 取 role 的优先级：
 *   1) $request->user->role（AuthMiddleware 挂载的 User 模型）
 *   2) Session::get('user_role')（remember-me 路径也会写入）
 *   3) 两者皆空 → 按未授权处理（fail closed，绝不含空值放行）。
 *
 * 非 admin 一律 403：JSON 请求返回 JSON 403；其余（浏览器表单提交）
 * flash 提示后 302 回 /admin 仪表盘。写法对齐 AuthMiddleware 的 wantsJson 分支。
 */
class RequireAdminMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $role = null;

        if ($request->user !== null) {
            $role = $request->user->role ?? null;
        }
        if ($role === null) {
            $role = Session::get('user_role');
        }

        if ($role !== 'admin') {
            if ($request->wantsJson()) {
                $response = new Response();
                $response->json([
                    'error' => 'Forbidden',
                    'message' => 'Admin role required.',
                ], 403);
            }

            Session::flash('error', '需要管理员权限才能执行该操作。');
            $response = new Response();
            $response->redirect('/admin');
        }

        return $next($request);
    }
}
