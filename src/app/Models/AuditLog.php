<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Core\Database;

/** 后台操作审计；写入是 best-effort，失败绝不影响主流程。 */
class AuditLog extends Model
{
    protected static string $table = 'audit_logs';
    protected static array $fillable = ['user_id', 'username', 'action', 'detail', 'ip', 'created_at'];

    /** 追加一条审计记录，永不抛异常。 */
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
        }
    }
}
