<?php
declare(strict_types=1);

namespace App\Storage;

class LocalDriver implements StorageInterface
{
    private const DEFAULT_REL_DIR = 'public/uploads';

    private string $uploadDir;
    private string $baseUrl;
    private string $cdnOverride;
    private int $signedTtl;

    /**
     * v1.0.35: path/cdn are ALWAYS injected from the owning StorageProfile —
     * there is no settings fallback anymore (profiles are the single source
     * of truth).
     * v1.2.0 迭代: $signedTtl — local files are served through the signed
     * /files endpoint (default 300s) instead of a permanent static URL.
     *
     * @param string $path     Relative (to project root) or absolute upload dir.
     * @param string $cdn      Optional CDN base URL for this instance.
     * @param int    $signedTtl Signed link lifetime in seconds.
     */
    public function __construct(string $path = '', string $cdn = '', int $signedTtl = 300)
    {
        $root = dirname(__DIR__, 2);
        $this->cdnOverride = $cdn;
        $this->signedTtl = max(1, $signedTtl);

        $configuredDir = trim($path);
        if ($configuredDir === '') {
            $configuredDir = self::DEFAULT_REL_DIR;
        }

        // A relative path from the settings form must be anchored to the project
        // root, never to the FPM worker's current working directory.
        $this->uploadDir = self::isAbsolutePath($configuredDir)
            ? rtrim($configuredDir, '/\\')
            : $root . '/' . trim(str_replace('\\', '/', $configuredDir), '/');

        $this->baseUrl = $this->resolveBaseUrl($root);

        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Public URL prefix for locally stored files.
     *
     * Priority (v1.0.35 — no settings reads):
     *   1. CDN domain from the profile (only when actually configured)
     *   2. derive from the SERVER document root + real upload dir, so the URL
     *      is correct whether the web root is the project root or a sub-dir
     *      such as public/  (the common case where a host points the site at
     *      public/ for security) — previously the URL was derived from the
     *      project root only, which 404'd every local image under that layout.
     *   3. hard default /public/uploads
     */
    private function resolveBaseUrl(string $root): string
    {
        if ($this->cdnOverride !== '') {
            return rtrim($this->cdnOverride, '/');
        }

        // Derive from the actual web document root so the URL always resolves.
        $docRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $docRoot = $docRoot !== '' ? realpath($docRoot) : '';
        $realUpload = realpath($this->uploadDir);
        if ($docRoot && $realUpload && str_starts_with($realUpload . DIRECTORY_SEPARATOR, $docRoot . DIRECTORY_SEPARATOR)) {
            $relative = ltrim(substr($realUpload, strlen($docRoot)), '/\\');
            if ($relative !== '') {
                return '/' . str_replace('\\', '/', $relative);
            }
        }

        // Fallback: relative to the project root (works when doc root == root).
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedDir = rtrim(str_replace('\\', '/', $this->uploadDir), '/');
        if ($normalizedDir !== $normalizedRoot && str_starts_with($normalizedDir . '/', $normalizedRoot . '/')) {
            $relative = ltrim(substr($normalizedDir, strlen($normalizedRoot)), '/');
            if ($relative !== '') {
                return '/' . $relative;
            }
        }

        return '/' . self::DEFAULT_REL_DIR;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    public function upload(string $localPath, string $remotePath, string $contentType): string
    {
        $targetPath = $this->uploadDir . '/' . ltrim($remotePath, '/');
        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Cannot create upload directory: {$targetDir}");
        }
        if (!is_writable($targetDir)) {
            throw new \RuntimeException("Upload directory is not writable: {$targetDir}");
        }

        // A silent copy() failure used to return a perfectly valid-looking URL
        // for a file that was never written — the classic "upload succeeded,
        // image 404s" report. Fail loudly instead.
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
        $filePath = $this->uploadDir . '/' . ltrim($remotePath, '/');
        if (is_file($filePath)) {
            return @unlink($filePath);
        }
        return false;
    }

    public function url(string $remotePath): string
    {
        $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
        if ($remotePath === '') {
            return '';
        }

        // v1.2.0 迭代: CDN keeps its permanent URL; otherwise hand back a
        // short-lived signed link served by the /files endpoint (no more
        // permanent static URLs for local files).
        if ($this->cdnOverride !== '') {
            return rtrim($this->cdnOverride, '/') . '/' . $remotePath;
        }

        return SignedUrl::url($remotePath, $this->signedTtl);
    }

    public function exists(string $remotePath): bool
    {
        return is_file($this->uploadDir . '/' . ltrim($remotePath, '/'));
    }

    /** Absolute filesystem directory currently in use (used by doctor.php). */
    public function uploadDir(): string
    {
        return $this->uploadDir;
    }

    /** Public URL prefix currently in use (used by doctor.php). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public static function configFields(): array
    {
        return [
            'storage_local_path' => ['label' => '本地存储路径', 'type' => 'text', 'default' => 'public/uploads', 'placeholder' => '相对于项目根目录'],
        ];
    }

    public static function name(): string
    {
        return '本地存储';
    }
}
