<?php
declare(strict_types=1);

/** 全局助手：仅保留视图通用的 HTML 转义函数 h()。 */

if (!function_exists('h')) {
    function h(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}
