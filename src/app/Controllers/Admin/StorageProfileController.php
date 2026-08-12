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
            $this->json(['success' => true, 'message' => '存储实例「' . $profile->name . '」已创建', 'item' => $this->toItem($profile)]);
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

            $data = $this->collectRequest($request);
            $data['name'] = $name;
            $profile->fill($data);
            if (!$profile->save()) {
                throw new \RuntimeException('保存失败，请重试');
            }
            if ($request->input('is_default') === '1') {
                $profile->setAsDefault();
            }
            $this->json(['success' => true, 'message' => '存储实例已更新', 'item' => $this->toItem($profile)]);
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

            $refs = (int) Database::getInstance()
                ->query('SELECT COUNT(*) FROM `images` WHERE `storage_profile_id` = ' . (int) $id)
                ->fetchColumn();
            if ($refs > 0) {
                throw new \RuntimeException("该实例下仍有 {$refs} 张图片，无法删除。请先删除或迁移这些图片。");
            }

            $wasDefault = $profile->isDefault();
            if (!$profile->delete()) {
                throw new \RuntimeException('删除失败，请重试');
            }

            // Deleting the default leaves the system without one — promote the
            // first remaining enabled profile so uploads keep working.
            if ($wasDefault) {
                $next = StorageProfile::defaultProfile();
                if ($next !== null) {
                    $next->setAsDefault();
                }
            }
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
            $this->json(['success' => true, 'message' => $profile->isEnabled() ? '已启用' : '已停用', 'item' => $this->toItem($profile)]);
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
            $this->json(['success' => true, 'message' => '默认上传方案已切换为「' . $profile->name . '」', 'item' => $this->toItem($profile)]);
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

    /** Validate + collect profile fields (driver/provider/config JSON). */
    private function collectRequest(Request $request): array
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
                'signed_ttl' => trim((string) $request->input('cfg_signed_ttl', '300')),
            ];
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
            $config = [
                'path'       => trim((string) $request->input('cfg_path', '')),
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
}
