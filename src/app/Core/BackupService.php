<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Database;
use App\Models\Setting;

/** 纯 PHP 导出数据库为 .sql，有 ZipArchive 时连同 uploads 打包为 .zip，并按 settings.backup_keep 清理旧备份。 */
class BackupService
{
    /** 备份目录：必须位于项目目录内（绝对路径不许逃逸，相对路径禁止 ..），否则抛异常。 */
    public static function dir(): string
    {
        $path = trim(Setting::get('backup_path', 'backups'));
        if ($path === '') {
            $path = 'backups';
        }
        $root = dirname(__DIR__, 2);
        if (self::isAbsolute($path)) {
            $real = realpath(rtrim($path, '/\\'));
            if ($real === false) {
                $parent = rtrim($path, '/\\');
                while ($parent !== '' && !is_dir($parent)) {
                    $up = dirname($parent);
                    if ($up === $parent) { $parent = ''; break; }
                    $parent = $up;
                }
                $real = $parent !== '' ? realpath($parent) : false;
            }
            if ($real === false || !self::pathInside($real, $root)) {
                throw new \InvalidArgumentException('备份目录必须位于项目目录内（出于安全考虑）。');
            }
            return rtrim($path, '/\\');
        }
        $normalized = trim(str_replace('\\', '/', $path), '/');
        foreach (explode('/', $normalized) as $seg) {
            if ($seg === '..') {
                throw new \InvalidArgumentException('备份目录不允许包含 .. 路径段。');
            }
        }
        return $root . '/' . $normalized;
    }

    private static function pathInside(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
        $path = str_replace('\\', '/', $path);
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function isAbsolute(string $p): bool
    {
        return str_starts_with($p, '/')
            || str_starts_with($p, '\\\\')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p);
    }

    /** 逐表导出到单个 .sql；逐行写盘以免整库读入内存 OOM。成功返回 ''，失败返回错误信息。 */
    public static function dumpSql(string $file): string
    {
        $fh = null;
        try {
            $db = Database::getInstance();
            $tables = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $fh = fopen($file, 'wb');
            if ($fh === false) {
                return '备份文件无法写入：' . $file;
            }
            fwrite($fh, "-- MoeRNG database backup " . date('Y-m-d H:i:s') . "\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($tables as $t) {
                $create = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(\PDO::FETCH_NUM);
                fwrite($fh, "\nDROP TABLE IF EXISTS `{$t}`;\n" . ($create[1] ?? '') . ";\n");
                $stmt = $db->query("SELECT * FROM `{$t}`", \PDO::FETCH_ASSOC);
                foreach ($stmt as $row) {
                    $cols = implode(',', array_map(static fn($c) => "`{$c}`", array_keys($row)));
                    $vals = implode(',', array_map(static function ($v) use ($db) {
                        return $v === null ? 'NULL' : $db->quote((string) $v);
                    }, array_values($row)));
                    fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES ({$vals});\n");
                }
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
            return '';
        } catch (\Throwable $e) {
            return '数据库导出失败：' . $e->getMessage();
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }

    /** 执行完整备份，返回 [ok, message, basename]。 */
    public static function create(): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [false, "无法创建备份目录：{$dir}", ''];
        }
        // 项目根即 web 根，故写 .htaccess/index.html 防护并给文件名加随机后缀；nginx 下 .htaccess 无效，需另配规则。
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
        $stamp = date('Ymd-His');
        $rand  = bin2hex(random_bytes(4));
        $base = rtrim($dir, '/\\') . '/moe-rng-' . $stamp . '-' . $rand;
        $sqlFile = $base . '.sql';

        $err = self::dumpSql($sqlFile);
        if ($err !== '') {
            @unlink($sqlFile);
            return [false, $err, ''];
        }

        $finalFile = $sqlFile;
        if (class_exists('ZipArchive')) {
            $zipFile = $base . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                $zip->addFile($sqlFile, 'moe-rng-' . $stamp . '.sql');
                foreach (self::mediaRoots() as $spec) {
                    if (is_dir($spec['dir']) && self::hasFiles($spec['dir'], $spec['exclude'])) {
                        self::zipDir($zip, $spec['dir'], $spec['label'], $spec['exclude']);
                    }
                }
                $zip->close();
                @unlink($sqlFile);
                $finalFile = $zipFile;
            }
        }

        self::prune();

        $size = is_file($finalFile) ? (int) filesize($finalFile) : 0;
        return [true, '备份完成：' . basename($finalFile) . ' (' . self::humanSize($size) . ')', basename($finalFile)];
    }

    /** @return list<array{label: string, dir: string, exclude: list<string>}> */
    private static function mediaRoots(): array
    {
        $branding = \App\Storage\LocalDriver::BRANDING_REL_DIR;
        $brandingName = basename($branding);

        return [
            ['label' => 'uploads', 'dir' => \App\Storage\LocalDriver::defaultUploadDir(), 'exclude' => []],
            ['label' => 'branding', 'dir' => \App\Storage\LocalDriver::resolveDir($branding), 'exclude' => []],
        ];
    }

    private static function hasFiles(string $dir, array $exclude = []): bool
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($exclude): bool {
                    if ($current->isDir() && in_array($current->getFilename(), $exclude, true)) {
                        return false;
                    }
                    return true;
                }
            )
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                return true;
            }
        }
        return false;
    }

    private static function zipDir(\ZipArchive $zip, string $src, string $prefix, array $exclude = []): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($exclude): bool {
                    if ($current->isDir() && in_array($current->getFilename(), $exclude, true)) {
                        return false;
                    }
                    return true;
                }
            )
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = $prefix . '/' . substr($f->getPathname(), strlen($src) + 1);
            $zip->addFile($f->getPathname(), str_replace('\\', '/', $rel));
        }
    }

    /** 清理到 settings.backup_keep；同一 stamp 的 zip 与 sql 算一组。 */
    public static function prune(): void
    {
        $keep = max(1, (int) (Setting::get('backup_keep', '7') ?: 7));
        $groups = self::groups();
        while (count($groups) > $keep) {
            $oldest = array_pop($groups);
            foreach ($oldest as $file) {
                @unlink($file);
            }
        }
    }

    /** 备份列表，最新在前：每项 ['name','size','mtime','files'=>[...]]。 */
    public static function list(): array
    {
        $out = [];
        foreach (self::groups() as $stamp => $files) {
            $main = '';
            foreach ($files as $f) {
                if (str_ends_with($f, '.zip')) {
                    $main = $f;
                    break;
                }
            }
            if ($main === '') {
                $main = $files[0] ?? '';
            }
            if ($main === '' || !is_file($main)) {
                continue;
            }
            $out[] = [
                'stamp' => $stamp,
                'name' => basename($main),
                'size' => (int) filesize($main),
                'mtime' => (int) filemtime($main),
                'files' => $files,
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public static function delete(string $stamp): bool
    {
        $stamp = preg_replace('/[^0-9\-]/', '', $stamp);
        $dir = self::dir();
        $removed = false;
        foreach (glob($dir . '/moe-rng-' . $stamp . '.*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
                $removed = true;
            }
        }
        return $removed;
    }

    private static function groups(): array
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return [];
        }
        $groups = [];
        foreach (glob($dir . '/moe-rng-*') ?: [] as $f) {
            if (!is_file($f) || !preg_match('/moe-rng-(\d{8}-\d{6})\.(zip|sql)$/', $f, $m)) {
                continue;
            }
            $groups[$m[1]][] = $f;
        }
        krsort($groups);
        return $groups;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
