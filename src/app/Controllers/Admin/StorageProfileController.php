<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Database;
use App\Models\StorageProfile;
use App\Storage\S3Driver;

/**
 * Storage profiles (v1.0.33): multi-instance storage configuration.
 *
 * Each profile is one named storage instance — a local directory or a single
 * object-storage bucket (COS / OSS / AWS S3). One profile is the default for
 * uploads; the upload dialog lets the operator pick any enabled profile.
 */
class StorageProfileController extends Controller
{
    public function index(Request $request): void
    {
        $profiles = StorageProfile::all('sort_order ASC, id ASC');
        $default = StorageProfile::defaultProfile();

        $this->render('admin/storage', [
            'title' => '存储管理',
            'profiles' => $profiles,
            'defaultProfile' => $default,
            'providerList' => S3Driver::providerList(),
            'providerFields' => S3Driver::providerFieldDefs(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->validateCsrf();
        try {
            $profile = $this->buildFromRequest($request);
            if (!$profile->save()) {
                throw new \RuntimeException('保存失败，请重试');
            }
            if ($request->input('is_default') === '1') {
                $profile->setAsDefault();
            }
            // MR-38: storage instances (and the cloud credentials behind them) are
            // the most sensitive config on the board. Log identity only — NEVER
            // the config JSON / key / secret.
            \App\Models\AuditLog::record('storage_profile_create', [
                'id'       => (int) $profile->id,
                'name'     => (string) $profile->name,
                'driver'   => (string) $profile->driver,
                'provider' => (string) $profile->provider,
            ]);
            $this->json(['success' => true, 'message' => '存储实例「' . $profile->name . '」已创建', 'item' => $this->toClientItem($profile)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request): void
    {
        $this->validateCsrf();
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }

            // Name is the unique business key; a rename colliding with an
            // existing profile must be rejected.
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                throw new \RuntimeException('请填写实例名称');
            }
            $dup = StorageProfile::firstWhere('name', $name);
            if ($dup !== null && (int) $dup->id !== $id) {
                throw new \RuntimeException('实例名称「' . $name . '」已存在');
            }

            // MR-12: 编辑路径传入解密后的旧配置，凭据类字段留空时回填原值。
            $data = $this->collectRequest($request, $profile->config());
            $data['name'] = $name;
            $profile->fill($data);
            if (!$profile->save()) {
                throw new \RuntimeException('保存失败，请重试');
            }
            if ($request->input('is_default') === '1') {
                $profile->setAsDefault();
            }
            // MR-38: same as create — identity only, never the sealed credentials.
            \App\Models\AuditLog::record('storage_profile_update', [
                'id'       => (int) $profile->id,
                'name'     => (string) $profile->name,
                'driver'   => (string) $profile->driver,
                'provider' => (string) $profile->provider,
            ]);
            $this->json(['success' => true, 'message' => '存储实例已更新', 'item' => $this->toClientItem($profile)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function delete(Request $request): void
    {
        $this->validateCsrf();
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }

            // ── 引用计数与删除必须在同一事务内 ──
            //
            // 两点必要：① TOCTOU —— 无事务/行锁时，检查通过到删除之间可能有并发
            // 上传正往该实例写，删掉后这批图解析不到驱动且已传对象成为孤儿；
            // ② legacy 行 —— storage_profile_id IS NULL 的老行按 storage_provider
            // 匹配实例，只统计 storage_profile_id 会漏判它们。
            $db = Database::getInstance();
            $db->beginTransaction();
            try {
                $countStmt = $db->prepare(
                    'SELECT COUNT(*) FROM `images` '
                    . 'WHERE `storage_profile_id` = ? '
                    . 'OR (`storage_profile_id` IS NULL AND `storage_provider` = ?) '
                    . 'FOR UPDATE'
                );
                $countStmt->execute([$id, (string) $profile->provider]);
                $refs = (int) $countStmt->fetchColumn();
                if ($refs > 0) {
                    throw new \RuntimeException("该实例下仍有 {$refs} 张图片，无法删除。请先删除或迁移这些图片。");
                }

                $wasDefault = $profile->isDefault();
                if (!$profile->delete()) {
                    throw new \RuntimeException('删除失败，请重试');
                }

                // Deleting the default leaves the system without one — promote the
                // first remaining enabled profile so uploads keep working.
                // 与删除同事务：中途失败不会留下「无默认实例且未启用替代」的状态。
                if ($wasDefault) {
                    $next = StorageProfile::defaultProfile();
                    if ($next !== null) {
                        $next->setAsDefault();
                    }
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            // MR-38: irreversible removal of a storage instance.
            \App\Models\AuditLog::record('storage_profile_delete', [
                'id'       => $id,
                'name'     => (string) $profile->name,
                'driver'   => (string) $profile->driver,
                'provider' => (string) $profile->provider,
            ]);
            $this->json(['success' => true, 'message' => '存储实例已删除']);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function toggle(Request $request): void
    {
        $this->validateCsrf();
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }
            $profile->enabled = $profile->isEnabled() ? 0 : 1;
            if (!$profile->save()) {
                throw new \RuntimeException('操作失败，请重试');
            }
            // MR-38: enabling/disabling an instance gates uploads.
            \App\Models\AuditLog::record('storage_profile_toggle', [
                'id'      => (int) $profile->id,
                'name'    => (string) $profile->name,
                'enabled' => $profile->isEnabled(),
            ]);
            $this->json(['success' => true, 'message' => $profile->isEnabled() ? '已启用' : '已停用', 'item' => $this->toClientItem($profile)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function setDefault(Request $request): void
    {
        $this->validateCsrf();
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }
            $profile->setAsDefault();
            // MR-38: switching the upload default changes where new images land.
            \App\Models\AuditLog::record('storage_profile_set_default', [
                'id'   => (int) $profile->id,
                'name' => (string) $profile->name,
            ]);
            $this->json(['success' => true, 'message' => '默认上传方案已切换为「' . $profile->name . '」', 'item' => $this->toClientItem($profile)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** Build a new profile from the request (create path). */
    private function buildFromRequest(Request $request): StorageProfile
    {
        $data = $this->collectRequest($request);
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            throw new \RuntimeException('请填写实例名称');
        }
        if (StorageProfile::firstWhere('name', $name) !== null) {
            throw new \RuntimeException('实例名称「' . $name . '」已存在');
        }
        $data['name'] = $name;
        return new StorageProfile($data);
    }

    /** Validate + collect profile fields (driver/provider/config JSON).
     *
     * @param array|null $keepSecrets decrypted old config (update path only):
     *        credential fields left blank in the form are filled from here,
     *        because the edit form no longer echoes plaintext secrets (MR-12).
     */
    private function collectRequest(Request $request, ?array $keepSecrets = null): array
    {
        $driver = trim((string) $request->input('driver', 'local'));
        if (!in_array($driver, ['local', 's3'], true)) {
            throw new \RuntimeException('无效的存储类型');
        }

        $provider = trim((string) $request->input('provider', ''));
        $config = [];

        if ($driver === 's3') {
            if (!in_array($provider, ['cos', 'oss', 'aws', 'obs', 'upyun', 'qiniu'], true)) {
                throw new \RuntimeException('请选择对象存储服务商');
            }
            $config = [
                'key'      => trim((string) $request->input('cfg_key', '')),
                'secret'   => trim((string) $request->input('cfg_secret', '')),
                'region'   => trim((string) $request->input('cfg_region', '')),
                'bucket'   => trim((string) $request->input('cfg_bucket', '')),
                'endpoint' => trim((string) $request->input('cfg_endpoint', '')),
                'cdn'      => trim((string) $request->input('cfg_cdn', '')),
                // v1.2.1: 自定义源站域名（cos 控制台绑到 bucket 的 CNAME）；
                // 签名 URL 用它替代默认 {bucket}.cos.{region}.myqcloud.com。
                'source_domain' => trim((string) $request->input('cfg_source_domain', '')),
                'signed_ttl' => trim((string) $request->input('cfg_signed_ttl', '300')),
                // MR-04: 又拍云 CDN Token 防盗链独立密钥（可空=不签名）。
                'token_key' => trim((string) $request->input('cfg_token_key', '')),
            ];
            // MR-12: 编辑表单不再回显凭据明文，留空 = 保留原值（仅 update 路径传入
            // $keepSecrets；create 路径无旧值，留空仍触发下方必填校验）。
            if ($keepSecrets !== null) {
                foreach (['key', 'secret', 'token_key'] as $sf) {
                    if (($config[$sf] ?? '') === '' && (string) ($keepSecrets[$sf] ?? '') !== '') {
                        $config[$sf] = (string) $keepSecrets[$sf];
                    }
                }
            }
            if ($config['key'] === '' || $config['secret'] === '' || $config['bucket'] === '') {
                throw new \RuntimeException('对象存储实例需填写完整的 AccessKey / SecretKey / Bucket');
            }
            // v1.2.0 迭代: provider-specific required fields — UPYUN 无 region，
            // OBS 用 endpoint 替代 region。
            if ($provider === 'obs' && $config['endpoint'] === '') {
                throw new \RuntimeException('对象存储实例（华为云 OBS）需填写 Endpoint');
            }
            if ($provider !== 'obs' && $provider !== 'upyun' && $config['region'] === '') {
                throw new \RuntimeException('对象存储实例需填写 Region');
            }
        } else {
            // v1.2.0 迭代: local storage also carries signed_ttl (signed /files links).
            // v1.2.1 迭代: local CDN override (LocalDriver uses it for image URLs).
            $config = [
                'path'       => trim((string) $request->input('cfg_path', '')),
                'cdn'        => trim((string) $request->input('cfg_cdn', '')),
                'signed_ttl' => trim((string) $request->input('cfg_signed_ttl', '300')),
            ];
        }

        return [
            'driver'     => $driver,
            'provider'   => $provider,
            'config'     => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'enabled'    => 1,
            'sort_order' => 0,
        ];
    }

    /** Shape returned to the frontend for table refresh. */
    private function toItem(StorageProfile $p): array
    {
        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'driver' => (string) $p->driver,
            'provider' => (string) $p->provider,
            'type_label' => $p->typeLabel(),
            'instance_label' => $p->instanceLabel(),
            'is_default' => $p->isDefault(),
            'enabled' => $p->isEnabled(),
            'usable' => $p->isUsable(),
            'config' => $p->config(),
        ];
    }

    /**
     * MR-12: same shape as toItem() but with credential values masked before
     * leaving the server. toItem() stays plaintext for internal/driver use;
     * every JSON response to the admin UI goes through this instead.
     */
    private function toClientItem(StorageProfile $p): array
    {
        $item = $this->toItem($p);
        $cfg = $item['config'] ?? [];
        if (is_array($cfg)) {
            foreach ($cfg as $k => $v) {
                if (is_string($v) && \App\Core\CredentialCipher::isSecretField((string) $k) && $v !== '') {
                    // 只露末 4 位做识别，完整值永不进响应体。
                    $cfg[$k] = '••••' . (strlen($v) > 4 ? substr($v, -4) : '');
                }
            }
            $item['config'] = $cfg;
        }
        return $item;
    }
}
