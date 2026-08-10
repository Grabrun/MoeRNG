<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Database;
use App\Models\Setting;

/**
 * Database + uploads backup manager (v1.1.0-beta.4).
 *
 * Pure-PHP SQL dump (SHOW CREATE TABLE + row INSERTs) so no mysqldump binary
 * is required. When the ZipArchive extension is present, the dump is packaged
 * with the uploads directory into one .zip; otherwise a bare .sql is kept.
 * Old backups are pruned to settings.backup_keep.
 */
class BackupService
{
    /** Absolute backup directory (settings.backup_path, default backups/). */
    public static function dir(): string
    {
        $path = trim(Setting::get('backup_path', 'backups'));
        if ($path === '') {
            $path = 'backups';
        }
        $root = dirname(__DIR__, 2);
        if (self::isAbsolute($path)) {
            return rtrim($path, '/\\');
        }
        return $root . '/' . trim(str_replace('\\', '/', $path), '/');
    }

    private static function isAbsolute(string $p): bool
    {
        return str_starts_with($p, '/')
            || str_starts_with($p, '\\\\')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p);
    }

    /**
     * Dump every table to a single .sql file. Returns '' on success, or an
     * error message.
     */
    public static function dumpSql(string $file): string
    {
        try {
            $db = Database::getInstance();
            $tables = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $out = "-- MoeRNG database backup " . date('Y-m-d H:i:s') . "\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
            foreach ($tables as $t) {
                $create = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(\PDO::FETCH_NUM);
                $out .= "\nDROP TABLE IF EXISTS `{$t}`;\n" . ($create[1] ?? '') . ";\n";
                $rows = $db->query("SELECT * FROM `{$t}`")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $cols = implode(',', array_map(static fn($c) => "`{$c}`", array_keys($row)));
                    $vals = implode(',', array_map(static function ($v) use ($db) {
                        return $v === null ? 'NULL' : $db->quote((string) $v);
                    }, array_values($row)));
                    $out .= "INSERT INTO `{$t}` ({$cols}) VALUES ({$vals});\n";
                }
            }
            $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
            file_put_contents($file, $out);
            return '';
        } catch (\Throwable $e) {
            return '数据库导出失败: ' . $e->getMessage();
        }
    }

    /** Run a full backup. Returns [ok, message, basename]. */
    public static function create(): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [false, "无法创建备份目录: {$dir}", ''];
        }
        $stamp = date('Ymd-His');
        $base = rtrim($dir, '/\\') . '/moe-rng-' . $stamp;
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
                $uploads = dirname(__DIR__, 2) . '/public/uploads';
                if (is_dir($uploads)) {
                    self::zipDir($zip, $uploads, 'uploads');
                }
                $zip->close();
                @unlink($sqlFile);
                $finalFile = $zipFile;
            }
        }

        self::prune();

        $size = is_file($finalFile) ? (int) filesize($finalFile) : 0;
        return [true, '备份完成: ' . basename($finalFile) . ' (' . self::humanSize($size) . ')', basename($finalFile)];
    }

    private static function zipDir(\ZipArchive $zip, string $src, string $prefix): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = $prefix . '/' . substr($f->getPathname(), strlen($src) + 1);
            $zip->addFile($f->getPathname(), str_replace('\\', '/', $rel));
        }
    }

    /**
     * Prune to settings.backup_keep. Groups are matched by the shared stamp
     * (moe-rng-{stamp}.zip + .sql), so a zip and its orphan sql count as one.
     */
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

    /** List backups newest-first: each entry is ['name','size','mtime','files'=>[...]]. */
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

    /** Delete one backup group (by stamp). */
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

    /** Group files by their shared stamp. */
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
        // Newest stamp first.
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
