<?php
declare(strict_types=1);

namespace App\Storage;

class LocalDriver implements StorageInterface
{
    /**
     * v1.5.0-beta.1: 本地媒体根默认在 **web 根之外**（`storage/uploads`）。
     *
     * 此前默认是 `public/uploads`（web 根之下）：即便 MoeRNG 自己的 URL 早已
     * 走短时签名端点（见 url()），文件仍能被 Web 服务器按静态路径直接读到，
     * 签名机制形同虚设；同时 `public/` 目录语义被媒体文件污染。
     */
    private const DEFAULT_REL_DIR = 'storage/uploads';

    /** 历史默认根（v1.5.0-beta.1 之前）——仅用于一次性迁移与读取回退。 */
    public const LEGACY_REL_DIR = 'public/uploads';

    /** 品牌 logo 目录：站点静态资源（非用户媒体），**不参与**迁根。 */
    public const BRANDING_REL_DIR = 'public/uploads/logo';

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
        $this->cdnOverride = $cdn;
        $this->signedTtl = max(1, $signedTtl);

        // 相对路径锚定项目根（见 resolveDir）；空值即默认布局 storage/uploads。
        $this->uploadDir = self::resolveDir($path);
        $this->baseUrl = $this->resolveBaseUrl();

        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Informational prefix for locally stored files (displayed by doctor.php).
     *
     * v1.5.0-beta.1: 本地文件的**实际**读取路径始终是签名端点（见 url()），
     * 所以这里只回答一个问题 —— 这个目录**能不能被 Web 服务器静态直读**：
     *   1. 配了 CDN 域名         → 该域名（文件由 CDN 回源，仍需回源映射）
     *   2. 目录位于 web 根之下    → 该静态路径（可直读 —— 签名形同虚设，建议迁出）
     *   3. 目录在 web 根之外      → '/files'（只能走短时签名，默认布局即此）
     *
     * 历史实现在拿不到 doc root 时会用「相对项目根」猜一个静态路径，并硬回退到
     * `/public/uploads`；迁根后这两个值都会误导（`storage/` 在 Nginx 里是 deny 的），
     * 因此这里改为只返回真实可用的形态。
     */
    private function resolveBaseUrl(): string
    {
        if ($this->cdnOverride !== '') {
            return rtrim($this->cdnOverride, '/');
        }

        // 只有「确实位于 web 文档根之下」才谈得上静态直读。
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

    /**
     * 把配置里的（相对/绝对）目录解析为绝对路径。
     *
     * 相对路径一律锚定**项目根**，绝不跟随 PHP-FPM 的工作目录 —— 否则同一个
     * 配置在不同上下文下会指向不同目录（历史 bug 源头）。
     */
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

    /** 默认媒体根（绝对路径）—— 单测/迁移/备份共用同一来源。 */
    public static function defaultUploadDir(): string
    {
        return self::resolveDir('');
    }

    /** 默认媒体根的**相对**形态（存储实例 config.path 里记录的形式）。 */
    public static function defaultRelDir(): string
    {
        return self::DEFAULT_REL_DIR;
    }

    /** 历史媒体根（绝对路径 v1.5.0-beta.1 之前），仅迁移与回退用。 */
    public static function legacyUploadDir(): string
    {
        return self::resolveDir(self::LEGACY_REL_DIR);
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

    /**
     * v1.3.2 迭代: 本地文件直接流式计算 SHA-256。
     */
    public function hashFile(string $remotePath): ?string
    {
        $full = $this->uploadDir . '/' . ltrim($remotePath, '/');
        if (!is_file($full)) {
            return null;
        }
        $h = @hash_file('sha256', $full);
        return ($h === false || $h === '') ? null : $h;
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

    /** 读取方式：'cdn'（配了 CDN 域名）或 'signed'（/files 短时签名）。 */
    public function servingMode(): string
    {
        return $this->cdnOverride !== '' ? 'cdn' : 'signed';
    }

    /** 该实例配置的 CDN 域名（空 = 未配置）。 */
    public function cdnUrl(): string
    {
        return $this->cdnOverride;
    }

    public static function configFields(): array
    {
        return [
            'storage_local_path' => ['label' => '本地存储路径', 'type' => 'text', 'default' => self::DEFAULT_REL_DIR, 'placeholder' => '相对于项目根目录；默认 storage/uploads（web 根之外，走签名端点）'],
        ];
    }

    public static function name(): string
    {
        return '本地存储';
    }
}
