<?php
// Upyun SDK 官方代码瘦 autoloader（v1.1.1 迭代）：
// 运行时 Guzzle/psr7 由 sdk/cos/vendor 提供（先 require cos autoload 再 require 本文件）。
spl_autoload_register(function ($class) {
    $prefix = 'Upyun\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
