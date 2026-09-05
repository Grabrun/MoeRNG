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
        $this->validateCsrf();

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            $this->fail('请填写 API Key 名称。', 422, self::PAGE);
        }

        $apiKey = new ApiKey([
            'name' => $name,
            'key' => ApiKey::generateKey(),
            'permissions' => json_encode($this->normalizePermissions($request), JSON_UNESCAPED_UNICODE),
            'rate_limit' => $this->positiveInt($request->input('rate_limit'), 60),
            'rate_window' => $this->positiveInt($request->input('rate_window'), 60),
            'status' => 'active',
        ]);

        if (!$apiKey->save()) {
            $this->fail('API Key 创建失败，请重试。', 500, self::PAGE);
        }

        // The plaintext key is returned exactly once, right here, so the UI can
        // show it in a modal with a copy button. (It is also stored in full in
        // the DB, so this is a UX affordance rather than a secrets-handling
        // guarantee, but the flow matches what operators expect.)
        $this->ok('API Key 已生成。', [
            'item' => $this->present($apiKey),
            'plain_key' => (string) $apiKey->key,
        ], self::PAGE);
    }

    public function update(Request $request): void
    {
        $this->validateCsrf();

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
        $apiKey->permissions = json_encode($this->normalizePermissions($request), JSON_UNESCAPED_UNICODE);

        // Model::update() previously produced invalid SQL for multi-column
        // updates, which is what made this endpoint answer HTTP 500.
        if (!$apiKey->save()) {
            $this->fail('API Key 更新失败。', 500, self::PAGE);
        }

        $this->ok('API Key 已更新。', ['item' => $this->present($apiKey)], self::PAGE);
    }

    public function toggleStatus(Request $request): void
    {
        $this->validateCsrf();

        $apiKey = ApiKey::find((int) $request->input('id'));
        if (!$apiKey) {
            $this->fail('API Key 不存在。', 404, self::PAGE);
        }

        $apiKey->status = $apiKey->status === 'active' ? 'disabled' : 'active';
        if (!$apiKey->save()) {
            $this->fail('状态切换失败。', 500, self::PAGE);
        }

        $this->ok(
            $apiKey->status === 'active' ? 'API Key 已启用。' : 'API Key 已禁用。',
            ['item' => $this->present($apiKey)],
            self::PAGE
        );
    }

    public function delete(Request $request): void
    {
        $this->validateCsrf();

        $id = (int) $request->input('id');
        $apiKey = ApiKey::find($id);
        if (!$apiKey) {
            $this->fail('API Key 不存在。', 404, self::PAGE);
        }

        if (!$apiKey->delete()) {
            $this->fail('API Key 删除失败。', 500, self::PAGE);
        }

        $this->ok('API Key 已删除。', ['id' => $id], self::PAGE);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<int, string> */
    private function normalizePermissions(Request $request): array
    {
        $allowed = ['read', 'write', 'admin'];
        $input = $request->input('permissions', []);
        if (!is_array($input)) {
            $input = [$input];
        }

        $permissions = array_values(array_intersect($allowed, array_map('strval', $input)));
        return $permissions ?: ['read'];
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $int = (int) $value;
        return $int > 0 ? $int : max(1, $fallback);
    }

    /** @return array<string, mixed> */
    private function present(ApiKey $apiKey): array
    {
        // v1.3.0-beta.2 安全加固 (CVE-2026-MR-011, CWE-312): list/edit responses
        // must never carry the full plaintext key — only the 16-char preview.
        // The full value is handed out exactly once, in create(), via the
        // separate 'plain_key' field (one-time reveal, same flow as major
        // token providers).
        return [
            'id' => (int) $apiKey->id,
            'name' => (string) $apiKey->name,
            'key_preview' => substr((string) $apiKey->key, 0, 16) . '...',
            'permissions' => json_decode((string) ($apiKey->permissions ?: '[]'), true) ?: [],
            'rate_limit' => (int) $apiKey->rate_limit,
            'rate_window' => (int) $apiKey->rate_window,
            'status' => (string) $apiKey->status,
        ];
    }
}
