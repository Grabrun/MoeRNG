<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Models\ApiKey;

class ApiKeyController extends Controller
{
    private const PAGE = '/admin/apikeys';

    public function index(Request $request): void
    {
        $apiKeys = ApiKey::all('id DESC');

        $this->render('admin/apikeys', [
            'title' => 'API Key 管理',
            'apiKeys' => $apiKeys,
        ]);
    }

    public function create(Request $request): void
    {

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            $this->fail('请填写 API Key 名称。', 422, self::PAGE);
        }

        // 明文只下发一次，库里只存哈希与 16 位展示前缀。
        $plainKey = ApiKey::generateKey();

        $apiKey = new ApiKey([
            'name' => $name,
            'key_hash' => ApiKey::hashKey($plainKey),
            'key_prefix' => substr($plainKey, 0, 16),
            'rate_limit' => $this->positiveInt($request->input('rate_limit'), 60),
            'rate_window' => $this->positiveInt($request->input('rate_window'), 60),
            'status' => 'active',
        ]);

        if (!$apiKey->save()) {
            $this->fail('API Key 创建失败，请重试。', 500, self::PAGE);
        }

        // 审计只记录前缀，绝不记录明文密钥。
        \App\Models\AuditLog::record('apikey_create', [
            'id'     => (int) $apiKey->id,
            'name'   => (string) $apiKey->name,
            'prefix' => (string) $apiKey->key_prefix,
        ]);

        $this->ok('API Key 已生成。', [
            'item' => $this->present($apiKey),
            'plain_key' => $plainKey,
        ], self::PAGE);
    }

    public function update(Request $request): void
    {

        $apiKey = ApiKey::find((int) $request->input('id'));
        if (!$apiKey) {
            $this->fail('API Key 不存在。', 404, self::PAGE);
        }

        $name = trim((string) $request->input('name', (string) $apiKey->name));
        if ($name === '') {
            $this->fail('请填写 API Key 名称。', 422, self::PAGE);
        }

        $apiKey->name = $name;
        $apiKey->rate_limit = $this->positiveInt($request->input('rate_limit'), (int) $apiKey->rate_limit);
        $apiKey->rate_window = $this->positiveInt($request->input('rate_window'), (int) $apiKey->rate_window);

        if (!$apiKey->save()) {
            $this->fail('API Key 更新失败。', 500, self::PAGE);
        }

        \App\Models\AuditLog::record('apikey_update', [
            'id'   => (int) $apiKey->id,
            'name' => (string) $apiKey->name,
        ]);

        $this->ok('API Key 已更新。', ['item' => $this->present($apiKey)], self::PAGE);
    }

    public function toggleStatus(Request $request): void
    {

        $apiKey = ApiKey::find((int) $request->input('id'));
        if (!$apiKey) {
            $this->fail('API Key 不存在。', 404, self::PAGE);
        }

        $apiKey->status = $apiKey->status === 'active' ? 'disabled' : 'active';
        if (!$apiKey->save()) {
            $this->fail('状态切换失败。', 500, self::PAGE);
        }

        \App\Models\AuditLog::record('apikey_status', [
            'id'     => (int) $apiKey->id,
            'name'   => (string) $apiKey->name,
            'status' => (string) $apiKey->status,
        ]);

        $this->ok(
            $apiKey->status === 'active' ? 'API Key 已启用。' : 'API Key 已禁用。',
            ['item' => $this->present($apiKey)],
            self::PAGE
        );
    }

    public function delete(Request $request): void
    {

        $id = (int) $request->input('id');
        $apiKey = ApiKey::find($id);
        if (!$apiKey) {
            $this->fail('API Key 不存在。', 404, self::PAGE);
        }

        if (!$apiKey->delete()) {
            $this->fail('API Key 删除失败。', 500, self::PAGE);
        }

        \App\Models\AuditLog::record('apikey_delete', [
            'id'     => $id,
            'name'   => (string) $apiKey->name,
            'prefix' => (string) $apiKey->key_prefix,
        ]);

        $this->ok('API Key 已删除。', ['id' => $id], self::PAGE);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $int = (int) $value;
        return $int > 0 ? $int : max(1, $fallback);
    }

    private function present(ApiKey $apiKey): array
    {
        return [
            'id' => (int) $apiKey->id,
            'name' => (string) $apiKey->name,
            'key_preview' => (string) $apiKey->key_prefix . '...',
            'rate_limit' => (int) $apiKey->rate_limit,
            'rate_window' => (int) $apiKey->rate_window,
            'status' => (string) $apiKey->status,
        ];
    }
}
