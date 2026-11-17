<?php
// Qiniu Kodo SDK 官方代码瘦 autoloader（v1.1.1 迭代）：自实现 curl 无 Guzzle 依赖。
spl_autoload_register(function ($class) {
    $prefixes = array('Qiniu\\' => '/src/Qiniu/', 'MyCLabs\\' => '/src/MyCLabs/');
    foreach ($prefixes as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});
