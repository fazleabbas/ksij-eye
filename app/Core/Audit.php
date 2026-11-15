<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Append-only audit trail. The runtime DB account has INSERT/SELECT only on these tables,
 * so entries cannot be altered or deleted through the application.
 * Never pass patient values or secrets in $changes — record field names instead.
 */
final class Audit
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(8));
    }

    /**
     * @param array|null $actor overrides the signed-in user: ['user_id'=>?, 'username'=>?, 'display'=>?]
     */
    public static function log(
        string $module,
        string $action,
        string $summary = '',
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?array $changes = null,
        ?array $actor = null,
    ): void {
        $ctx = Auth::user();
        Database::execute(
            'INSERT INTO audit_log (occurred_at, user_id, username, user_display, ip_address, module, action,
                                    entity_type, entity_id, summary, changes, request_id)
             VALUES (UTC_TIMESTAMP(3), :uid, :uname, :udisplay, :ip, :module, :action, :etype, :eid, :summary, :changes, :rid)',
            [
                'uid'      => $actor['user_id'] ?? $ctx?->userId,
                'uname'    => $actor['username'] ?? $ctx?->username,
                'udisplay' => $actor['display'] ?? $ctx?->displayName,
                'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
                'module'   => $module,
                'action'   => $action,
                'etype'    => $entityType,
                'eid'      => $entityId === null ? null : (string) $entityId,
                'summary'  => mb_substr($summary, 0, 255),
                'changes'  => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'rid'      => self::requestId(),
            ]
        );
    }

    /** Records that a user viewed/printed/exported a patient's record. */
    public static function access(int $patientId, string $module, string $action, ?string $entityType = null, int|string|null $entityId = null): void
    {
        $ctx = Auth::require();
        Database::execute(
            'INSERT INTO record_access_log (occurred_at, user_id, patient_id, module, action, entity_type, entity_id, ip_address, request_id)
             VALUES (UTC_TIMESTAMP(3), :uid, :pid, :module, :action, :etype, :eid, :ip, :rid)',
            [
                'uid' => $ctx->userId, 'pid' => $patientId, 'module' => $module, 'action' => $action,
                'etype' => $entityType, 'eid' => $entityId === null ? null : (string) $entityId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'rid' => self::requestId(),
            ]
        );
    }

    /**
     * Field-level differences between two snapshots.
     * Fields listed in $sensitive are recorded as changed without their values.
     */
    public static function diff(array $before, array $after, array $sensitive = []): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            $old = $before[$field] ?? null;
            if (self::same($old, $new)) {
                continue;
            }
            $changes[$field] = in_array($field, $sensitive, true) ? ['changed' => true] : ['from' => $old, 'to' => $new];
        }
        return $changes;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = (array) $a;
            $b = (array) $b;
            sort($a);
            sort($b);
            return $a == $b;
        }
        return (string) $a === (string) $b;
    }
}
