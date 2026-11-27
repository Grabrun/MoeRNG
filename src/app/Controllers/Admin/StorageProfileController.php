<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Database;
use App\Models\StorageProfile;
use App\Storage\S3Driver;

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
        try {
            $profile = $this->buildFromRequest($request);
            if (!$profile->save()) {
                throw new \RuntimeException('保存失败，请重试');
            }
            if ($request->input('is_default') === '1') {
                $profile->setAsDefault();
            }
            // 审计只记录实例身份，绝不记录配置与凭据。
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
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }

            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                throw new \RuntimeException('请填写实例名称');
            }
            $dup = StorageProfile::firstWhere('name', $name);
            if ($dup !== null && (int) $dup->id !== $id) {
                throw new \RuntimeException('实例名称「' . $name . '」已存在');
            }

            // 编辑表单不回显凭据，留空表示保留原值。
            $data = $this->collectRequest($request, $profile->config());
            $data['name'] = $name;
            $profile->fill($data);
            if (!$profile->save()) {
                throw new \RuntimeException('保存失败，请重试');
            }
            if ($request->input('is_default') === '1') {
                $profile->setAsDefault();
            }
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
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }

            // 引用检查与删除必须同事务；legacy 行的 storage_profile_id 为空，需按 provider 一并计数。
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
        try {
            $id = (int) $request->input('id', '0');
            $profile = StorageProfile::find($id);
            if ($profile === null) {
                throw new \RuntimeException('存储实例不存在');
            }
            $profile->setAsDefault();
            \App\Models\AuditLog::record('storage_profile_set_default', [
                'id'   => (int) $profile->id,
                'name' => (string) $profile->name,
            ]);
            $this->json(['success' => true, 'message' => '默认上传方案已切换为「' . $profile->name . '」', 'item' => $this->toClientItem($profile)]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

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

    /** @param array|null $keepSecrets 编辑路径传入旧配置，凭据字段留空时回填。 */
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
                'source_domain' => trim((string) $request->input('cfg_source_domain', '')),
                'signed_ttl' => trim((string) $request->input('cfg_signed_ttl', '300')),
                'token_key' => trim((string) $request->input('cfg_token_key', '')),
            ];
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
            if ($provider === 'obs' && $config['endpoint'] === '') {
                throw new \RuntimeException('对象存储实例（华为云 OBS）需填写 Endpoint');
            }
            if ($provider !== 'obs' && $provider !== 'upyun' && $config['region'] === '') {
                throw new \RuntimeException('对象存储实例需填写 Region');
            }
            // endpoint 的 SSRF 自检只在写入边界做一次，避免每次构造驱动都刷日志。
            foreach (S3Driver::endpointSafetyWarnings($config['endpoint'], $config['source_domain']) as $w) {
                error_log('[MoeRNG] 存储实例 endpoint 检查：' . $w);
            }
        } else {
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

    /** 返回前端前遮罩凭据，明文只在服务端内部使用。 */
    private function toClientItem(StorageProfile $p): array
    {
        $item = $this->toItem($p);
        $cfg = $item['config'] ?? [];
        if (is_array($cfg)) {
            foreach ($cfg as $k => $v) {
                if (is_string($v) && \App\Core\CredentialCipher::isSecretField((string) $k) && $v !== '') {
                    $cfg[$k] = '••••' . (strlen($v) > 4 ? substr($v, -4) : '');
                }
            }
            $item['config'] = $cfg;
        }
        return $item;
    }
}
