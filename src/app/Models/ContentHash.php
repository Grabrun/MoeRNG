<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 内容哈希索引：同一张表承载源字节、落盘产物与三档缩略图的哈希。
 * 唯一键（sha256 主键）同时充当并发闸门 —— 上传时 INSERT 抢位，撞 1062 即判重复。
 */
final class ContentHash
{
    public const KIND_SOURCE = 'source';
    public const KIND_STORED = 'stored';

    /** 尺寸名 => kind */
    public const THUMB_KINDS = ['sm' => 'thumb_sm', 'md' => 'thumb_md', 'lg' => 'thumb_lg'];

    private const HASH_RE = '/^[0-9a-f]{64}$/';

    public static function isHash(string $value): bool
    {
        return preg_match(self::HASH_RE, $value) === 1;
    }

    /** @return array{sha256:string, image_id:?int, kind:string}|null */
    public static function find(string $sha256): ?array
    {
        if (!self::isHash($sha256)) {
            return null;
        }
        $st = Database::getInstance()->prepare(
            'SELECT `sha256`, `image_id`, `kind` FROM `content_hashes` WHERE `sha256` = ? LIMIT 1'
        );
        $st->execute([$sha256]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : self::shape($row);
    }

    /**
     * @param string[] $hashes
     * @return array<string, array{sha256:string, image_id:?int, kind:string}>
     */
    public static function findMany(array $hashes): array
    {
        $clean = [];
        foreach ($hashes as $h) {
            if (is_string($h) && self::isHash($h)) {
                $clean[$h] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $list = array_keys($clean);
        $in = implode(',', array_fill(0, count($list), '?'));
        $st = Database::getInstance()->prepare(
            "SELECT `sha256`, `image_id`, `kind` FROM `content_hashes` WHERE `sha256` IN ({$in})"
        );
        $st->execute($list);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $shaped = self::shape($row);
            $out[$shaped['sha256']] = $shaped;
        }
        return $out;
    }

    /**
     * 抢位：成功返回 true；已被占用（含并发撞唯一键）返回 false。
     * 调用方拿到 false 后应重新 find() 一次，避免把「别人刚占位」当不存在。
     */
    public static function reserve(string $sha256, string $kind): bool
    {
        if (!self::isHash($sha256)) {
            return false;
        }
        try {
            Database::getInstance()->prepare(
                'INSERT INTO `content_hashes` (`sha256`, `image_id`, `kind`) VALUES (?, NULL, ?)'
            )->execute([$sha256, $kind]);
            return true;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public static function attach(string $sha256, int $imageId): void
    {
        if (!self::isHash($sha256) || $imageId <= 0) {
            return;
        }
        Database::getInstance()->prepare(
            'UPDATE `content_hashes` SET `image_id` = ? WHERE `sha256` = ?'
        )->execute([$imageId, $sha256]);
    }

    public static function release(string ...$hashes): void
    {
        $list = [];
        foreach ($hashes as $h) {
            if (self::isHash($h)) {
                $list[$h] = true;
            }
        }
        if ($list === []) {
            return;
        }
        $keys = array_keys($list);
        $in = implode(',', array_fill(0, count($keys), '?'));
        Database::getInstance()->prepare("DELETE FROM `content_hashes` WHERE `sha256` IN ({$in})")
            ->execute($keys);
    }

    /**
     * 记录产出物哈希（落盘原图 / 各档缩略图）。
     * 撞键时保留先到的归属：相同字节可能被多张图产出，覆盖会让先出的那张失去索引。
     *
     * @param array<string,string> $kindToHash kind => sha256
     */
    public static function record(int $imageId, array $kindToHash): void
    {
        if ($imageId <= 0 || $kindToHash === []) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($kindToHash as $kind => $hash) {
            if (!is_string($hash) || !self::isHash($hash)) {
                continue;
            }
            $values[] = '(?, ?, ?)';
            $params[] = $hash;
            $params[] = $imageId;
            $params[] = (string) $kind;
        }
        if ($values === []) {
            return;
        }
        Database::getInstance()->prepare(
            'INSERT INTO `content_hashes` (`sha256`, `image_id`, `kind`) VALUES ' . implode(',', $values)
            . ' ON DUPLICATE KEY UPDATE `sha256` = `sha256`'
        )->execute($params);
    }

    /** 回收请求中途死亡留下的空占位（image_id 仍为 NULL）。 */    public static function sweepStale(int $minutes = 10): int
    {
        $minutes = max(1, min(1440, $minutes));
        $st = Database::getInstance()->prepare(
            'DELETE FROM `content_hashes` WHERE `image_id` IS NULL'
            . ' AND `created_at` < (NOW() - INTERVAL ? MINUTE)'
        );
        $st->execute([$minutes]);
        return $st->rowCount();
    }

    /**
     * 读出图片当前字节并算 SHA-256（Local 直读；远端下载到临时文件后立即删除）。
     * 存量回填的单一来源：Web 分批回填与两个 CLI 都走这里，避免三份取数逻辑各自漂移。
     *
     * @param array{path?:string, storage_profile_id?:mixed} $row
     * @return array{ok:bool, sha?:string, error?:string}
     */
    public static function sha256ForImageRow(array $row, bool $allowRemote = true): array
    {
        $path = (string) ($row['path'] ?? '');
        if ($path === '') {
            return ['ok' => false, 'error' => '缺少 path'];
        }

        try {
            $rawPid = $row['storage_profile_id'] ?? null;
            $profile = StorageProfile::resolve(($rawPid === null || $rawPid === '') ? null : (int) $rawPid);
            if ($profile === null) {
                return ['ok' => false, 'error' => '无可用存储实例'];
            }
            $driver = StorageProfile::cachedDriver($profile);

            if ($driver instanceof \App\Storage\LocalDriver) {
                $local = $driver->uploadDir() . '/' . ltrim($path, '/');
                if (!is_file($local) || !is_readable($local)) {
                    return ['ok' => false, 'error' => '本地文件不可读'];
                }
                $sha = @hash_file('sha256', $local);
                return is_string($sha) && $sha !== ''
                    ? ['ok' => true, 'sha' => $sha]
                    : ['ok' => false, 'error' => '哈希计算失败'];
            }

            if (!$allowRemote) {
                return ['ok' => false, 'error' => '已跳过远端回源'];
            }
            $tmp = \App\Storage\S3Driver::downloadUrl($driver->url($path));
            if ($tmp === null) {
                return ['ok' => false, 'error' => '对象拉取失败'];
            }
            try {
                $sha = @hash_file('sha256', $tmp);
            } finally {
                @unlink($tmp);
            }
            return is_string($sha) && $sha !== ''
                ? ['ok' => true, 'sha' => $sha]
                : ['ok' => false, 'error' => '哈希计算失败'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return list<array{sha256:string, kind:string}> */
    public static function forImage(int $imageId): array
    {
        $st = Database::getInstance()->prepare(
            'SELECT `sha256`, `kind` FROM `content_hashes` WHERE `image_id` = ?'
        );
        $st->execute([$imageId]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['sha256' => (string) $row['sha256'], 'kind' => (string) $row['kind']];
        }
        return $out;
    }

    public static function tableExists(): bool
    {
        try {
            Database::getInstance()->query('SELECT 1 FROM `content_hashes` LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 建表 + 外键：运行时迁移与健康检查修复共用同一份 DDL。 */
    public static function ensureTable(?\PDO $db = null): void
    {
        $db ??= Database::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS `content_hashes` (
            `sha256` CHAR(64) NOT NULL,
            `image_id` INT NULL DEFAULT NULL,
            `kind` ENUM('source','stored','thumb_sm','thumb_md','thumb_lg') NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`sha256`),
            INDEX `idx_image` (`image_id`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::ensureForeignKey($db);
    }

    private static function ensureForeignKey(\PDO $db): void
    {
        try {
            $hasFk = $db->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_hashes'"
                . " AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1"
            )->fetchColumn() !== false;
            if (!$hasFk) {
                $db->exec(
                    'ALTER TABLE `content_hashes` ADD CONSTRAINT `fk_ch_image`'
                    . ' FOREIGN KEY (`image_id`) REFERENCES `images`(`id`) ON DELETE CASCADE'
                );
            }
        } catch (\Throwable) {
            // 存量脏数据可能拒绝外键：降级为「删图不自动释放哈希」，由 sweepStale 与删除路径兜底
        }
    }

    /** @param array<string,mixed> $row */
    private static function shape(array $row): array
    {
        return [
            'sha256'   => (string) $row['sha256'],
            'image_id' => $row['image_id'] === null ? null : (int) $row['image_id'],
            'kind'     => (string) $row['kind'],
        ];
    }
}
