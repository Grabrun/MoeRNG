<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Session;
use App\Models\Image;
use App\Models\Category;
use App\Models\StorageProfile;

class ImageController extends Controller
{
    private const PAGE = '/admin/images';

    private array $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/bmp',
        // SVG deliberately excluded (CVE-2026-MR-003): an <img>-served SVG can
        // carry <script>/<iframe> payloads (stored XSS on the admin grid);
        // CSP cannot fully neutralize scripts inside document-loaded SVGs.
        // Logo upload already excludes SVG for the same reason.
    ];

    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function index(Request $request): void
    {
        $page = max(1, (int) $request->input('page', '1'));
        // v1.2.0 迭代: per-page selector (?per_page=10/20/50/100), falls back
        // to the global settings value when absent or not in the whitelist.
        $perPage = (int) $request->input('per_page', '0');
        $perPage = in_array($perPage, [10, 20, 50, 100], true)
            ? $perPage
            : (int) Config::get('settings.per_page', '20');
        $search = $request->input('search', '');
        $categoryId = $request->input('category_id', '');

        $where = '1=1';
        $params = [];

        if ($search) {
            $where .= " AND (original_name LIKE ? OR filename LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($categoryId !== '') {
            if ($categoryId === '0' || $categoryId === 'null') {
                $where .= " AND category_id IS NULL";
            } else {
                $where .= " AND category_id = ?";
                $params[] = (int) $categoryId;
            }
        }

        $result = Image::paginate($page, $perPage, 'sort_order ASC, id DESC', $where, $params);
        $categories = Category::all('sort_order ASC');
        $storageProfiles = StorageProfile::enabledAll();
        $defaultProfile = StorageProfile::defaultProfile();

        $this->render('admin/images', [
            'title' => '图片管理',
            'images' => $result['data'],
            'total' => $result['total'],
            'page' => $result['page'],
            'lastPage' => $result['last_page'],
            'perPage' => $perPage,
            'categories' => $categories,
            'search' => $search,
            'categoryId' => $categoryId,
            'storageProfiles' => $storageProfiles,
            'defaultProfile' => $defaultProfile,
        ]);
    }

    /**
     * v1.2.0 迭代: all image ids matching the current filters — powers the
     * cross-page "全选全部" action. Same filter logic as index().
     */
    public function ids(Request $request): void
    {
        $search = $request->input('search', '');
        $categoryId = $request->input('category_id', '');

        $where = '1=1';
        $params = [];
        if ($search) {
            $where .= " AND (original_name LIKE ? OR filename LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($categoryId !== '') {
            if ($categoryId === '0' || $categoryId === 'null') {
                $where .= " AND category_id IS NULL";
            } else {
                $where .= " AND category_id = ?";
                $params[] = (int) $categoryId;
            }
        }

        // Lightweight: SELECT id only (avoids hydrating full Models — the old
        // paginate call returned Model objects, so $r['id'] was array-access
        // on an object -> HTTP 500).
        $sql = 'SELECT id FROM images';
        if ($where !== '1=1') {
            $sql .= " WHERE {$where}";
        }
        $sql .= ' ORDER BY id DESC';
        $rows = Image::query($sql, $params);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $this->json(['ids' => $ids, 'total' => count($ids)]);
    }

    /**
     * v1.3.2-beta.2: 生成缩略图到系统临时文件（GD；不可用时返回 null 表示降级跳过）。
     * 供新上传队列 Worker 与「补全历史缩略图」共用。
     *
     * @return array{0: ?string, 1: ?string} [临时文件路径, 存储 key]，失败/降级为 [null, null]
     */
    private function makeThumbnail(string $srcFile, string $mime, string $path): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return [null, null];
        }
        $src = null;
        if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($srcFile);
        elseif ($mime === 'image/png') $src = @imagecreatefrompng($srcFile);
        elseif ($mime === 'image/gif') $src = @imagecreatefromgif($srcFile);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($srcFile);
        // 扩展名兜底（mime 可能缺失/不准）
        if (($src === null || $src === false) && function_exists('getimagesize')) {
            $info = @getimagesize($srcFile);
            $m2 = is_array($info) ? (string) ($info['mime'] ?? '') : '';
            if ($m2 === 'image/jpeg') $src = @imagecreatefromjpeg($srcFile);
            elseif ($m2 === 'image/png') $src = @imagecreatefrompng($srcFile);
            elseif ($m2 === 'image/gif') $src = @imagecreatefromgif($srcFile);
            elseif ($m2 === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($srcFile);
        }
        if ($src === null || $src === false) {
            return [null, null];
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $max = 480;
        $scale = min(1, $max / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        $key = 'thumbs/' . ltrim((string) preg_replace('/\.[a-z0-9]+$/i', '.webp', $path), '/');
        $tmp = tempnam(sys_get_temp_dir(), 'moerng-thumb-');
        $ok = function_exists('imagewebp') && imagewebp($dst, $tmp, 82);
        imagedestroy($dst);
        imagedestroy($src);
        if (!$ok) {
            @unlink($tmp);
            return [null, null];
        }
        return [$tmp, $key];
    }

    /** v1.3.2-beta.2: 临时存放目录（站点根 storage/incoming，web 不可达）。 */
    public static function incomingDir(): string
    {
        return dirname(__DIR__, 3) . '/storage/incoming';
    }

    /**
     * v1.3.2-beta.2 迭代: 异步处理队列 Worker（管理员驱动，Web 分批）。
     *
     * 每次调用处理一小批 pending 图片：
     *   1. 从 storage/incoming 取临时原图；
     *   2. GD 生成缩略图（最大边 480px，webp，质量 82；无 GD/webp 时降级为
     *      不生成缩略图，仅搬运原图）；
     *   3. 原图 + 缩略图上传到该图所属的最终存储实例；
     *   4. 记录置 done（path/url/thumb_path 落库）；
     *   5. 删除临时文件（原图与缩略图临时副本）。
     * 失败置 failed + process_error，临时文件保留供重试（requeueFailed 可把
     * failed 重置回 pending）。前端循环调用直到 remaining=0。
     */
    public function processQueue(Request $request): void
    {
        $this->validateCsrf();

        $batchSize = max(1, min(10, (int) $request->input('batch', '3')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $pendingBefore = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'pending'"
        )->fetchColumn();

        $done = 0;
        $failed = 0;
        $results = [];

        if ($pendingBefore > 0) {
            $rows = $pdo->query(
                "SELECT * FROM `images` WHERE process_status = 'pending' ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $incomingDir = self::incomingDir();

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                $incomingFile = $incomingDir . '/' . ltrim($path, '/');

                try {
                    if (!is_file($incomingFile)) {
                        throw new \RuntimeException('临时文件不存在（可能已被清理）');
                    }

                    // —— 该图所属存储实例 ——
                    $profile = $row['storage_profile_id'] !== null
                        ? StorageProfile::find((int) $row['storage_profile_id'])
                        : null;
                    if ($profile === null) {
                        $profile = StorageProfile::defaultProfile();
                    }
                    if ($profile === null) {
                        throw new \RuntimeException('无可用存储实例');
                    }
                    $storage = $profile->driver();

                    // —— 缩略图（共用 makeThumbnail；环境不支持则降级跳过）——
                    [$thumbTmp, $thumbKey] = $this->makeThumbnail($incomingFile, (string) $row['mime_type'], $path);

                    // —— 上传原图 + 缩略图到最终存储 ——
                    $url = $storage->upload($incomingFile, $path, $mime);
                    $thumbPath = null;
                    if ($thumbTmp !== null && $thumbKey !== null) {
                        $thumbPath = $thumbKey;
                        $storage->upload($thumbTmp, $thumbKey, 'image/webp');
                        @unlink($thumbTmp);
                    }

                    // —— 记录置 done ——
                    $upd = $pdo->prepare(
                        "UPDATE `images` SET `url` = ?, `thumb_path` = ?, `process_status` = 'done', `process_error` = NULL WHERE `id` = ?"
                    );
                    $upd->execute([$url, $thumbPath, $id]);

                    // —— 成功后删除临时原图 ——
                    @unlink($incomingFile);
                    // 空的日期目录顺手清理（忽略失败）
                    @rmdir(dirname($incomingFile));

                    $done++;
                    $results[] = ['id' => $id, 'ok' => true];
                } catch (\Throwable $e) {
                    $failed++;
                    $err = mb_substr($e->getMessage(), 0, 480);
                    try {
                        $pdo->prepare("UPDATE `images` SET `process_status` = 'failed', `process_error` = ? WHERE `id` = ?")
                            ->execute([$err, $id]);
                    } catch (\Throwable) { /* 忽略 */ }
                    $results[] = ['id' => $id, 'error' => $err];
                }
            }

            // 顺带把 'processing' 卡死的行（前次进程中断）复位为 pending
            $pdo->exec("UPDATE `images` SET `process_status` = 'pending' WHERE `process_status` = 'processing'");
        }

        // remaining 只计 pending —— 它是前端循环的终止条件；failed 需人工
        // 显式重试（requeueFailed），若并入 remaining 会导致循环永不终止。
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'pending'"
        )->fetchColumn();
        $failedLeft = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'failed'"
        )->fetchColumn();

        $this->json([
            'success' => true,
            'total' => $total,
            'remaining' => $remaining,
            'failed_left' => $failedLeft,
            'done' => $done,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * POST /admin/images/backfill-thumbs —— 补全历史图片缩略图（分批，幂等）。
     *
     * 判据：process_status='done' AND (thumb_path IS NULL OR thumb_path='')
     * —— 关键：**不改变 process_status**。存量图已在线上展示，若置回 pending
     * 会因前台过滤条件而全部暂时下架；本流程改为从「最终存储」取回原图生成
     * 缩略图、仅回填 thumb_path，图片始终可见。成功后 thumb_path 非空，天然
     * 幂等（不会被重复选中）。
     */
    public function backfillThumbs(Request $request): void
    {
        $this->validateCsrf();
        $batchSize = max(1, min(10, (int) $request->input('batch', '3')));

        // 环境守卫：无 GD/webp 时直接返回错误（避免逐张失败与前端空转）
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->json(['success' => false, 'error' => 'PHP GD 或 WebP 支持不可用，无法生成缩略图（请安装/启用 gd 扩展的 webp 支持）'], 500);
            return;
        }

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $missingCond = "(thumb_path IS NULL OR thumb_path = '')";
        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$missingCond}"
        )->fetchColumn();

        $done = 0;
        $failed = 0;
        $results = [];

        if ($remaining > 0) {
            $rows = $pdo->query(
                "SELECT * FROM `images` WHERE process_status = 'done' AND {$missingCond} ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                try {
                    $profile = $row['storage_profile_id'] !== null
                        ? StorageProfile::find((int) $row['storage_profile_id'])
                        : null;
                    if ($profile === null) {
                        $profile = StorageProfile::defaultProfile();
                    }
                    if ($profile === null) {
                        throw new \RuntimeException('无可用存储实例');
                    }
                    $driver = $profile->driver();

                    // —— 从最终存储取回原图字节 ——
                    $localFile = null;
                    $isTemp = false;
                    if ($driver instanceof \App\Storage\LocalDriver) {
                        $localFile = $driver->uploadDir() . '/' . ltrim($path, '/');
                        if (!is_file($localFile) || !is_readable($localFile)) {
                            throw new \RuntimeException('原图文件不存在（' . $path . '）');
                        }
                    } else {
                        $localFile = \App\Storage\S3Driver::downloadUrl($driver->url($path));
                        if ($localFile === null) {
                            throw new \RuntimeException('对象存储取回失败');
                        }
                        $isTemp = true;
                    }

                    // —— 生成缩略图并上传（makeThumbnail 共用）——
                    [$thumbTmp, $thumbKey] = $this->makeThumbnail($localFile, (string) $row['mime_type'], $path);
                    if ($isTemp) {
                        @unlink($localFile);
                    }
                    if ($thumbTmp === null || $thumbKey === null) {
                        throw new \RuntimeException('缩略图生成失败（源文件可能损坏或格式不支持）');
                    }
                    $driver->upload($thumbTmp, $thumbKey, 'image/webp');
                    @unlink($thumbTmp);

                    // —— 仅回填 thumb_path，状态保持 done ——
                    $pdo->prepare("UPDATE `images` SET `thumb_path` = ? WHERE `id` = ?")
                        ->execute([$thumbKey, $id]);

                    $done++;
                    $results[] = ['id' => $id, 'ok' => true];
                } catch (\Throwable $e) {
                    $failed++;
                    $results[] = ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)];
                }
            }

            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE process_status = 'done' AND {$missingCond}"
            )->fetchColumn();
        }

        $this->json([
            'success' => true,
            'total' => $total,
            'remaining' => $remaining,
            'done' => $done,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * GET /admin/images/queue —— 图片处理管理页（状态总览 + 开始/重试）。
     */
    public function queue(Request $request): void
    {
        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->render('admin/queue', [
                'error' => '数据库连接失败: ' . $e->getMessage(),
                'stats' => ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'no_thumb' => 0, 'total' => 0],
                'pendingRows' => [],
                'failedRows' => [],
                'incomingDir' => self::incomingDir(),
            ]);
            return;
        }

        $count = function (string $where) use ($pdo): int {
            return (int) $pdo->query("SELECT COUNT(*) FROM `images`" . ($where !== '' ? " WHERE {$where}" : ''))->fetchColumn();
        };

        $stats = [
            'pending'    => $count("process_status = 'pending'"),
            'processing' => $count("process_status = 'processing'"),
            'done'       => $count("process_status = 'done'"),
            'failed'     => $count("process_status = 'failed'"),
            'no_thumb'   => $count("process_status = 'done' AND (thumb_path IS NULL OR thumb_path = '')"),
            'total'      => $count(''),
        ];

        $pendingRows = $pdo->query(
            "SELECT id, original_name, file_size, created_at FROM `images` WHERE process_status = 'pending' ORDER BY id ASC LIMIT 50"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $failedRows = $pdo->query(
            "SELECT id, original_name, process_error, file_size, created_at FROM `images` WHERE process_status = 'failed' ORDER BY id DESC LIMIT 50"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $incomingDir = self::incomingDir();
        // 失败项的临时文件是否仍在（决定能否直接重试）
        foreach ($failedRows as &$fr) {
            $row = $pdo->query("SELECT `path` FROM `images` WHERE id = " . (int) $fr['id'])->fetch(\PDO::FETCH_ASSOC);
            $p = (string) ($row['path'] ?? '');
            $fr['temp_exists'] = $p !== '' && is_file($incomingDir . '/' . ltrim($p, '/'));
        }
        unset($fr);

        $this->render('admin/queue', [
            'error' => '',
            'stats' => $stats,
            'pendingRows' => $pendingRows,
            'failedRows' => $failedRows,
            'incomingDir' => $incomingDir,
        ]);
    }

    /** POST /admin/images/requeue-failed —— 把 failed 的图片重置回 pending 重试。 */
    public function requeueFailed(Request $request): void
    {
        $this->validateCsrf();
        try {
            $pdo = \App\Core\Database::getInstance();
            $n = $pdo->exec("UPDATE `images` SET `process_status` = 'pending' WHERE `process_status` = 'failed'");
            $this->json(['success' => true, 'requeued' => (int) $n]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * v1.3.2 迭代: 历史图片哈希回填 —— Web 分批端点（管理员，POST + CSRF）。
     *
     * 每次 POST 处理一小批（默认 5 张）缺哈希的图片：定位其存储实例、把字节
     * 取到服务器（对象存储经签名 URL 拉到临时文件，本地直接读）、对同一份
     * 字节算 MD5 + SHA-256、只补缺失字段、用完即删临时文件。前端循环调用并
     * 显示进度条。幂等 —— 已有双哈希的行永远跳过。
     */
    public function backfillHashes(Request $request): void
    {
        $this->validateCsrf();

        $batchSize = max(1, min(20, (int) $request->input('batch', '5')));

        try {
            $pdo = \App\Core\Database::getInstance();
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => '数据库连接失败: ' . $e->getMessage()], 500);
            return;
        }

        $missingCond = "(file_sha256 IS NULL OR file_sha256='' OR file_hash IS NULL OR file_hash='')";
        $total = (int) $pdo->query("SELECT COUNT(*) FROM `images`")->fetchColumn();
        $remaining = (int) $pdo->query(
            "SELECT COUNT(*) FROM `images` WHERE {$missingCond}"
        )->fetchColumn();

        $updated = 0;
        $failed = 0;
        $results = [];

        if ($remaining > 0) {
            $rows = $pdo->query(
                "SELECT * FROM `images` WHERE {$missingCond} ORDER BY id ASC LIMIT {$batchSize}"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $path = (string) $row['path'];
                $hasMd5 = (string) ($row['file_hash'] ?? '') !== '';
                $hasSha = (string) ($row['file_sha256'] ?? '') !== '';

                try {
                    // 该图所属存储实例 —— storage_profile_id 精确优先，缺省回退默认实例
                    $profile = $row['storage_profile_id'] !== null
                        ? StorageProfile::find((int) $row['storage_profile_id'])
                        : null;
                    if ($profile === null) {
                        $profile = StorageProfile::defaultProfile();
                    }
                    if ($profile === null) {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => '无可用存储实例'];
                        continue;
                    }
                    $driver = $profile->driver();

                    // —— 取字节到本地（对象存储拉临时文件；本地直读）——
                    $localFile = null;
                    $isTemp = false;
                    if ($driver instanceof \App\Storage\LocalDriver) {
                        $localFile = $driver->uploadDir() . '/' . ltrim($path, '/');
                        if (!is_file($localFile) || !is_readable($localFile)) {
                            $failed++;
                            $results[] = ['id' => $id, 'error' => '本地文件不可读'];
                            continue;
                        }
                    } else {
                        try {
                            $localFile = \App\Storage\S3Driver::downloadUrl($driver->url($path));
                        } catch (\Throwable $e) {
                            $localFile = null;
                        }
                        if ($localFile === null) {
                            $failed++;
                            $results[] = ['id' => $id, 'error' => '对象拉取失败'];
                            continue;
                        }
                        $isTemp = true;
                    }

                    // —— 同一份字节算双哈希 ——
                    $md5 = @hash_file('md5', $localFile);
                    $sha = @hash_file('sha256', $localFile);
                    if ($isTemp) {
                        @unlink($localFile);
                    }
                    if ($md5 === false || $sha === false || $md5 === '' || $sha === '') {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => '哈希计算失败'];
                        continue;
                    }

                    $image = new Image($row);
                    if (!$hasMd5) {
                        $image->file_hash = $md5;
                    }
                    if (!$hasSha) {
                        $image->file_sha256 = $sha;
                    }
                    if ($image->save()) {
                        $updated++;
                        $results[] = ['id' => $id, 'ok' => true];
                    } else {
                        $failed++;
                        $results[] = ['id' => $id, 'error' => '保存失败'];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $results[] = ['id' => $id, 'error' => $e->getMessage()];
                }
            }

            $remaining = (int) $pdo->query(
                "SELECT COUNT(*) FROM `images` WHERE {$missingCond}"
            )->fetchColumn();
        }

        $this->json([
            'success' => true,
            'total' => $total,
            'remaining' => $remaining,
            'updated' => $updated,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    public function upload(Request $request): void
    {
        $this->validateCsrf();

        $categoryId = $request->input('category_id', '');
        $categoryId = $categoryId !== '' ? (int) $categoryId : null;

        $files = $_FILES['images'] ?? null;
        if (!$files || empty($files['tmp_name'][0])) {
            Session::flash('error', 'No files uploaded.');
            $this->redirect('/admin/images');
        }

        // Resolve the storage instance for this upload. The operator can pick
        // any enabled profile in the upload dialog; otherwise the default
        // profile is used. v1.0.35: profiles are the single source of truth —
        // a missing usable profile is a hard error, no settings fallback.
        $profileId = (int) $request->input('storage_profile_id', '0');
        $profile = $profileId > 0 ? StorageProfile::find($profileId) : null;
        if ($profile === null || !$profile->isEnabled() || !$profile->isUsable()) {
            $profile = StorageProfile::defaultProfile();
        }
        if ($profile === null) {
            throw new \RuntimeException(
                '未配置任何启用的存储实例。请到后台「存储管理」新增存储实例并设为默认。'
            );
        }
        $storage = $profile->driver();
        $storageType = (string) $profile->driver;
        $storageProvider = (string) $profile->provider;
        $profileId = (int) $profile->id;
        $uploaded = 0;
        $errors = [];
        // v1.3.1 迭代: 内容级重复检测 —— MD5 内容哈希比对（批内 + 数据库）。
        // 字节在服务器手上，哈希由服务端计算，天然权威、不可被前端伪造。
        // 重复图片跳过不存储，汇总提示，不影响其余文件继续上传。
        $duplicates = [];
        $batchHashes = [];

        $fileCount = count($files['tmp_name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $tmpName = $files['tmp_name'][$i];
            $originalName = $files['name'][$i];
            $fileSize = $files['size'][$i];
            $mimeType = $files['type'][$i];

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "{$originalName}: Upload error code {$files['error'][$i]}";
                continue;
            }

            // Validate MIME
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);

            if (!in_array($detectedMime, $this->allowedMimeTypes, true)) {
                $errors[] = "{$originalName}: Invalid file type ({$detectedMime})";
                continue;
            }

            // Validate extension
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowedExtensions, true)) {
                $errors[] = "{$originalName}: Invalid file extension (.{$ext})";
                continue;
            }

            // v1.3.2 迭代: 分层校验去重 —— MD5 快速初筛 + SHA-256 二次确认。
            // MD5 命中疑似重复后再用强哈希确定性判重，消除 MD5 碰撞伪重复。
            // 字节在服务器手上，哈希全部服务端计算，天然权威、不可被前端伪造。
            $md5 = @hash_file('md5', $tmpName);
            $sha256 = @hash_file('sha256', $tmpName);
            $fileHash = ($md5 !== false && $md5 !== '') ? $md5 : null;
            $fileSha256 = ($sha256 !== false && $sha256 !== '') ? $sha256 : null;

            if ($fileHash !== null && $fileSha256 !== null) {
                // ① 批内初筛（MD5，快）
                if (isset($batchHashes[$fileHash])) {
                    // 批内命中 —— 同批同文件必然同内容，直接判定重复。
                    // 但为严谨，仍用 SHA-256 复核（两个不同文件同 MD5 的
                    // 理论场景），确认一致才算重复。
                    $batch = $batchHashes[$fileHash];
                    if ($batch['sha256'] === $fileSha256) {
                        $duplicates[] = "{$originalName}：与本批次中「{$batch['name']}」重复，已跳过";
                        continue;
                    }
                    // 罕见：同 MD5 不同内容（碰撞）—— 不判重，按新图继续。
                }

                // ② 库内初筛（MD5，走索引）
                $existing = Image::firstWhere('file_hash', $fileHash);
                if ($existing !== null) {
                    // ③ 二次确认（SHA-256）：库内已有强哈希则直接比，零流量。
                    if ($existing->file_sha256 !== null && $existing->file_sha256 !== '') {
                        if ($existing->file_sha256 === $fileSha256) {
                            $duplicates[] = "{$originalName}：与已有图片「{$existing->original_name}」重复，已跳过";
                            continue;
                        }
                        // MD5 命中但 SHA-256 不符 → 互不重复，放行（防碰撞误杀）。
                    } else {
                        // 库内旧记录无强哈希 —— 从存储补算（仅疑似、存量少，低频）。
                        $archivedSha = $storage->hashFile($existing->path);
                        if ($archivedSha !== null && $archivedSha === $fileSha256) {
                            $duplicates[] = "{$originalName}：与已有图片「{$existing->original_name}」重复，已跳过";
                            continue;
                        }
                    }
                }

                // 记录批内（存 MD5 + SHA-256 供后续同批比对）
                $batchHashes[$fileHash] = ['name' => $originalName, 'sha256' => $fileSha256];
            }

            // Generate unique filename
            $uuid = bin2hex(random_bytes(8));
            $filename = "{$uuid}.{$ext}";
            $remotePath = date('Y/m') . '/' . $filename;

            try {
                // Get image dimensions
                $imgInfo = @getimagesize($tmpName);
                $width = $imgInfo ? $imgInfo[0] : 0;
                $height = $imgInfo ? $imgInfo[1] : 0;

                // v1.3.2-beta.2 迭代: 异步处理管线 —— 上传落「临时目录」即为成功。
                // 记录以 process_status='pending' 入队，由管理页驱动的队列 Worker
                // 生成缩略图、上传最终存储并把记录置 done；临时文件随后删除。
                $incomingFile = self::incomingDir() . '/' . $remotePath;
                if (!is_dir(dirname($incomingFile))) {
                    @mkdir(dirname($incomingFile), 0755, true);
                }
                if (!@move_uploaded_file($tmpName, $incomingFile)) {
                    throw new \RuntimeException('临时文件写入失败（storage/incoming 不可写）');
                }

                $image = new Image([
                    'filename' => $filename,
                    'original_name' => $originalName,
                    'path' => $remotePath,
                    'url' => '',
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'file_hash' => $fileHash,
                    'file_sha256' => $fileSha256,
                    'width' => $width,
                    'height' => $height,
                    'category_id' => $categoryId,
                    'sort_order' => 0,
                    'status' => 'active',
                    'process_status' => 'pending',
                    'storage' => $storageType,
                    'storage_provider' => $storageProvider,
                    'storage_profile_id' => $profileId,
                ]);
                if (!$image->save()) {
                    // insert() can return false on a silent DB error (e.g. a
                    // missing `storage` column when the migration did not run).
                    // Surface it instead of leaving the file on disk with no
                    // database record, which reads as "uploaded but missing".
                    throw new \RuntimeException('数据库写入失败：图片记录未保存（请检查 images 表是否含 storage / storage_provider 列）');
                }
                $uploaded++;

            } catch (\Throwable $e) {
                $errors[] = "{$originalName}: Storage error - {$e->getMessage()}";
            }
        }

        // AJAX uploads (drag-drop with progress bar) get a JSON verdict instead
        // of the 302+flash dance. A blind XHR follow of a 302 consumes the
        // flash, so the operator would see nothing; returning JSON lets the
        // frontend show a toast with the real error (or success).
        $dupCount = count($duplicates);
        if ($this->isAjax()) {
            // v1.3.1: 全部为重复也算"有结果"（success=true）——不是服务器错误。
            if ($uploaded > 0 || $dupCount > 0) {
                $msg = $uploaded > 0 ? "上传成功 {$uploaded} 张（已进入处理队列，稍后自动完成存储与缩略图）" : '所选图片均为重复，未新增';
                if ($dupCount > 0) {
                    $msg .= $uploaded > 0 ? "，重复跳过 {$dupCount} 张" : "（共 {$dupCount} 张）";
                }
                $this->json([
                    'success' => true,
                    'message' => $msg,
                    'errors' => $errors,
                    'duplicates' => $duplicates,
                ]);
            }
            $this->json([
                'success' => false,
                'message' => '上传失败',
                'errors' => $errors,
            ], 500);
        }

        if ($uploaded > 0 || $dupCount > 0) {
            $msg = $uploaded > 0 ? "Successfully uploaded {$uploaded} image(s)." : '所选图片均为重复，未新增。';
            if ($dupCount > 0) {
                $msg .= $uploaded > 0 ? " 重复跳过 {$dupCount} 张。" : "（共 {$dupCount} 张）";
            }
            Session::flash('success', $msg);
        }
        if (!empty($errors)) {
            Session::flash('error', implode('<br>', $errors));
        }

        $this->redirect('/admin/images');
    }

    public function update(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $image = Image::find($id);

        if (!$image) {
            $this->fail('图片不存在。', 404, self::PAGE);
        }

        $categoryId = $request->input('category_id');
        $image->category_id = $categoryId !== '' && $categoryId !== 'null' ? (int) $categoryId : null;
        $image->save();

        $this->ok('图片已更新。', ['id' => (int) $image->id], self::PAGE);
    }

    public function delete(Request $request): void
    {
        $this->validateCsrf();
        $id = (int) $request->input('id');
        $image = Image::find($id);

        if (!$image) {
            $this->fail('图片不存在。', 404, self::PAGE);
        }

        if (!$image->delete()) {
            $this->fail('图片删除失败。', 500, self::PAGE);
        }

        $this->ok('已删除 1 张图片。', ['deleted' => [$id]], self::PAGE);
    }

    public function batchDelete(Request $request): void
    {
        $this->validateCsrf();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null || $ids === '' ? [] : [$ids];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            $this->fail('未选择任何图片。', 400, self::PAGE);
        }

        $deleted = [];
        $failed = [];
        foreach ($ids as $id) {
            $image = Image::find($id);
            if (!$image) {
                $failed[] = $id;
                continue;
            }
            if ($image->delete()) {
                $deleted[] = $id;
            } else {
                $failed[] = $id;
            }
        }

        if (empty($deleted)) {
            $this->fail('删除失败，未能移除任何图片。', 500, self::PAGE);
        }

        $message = '已删除 ' . count($deleted) . ' 张图片。';
        if (!empty($failed)) {
            $message .= ' ' . count($failed) . ' 张删除失败。';
        }

        $this->ok($message, ['deleted' => $deleted, 'failed' => $failed], self::PAGE);
    }

    /**
     * v1.2.1 迭代: bulk re-categorize selected images (admin UI audit I2).
     * POST /admin/images/batch-categorize { ids: [], category_id: N|0 }
     * category_id 0 clears the category.
     */
    public function batchCategorize(Request $request): void
    {
        $this->validateCsrf();
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            $ids = $ids === null || $ids === '' ? [] : [$ids];
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            $this->fail('未选择任何图片。', 400, self::PAGE);
        }
        $categoryId = (int) $request->input('category_id', '0');
        if ($categoryId < 0) {
            $categoryId = 0;
        }
        if ($categoryId > 0 && !\App\Models\Category::find($categoryId)) {
            $this->fail('分类不存在。', 400, self::PAGE);
        }

        $ok = 0;
        foreach ($ids as $id) {
            $image = Image::find($id);
            if (!$image) {
                continue;
            }
            if ($image->update(['category_id' => $categoryId === 0 ? null : $categoryId])) {
                $ok++;
            }
        }
        if ($ok === 0) {
            $this->fail('未能更新任何图片分类。', 500, self::PAGE);
        }
        \App\Models\AuditLog::record('image_batch_category', [
            'count' => $ok,
            'category_id' => $categoryId,
        ]);
        $message = '已更新 ' . $ok . ' 张图片的'
            . ($categoryId === 0 ? '分类（未分类）。' : '分类。');
        $this->ok($message, ['updated' => $ok, 'category_id' => $categoryId], self::PAGE);
    }

    public function sort(Request $request): void
    {
        $this->validateCsrf();
        $order = $request->input('order', []);
        if (empty($order) || !is_array($order)) {
            $this->json(['error' => 'No order data.'], 400);
        }

        $data = [];
        foreach ($order as $index => $id) {
            $data[] = ['id' => (int) $id, 'sort_order' => $index];
        }

        Image::updateSortOrders($data);
        $this->json(['success' => true, 'message' => 'Sort order updated.']);
    }
}
