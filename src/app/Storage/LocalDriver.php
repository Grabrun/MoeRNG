<?php
declare(strict_types=1);

namespace App\Storage;

class LocalDriver implements StorageInterface
{
    /** 媒体根默认在 web 根之外，避免被 Web 服务器静态直读绕过签名。 */
    private const DEFAULT_REL_DIR = 'storage/uploads';

    /** 站点 logo 目录，位于 web 根下（非用户媒体）。 */
    public const BRANDING_REL_DIR = 'public/uploads/logo';

    private string $uploadDir;
    private string $baseUrl;
    private string $cdnOverride;
    private int $signedTtl;

    public function __construct(string $path = '', string $cdn = '', int $signedTtl = 300)
    {
        $this->cdnOverride = $cdn;
        $this->signedTtl = max(1, $signedTtl);

        $this->uploadDir = self::resolveDir($path);
        $this->baseUrl = $this->resolveBaseUrl();

        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    /** 此处只回答一个问题：该目录能否被 Web 服务器静态直读。 */
    private function resolveBaseUrl(): string
    {
        if ($this->cdnOverride !== '') {
            return rtrim($this->cdnOverride, '/');
        }

        $docRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $docRoot = $docRoot !== '' ? realpath($docRoot) : '';
        $realUpload = realpath($this->uploadDir);
        if ($docRoot && $realUpload && str_starts_with($realUpload . DIRECTORY_SEPARATOR, $docRoot . DIRECTORY_SEPARATOR)) {
            $relative = ltrim(substr($realUpload, strlen($docRoot)), '/\\');
            if ($relative !== '') {
                return '/' . str_replace('\\', '/', $relative);
            }
        }

        return '/files';
    }

    /** 相对路径一律锚定项目根，不跟随 PHP-FPM 工作目录。 */
    public static function resolveDir(string $path = ''): string
    {
        $dir = trim($path);
        if ($dir === '') {
            $dir = self::DEFAULT_REL_DIR;
        }
        if (self::isAbsolutePath($dir)) {
            return rtrim($dir, '/\\');
        }
        return dirname(__DIR__, 2) . '/' . trim(str_replace('\\', '/', $dir), '/');
    }

    public static function defaultUploadDir(): string
    {
        return self::resolveDir('');
    }

    public static function defaultRelDir(): string
    {
        return self::DEFAULT_REL_DIR;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /** 拒绝绝对路径与 .. 段：否则路径可逃出媒体根，delete() 会变成任意文件删除。 */
    private function resolveKeyPath(string $remotePath): string
    {
        $p = ltrim(str_replace('\\', '/', trim($remotePath)), '/');
        if ($p === '' || (bool) preg_match('#^[A-Za-z]:#', $p)) {
            throw new \RuntimeException("Invalid storage key (must be relative): {$remotePath}");
        }
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') {
                throw new \RuntimeException("Storage key escapes media root (.. segment): {$remotePath}");
            }
        }
        return rtrim($this->uploadDir, '/\\') . '/' . $p;
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        $targetPath = $this->resolveKeyPath($remotePath);
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Cannot create upload directory: {$targetDir}");
        }
        if (!is_writable($targetDir)) {
            throw new \RuntimeException("Upload directory is not writable: {$targetDir}");
        }

        $moved = is_uploaded_file($localPath)
            ? @move_uploaded_file($localPath, $targetPath)
            : @copy($localPath, $targetPath);

        if (!$moved || !is_file($targetPath)) {
            throw new \RuntimeException("Failed to write uploaded file to {$targetPath}");
        }

        @chmod($targetPath, 0644);
        return $this->url($remotePath);
    }

    public function delete(string $remotePath): bool
    {
        $filePath = $this->resolveKeyPath($remotePath);
        if (is_file($filePath)) {
            return @unlink($filePath);
        }
        // 键不存在也算删除成功（与云驱动一致），避免历史行清理被误判为失败。
        return true;
    }

        public function url(string $remotePath): string
    {
        $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        if ($remotePath === '') {
            return '';
        }

        // 本地文件不提供永久静态 URL，无 CDN 时一律走 /files 短时签名。
        if ($this->cdnOverride !== '') {
            return rtrim($this->cdnOverride, '/') . '/' . $remotePath;
        }

        return SignedUrl::url($remotePath, $this->signedTtl);
    }

    public function exists(string $remotePath): bool
    {
        return is_file($this->resolveKeyPath($remotePath));
    }

    public function size(string $remotePath): ?int
    {
        $full = $this->resolveKeyPath($remotePath);
        if (!is_file($full)) {
            return null;
        }
        $n = @filesize($full);
        return ($n === false || $n <= 0) ? null : (int) $n;
    }

    public function uploadDir(): string
    {
        return $this->uploadDir;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function servingMode(): string
    {
        return $this->cdnOverride !== '' ? 'cdn' : 'signed';
    }
}
