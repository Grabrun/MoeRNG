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
            // v1.3.1: 对象存储直传开关（前端据此决定 sign→PUT→confirm 或服务器分批）
            'directUploadEnabled' => Config::get('settings.direct_upload_enabled', '0') === '1',
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
        // v1.3.1 迭代: 内容级重复检测 —— SHA-256 哈希比对（批内 + 数据库）。
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

            // v1.3.1: 内容哈希去重 —— 与本批已上传文件、库内已有图片比对。
            // 重复 → 跳过（不上传存储、不建记录），计入 duplicates 继续下一张。
            $fileHash = @hash_file('sha256', $tmpName);
            if ($fileHash !== false && $fileHash !== '') {
                if (isset($batchHashes[$fileHash])) {
                    $duplicates[] = "{$originalName}：与本批次中「{$batchHashes[$fileHash]}」重复，已跳过";
                    continue;
                }
                $existing = Image::firstWhere('file_hash', $fileHash);
                if ($existing !== null) {
                    $duplicates[] = "{$originalName}：与已有图片「{$existing->original_name}」重复，已跳过";
                    continue;
                }
                $batchHashes[$fileHash] = $originalName;
            } else {
                $fileHash = null;
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

                $url = $storage->upload($tmpName, $remotePath, $detectedMime);

                $image = new Image([
                    'filename' => $filename,
                    'original_name' => $originalName,
                    'path' => $remotePath,
                    'url' => $url,
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'file_hash' => $fileHash,
                    'width' => $width,
                    'height' => $height,
                    'category_id' => $categoryId,
                    'sort_order' => 0,
                    'status' => 'active',
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
                $msg = $uploaded > 0 ? "上传完成：成功 {$uploaded} 张" : '所选图片均为重复，未新增';
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

    // ── v1.3.1 迭代: 对象存储前端直传（浏览器 → 云，绕过服务器流量）────
    // mime → 扩展名映射（存储文件名的扩展名与真实 MIME 恒一致）
    private const MIME_EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp',
    ];
    /** 直传单文件上限（presigned PUT 无 post_max_size 约束，设合理硬顶）。 */
    private const DIRECT_MAX_BYTES = 209715200; // 200MB

    /** 解析上传目标存储实例（与 upload() 同逻辑：指定实例 → 默认实例）。 */
    private function resolveUploadProfile(Request $request): ?StorageProfile
    {
        $profileId = (int) $request->input('storage_profile_id', '0');
        $profile = $profileId > 0 ? StorageProfile::find($profileId) : null;
        if ($profile === null || !$profile->isEnabled() || !$profile->isUsable()) {
            $profile = StorageProfile::defaultProfile();
        }
        return $profile;
    }

    /**
     * 直传第一步：为每个待传文件签发 presigned PUT。
     * 开关关闭 / 本地或七牛又拍云等不支持直传的驱动 → 返回 direct=false，
     * 前端回退服务器上传路径。响应体中的 error 项为逐文件校验失败原因。
     */
    public function directSign(Request $request): void
    {
        $this->validateCsrf();

        if (Config::get('settings.direct_upload_enabled', '0') !== '1') {
            $this->json(['direct' => false, 'message' => '直传未启用'], 200);
        }
        $profile = $this->resolveUploadProfile($request);
        if ($profile === null) {
            $this->json(['direct' => false, 'message' => '未配置任何启用的存储实例'], 200);
        }
        if ((string) $profile->driver === 'local') {
            $this->json(['direct' => false, 'message' => '本地存储无需直传'], 200);
        }

        $items = json_decode((string) $request->input('items', '[]'), true);
        if (!is_array($items) || $items === []) {
            $this->json(['error' => '没有待签名的文件'], 400);
        }

        $storage = $profile->driver();
        $signed = [];
        // v1.3.1: 批内哈希集合 —— 同批重复文件只保留第一张
        $batchHashes = [];
        foreach ($items as $it) {
            $original = mb_substr((string) ($it['name'] ?? 'image'), 0, 255);
            $mime = (string) ($it['mime'] ?? '');
            $size = (int) ($it['size'] ?? 0);
            if (!in_array($mime, $this->allowedMimeTypes, true)) {
                $signed[] = ['name' => $original, 'error' => "不支持的文件类型 {$mime}"];
                continue;
            }
            if ($size <= 0 || $size > self::DIRECT_MAX_BYTES) {
                $signed[] = ['name' => $original, 'error' => '文件大小超出直传上限（200MB）'];
                continue;
            }
            $ext = self::MIME_EXT[$mime] ?? strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowedExtensions, true)) {
                $signed[] = ['name' => $original, 'error' => "扩展名 .{$ext} 不在允许列表"];
                continue;
            }

            // v1.3.1: 直传去重 —— 客户端（crypto.subtle）预计算的 SHA-256。
            // 重复项不签发 presigned PUT：浏览器根本不上传字节，直接省掉流量。
            // hash 可为空（非安全上下文等拿不到 crypto.subtle 时），此时跳过去重。
            $itemHash = strtolower(trim((string) ($it['hash'] ?? '')));
            if ($itemHash !== '' && !preg_match('#^[a-f0-9]{64}$#', $itemHash)) {
                $itemHash = '';
            }
            if ($itemHash !== '') {
                if (isset($batchHashes[$itemHash])) {
                    $signed[] = [
                        'name' => $original, 'duplicate' => true,
                        'message' => "与本批次中「{$batchHashes[$itemHash]['name']}」重复，已跳过",
                    ];
                    continue;
                }
                $existing = Image::firstWhere('file_hash', $itemHash);
                if ($existing !== null) {
                    $signed[] = [
                        'name' => $original, 'duplicate' => true,
                        'message' => "与已有图片「{$existing->original_name}」重复，已跳过",
                    ];
                    continue;
                }
                $batchHashes[$itemHash] = ['name' => $original];
            }

            $uuid = bin2hex(random_bytes(8));
            $filename = "{$uuid}.{$ext}";
            $key = date('Y/m') . '/' . $filename;

            $presign = $storage->presignPut($key, $mime, 600);
            if ($presign === null) {
                // SDK 缺席（partial deploy / 精简部署）或驱动不支持直传：
                // 明确告知运维回退原因，前端据此 toast 并走服务器分批上传。
                $this->json([
                    'direct'  => false,
                    'message' => '该存储实例的前端直传不可用（官方 SDK 未部署——sdk/ 目录缺失，或驱动类型不支持直传），已自动回退为服务器上传',
                ], 200);
                return;
            }
            $signed[] = [
                'name' => $original,
                'key' => $key,
                'filename' => $filename,
                'url' => $presign['url'],
                'headers' => $presign['headers'],
                'mime' => $mime,
                'size' => $size,
                'hash' => $itemHash !== '' ? $itemHash : null,
            ];
        }

        $this->json([
            'direct' => true,
            'storage' => (string) $profile->driver,
            'provider' => (string) $profile->provider,
            'profile_id' => (int) $profile->id,
            'items' => $signed,
        ]);
    }

    /**
     * 直传第二步：登记确认。exists()（HeadObject 语义）验证对象真实存在，
     * 防止「登记一个不存在的对象」；key 格式白名单防路径穿越。宽高字段
     * 置 0（浏览器侧不读像素，展示层自适应）。
     */
    public function directConfirm(Request $request): void
    {
        $this->validateCsrf();

        $key = (string) $request->input('key', '');
        $originalName = mb_substr(trim((string) $request->input('original_name', '')), 0, 255);
        $mime = (string) $request->input('mime', '');
        $size = (int) $request->input('size', '0');
        // v1.3.1: 客户端预计算的 SHA-256（sign 时已查过库，此处复核防绕过直调）
        $fileHash = strtolower(trim((string) $request->input('hash', '')));
        if ($fileHash !== '' && !preg_match('#^[a-f0-9]{64}$#', $fileHash)) {
            $fileHash = '';
        }
        $categoryId = $request->input('category_id', '');
        $categoryId = $categoryId !== '' ? (int) $categoryId : null;

        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp|bmp)$#', $key)) {
            $this->json(['error' => '非法的对象 key'], 400);
        }
        if ($originalName === '') {
            $this->json(['error' => '缺少原始文件名'], 400);
        }
        if (!in_array($mime, $this->allowedMimeTypes, true) || $size <= 0 || $size > self::DIRECT_MAX_BYTES) {
            $this->json(['error' => '文件元数据不合法'], 400);
        }

        $profile = $this->resolveUploadProfile($request);
        if ($profile === null) {
            $this->json(['error' => '未配置任何启用的存储实例'], 400);
        }
        $storage = $profile->driver();
        if (!$storage->exists($key)) {
            $this->json(['error' => '直传对象不存在（可能直传失败，或 Bucket 未配置 CORS）'], 400);
        }

        // v1.3.1: confirm 复核去重 —— sign 后又有人传了同一内容（或绕过 sign
        // 直调 confirm）时，对象已到云上但不再建库记录；返回 duplicate 标记，
        // 前端计入"重复跳过"而非错误。孤儿对象留待后续清理任务，无功能影响。
        if ($fileHash !== '') {
            $existing = Image::firstWhere('file_hash', $fileHash);
            if ($existing !== null) {
                $this->json([
                    'success' => false,
                    'duplicate' => true,
                    'error' => "与已有图片「{$existing->original_name}」重复，未登记",
                ]);
            }
        }

        $filename = basename($key);
        $url = $storage->url($key);
        $image = new Image([
            'filename' => $filename,
            'original_name' => $originalName,
            'path' => $key,
            'url' => $url,
            'mime_type' => $mime,
            'file_size' => $size,
            'file_hash' => $fileHash !== '' ? $fileHash : null,
            'width' => 0,
            'height' => 0,
            'category_id' => $categoryId,
            'sort_order' => 0,
            'status' => 'active',
            'storage' => (string) $profile->driver,
            'storage_provider' => (string) $profile->provider,
            'storage_profile_id' => (int) $profile->id,
        ]);
        if (!$image->save()) {
            $this->json(['error' => '数据库写入失败：图片记录未保存'], 500);
        }
        $this->json(['success' => true, 'id' => (int) $image->id, 'url' => $url]);
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
