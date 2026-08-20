#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""拷贝 upyun/qiniu SDK 到 src/sdk/ + upyun psr7 v2 适配补丁 + 自写瘦 autoloader。"""
import os
import re
import shutil

ROOT = r'D:\projects\2026-08-05-10-13-25'
REF = os.path.join(ROOT, 'reference')
DST = os.path.join(ROOT, 'src', 'sdk')

# ---------- 1) upyun ----------
upyun_src = os.path.join(REF, 'upyun-sdk-php-uss-3.5.0', 'src', 'Upyun')
upyun_dst = os.path.join(DST, 'upyun', 'src', 'Upyun')
if os.path.isdir(upyun_dst):
    shutil.rmtree(upyun_dst)
shutil.copytree(upyun_src, upyun_dst)
print('[OK] upyun src copied:', len(os.listdir(upyun_dst)), 'items')

# psr7 v1 -> v2 patch (reuse COS vendor's Guzzle 7 / psr7 v2)
V1_TO_V2 = [
    (r'Psr7\\stream_for\(', 'Utils::streamFor('),
    (r'Psr7\\copy_to_stream\(', 'Utils::copyToStream('),
    (r'Psr7\\mimetype_from_filename\(', 'Utils::mimetypeFromFilename('),
]
patched = 0
for dirpath, _, files in os.walk(upyun_dst):
    for fn in files:
        if not fn.endswith('.php'):
            continue
        p = os.path.join(dirpath, fn)
        s = open(p, encoding='utf-8').read()
        orig = s
        for pat, rep in V1_TO_V2:
            s = re.sub(pat, rep, s)
        # ensure Utils import when v2 Utils:: is used but import missing
        if 'Utils::' in s and 'use GuzzleHttp\\Psr7\\Utils;' not in s:
            if 'use GuzzleHttp\\Psr7;' in s:
                s = s.replace('use GuzzleHttp\\Psr7;', 'use GuzzleHttp\\Psr7;\nuse GuzzleHttp\\Psr7\\Utils;')
            elif 'use GuzzleHttp\\Client;' in s:
                s = s.replace('use GuzzleHttp\\Client;', 'use GuzzleHttp\\Client;\nuse GuzzleHttp\\Psr7\\Utils;')
        if s != orig:
            open(p, 'w', encoding='utf-8').write(s)
            patched += 1
            print('   patched:', os.path.relpath(p, DST))
print('[OK] upyun psr7-v2 patches:', patched, 'files')

with open(os.path.join(DST, 'upyun', 'autoload.php'), 'w', encoding='utf-8') as f:
    f.write('''<?php
// Upyun SDK 官方代码瘦 autoloader（v1.1.1 迭代）：
// 运行时 Guzzle/psr7 由 sdk/cos/vendor 提供（先 require cos autoload 再 require 本文件）。
spl_autoload_register(function ($class) {
    $prefix = 'Upyun\\\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
''')
print('[OK] upyun autoload.php')

# ---------- 2) qiniu ----------
qiniu_src = os.path.join(REF, 'qiniu-sdk-php-kodo-7.14.0', 'src', 'Qiniu')
qiniu_dst = os.path.join(DST, 'qiniu', 'src', 'Qiniu')
if os.path.isdir(qiniu_dst):
    shutil.rmtree(qiniu_dst)
shutil.copytree(qiniu_src, qiniu_dst)
n = sum(1 for _ in os.walk(qiniu_dst))
print('[OK] qiniu src copied (tree nodes:', n, ')')

# Minimal MyCLabs\\Enum\\Enum compatible implementation (MIT, qiniu uses ::from)
myclabs_dir = os.path.join(DST, 'qiniu', 'src', 'MyCLabs', 'Enum')
os.makedirs(myclabs_dir, exist_ok=True)
with open(os.path.join(myclabs_dir, 'Enum.php'), 'w', encoding='utf-8') as f:
    f.write('''<?php
namespace MyCLabs\\Enum;

/**
 * Minimal compatible subset of myclabs/php-enum (MIT) bundled for the Qiniu
 * Kodo SDK. Qiniu only uses Enum::from() / values() / search() semantics.
 */
abstract class Enum implements \\JsonSerializable
{
    protected $value;

    private static $cache = array();

    public function __construct($value)
    {
        if ($value instanceof static) {
            $value = $value->getValue();
        }
        if (!self::isValid($value)) {
            throw new \\UnexpectedValueException("Value '$value' is not part of the enum " . get_called_class());
        }
        $this->value = $value;
    }

    public static function from($value)
    {
        return new static($value);
    }

    public static function toArray()
    {
        $class = get_called_class();
        if (!isset(self::$cache[$class])) {
            $reflection = new \\ReflectionClass($class);
            self::$cache[$class] = $reflection->getConstants();
        }
        return self::$cache[$class];
    }

    public static function values()
    {
        $values = array();
        foreach (self::toArray() as $value) {
            $values[] = new static($value);
        }
        return $values;
    }

    public static function search($value)
    {
        return array_search($value, self::toArray(), true);
    }

    public static function isValid($value)
    {
        return in_array($value, self::toArray(), true);
    }

    public function getValue()
    {
        return $this->value;
    }

    public function equals($enum = null)
    {
        return $enum instanceof static && $this->getValue() === $enum->getValue();
    }

    public function jsonSerialize()
    {
        return $this->getValue();
    }

    public function __toString()
    {
        return (string) $this->getValue();
    }
}
''')
print('[OK] qiniu MyCLabs\\Enum\\Enum bundled')

with open(os.path.join(DST, 'qiniu', 'autoload.php'), 'w', encoding='utf-8') as f:
    f.write('''<?php
// Qiniu Kodo SDK 官方代码瘦 autoloader（v1.1.1 迭代）：自实现 curl 无 Guzzle 依赖。
spl_autoload_register(function ($class) {
    $prefixes = array('Qiniu\\\\' => '/src/Qiniu/', 'MyCLabs\\\\' => '/src/MyCLabs/');
    foreach ($prefixes as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = __DIR__ . $dir . str_replace('\\\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});
''')
print('[OK] qiniu autoload.php')

# ---------- 3) verify no leftover psr7 v1 calls ----------
leftover = []
for sdk, sub in (('upyun', 'src/Upyun'), ('qiniu', 'src/Qiniu')):
    base = os.path.join(DST, sdk, sub)
    for dirpath, _, files in os.walk(base):
        for fn in files:
            if fn.endswith('.php'):
                s = open(os.path.join(dirpath, fn), encoding='utf-8').read()
                for v1 in ('stream_for(', 'copy_to_stream(', 'mimetype_from_filename('):
                    if v1 in s:
                        leftover.append(f'{sdk}:{os.path.relpath(os.path.join(dirpath, fn), DST)}:{v1}')
print('psr7 v1 leftover:', leftover if leftover else 'CLEAN')
