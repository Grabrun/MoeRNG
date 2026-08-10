<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

/**
 * Admin operation audit trail (v1.1.0-beta.4).
 *
 * Every mutating admin action worth remembering lands here: settings changes
 * (with a field-level diff), login attempts, cache clears, backups, storage
 * profile changes. Logging is best-effort — a failure must never break the
 * request that triggered it.
 */
class AuditLog extends Model
{
    protected static string $table = 'audit_logs';
    protected static array $fillable = ['user_id', 'username', 'action', 'detail', 'ip', 'created_at'];

    /** JSON-decoded detail (never null). */
    public function detailArray(): array
    {
        $raw = (string) ($this->attributes['detail'] ?? '');
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Append one audit entry. Never throws — logging is fire-and-forget.
     *
     * @param string $action  stable slug: settings_update / login_success /
     *                        login_failed / cache_clear / backup_run /
     *                        backup_delete / mail_test / ...
     * @param array  $detail  human-readable context, JSON-encoded on the row.
     */
    public static function record(
        string $action,
        array $detail = [],
        ?int $userId = null,
        ?string $username = null
    ): void {
        try {
            $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $sessionUser = isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : '';
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, username, action, detail, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $userId ?? $sessionUserId,
                $username ?? $sessionUser,
                $action,
                $detail !== [] ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
        } catch (\Throwable) {
            // never break the request because logging failed
        }
    }
}
