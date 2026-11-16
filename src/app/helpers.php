<?php
declare(strict_types=1);

/**
 * MoeRNG - Global Helper Functions
 *
 * 只保留 `h()` —— HTML 转义在 300+ 处视图与错误信息里使用，是真正的公共能力。
 *
 * v2.0.0-beta.19 清理：config / session / csrf_token / csrf_field / old / asset /
 * url / redirect / json_response 这 9 个框架式助手函数全库 **0 调用**（视图直接用
 * `Config::get()`、`$csrf_field` 变量、`$this->redirect()`）。它们还各自造了两套
 * 规则：`url()` 与 `Application::baseUrl()` 的 base-url 解析不同。留着会让人以为
 * 存在第二套调用方式，故整体移除。
 */

if (!function_exists('h')) {
    function h(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}
